<?php
/**
 * HXMC Converter — WebP generation with built-in compression.
 *
 * Design decisions:
 * - Compression IS the WebP quality parameter (default 82). One knob,
 *   not two features (subtraction).
 * - Originals are kept on disk (insurance; old URLs keep working even
 *   without the redirect map).
 * - Generates .webp twins for the original and every intermediate size,
 *   rewrites references, records the redirect map anyway (covers the case
 *   where originals are manually deleted later).
 * - GD or Imagick, whichever is available. No external services.
 * - Skips images that are already WebP/AVIF/SVG, and animated GIFs
 *   (GD flattens animation — honest scoping: we refuse rather than break).
 * - Fires `hxmc_after_convert` for the HXMD bridge.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HXMC_Converter {

	const META_KEY = '_hxmc_webp';

	/**
	 * Serve WebP twins for metadata-driven output (featured images, theme
	 * templates via wp_get_attachment_image, render-time srcset). Stored
	 * content is rewritten at conversion time; these filters cover every URL
	 * WordPress generates dynamically from attachment metadata, which still
	 * points at the originals (twins are intentionally not registered there).
	 * Only URLs whose generated twin actually exists are swapped, so partial
	 * conversions degrade gracefully. Priority 9: runs before HXMC_Replacer's
	 * ?v= filters (10) so the version lands on the final URL.
	 */
	public static function init() {
		// Order matters: srcset must be computed by core from the ORIGINAL
		// filenames (a .webp src makes wp_calculate_image_srcset bail before
		// its filter fires, killing responsive images), so the src swap
		// happens at the final-HTML stage instead of wp_get_attachment_image_src.
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'filter_srcset' ), 9, 5 );
		add_filter( 'wp_get_attachment_image', array( __CLASS__, 'filter_attachment_image_html' ), 9, 2 );
		add_filter( 'wp_content_img_tag', array( __CLASS__, 'filter_content_img_tag' ), 9, 3 );
	}

	/**
	 * Final <img> HTML from wp_get_attachment_image() / the_post_thumbnail().
	 */
	public static function filter_attachment_image_html( $html, $attachment_id ) {
		return self::swap_urls_in_html( $html, $attachment_id );
	}

	/**
	 * Content <img> tags at render time (wp_filter_content_tags).
	 */
	public static function filter_content_img_tag( $filtered_image, $context, $attachment_id ) {
		if ( ! $attachment_id ) {
			return $filtered_image;
		}
		return self::swap_urls_in_html( $filtered_image, $attachment_id );
	}

	/**
	 * Swap every original-file URL in an HTML fragment to its WebP twin
	 * (only where the twin was actually generated).
	 */
	public static function swap_urls_in_html( $html, $attachment_id ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		$webp_meta = get_post_meta( (int) $attachment_id, self::META_KEY, true );
		if ( ! is_array( $webp_meta ) || empty( $webp_meta['files'] ) ) {
			return $html;
		}
		return preg_replace_callback(
			'#[^\s"\x27>]+\.(?:jpe?g|png|gif)#i',
			function ( $m ) use ( $attachment_id ) {
				$swapped = self::to_webp_url( $m[0], $attachment_id );
				return $swapped ? $swapped : $m[0];
			},
			$html
		);
	}

	public static function filter_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		if ( ! is_array( $sources ) ) {
			return $sources;
		}
		foreach ( $sources as $k => $source ) {
			if ( empty( $source['url'] ) ) {
				continue;
			}
			$swapped = self::to_webp_url( $source['url'], $attachment_id );
			if ( $swapped ) {
				$sources[ $k ]['url'] = $swapped;
			}
		}
		return $sources;
	}

	/**
	 * Map a generated original-file URL to its WebP twin, or null when no
	 * twin was generated for that exact file.
	 */
	public static function to_webp_url( $url, $attachment_id ) {
		$webp_meta = get_post_meta( (int) $attachment_id, self::META_KEY, true );
		if ( ! is_array( $webp_meta ) || empty( $webp_meta['files'] ) ) {
			return null;
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! preg_match( '/\.(jpe?g|png|gif)$/i', (string) $path ) ) {
			return null;
		}
		$candidate = preg_replace( '/\.(jpe?g|png|gif)$/i', '.webp', wp_basename( (string) $path ) );
		foreach ( $webp_meta['files'] as $rel ) {
			if ( wp_basename( $rel ) === $candidate ) {
				return preg_replace( '/\.(jpe?g|png|gif)$/i', '.webp', $url );
			}
		}
		return null;
	}

	public static function supported() {
		if ( class_exists( 'Imagick' ) ) {
			$formats = Imagick::queryFormats( 'WEBP' );
			if ( ! empty( $formats ) ) {
				return 'imagick';
			}
		}
		if ( function_exists( 'imagewebp' ) ) {
			return 'gd';
		}
		return false;
	}

	public static function is_convertible( $attachment_id ) {
		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif' ), true ) ) {
			return false;
		}
		if ( 'image/gif' === $mime ) {
			$path = get_attached_file( $attachment_id );
			if ( $path && self::is_animated_gif( $path ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @return array|WP_Error ['files' => n, 'saved_bytes' => n, 'new_url' => ...]
	 */
	public static function convert( $attachment_id, $quality = null ) {
		$engine = self::supported();
		if ( ! $engine ) {
			return new WP_Error( 'hxmc_no_engine', __( 'Neither GD (with WebP) nor Imagick is available on this server.', 'hxmc-smart-media-cleaner' ) );
		}
		if ( ! self::is_convertible( $attachment_id ) ) {
			return new WP_Error( 'hxmc_not_convertible', __( 'This file type cannot be converted (already WebP, SVG, or animated GIF).', 'hxmc-smart-media-cleaner' ) );
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

		// Collect original + all sizes.
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

		$generated   = array();
		$saved_bytes = 0;
		$base_url    = trailingslashit( $uploads['baseurl'] );

		foreach ( $targets as $basename ) {
			if ( ! HXMC_Paths::is_valid_size_basename( $basename ) && wp_basename( $file ) !== $basename ) {
				continue;
			}
			$src = $dir_abs . $basename;
			if ( ! file_exists( $src ) || ! HXMC_Paths::is_inside_uploads( $src ) ) {
				continue;
			}
			$webp_basename = preg_replace( '/\.[^.]+$/', '', $basename ) . '.webp';
			$dst           = $dir_abs . $webp_basename;
			if ( ! HXMC_Paths::is_safe_target( $dst ) ) {
				continue;
			}

			$ok = self::encode( $src, $dst, $quality, $engine );
			if ( ! $ok ) {
				continue;
			}
			$saved_bytes += max( 0, filesize( $src ) - filesize( $dst ) );
			$generated[] = $dir_rel . $webp_basename;

			$old_url = $base_url . $dir_rel . $basename;
			$new_url = $base_url . $dir_rel . $webp_basename;
			HXMC_Renamer::rewrite_everywhere( $old_url, $new_url );
			HXMC_DB::add_redirect( wp_parse_url( $old_url, PHP_URL_PATH ), $new_url, $attachment_id );
		}

		if ( empty( $generated ) ) {
			return new WP_Error( 'hxmc_encode_failed', __( 'WebP encoding failed for all files.', 'hxmc-smart-media-cleaner' ) );
		}

		update_post_meta(
			$attachment_id,
			self::META_KEY,
			array(
				'files'        => $generated,
				'quality'      => $quality,
				'saved_bytes'  => $saved_bytes,
				'converted_at' => time(),
			)
		);

		$new_main = $base_url . $dir_rel . preg_replace( '/\.[^.]+$/', '', wp_basename( $file ) ) . '.webp';

		/**
		 * Fires after a successful WebP conversion. HXMD bridge listens here.
		 */
		do_action( 'hxmc_after_convert', $attachment_id, $generated, $quality, $saved_bytes );

		return array(
			'files'       => count( $generated ),
			'saved_bytes' => $saved_bytes,
			'new_url'     => $new_main,
		);
	}

	private static function encode( $src, $dst, $quality, $engine ) {
		if ( 'imagick' === $engine ) {
			try {
				$im = new Imagick( $src );
				$im->setImageFormat( 'webp' );
				$im->setImageCompressionQuality( $quality );
				$ok = $im->writeImage( $dst );
				$im->clear();
				return (bool) $ok;
			} catch ( Exception $e ) {
				return false;
			}
		}

		$info = getimagesize( $src );
		if ( ! $info ) {
			return false;
		}
		switch ( $info['mime'] ) {
			case 'image/jpeg':
				$img = imagecreatefromjpeg( $src );
				break;
			case 'image/png':
				$img = imagecreatefrompng( $src );
				if ( $img ) {
					imagepalettetotruecolor( $img ); // lesson: work on truecolor
					imagealphablending( $img, true );
					imagesavealpha( $img, true );
				}
				break;
			case 'image/gif':
				$img = imagecreatefromgif( $src );
				if ( $img ) {
					imagepalettetotruecolor( $img );
				}
				break;
			default:
				return false;
		}
		if ( ! $img ) {
			return false;
		}
		$ok = imagewebp( $img, $dst, $quality );
		imagedestroy( $img );
		return (bool) $ok;
	}

	private static function is_animated_gif( $path ) {
		$contents = file_get_contents( $path, false, null, 0, 512 * 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents ) {
			return false;
		}
		return preg_match_all( '/\x00\x21\xF9\x04/', $contents ) > 1;
	}
}
