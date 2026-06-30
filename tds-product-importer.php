<?php
/**
 * Plugin Name:       TDS Product Importer for WooCommerce
 * Plugin URI:        https://github.com/Tracht-Digital-Solutions/WP-Plugin-TDS-Product-Import
 * Description:       Resumable high-volume CSV and XML product imports for WooCommerce.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * WC requires at least: 8.2
 * Author:            Julian Tracht von Tracht Digital Solutions
 * Author URI:        https://github.com/Tracht-Digital-Solutions
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tds-product-importer
 * Domain Path:       /languages
 *
 * @package TDS\ProductImporter
 */

defined( 'ABSPATH' ) || exit;

define( 'TDS_IMPORTER_VERSION', '1.0.0' );
define( 'TDS_IMPORTER_FILE', __FILE__ );
define( 'TDS_IMPORTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'TDS_IMPORTER_URL', plugin_dir_url( __FILE__ ) );

$tds_importer_composer = TDS_IMPORTER_DIR . 'vendor/autoload.php';
if ( is_readable( $tds_importer_composer ) ) {
	require_once $tds_importer_composer;
} else {
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'TDS\\ProductImporter\\';
			if ( ! str_starts_with( $class_name, $prefix ) ) {
				return;
			}
			$relative = str_replace( '\\', DIRECTORY_SEPARATOR, substr( $class_name, strlen( $prefix ) ) );
			$file     = TDS_IMPORTER_DIR . 'src/' . $relative . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

register_activation_hook( __FILE__, array( TDS\ProductImporter\Infrastructure\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( TDS\ProductImporter\Infrastructure\Installer::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		TDS\ProductImporter\Plugin::instance()->boot();
	}
);
