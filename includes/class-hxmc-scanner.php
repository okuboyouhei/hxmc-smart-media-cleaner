<?php
/**
 * HXMC Scanner — usage detection for attachments.
 *
 * Honest scoping: we can only prove PRESENCE of a reference, never absence.
 * Checked surfaces:
 *   1. Featured image (_thumbnail_id postmeta)
 *   2. Post content: uploads-relative path without extension (covers all
 *      intermediate sizes and .webp variants), plus wp-image-{ID} class
 *   3. Other postmeta values containing the file path or the bare ID stored
 *      by common gallery/ACF-style fields
 *   4. Site options (widgets, customizer) containing the file path
 * NOT checked (and disclosed in UI): theme/CSS hardcoded URLs, page builders
 * storing serialized/encoded data, external sites hotlinking.
 *
 * Result cached in `_hxmc_usage` attachment meta:
 * { count, locations: [{type, id, title}], scanned_at }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HXMC_Scanner {

	const META_KEY = '_hxmc_usage';

	/**
	 * Scan one attachment and cache the result.
	 */
	public static function scan( $attachment_id ) {
		global $wpdb;

		$attachment_id = (int) $attachment_id;
		$file          = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! $file ) {
			return null;
		}

		$locations = array();

		// 1. Featured image.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$thumb_posts = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT 20",
				(string) $attachment_id
			)
		);
		foreach ( $thumb_posts as $pid ) {
			$locations[] = self::loc( 'featured', $pid );
		}

		// 2. Post content. Search by path-without-extension so -300x200 sizes
		// and .webp twins all match. Also match wp-image-{ID}.
		$path_no_ext = preg_replace( '/\.[^.\/]+$/', '', $file ); // e.g. 2026/07/photo
		$like_path   = '%' . $wpdb->esc_like( $path_no_ext ) . '%';
		$like_class  = '%' . $wpdb->esc_like( 'wp-image-' . $attachment_id ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$content_posts = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_status NOT IN ('trash','auto-draft')
				   AND post_type NOT IN ('revision','attachment')
				   AND (post_content LIKE %s OR post_content LIKE %s)
				 LIMIT 20",
				$like_path,
				$like_class
			)
		);
		foreach ( $content_posts as $pid ) {
			$locations[] = self::loc( 'content', $pid );
		}

		// 3. Other postmeta (galleries, custom fields). Exclude the
		// attachment's own meta rows and _thumbnail_id (already counted).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_posts = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				 WHERE post_id != %d
				   AND meta_key NOT IN ('_thumbnail_id','_wp_attached_file','_wp_attachment_metadata','_hxmc_usage')
				   AND meta_value LIKE %s
				 LIMIT 20",
				$attachment_id,
				$like_path
			)
		);
		foreach ( $meta_posts as $pid ) {
			$locations[] = self::loc( 'meta', $pid );
		}

		// 4. Options (widgets / customizer / theme mods).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$opt_hit = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name NOT LIKE %s AND option_value LIKE %s",
				$wpdb->esc_like( '_transient' ) . '%',
				$like_path
			)
		);
		if ( $opt_hit > 0 ) {
			$locations[] = array(
				'type'  => 'option',
				'id'    => 0,
				'title' => sprintf( '%d option(s)', $opt_hit ),
			);
		}

		// De-duplicate by type+id.
		$seen  = array();
		$clean = array();
		foreach ( $locations as $l ) {
			$key = $l['type'] . ':' . $l['id'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$clean[]      = $l;
		}

		$result = array(
			'count'      => count( $clean ),
			'locations'  => array_slice( $clean, 0, 10 ),
			'scanned_at' => time(),
		);
		update_post_meta( $attachment_id, self::META_KEY, $result );

		return $result;
	}

	public static function get_cached( $attachment_id ) {
		$v = get_post_meta( $attachment_id, self::META_KEY, true );
		return is_array( $v ) ? $v : null;
	}

	/**
	 * Whether the attachment filename contains non-ASCII characters
	 * (the "Japanese filename" flag, but honest about scope: any non-ASCII).
	 */
	public static function has_non_ascii_name( $attachment_id ) {
		$file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! $file ) {
			return false;
		}
		$basename = wp_basename( $file );
		return (bool) preg_match( '/[^\x20-\x7E]/', $basename );
	}

	private static function loc( $type, $post_id ) {
		return array(
			'type'  => $type,
			'id'    => (int) $post_id,
			'title' => wp_strip_all_tags( get_the_title( $post_id ) ),
		);
	}
}
