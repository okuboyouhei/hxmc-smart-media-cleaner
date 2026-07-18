<?php
/**
 * HXMC Replacer — replace an attachment's file, keeping the filename.
 *
 * Design decisions:
 * - The existing filename is kept no matter what the uploaded file is called:
 *   URLs never change (that is the point).
 * - Same MIME type only. Keeping the filename while changing the format would
 *   create an extension/content mismatch — refused honestly.
 * - Old intermediate sizes and old WebP twins are deleted before regeneration
 *   (no orphan files with stale dimensions).
 * - WebP reset: content URLs pointing at deleted .webp files are rewritten
 *   back to the original extension, and a 302 map entry (.webp → original)
 *   catches stragglers (serialized data etc.).
 * - Cache busting without renaming: references get `?v={timestamp}` appended
 *   (updated on subsequent replaces). Version stored in `_hxmc_replaced`.
 * - Fires `hxmc_after_replace` for the HXMD bridge.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HXMC_Replacer {

	const META_KEY = '_hxmc_replaced';

	/**
	 * Register cache-bust filters so WP-generated URLs for replaced
	 * attachments carry ?v= too (media edit screen, library grid, modal,
	 * render-time srcset). Admin-only for url/image_src because some
	 * front-end code derives file paths from those URLs; srcset output is
	 * HTML-only and safe to version everywhere.
	 */
	public static function init() {
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'filter_url' ), 10, 2 );
		add_filter( 'wp_get_attachment_image_src', array( __CLASS__, 'filter_image_src' ), 10, 2 );
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'filter_srcset' ), 10, 5 );
	}

	public static function version_of( $attachment_id ) {
		$repl = get_post_meta( (int) $attachment_id, self::META_KEY, true );
		return ( is_array( $repl ) && ! empty( $repl['version'] ) ) ? (int) $repl['version'] : 0;
	}

	public static function filter_url( $url, $attachment_id ) {
		if ( ! is_admin() ) {
			return $url;
		}
		$v = self::version_of( $attachment_id );
		return $v ? add_query_arg( 'v', $v, $url ) : $url;
	}

	public static function filter_image_src( $image, $attachment_id ) {
		if ( ! is_admin() || ! is_array( $image ) || empty( $image[0] ) ) {
			return $image;
		}
		$v = self::version_of( $attachment_id );
		if ( $v ) {
			$image[0] = add_query_arg( 'v', $v, $image[0] );
		}
		return $image;
	}

	public static function filter_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		$v = self::version_of( $attachment_id );
		if ( ! $v || ! is_array( $sources ) ) {
			return $sources;
		}
		foreach ( $sources as $k => $source ) {
			if ( ! empty( $source['url'] ) ) {
				$sources[ $k ]['url'] = add_query_arg( 'v', $v, $source['url'] );
			}
		}
		return $sources;
	}

	/**
	 * @param int    $attachment_id Attachment to replace.
	 * @param string $tmp_path      Uploaded temp file path.
	 * @return array|WP_Error
	 */
	public static function replace( $attachment_id, $tmp_path ) {
		$attachment_id = (int) $attachment_id;
		$file          = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! $file ) {
			return new WP_Error( 'hxmc_no_file', __( 'Attachment file not found.', 'hxmc-smart-media-cleaner' ) );
		}

		$uploads  = wp_get_upload_dir();
		$dir_rel  = dirname( $file );
		$dir_rel  = ( '.' === $dir_rel ) ? '' : trailingslashit( $dir_rel );
		$dir_abs  = trailingslashit( $uploads['basedir'] ) . $dir_rel;
		$main_abs = trailingslashit( $uploads['basedir'] ) . $file;
		$base_url = trailingslashit( $uploads['baseurl'] );

		// MIME must match the existing attachment.
		$existing_mime = get_post_mime_type( $attachment_id );
		$new_info      = getimagesize( $tmp_path );
		if ( ! $new_info || $new_info['mime'] !== $existing_mime ) {
			return new WP_Error(
				'hxmc_mime_mismatch',
				sprintf(
					/* translators: %s: required mime type */
					__( 'The uploaded file must be the same type as the existing one (%s), because the filename is kept.', 'hxmc-smart-media-cleaner' ),
					$existing_mime
				)
			);
		}

		// Refuse images over WP's big-image threshold (default 2560px):
		// core would generate a -scaled file and change _wp_attached_file,
		// breaking the keep-the-filename invariant. Refused honestly instead.
		$threshold = (int) apply_filters( 'big_image_size_threshold', 2560, array( $new_info[0], $new_info[1] ), '', $attachment_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- reading WordPress core's own filter (not defining a hook), to pre-check the same threshold core applies in wp_create_image_subsizes().
		if ( $threshold && ( $new_info[0] > $threshold || $new_info[1] > $threshold ) ) {
			return new WP_Error(
				'hxmc_too_large',
				sprintf(
					/* translators: %d: max pixels */
					__( 'The uploaded image exceeds %dpx and WordPress would rename it to a -scaled file, breaking the kept URLs. Please resize it below the threshold first.', 'hxmc-smart-media-cleaner' ),
					$threshold
				)
			);
		}

		$old_meta  = wp_get_attachment_metadata( $attachment_id );
		$old_sizes = ( ! empty( $old_meta['sizes'] ) && is_array( $old_meta['sizes'] ) ) ? $old_meta['sizes'] : array();
		$prev      = get_post_meta( $attachment_id, self::META_KEY, true );
		$prev_v    = ( is_array( $prev ) && ! empty( $prev['version'] ) ) ? (int) $prev['version'] : 0;
		$version   = max( time(), $prev_v + 1 ); // never collide with the previous version, even within the same second

		// 1. WebP reset (before size cleanup, while old file names are known).
		$webp_meta = get_post_meta( $attachment_id, HXMC_Converter::META_KEY, true );
		if ( is_array( $webp_meta ) && ! empty( $webp_meta['files'] ) ) {
			foreach ( $webp_meta['files'] as $webp_rel ) {
				$webp_abs = trailingslashit( $uploads['basedir'] ) . $webp_rel;
				$orig_rel = self::webp_to_original_rel( $webp_rel, $file, $old_sizes, $dir_rel );
				if ( $orig_rel ) {
					// Rewrite content .webp → original ext, register stragglers redirect.
					HXMC_Renamer::rewrite_everywhere( $base_url . $webp_rel, $base_url . $orig_rel );
					HXMC_DB::add_redirect( wp_parse_url( $base_url . $webp_rel, PHP_URL_PATH ), $base_url . $orig_rel, $attachment_id );
				}
				if ( file_exists( $webp_abs ) ) {
					unlink( $webp_abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			}
			delete_post_meta( $attachment_id, HXMC_Converter::META_KEY );
		}

		// 2. Delete old intermediate size files.
		foreach ( $old_sizes as $info ) {
			if ( ! empty( $info['file'] ) && file_exists( $dir_abs . $info['file'] ) ) {
				unlink( $dir_abs . $info['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}

		// 3. Overwrite the main file (same filename).
		if ( ! @copy( $tmp_path, $main_abs ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_copy
			return new WP_Error( 'hxmc_write_failed', __( 'Could not overwrite the existing file.', 'hxmc-smart-media-cleaner' ) );
		}
		@unlink( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink

		// 4. Regenerate metadata + sizes from the new file.
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$new_meta = wp_generate_attachment_metadata( $attachment_id, $main_abs );
		wp_update_attachment_metadata( $attachment_id, $new_meta );
		$new_sizes = ( ! empty( $new_meta['sizes'] ) && is_array( $new_meta['sizes'] ) ) ? $new_meta['sizes'] : array();

		// 5. Compression meta reset (the new file is untouched).
		delete_post_meta( $attachment_id, HXMC_Compressor::META_KEY );

		// 6. Cache busting + stale size-URL healing.
		// 6a. Main file: url[?v=prev] → url?v=new
		self::bust( $base_url . $file, $prev_v, $version );
		// 6b. Sizes: for each old size, rewrite to the matching new size (same
		// size key) or to the main file if the size no longer exists.
		foreach ( $old_sizes as $size_key => $info ) {
			if ( empty( $info['file'] ) ) {
				continue;
			}
			$old_url = $base_url . $dir_rel . $info['file'];
			if ( isset( $new_sizes[ $size_key ]['file'] ) ) {
				$new_url = $base_url . $dir_rel . $new_sizes[ $size_key ]['file'];
			} else {
				$new_url = $base_url . $file;
			}
			if ( $old_url === $new_url ) {
				self::bust( $old_url, $prev_v, $version );
			} else {
				// Filename changed (different dimensions): rewrite + redirect.
				HXMC_Renamer::rewrite_everywhere( $old_url . ( $prev_v ? '?v=' . $prev_v : '' ), $new_url . '?v=' . $version );
				if ( $prev_v ) {
					HXMC_Renamer::rewrite_everywhere( $old_url, $new_url . '?v=' . $version );
				}
				HXMC_DB::add_redirect( wp_parse_url( $old_url, PHP_URL_PATH ), $new_url, $attachment_id );
			}
		}
		// 6c. New sizes that did not exist before need no rewrite (nothing references them yet).

		update_post_meta(
			$attachment_id,
			self::META_KEY,
			array(
				'version'     => $version,
				'replaced_at' => $version,
				'count'       => ( is_array( $prev ) && ! empty( $prev['count'] ) ) ? (int) $prev['count'] + 1 : 1,
			)
		);

		/**
		 * Fires after a successful in-place replacement. HXMD bridge listens here.
		 *
		 * @param int   $attachment_id
		 * @param int   $version   Cache-bust version (timestamp).
		 * @param array $new_meta  Regenerated attachment metadata.
		 */
		do_action( 'hxmc_after_replace', $attachment_id, $version, $new_meta );

		return array(
			'version' => $version,
			'url'     => $base_url . $file . '?v=' . $version,
		);
	}

	/**
	 * url[?v=prev] → url?v=new in posts/postmeta.
	 */
	private static function bust( $url, $prev_v, $version ) {
		$sentinel = '{{HXMC-V-SENTINEL}}';
		if ( $prev_v ) {
			HXMC_Renamer::rewrite_everywhere( $url . '?v=' . $prev_v, $url . '?v=' . $version );
		}
		// MySQL REPLACE has no lookahead, so protect remaining versioned refs
		// with a sentinel before versioning bare refs, then restore.
		HXMC_Renamer::rewrite_everywhere( $url . '?v=', $sentinel );
		HXMC_Renamer::rewrite_everywhere( $url, $url . '?v=' . $version );
		HXMC_Renamer::rewrite_everywhere( $sentinel, $url . '?v=' );
	}

	/**
	 * Map a generated webp relative path back to its original file.
	 */
	private static function webp_to_original_rel( $webp_rel, $main_file, $old_sizes, $dir_rel ) {
		$webp_base = preg_replace( '/\.webp$/', '', wp_basename( $webp_rel ) );
		$main_base = preg_replace( '/\.[^.]+$/', '', wp_basename( $main_file ) );
		if ( $webp_base === $main_base ) {
			return $main_file;
		}
		foreach ( $old_sizes as $info ) {
			if ( empty( $info['file'] ) ) {
				continue;
			}
			if ( preg_replace( '/\.[^.]+$/', '', $info['file'] ) === $webp_base ) {
				return $dir_rel . $info['file'];
			}
		}
		return null;
	}
}
