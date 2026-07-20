<?php
/**
 * HXMC Paths — uploads-directory containment for every file operation.
 *
 * Attachment metadata (_wp_attached_file, sizes[]) is stored in the database
 * and must be treated as untrusted: a tampered value like "../../evil.jpg"
 * would otherwise let rename/copy/unlink escape the uploads directory.
 *
 * Two layers, both required:
 * 1. validate_file() (core) on the relative value — rejects "../" and
 *    absolute paths before any path is built.
 * 2. realpath() containment on the final absolute path — canonicalizes
 *    symlinks and verifies the result stays under uploads basedir.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HXMC_Paths {

	/**
	 * Canonical uploads base dir with trailing slash, or null when unresolvable.
	 */
	public static function basedir() {
		$uploads = wp_get_upload_dir();
		$real    = realpath( $uploads['basedir'] );
		if ( false === $real ) {
			return null;
		}
		return trailingslashit( wp_normalize_path( $real ) );
	}

	/**
	 * Validate a DB-sourced relative path (e.g. _wp_attached_file value).
	 */
	public static function is_valid_relative( $rel ) {
		return is_string( $rel ) && '' !== $rel && 0 === validate_file( $rel );
	}

	/**
	 * Validate a size-entry filename from attachment metadata: must be a bare
	 * basename (no directories, no traversal).
	 */
	public static function is_valid_size_basename( $name ) {
		return is_string( $name ) && '' !== $name
			&& wp_basename( $name ) === $name
			&& 0 === validate_file( $name );
	}

	/**
	 * An EXISTING path resolves inside uploads.
	 */
	public static function is_inside_uploads( $path ) {
		$base = self::basedir();
		$real = realpath( $path );
		if ( null === $base || false === $real ) {
			return false;
		}
		$real = wp_normalize_path( $real );
		return 0 === strpos( trailingslashit( $real ), $base ) || 0 === strpos( $real . '/', $base );
	}

	/**
	 * A WRITE TARGET (may not exist yet) lands inside uploads: its parent
	 * directory must resolve inside uploads and its basename must be clean.
	 */
	public static function is_safe_target( $path ) {
		$base   = self::basedir();
		$parent = realpath( dirname( $path ) );
		if ( null === $base || false === $parent ) {
			return false;
		}
		$parent = trailingslashit( wp_normalize_path( $parent ) );
		if ( 0 !== strpos( $parent, $base ) ) {
			return false;
		}
		$name = basename( $path );
		return '' !== $name && wp_basename( $name ) === $name && 0 === validate_file( $name );
	}

	/**
	 * Shared WP_Error for containment failures.
	 */
	public static function error() {
		return new WP_Error( 'hxmc_path_outside_uploads', __( 'Refused: the file path does not resolve inside the uploads directory.', 'hxmc-smart-media-cleaner' ) );
	}
}
