<?php
/**
 * HXMC Admin — Media submenu page, Alpine.js UI.
 *
 * Lessons applied:
 * - Alpine registered as DEPENDENT on hxmc-admin.js (plugin JS must define
 *   components before Alpine initializes), both defer.
 * - esc_attr( wp_json_encode() ) for HTML-attribute JSON (never esc_js).
 * - No inline styles: everything is .hxmc-* classes.
 * - No WP-reserved query params; ours are hxmc_-prefixed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HXMC_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function menu() {
		add_media_page(
			__( 'Smart Media Cleaner', 'hxmc-smart-media-cleaner' ),
			__( 'Media Cleaner', 'hxmc-smart-media-cleaner' ),
			'manage_options',
			'hxmc',
			array( __CLASS__, 'render' )
		);
	}

	public static function register_settings() {
		register_setting(
			'hxmc',
			'hxmc_webp_quality',
			array(
				'type'              => 'integer',
				'default'           => 82,
				'sanitize_callback' => function ( $v ) {
					return max( 1, min( 100, (int) $v ) );
				},
			)
		);
	}

	public static function assets( $hook ) {
		if ( 'media_page_hxmc' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'hxmc-admin', HXMC_PLUGIN_URL . 'assets/css/hxmc-admin.css', array(), HXMC_VERSION );

		wp_register_script(
			'hxmc-admin',
			HXMC_PLUGIN_URL . 'assets/js/hxmc-admin.js',
			array(),
			HXMC_VERSION,
			array( 'strategy' => 'defer' )
		);
		wp_localize_script(
			'hxmc-admin',
			'hxmcData',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'hxmc_admin' ),
				'quality'     => (int) get_option( 'hxmc_webp_quality', 82 ),
				'webpEngine'  => HXMC_Converter::supported() ? HXMC_Converter::supported() : '',
				'i18n'        => array(
					'confirmRename'  => __( 'Rename this file? URLs in content will be rewritten and a 302 fallback will be registered.', 'hxmc-smart-media-cleaner' ),
					'confirmConvert' => __( 'Generate WebP for this image? Originals are kept.', 'hxmc-smart-media-cleaner' ),
					'confirmReplace' => __( 'Replace this file with the selected one? The filename and URLs stay the same; thumbnails are regenerated, WebP twins are reset, and references get a fresh ?v= cache-busting parameter.', 'hxmc-smart-media-cleaner' ),
					'confirmCompress' => __( 'Compress this image in place? The original file is OVERWRITTEN (lossy for JPEG, irreversible). Files are only overwritten when the result is smaller.', 'hxmc-smart-media-cleaner' ),
				),
			)
		);
		wp_enqueue_script( 'hxmc-admin' );

		wp_enqueue_script(
			'hxmc-alpine',
			HXMC_PLUGIN_URL . 'assets/js/alpine.min.js',
			array( 'hxmc-admin' ), // Alpine depends on our JS, never the reverse.
			'3.15.12',
			array( 'strategy' => 'defer' )
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$engine = HXMC_Converter::supported();
		?>
		<div class="wrap hxmc-wrap" x-data="hxmcApp" x-cloak>
			<h1><?php esc_html_e( 'Smart Media Cleaner', 'hxmc-smart-media-cleaner' ); ?></h1>

			<p class="hxmc-honesty">
				<?php esc_html_e( 'Usage detection covers: featured images, post content, custom fields, widgets/options. It cannot see theme/CSS hardcoded URLs or external sites. "No reference found" is a hint, not proof of non-use. This version never deletes anything.', 'hxmc-smart-media-cleaner' ); ?>
			</p>

			<?php if ( HXMC_Htaccess::is_apache_like() ) : ?>
				<?php if ( HXMC_Htaccess::is_installed() ) : ?>
					<p class="hxmc-fallback-ok"><?php esc_html_e( 'WebP fallback for non-supporting browsers is active (uploads/.htaccess).', 'hxmc-smart-media-cleaner' ); ?></p>
				<?php else : ?>
					<div class="notice notice-warning"><p><?php esc_html_e( 'WebP fallback rules could not be written to uploads/.htaccess. Check file permissions, or deactivate and reactivate the plugin.', 'hxmc-smart-media-cleaner' ); ?></p></div>
				<?php endif; ?>
			<?php else : ?>
				<details class="hxmc-nginx">
					<summary><?php esc_html_e( 'Non-Apache server detected: add the WebP fallback to your server config manually', 'hxmc-smart-media-cleaner' ); ?></summary>
					<pre><?php echo esc_html( HXMC_Htaccess::nginx_snippet() ); ?></pre>
				</details>
			<?php endif; ?>

			<?php if ( ! $engine ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'WebP conversion is unavailable: this server has neither GD with WebP support nor Imagick.', 'hxmc-smart-media-cleaner' ); ?></p></div>
			<?php endif; ?>

			<div class="hxmc-toolbar">
				<button type="button" class="button button-primary" x-on:click="scanAll()" x-bind:disabled="scanning">
					<span x-show="!scanning"><?php esc_html_e( 'Scan usage', 'hxmc-smart-media-cleaner' ); ?></span>
					<span x-show="scanning" x-text="scanProgressLabel"></span>
				</button>
				<select x-model="filter" x-on:change="load(1)">
					<option value=""><?php esc_html_e( 'All images', 'hxmc-smart-media-cleaner' ); ?></option>
					<option value="unused"><?php esc_html_e( 'No reference found', 'hxmc-smart-media-cleaner' ); ?></option>
					<option value="nonascii"><?php esc_html_e( 'Non-ASCII filename', 'hxmc-smart-media-cleaner' ); ?></option>
					<option value="nowebp"><?php esc_html_e( 'No WebP yet', 'hxmc-smart-media-cleaner' ); ?></option>
				</select>
				<input type="search" x-model.debounce.300ms="search" x-on:input="load(1)" placeholder="<?php esc_attr_e( 'Search filename…', 'hxmc-smart-media-cleaner' ); ?>" />
				<span class="hxmc-quality">
					<?php esc_html_e( 'WebP quality', 'hxmc-smart-media-cleaner' ); ?>
					<input type="number" min="1" max="100" x-model.number="quality" />
				</span>
			</div>

			<div class="hxmc-error" x-show="error" x-text="error" x-ref="errorBox"></div>

			<table class="widefat striped hxmc-table">
				<thead>
					<tr>
						<th class="hxmc-col-thumb"></th>
						<th><?php esc_html_e( 'File', 'hxmc-smart-media-cleaner' ); ?></th>
						<th><?php esc_html_e( 'Usage', 'hxmc-smart-media-cleaner' ); ?></th>
						<th><?php esc_html_e( 'Optimization', 'hxmc-smart-media-cleaner' ); ?></th>
						<th><?php esc_html_e( 'Size', 'hxmc-smart-media-cleaner' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'hxmc-smart-media-cleaner' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<template x-for="item in items" x-bind:key="item.id">
						<tr>
							<td class="hxmc-col-thumb"><a x-bind:href="item.edit_url" target="_blank"><img x-bind:src="item.thumb" alt="" /></a></td>
							<td>
								<a class="hxmc-filename" x-bind:href="item.edit_url" target="_blank" x-text="item.filename" title="<?php esc_attr_e( 'Open in media edit screen', 'hxmc-smart-media-cleaner' ); ?>"></a>
								<span class="hxmc-badge hxmc-badge-jp" x-show="item.non_ascii"><?php esc_html_e( 'non-ASCII', 'hxmc-smart-media-cleaner' ); ?></span>
								<div class="hxmc-rename" x-show="renameId === item.id">
									<input type="text" x-model="renameSlug" placeholder="img-123" />
									<button type="button" class="button" x-on:click="doRename(item)" x-bind:disabled="busy"><?php esc_html_e( 'Apply', 'hxmc-smart-media-cleaner' ); ?></button>
									<button type="button" class="button-link" x-on:click="renameId = 0"><?php esc_html_e( 'Cancel', 'hxmc-smart-media-cleaner' ); ?></button>
								</div>
							</td>
							<td>
								<span x-show="item.usage === null" class="hxmc-muted"><?php esc_html_e( 'Not scanned', 'hxmc-smart-media-cleaner' ); ?></span>
								<span x-show="item.usage !== null && item.usage_count > 0" class="hxmc-badge hxmc-badge-used" x-text="item.usage_label"></span>
								<span x-show="item.usage !== null && item.usage_count === 0" class="hxmc-badge hxmc-badge-unused"><?php esc_html_e( 'No reference found', 'hxmc-smart-media-cleaner' ); ?></span>
							</td>
							<td>
								<span x-show="item.webp" class="hxmc-badge hxmc-badge-webp" x-text="item.webp_label"></span>
								<span x-show="item.compressed" class="hxmc-badge hxmc-badge-comp" x-text="item.comp_label"></span>
								<span x-show="item.replaced" class="hxmc-badge hxmc-badge-repl" x-text="item.repl_label"></span>
								<span x-show="!item.webp && !item.compressed && item.convertible" class="hxmc-muted">—</span>
								<span x-show="!item.convertible && !item.compressible" class="hxmc-muted"><?php esc_html_e( 'n/a', 'hxmc-smart-media-cleaner' ); ?></span>
							</td>
							<td x-text="item.size_label"></td>
							<td class="hxmc-actions">
								<button type="button" class="button" x-on:click="scanOne(item)" x-bind:disabled="busy"><?php esc_html_e( 'Scan', 'hxmc-smart-media-cleaner' ); ?></button>
								<button type="button" class="button" x-show="item.non_ascii" x-on:click="openRename(item)" x-bind:disabled="busy"><?php esc_html_e( 'Rename', 'hxmc-smart-media-cleaner' ); ?></button>
								<?php if ( $engine ) : ?>
								<button type="button" class="button" x-show="item.convertible && !item.webp" x-on:click="doConvert(item)" x-bind:disabled="busy"><?php esc_html_e( 'WebP', 'hxmc-smart-media-cleaner' ); ?></button>
								<?php endif; ?>
								<button type="button" class="button" x-show="item.compressible && !item.compressed" x-on:click="doCompress(item)" x-bind:disabled="busy"><?php esc_html_e( 'Compress', 'hxmc-smart-media-cleaner' ); ?></button>
								<button type="button" class="button" x-on:click="pickReplace(item)" x-bind:disabled="busy"><?php esc_html_e( 'Replace', 'hxmc-smart-media-cleaner' ); ?></button>
							</td>
						</tr>
					</template>
					<tr x-show="!loading && items.length === 0">
						<td colspan="6" class="hxmc-muted"><?php esc_html_e( 'No images match.', 'hxmc-smart-media-cleaner' ); ?></td>
					</tr>
				</tbody>
			</table>

			<input type="file" class="hxmc-hidden-file" x-ref="replaceFile" accept="image/*" x-on:change="doReplace()" />

			<div class="hxmc-pager">
				<button type="button" class="button" x-on:click="load(page - 1)" x-bind:disabled="page <= 1"><?php esc_html_e( 'Prev', 'hxmc-smart-media-cleaner' ); ?></button>
				<span x-text="pageLabel"></span>
				<button type="button" class="button" x-on:click="load(page + 1)" x-bind:disabled="page >= totalPages"><?php esc_html_e( 'Next', 'hxmc-smart-media-cleaner' ); ?></button>
			</div>
		</div>
		<?php
	}
}
