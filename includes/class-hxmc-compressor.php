<?php
/**
 * HXMC Compressor — in-place recompression of originals.
 *
 * Design decisions:
 * - Overwrites the same filename: URLs never change, so no DB rewrite and
 *   no redirect map entry is needed. This IS the "上書き" mode.
 * - Overwrite happens ONLY when the re-encode is smaller; otherwise the
 *   original byte stream is kept untouched.
 * - No backups: doubling disk usage contradicts the purpose of a cleanup
 *   plugin. The UI confirm dialog states clearly that this is lossy (JPEG)
 *   and irreversible.
 * - JPEG: quality re-encode (shares the single hxmc_webp_quality knob —
 *   one quality concept across the plugin, subtraction).
 * - PNG: lossless zlib recompress only (GD/Imagick have no quantizer);
 *   gains are honestly small and reported as such.
 * - Animated GIF / SVG / WebP: refused.
 * - Fires `hxmc_after_compress` for the HXMD bridge.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HXMC_Compressor {

	const META_KEY = '_hxmc_compressed';

	public static function is_compressible( $attachment_id ) {
		$mime = get_post_mime_type( $attachment_id );
		return in_array( $mime, array( 'image/jpeg', 'image/png' ), true );
	}

	/**
	 * @return array|WP_Error ['files' => n, 'saved_bytes' => n, 'skipped' => n]
	 */
	public static function compress( $attachment_id, $quality = null ) {
		if ( ! self::is_compressible( $attachment_id ) ) {
			return new WP_Error( 'hxmc_not_compressible', __( 'Only JPEG and PNG files can be compressed in place.', 'hxmc-smart-media-cleaner' ) );
		}

		$quality = null === $quality ? (int) get_option( 'hxmc_webp_quality', 82 ) : (int) $quality;
		$quality = max( 1, min( 100, $quality ) );

		$file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! $file ) {
			return new WP_Error( 'hxmc_no_file', __( 'Attachment file not found.', 'hxmc-smart-media-cleaner' ) );
		}
		if ( ! HXMC_Paths::is_valid_relative( $file ) ) {
			return HXMC_Paths::error();
		}

		$uploads = wp_get_upload_dir();
		$dir_rel = dirname( $file );
		$dir_rel = ( '.' === $dir_rel ) ? '' : trailingslashit( $dir_rel );
		$dir_abs = trailingslashit( $uploads['basedir'] ) . $dir_rel;

		$targets = array( wp_basename( $file ) );
		$meta    = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $info ) {
				if ( ! empty( $info['file'] ) ) {
					$targets[] = $info['file'];
				}
			}
		}
		$targets = array_unique( $targets );

		$done        = 0;
		$skipped     = 0;
		$saved_bytes = 0;

		foreach ( $targets as $basename ) {
			if ( ! HXMC_Paths::is_valid_size_basename( $basename ) && wp_basename( $file ) !== $basename ) {
				$skipped++;
				continue;
			}
			$src = $dir_abs . $basename;
			if ( ! file_exists( $src ) || ! HXMC_Paths::is_inside_uploads( $src ) ) {
				$skipped++;
				continue;
			}
			$result = self::reencode_smaller( $src, $quality );
			if ( null === $result ) {
				$skipped++;
				continue;
			}
			$saved_bytes += $result;
			$done++;
		}

		if ( 0 === $done && 0 === $skipped ) {
			return new WP_Error( 'hxmc_compress_failed', __( 'Compression failed for all files.', 'hxmc-smart-media-cleaner' ) );
		}

		// Refresh filesize in attachment metadata (WP 6.0+ stores it).
		if ( ! empty( $meta ) && $done > 0 ) {
			$main = $dir_abs . wp_basename( $file );
			if ( file_exists( $main ) ) {
				$meta['filesize'] = filesize( $main );
				wp_update_attachment_metadata( $attachment_id, $meta );
			}
		}

		$prev_meta   = get_post_meta( $attachment_id, self::META_KEY, true );
		$total_saved = $saved_bytes + ( is_array( $prev_meta ) ? (int) $prev_meta['saved_bytes'] : 0 );

		update_post_meta(
			$attachment_id,
			self::META_KEY,
			array(
				'saved_bytes'   => $total_saved,
				'quality'       => $quality,
				'files'         => $done,
				'skipped'       => $skipped,
				'compressed_at' => time(),
			)
		);

		/**
		 * Fires after in-place compression. HXMD bridge listens here.
		 */
		do_action( 'hxmc_after_compress', $attachment_id, $done, $skipped, $quality, $saved_bytes );

		return array(
			'files'       => $done,
			'skipped'     => $skipped,
			'saved_bytes' => $saved_bytes,
		);
	}

	/**
	 * Re-encode one file to a temp path; overwrite only if smaller.
	 *
	 * @return int|null Bytes saved, or null when skipped (kept original).
	 */
	private static function reencode_smaller( $src, $quality ) {
		$info = getimagesize( $src );
		if ( ! $info || ! in_array( $info['mime'], array( 'image/jpeg', 'image/png' ), true ) ) {
			return null;
		}

		$tmp = $src . '.hxmc-tmp';
		$ok  = false;

		if ( class_exists( 'Imagick' ) ) {
			try {
				$im = new Imagick( $src );
				if ( 'image/jpeg' === $info['mime'] ) {
					$im->setImageCompressionQuality( $quality );
					$im->stripImage(); // EXIF/metadata removal is part of the diet.
				} else {
					$im->setImageCompressionQuality( 95 ); // zlib level 9 / adaptive filtering; lossless.
				}
				$ok = $im->writeImage( $tmp );
				$im->clear();
			} catch ( Exception $e ) {
				$ok = false;
			}
		}

		if ( ! $ok ) {
			if ( 'image/jpeg' === $info['mime'] ) {
				$img = imagecreatefromjpeg( $src );
				if ( ! $img ) {
					return null;
				}
				$ok = imagejpeg( $img, $tmp, $quality );
				imagedestroy( $img );
			} else {
				$img = imagecreatefrompng( $src );
				if ( ! $img ) {
					return null;
				}
				imagealphablending( $img, false );
				imagesavealpha( $img, true );
				$ok = imagepng( $img, $tmp, 9 ); // lossless, max zlib.
				imagedestroy( $img );
			}
		}

		if ( ! $ok || ! file_exists( $tmp ) ) {
			if ( file_exists( $tmp ) ) {
				unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			return null;
		}

		$before = filesize( $src );
		$after  = filesize( $tmp );

		if ( $after >= $before ) {
			unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			return null; // Re-encode did not help; keep the original bytes.
		}

		// Containment re-check at the write site: $src must still resolve
		// inside the uploads directory before its bytes are replaced.
		if ( ! HXMC_Paths::is_inside_uploads( $src ) || ! rename( $tmp, $src ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			return null;
		}

		return $before - $after;
	}
}
