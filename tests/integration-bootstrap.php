<?php
/**
 * Bootstrap used inside a WordPress PHPUnit test installation.
 */

$project_dir = dirname( __DIR__ );
require_once $project_dir . '/vendor/autoload.php';

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $project_dir . '/vendor/yoast/phpunit-polyfills' );
}

$tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';
require_once $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		$woocommerce = getenv( 'WC_PLUGIN_FILE' ) ?: WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		require $woocommerce;
		require dirname( __DIR__ ) . '/tds-product-importer.php';
	}
);

require $tests_dir . '/includes/bootstrap.php';
