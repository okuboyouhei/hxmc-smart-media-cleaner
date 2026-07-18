<?php
/**
 * HXMC AJAX — list / scan / rename / convert endpoints.
 * All endpoints: manage_options + nonce. Admin-only plugin, no public AJAX.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HXMC_Ajax {

	public static function init() {
		add_action( 'wp_ajax_hxmc_list', array( __CLASS__, 'list_items' ) );
		add_action( 'wp_ajax_hxmc_scan', array( __CLASS__, 'scan' ) );
		add_action( 'wp_ajax_hxmc_scan_ids', array( __CLASS__, 'scan_ids' ) );
		add_action( 'wp_ajax_hxmc_rename', array( __CLASS__, 'rename' ) );
		add_action( 'wp_ajax_hxmc_convert', array( __CLASS__, 'convert' ) );
		add_action( 'wp_ajax_hxmc_compress', array( __CLASS__, 'compress' ) );
		add_action( 'wp_ajax_hxmc_replace', array( __CLASS__, 'replace_file' ) );
		add_action( 'wp_ajax_hxmc_quality', array( __CLASS__, 'quality' ) );
	}

	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'hxmc_admin', 'nonce' );
	}

	public static function list_items() {
		self::guard();

		$page   = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified via check_ajax_referer() in self::guard() above.
		$filter = isset( $_POST['filter'] ) ? sanitize_key( $_POST['filter'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified via check_ajax_referer() in self::guard() above.
		$search = isset( $_POST['hxmc_s'] ) ? sanitize_text_field( wp_unslash( $_POST['hxmc_s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified via check_ajax_referer() in self::guard() above.
		$per    = 30;

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => $per,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		);
		if ( '' !== $search ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_wp_attached_file',
					'value'   => $search,
					'compare' => 'LIKE',
				),
			);
		}
		if ( 'unused' === $filter ) {
			$args['meta_query'][] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'key'     => HXMC_Scanner::META_KEY,
				'value'   => '"count";i:0',
				'compare' => 'LIKE',
			);
		} elseif ( 'nowebp' === $filter ) {
			$args['meta_query'][] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'key'     => HXMC_Converter::META_KEY,
				'compare' => 'NOT EXISTS',
			);
		}

		$q     = new WP_Query( $args );
		$ids   = $q->posts;
		$items = array();
		foreach ( $ids as $id ) {
			$item = self::item( $id );
			if ( 'nonascii' === $filter && ! $item['non_ascii'] ) {
				continue;
			}
			$items[] = $item;
		}

		wp_send_json_success(
			array(
				'items'       => $items,
				'page'        => $page,
				'total_pages' => 'nonascii' === $filter ? $page : max( 1, (int) $q->max_num_pages ),
				'total'       => (int) $q->found_posts,
			)
		);
	}

	private static function item( $id ) {
		$file   = get_post_meta( $id, '_wp_attached_file', true );
		$usage  = HXMC_Scanner::get_cached( $id );
		$webp   = get_post_meta( $id, HXMC_Converter::META_KEY, true );
		$comp   = get_post_meta( $id, HXMC_Compressor::META_KEY, true );
		$repl   = get_post_meta( $id, HXMC_Replacer::META_KEY, true );
		$path   = get_attached_file( $id );
		$size   = ( $path && file_exists( $path ) ) ? filesize( $path ) : 0;
		$thumb  = wp_get_attachment_image_url( $id, 'thumbnail' );
		if ( $thumb && is_array( $repl ) && ! empty( $repl['version'] ) ) {
			$thumb = add_query_arg( 'v', (int) $repl['version'], $thumb );
		}

		return array(
			'id'          => (int) $id,
			'edit_url'    => get_edit_post_link( $id, 'raw' ),
			'filename'    => wp_basename( (string) $file ),
			'thumb'       => $thumb ? $thumb : '',
			'non_ascii'   => HXMC_Scanner::has_non_ascii_name( $id ),
			'usage'       => $usage ? $usage : null,
			'usage_count' => $usage ? (int) $usage['count'] : 0,
			'usage_label' => $usage ? sprintf(
				/* translators: %d: number of references */
				_n( '%d reference', '%d references', (int) $usage['count'], 'hxmc-smart-media-cleaner' ),
				(int) $usage['count']
			) : '',
			'webp'        => (bool) $webp,
			'webp_label'  => $webp ? sprintf(
				/* translators: %s: bytes saved */
				__( 'WebP (−%s)', 'hxmc-smart-media-cleaner' ),
				size_format( max( 0, (int) $webp['saved_bytes'] ) )
			) : '',
			'convertible' => HXMC_Converter::is_convertible( $id ),
			'compressible' => HXMC_Compressor::is_compressible( $id ),
			'compressed'  => (bool) $comp,
			'replaced'    => (bool) $repl,
			'repl_label'  => $repl ? sprintf(
				/* translators: %s: date */
				__( 'Replaced %s', 'hxmc-smart-media-cleaner' ),
				wp_date( get_option( 'date_format' ), (int) $repl['replaced_at'] )
			) : '',
			'comp_label'  => $comp ? sprintf(
				/* translators: %s: bytes saved */
				__( 'Compressed (−%s)', 'hxmc-smart-media-cleaner' ),
				size_format( max( 0, (int) $comp['saved_bytes'] ) )
			) : '',
			'size_label'  => $size ? size_format( $size ) : '—',
		);
	}

	/**
	 * Returns the full ID list for a batch scan (client drives the loop).
	 */
	public static function scan_ids() {
		self::guard();
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		wp_send_json_success( array( 'ids' => array_map( 'intval', $ids ) ) );
	}

	public static function scan() {
		self::guard();
		$ids = isset( $_POST['ids'] ) ? array_map( 'intval', (array) $_POST['ids'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified via check_ajax_referer() in self::guard() above.
		$ids = array_slice( $ids, 0, 10 ); // batch cap
		$out = array();
		foreach ( $ids as $id ) {
			HXMC_Scanner::scan( $id );
			$out[ $id ] = self::item( $id );
		}
		wp_send_json_success( array( 'items' => $out ) );
	}

	public static function rename() {
		self::guard();
		$id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified via check_ajax_referer() in self::guard() above.
		$slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified via check_ajax_referer() in self::guard() above.
		$res  = HXMC_Renamer::rename( $id, $slug );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'item' => self::item( $id ), 'result' => $res ) );
	}

	public static function convert() {
		self::guard();
		$id      = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified via check_ajax_referer() in self::guard() above.
		$quality = isset( $_POST['quality'] ) ? (int) $_POST['quality'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified via check_ajax_referer() in self::guard() above.
		$res     = HXMC_Converter::convert( $id, $quality );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'item' => self::item( $id ), 'result' => $res ) );
	}

	public static function compress() {
		self::guard();
		$id      = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified via check_ajax_referer() in self::guard() above.
		$quality = isset( $_POST['quality'] ) ? (int) $_POST['quality'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified via check_ajax_referer() in self::guard() above.
		$res     = HXMC_Compressor::compress( $id, $quality );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'item' => self::item( $id ), 'result' => $res ) );
	}

	public static function replace_file() {
		self::guard();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified via check_ajax_referer() in self::guard() above.
		// tmp_name is generated by PHP itself, not user input; is_uploaded_file()
		// is the authoritative validation for it.
		$tmp = isset( $_FILES['file']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['file']['tmp_name'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- PHP-generated path validated by is_uploaded_file() below; nonce verified in self::guard() above.
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			wp_send_json_error( array( 'message' => __( 'No file was uploaded.', 'hxmc-smart-media-cleaner' ) ) );
		}
		$res = HXMC_Replacer::replace( $id, $tmp );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'item' => self::item( $id ), 'result' => $res ) );
	}

	public static function quality() {
		self::guard();
		$q = isset( $_POST['quality'] ) ? max( 1, min( 100, (int) $_POST['quality'] ) ) : 82; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified via check_ajax_referer() in self::guard() above.
		update_option( 'hxmc_webp_quality', $q );
		wp_send_json_success( array( 'quality' => $q ) );
	}
}
