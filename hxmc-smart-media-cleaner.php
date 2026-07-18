<?php
/**
 * Plugin Name: HXMC — Smart Media Cleaner
 * Plugin URI: https://github.com/okuboyouhei/hxmc-smart-media-cleaner
 * Description: Code-first media library cleanup. Detect unused images, rename non-ASCII filenames safely, convert to WebP with built-in compression. No external services.
 * Version: 0.3.9
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: youheiokubo
 * Author URI: https://profiles.wordpress.org/youheiokubo/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hxmc-smart-media-cleaner
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HXMC_VERSION', '0.3.9' );
define( 'HXMC_DB_VERSION', '1' );
define( 'HXMC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HXMC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once HXMC_PLUGIN_DIR . 'includes/class-hxmc-db.php';
require_once HXMC_PLUGIN_DIR . 'includes/class-hxmc-htaccess.php';
require_once HXMC_PLUGIN_DIR . 'includes/class-hxmc-scanner.php';
require_once HXMC_PLUGIN_DIR . 'includes/class-hxmc-renamer.php';
require_once HXMC_PLUGIN_DIR . 'includes/class-hxmc-converter.php';
require_once HXMC_PLUGIN_DIR . 'includes/class-hxmc-compressor.php';
require_once HXMC_PLUGIN_DIR . 'includes/class-hxmc-replacer.php';
require_once HXMC_PLUGIN_DIR . 'includes/class-hxmc-admin.php';
require_once HXMC_PLUGIN_DIR . 'includes/class-hxmc-ajax.php';

register_activation_hook( __FILE__, 'hxmc_activate' );
function hxmc_activate() {
	HXMC_DB::install();
	if ( HXMC_Htaccess::is_apache_like() ) {
		HXMC_Htaccess::install();
	}
}

add_action( 'init', 'hxmc_load_textdomain' );
function hxmc_load_textdomain() {
	// Bundled ja translation ships from day one (this plugin's primary audience
	// deals with Japanese filenames). Once a wordpress.org language pack exists,
	// WP_LANG_DIR/plugins takes precedence automatically, so both coexist.
	load_plugin_textdomain( 'hxmc-smart-media-cleaner', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
}

add_action( 'plugins_loaded', array( 'HXMC_DB', 'maybe_upgrade' ) );

HXMC_Admin::init();
HXMC_Converter::init();
HXMC_Replacer::init();
HXMC_Ajax::init();

/**
 * Old-URL fallback redirect (302 only, same philosophy as HXSR).
 * Fires only on 404 so it costs nothing on normal requests.
 */
add_action( 'template_redirect', 'hxmc_maybe_redirect_old_url' );
function hxmc_maybe_redirect_old_url() {
	if ( ! is_404() ) {
		return;
	}
	$request_path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
	if ( '' === $request_path || false === strpos( $request_path, '/uploads/' ) ) {
		return;
	}
	$new_url = HXMC_DB::lookup_redirect( $request_path );
	if ( $new_url ) {
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- media may live on a different host in staging/production setups; URL comes from our own map table.
		wp_redirect( esc_url_raw( $new_url ), 302 );
		exit;
	}
}
