<?php
/**
 * HXMC Renamer — rename attachment files to ASCII-safe names.
 *
 * Design decisions:
 * - Default suggestion is `img-{ID}` (subtraction: no kanji→romaji dictionary,
 *   no guessing). User can type a custom slug; validated as [a-z0-9-_].
 * - Renames the original file AND every registered intermediate size on disk.
 * - Rewrites URLs in post_content and postmeta (path-based replace, so every
 *   size variant is covered in one pass).
 * - Registers old→new redirects in the HXMC map (302 fallback insurance).
 * - GUID is left untouched (WordPress convention: GUIDs never change).
 * - Fires `hxmc_after_rename` for the HXMD bridge.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HXMC_Renamer {

	/**
	 * @param int    $attachment_id Attachment to rename.
	 * @param string $new_slug      New basename without extension (ascii slug).
	 * @return array|WP_Error       ['old_url' => ..., 'new_url' => ...]
	 */
	public static function rename( $attachment_id, $new_slug ) {
		$attachment_id = (int) $attachment_id;
		$new_slug      = strtolower( trim( (string) $new_slug ) );

		if ( ! preg_match( '/^[a-z0-9][a-z0-9\-_]{0,80}$/', $new_slug ) ) {
			return new WP_Error( 'hxmc_bad_slug', __( 'Use lowercase letters, numbers, hyphens and underscores only.', 'hxmc-smart-media-cleaner' ) );
		}

		$file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! $file ) {
			return new WP_Error( 'hxmc_no_file', __( 'Attachment file not found.', 'hxmc-smart-media-cleaner' ) );
		}
		if ( ! HXMC_Paths::is_valid_relative( $file ) ) {
			return HXMC_Paths::error();
		}

		$uploads  = wp_get_upload_dir();
		$old_path = trailingslashit( $uploads['basedir'] ) . $file;
		if ( ! file_exists( $old_path ) ) {
			return new WP_Error( 'hxmc_missing', __( 'File does not exist on disk.', 'hxmc-smart-media-cleaner' ) );
		}
		if ( ! HXMC_Paths::is_inside_uploads( $old_path ) ) {
			return HXMC_Paths::error();
		}

		$dir_rel  = dirname( $file );
		$dir_rel  = ( '.' === $dir_rel ) ? '' : trailingslashit( $dir_rel );
		$ext      = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		$old_base = wp_basename( $file, '.' . $ext );

		// Ensure uniqueness within the directory.
		$dir_abs   = trailingslashit( $uploads['basedir'] ) . $dir_rel;
		$candidate = $new_slug;
		$n         = 1;
		while ( file_exists( $dir_abs . $candidate . '.' . $ext ) ) {
			$n++;
			$candidate = $new_slug . '-' . $n;
			if ( $n > 100 ) {
				return new WP_Error( 'hxmc_no_name', __( 'Could not find a free filename.', 'hxmc-smart-media-cleaner' ) );
			}
		}
		$new_slug = $candidate;
		$new_file = $dir_rel . $new_slug . '.' . $ext;
		$new_path = trailingslashit( $uploads['basedir'] ) . $new_file;

		if ( ! HXMC_Paths::is_safe_target( $new_path ) ) {
			return HXMC_Paths::error();
		}

		// 1. Rename main file.
		if ( ! rename( $old_path, $new_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			return new WP_Error( 'hxmc_rename_failed', __( 'Filesystem rename failed.', 'hxmc-smart-media-cleaner' ) );
		}

		// 2. Rename every intermediate size + collect old/new pairs for rewrite.
		$meta  = wp_get_attachment_metadata( $attachment_id );
		$pairs = array(
			array( $dir_rel . $old_base . '.' . $ext, $new_file ),
		);
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size => $info ) {
				if ( empty( $info['file'] ) ) {
					continue;
				}
				$old_size_base = $info['file'];
				if ( ! HXMC_Paths::is_valid_size_basename( $old_size_base ) ) {
					continue;
				}
				$suffix        = self::size_suffix( $old_size_base, $old_base );
				if ( null === $suffix ) {
					continue;
				}
				$size_ext = pathinfo( $old_size_base, PATHINFO_EXTENSION );
				$new_size = $new_slug . $suffix . '.' . $size_ext;
				$old_abs  = $dir_abs . $old_size_base;
				$new_abs  = $dir_abs . $new_size;
				if ( file_exists( $old_abs ) && HXMC_Paths::is_inside_uploads( $old_abs ) && HXMC_Paths::is_safe_target( $new_abs ) ) {
					rename( $old_abs, $new_abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				}
				$meta['sizes'][ $size ]['file'] = $new_size;
				$pairs[]                        = array( $dir_rel . $old_size_base, $dir_rel . $new_size );
			}
		}

		// 3. Update attachment records.
		update_post_meta( $attachment_id, '_wp_attached_file', $new_file );
		if ( ! empty( $meta ) ) {
			$meta['file'] = $new_file;
			wp_update_attachment_metadata( $attachment_id, $meta );
		}
		wp_update_post(
			array(
				'ID'        => $attachment_id,
				'post_name' => sanitize_title( $new_slug ),
			)
		);

		// 4. Rewrite references + register redirects.
		$base_url = trailingslashit( $uploads['baseurl'] );
		$old_url  = $base_url . $file;
		$new_url  = $base_url . $new_file;
		foreach ( $pairs as $pair ) {
			self::rewrite_everywhere( $base_url . $pair[0], $base_url . $pair[1] );
			HXMC_DB::add_redirect( wp_parse_url( $base_url . $pair[0], PHP_URL_PATH ), $base_url . $pair[1], $attachment_id );
			// Encoded variant of the same path (browsers request Japanese names percent-encoded either way; map stores decoded).
		}

		/**
		 * Fires after a successful rename. HXMD bridge listens here.
		 *
		 * @param int    $attachment_id
		 * @param string $old_url
		 * @param string $new_url
		 * @param array  $pairs old/new relative path pairs (all sizes)
		 */
		do_action( 'hxmc_after_rename', $attachment_id, $old_url, $new_url, $pairs );

		return array(
			'old_url' => $old_url,
			'new_url' => $new_url,
			'slug'    => $new_slug,
		);
	}

	/**
	 * Extract "-300x200" style suffix from a size filename.
	 */
	private static function size_suffix( $size_file, $old_base ) {
		$size_no_ext = preg_replace( '/\.[^.]+$/', '', $size_file );
		if ( 0 !== strpos( $size_no_ext, $old_base ) ) {
			return null;
		}
		return substr( $size_no_ext, strlen( $old_base ) ); // e.g. "-300x200" or "-scaled"
	}

	/**
	 * Replace a URL in post_content and postmeta (plain-string values only;
	 * serialized data is intentionally left to the 302 fallback — replacing
	 * inside serialized strings corrupts length prefixes).
	 */
	public static function rewrite_everywhere( $old_url, $new_url ) {
		global $wpdb;

		// Collect affected post IDs BEFORE updating so caches can be
		// invalidated afterwards (matters with persistent object caches).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s",
				'%' . $wpdb->esc_like( $old_url ) . '%'
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_value LIKE %s AND meta_value NOT LIKE %s",
				'%' . $wpdb->esc_like( $old_url ) . '%',
				$wpdb->esc_like( 'a:' ) . '%'
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
				$old_url,
				$new_url,
				'%' . $wpdb->esc_like( $old_url ) . '%'
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s)
				 WHERE meta_value LIKE %s AND meta_value NOT LIKE %s",
				$old_url,
				$new_url,
				'%' . $wpdb->esc_like( $old_url ) . '%',
				$wpdb->esc_like( 'a:' ) . '%' // skip serialized arrays
			)
		);

		foreach ( array_unique( array_merge( $post_ids, $meta_post_ids ) ) as $pid ) {
			clean_post_cache( (int) $pid );
			wp_cache_delete( (int) $pid, 'post_meta' );
		}
	}
}
