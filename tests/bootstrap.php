<?php
/**
 * PHPUnit bootstrap.
 */

$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( is_readable( $autoload ) ) {
	require_once $autoload;
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = 'TDS\\ProductImporter\\';
			if ( str_starts_with( $class, $prefix ) ) {
				$file = dirname( __DIR__ ) . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		}
	);
}

