<?php
/**
 * HXMC DB layer — old-URL redirect map.
 *
 * Lessons applied (CLAUDE.md):
 * - dbDelta: `PRIMARY KEY  (id)` needs two spaces, no DEFAULT CURRENT_TIMESTAMP on DATETIME.
 * - Table name via %i placeholder (WP 6.2+).
 * - Schema auto-upgrade via hxmc_db_version option.
 * - suppress_errors() around expected-failure queries (duplicate keys) so AJAX JSON survives WP_DEBUG_DISPLAY.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HXMC_DB {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'hxmc_url_map';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			old_path VARCHAR(2048) NOT NULL,
			old_path_hash CHAR(32) NOT NULL,
			new_url VARCHAR(2048) NOT NULL,
			attachment_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY old_path_hash (old_path_hash)
		) {$charset};";

		dbDelta( $sql );
		update_option( 'hxmc_db_version', HXMC_DB_VERSION );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'hxmc_db_version' ) !== HXMC_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Register (or update) a redirect from an old uploads path to a new URL.
	 * $old_path is the URL path only (e.g. /wp-content/uploads/2026/07/foo.jpg)
	 * so the map survives staging/production host differences.
	 */
	public static function add_redirect( $old_path, $new_url, $attachment_id = 0 ) {
		global $wpdb;
		$old_path = self::normalize_path( $old_path );
		$hash     = md5( $old_path );

		$prev = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (old_path, old_path_hash, new_url, attachment_id, created_at) VALUES (%s, %s, %s, %d, %s)
				 ON DUPLICATE KEY UPDATE new_url = VALUES(new_url), attachment_id = VALUES(attachment_id)',
				self::table(),
				$old_path,
				$hash,
				esc_url_raw( $new_url ),
				$attachment_id,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		$wpdb->suppress_errors( $prev );
	}

	public static function lookup_redirect( $path ) {
		global $wpdb;
		$path = self::normalize_path( $path );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT new_url FROM %i WHERE old_path_hash = %s LIMIT 1',
				self::table(),
				md5( $path )
			)
		);
		return $row ? $row : null;
	}

	public static function count_redirects() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) );
	}

	private static function normalize_path( $path ) {
		$path = wp_parse_url( $path, PHP_URL_PATH );
		return rawurldecode( (string) $path );
	}
}
