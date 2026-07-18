<?php
/**
 * HXMC Htaccess — serve originals to browsers without WebP support.
 *
 * Because HXMC rewrites URLs to .webp while keeping the originals on disk,
 * the fallback for non-supporting browsers is a pure web-server concern:
 * if the Accept header lacks image/webp and the sibling original exists,
 * rewrite the request back to it. Zero PHP at request time, zero visitor JS.
 *
 * Apache/LiteSpeed: rules written to uploads/.htaccess via insert_with_markers()
 * (IfModule-guarded, safe on servers without mod_rewrite/mod_headers).
 * Nginx: cannot be configured from a plugin — the admin page shows a
 * copy-paste snippet instead (honest scoping).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HXMC_Htaccess {

	const MARKER = 'HXMC WebP Fallback';

	public static function rules() {
		return array(
			'<IfModule mod_setenvif.c>',
			'SetEnvIf Request_URI "\.webp$" HXMC_IS_WEBP',
			'</IfModule>',
			'<IfModule mod_headers.c>',
			'Header append Vary Accept env=HXMC_IS_WEBP',
			'</IfModule>',
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
			'RewriteCond %{HTTP_ACCEPT} !image/webp [NC]',
			'RewriteCond %{REQUEST_FILENAME} ^(.*)\.webp$',
			'RewriteCond %1.jpg -f',
			'RewriteRule ^(.*)\.webp$ $1.jpg [T=image/jpeg,L]',
			'RewriteCond %{HTTP_ACCEPT} !image/webp [NC]',
			'RewriteCond %{REQUEST_FILENAME} ^(.*)\.webp$',
			'RewriteCond %1.jpeg -f',
			'RewriteRule ^(.*)\.webp$ $1.jpeg [T=image/jpeg,L]',
			'RewriteCond %{HTTP_ACCEPT} !image/webp [NC]',
			'RewriteCond %{REQUEST_FILENAME} ^(.*)\.webp$',
			'RewriteCond %1.png -f',
			'RewriteRule ^(.*)\.webp$ $1.png [T=image/png,L]',
			'RewriteCond %{HTTP_ACCEPT} !image/webp [NC]',
			'RewriteCond %{REQUEST_FILENAME} ^(.*)\.webp$',
			'RewriteCond %1.gif -f',
			'RewriteRule ^(.*)\.webp$ $1.gif [T=image/gif,L]',
			'</IfModule>',
		);
	}

	public static function htaccess_path() {
		$uploads = wp_get_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . '.htaccess';
	}

	/**
	 * Apache/LiteSpeed only; other servers ignore .htaccess entirely.
	 */
	public static function is_apache_like() {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
		return ( false !== strpos( $software, 'apache' ) || false !== strpos( $software, 'litespeed' ) );
	}

	/**
	 * Write (or refresh) the marker block. Returns true on success.
	 */
	public static function install() {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		return insert_with_markers( self::htaccess_path(), self::MARKER, self::rules() );
	}

	public static function remove() {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		if ( file_exists( self::htaccess_path() ) ) {
			insert_with_markers( self::htaccess_path(), self::MARKER, array() );
		}
	}

	public static function is_installed() {
		$path = self::htaccess_path();
		if ( ! file_exists( $path ) ) {
			return false;
		}
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return false !== strpos( (string) $contents, '# BEGIN ' . self::MARKER );
	}

	/**
	 * Equivalent snippet for Nginx users to paste into their server block.
	 */
	public static function nginx_snippet() {
		return implode(
			"\n",
			array(
				'# HXMC WebP fallback — serve originals to browsers without WebP support.',
				'# http {} block:',
				'map $http_accept $hxmc_webp_ok {',
				'    default 0;',
				'    "~image/webp" 1;',
				'}',
				'# server {} block:',
				'location ~* ^(?<hxmc_base>.+)\.webp$ {',
				'    add_header Vary Accept;',
				'    error_page 418 = @hxmc_fallback;',
				'    if ($hxmc_webp_ok = 0) { return 418; }',
				'}',
				'location @hxmc_fallback {',
				'    try_files $hxmc_base.jpg $hxmc_base.jpeg $hxmc_base.png $hxmc_base.gif =404;',
				'}',
			)
		);
	}
}
