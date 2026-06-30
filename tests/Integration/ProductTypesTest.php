<?php

namespace TDS\ProductImporter\Tests\Integration;

use TDS\ProductImporter\Domain\Import\ProductWriter;
use TDS\ProductImporter\Infrastructure\Database;
use TDS\ProductImporter\Infrastructure\Installer;
use TDS\ProductImporter\Infrastructure\JobRepository;

final class ProductTypesTest extends \WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		Installer::activate();
	}

	public function test_snapshot_fingerprint_changes_with_product_data(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Integration Product' );
		$product->set_sku( 'TDS-I-1' );
		$id = $product->save();
		$before = ProductWriter::fingerprint( $id );
		$product->set_regular_price( '19.90' );
		$product->save();
		self::assertNotSame( $before, ProductWriter::fingerprint( $id ) );
	}

	public function test_native_product_types_can_be_persisted(): void {
		$products = array(
			new \WC_Product_Simple(),
			new \WC_Product_Variable(),
			new \WC_Product_Grouped(),
			new \WC_Product_External(),
		);
		foreach ( $products as $index => $product ) {
			$product->set_name( 'Type ' . $index );
			self::assertGreaterThan( 0, $product->save() );
		}
	}
}
