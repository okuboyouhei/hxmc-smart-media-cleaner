<?php
/**
 * HXMC uninstall — remove table, options, and attachment meta.
 * Generated .webp files and renamed files are left in place (they are the
 * user's media; deleting them on uninstall would break live sites).
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove the WebP fallback block from uploads/.htaccess.
$hxmc_uploads = wp_get_upload_dir();
$hxmc_ht      = trailingslashit( $hxmc_uploads['basedir'] ) . '.htaccess';
if ( file_exists( $hxmc_ht ) ) {
	if ( ! function_exists( 'insert_with_markers' ) ) {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
	}
	insert_with_markers( $hxmc_ht, 'HXMC WebP Fallback', array() );
}

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'hxmc_url_map' ) );

delete_option( 'hxmc_db_version' );
delete_option( 'hxmc_webp_quality' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_hxmc_usage', '_hxmc_webp', '_hxmc_compressed', '_hxmc_replaced')" );
