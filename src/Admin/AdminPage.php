<?php
/**
 * WordPress admin application.
 *
 * @package TDS\ProductImporter
 */

namespace TDS\ProductImporter\Admin;

/**
 * Registers and assets the React-based WooCommerce submenu.
 */
final class AdminPage {
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'TDS Product Importer', 'tds-product-importer' ),
			__( 'TDS Import', 'tds-product-importer' ),
			'manage_woocommerce',
			'tds-product-importer',
			array( $this, 'render' )
		);
	}

	public function render(): void {
		echo '<div class="wrap"><div id="tds-importer-admin">';
		echo '<p>' . esc_html__( 'Loading TDS Product Importer…', 'tds-product-importer' ) . '</p>';
		echo '</div></div>';
	}

	public function assets( string $hook ): void {
		if ( 'woocommerce_page_tds-product-importer' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'tds-importer-admin',
			TDS_IMPORTER_URL . 'assets/admin.css',
			array( 'wp-components' ),
			TDS_IMPORTER_VERSION
		);
		wp_enqueue_script(
			'tds-importer-admin',
			TDS_IMPORTER_URL . 'assets/admin.js',
			array( 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n' ),
			TDS_IMPORTER_VERSION,
			true
		);
		wp_add_inline_script(
			'tds-importer-admin',
			'window.tdsImporter=' . wp_json_encode(
				array(
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'root'    => esc_url_raw( rest_url( 'tds-import/v1' ) ),
					'version' => TDS_IMPORTER_VERSION,
				)
			) . ';',
			'before'
		);
	}
}
