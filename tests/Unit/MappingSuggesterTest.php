<?php
/**
 * Mapping suggestion tests.
 *
 * @package TDS\ProductImporter\Tests
 */

namespace TDS\ProductImporter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TDS\ProductImporter\Domain\Import\MappingSuggester;

final class MappingSuggesterTest extends TestCase {
	public function test_suggests_german_and_english_aliases(): void {
		$suggestions = ( new MappingSuggester() )->suggest(
			array( 'Produktname', 'Artikelnummer', 'regular_price', 'Lagerbestand', 'Bild URL', 'Kategorien' )
		);
		$pairs       = array_column( $suggestions, 'target', 'source' );

		self::assertSame( 'name', $pairs['Produktname'] );
		self::assertSame( 'sku', $pairs['Artikelnummer'] );
		self::assertSame( 'regular_price', $pairs['regular_price'] );
		self::assertSame( 'stock_quantity', $pairs['Lagerbestand'] );
		self::assertSame( 'image', $pairs['Bild URL'] );
		self::assertSame( 'categories', $pairs['Kategorien'] );
	}

	public function test_does_not_duplicate_existing_targets_or_sources(): void {
		$suggestions = ( new MappingSuggester() )->suggest(
			array( 'name', 'title', 'SKU' ),
			array(
				array(
					'source' => 'name',
					'target' => 'name',
				),
			)
		);
		self::assertSame( array( 'sku' ), array_column( $suggestions, 'target' ) );
	}

	public function test_normalizes_umlauts_without_wordpress_runtime(): void {
		self::assertSame( 'verfugbarkeit grosse', MappingSuggester::normalize( ' Verfügbarkeit / GROSSE ' ) );
	}
}
