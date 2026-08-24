<?php
/**
 * Source structure detection tests.
 *
 * @package TDS\ProductImporter\Tests
 */

namespace TDS\ProductImporter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TDS\ProductImporter\Domain\Parsing\ParserFactory;

final class ParserFactoryTest extends TestCase {
	/** @var string[] */
	private array $files = array();

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			@unlink( $file );
		}
	}

	public function test_detects_csv_delimiter_and_encoding(): void {
		$file      = $this->temporary( "sku;name;price\nA-1;Produkt;12,50\n", 'csv' );
		$structure = ( new ParserFactory() )->detect_structure( $file, array( 'format' => 'auto' ) );
		self::assertSame( ';', $structure['delimiter'] );
		self::assertSame( 'UTF-8', $structure['encoding'] );
	}

	public function test_detects_xml_record_path(): void {
		$file      = $this->temporary( '<catalog><product><sku>A-1</sku></product></catalog>', 'xml' );
		$structure = ( new ParserFactory() )->detect_structure( $file, array( 'format' => 'auto' ) );
		self::assertSame( '/catalog/product', $structure['record_path'] );
	}

	public function test_preview_never_reads_more_than_twenty_records(): void {
		$rows = array( 'sku,name' );
		for ( $index = 1; $index <= 30; ++$index ) {
			$rows[] = "SKU-$index,Product $index";
		}
		$file    = $this->temporary( implode( "\n", $rows ) . "\n", 'csv' );
		$preview = ( new ParserFactory() )->preview( $file, array( 'format' => 'csv' ), 1000 );

		self::assertCount( 20, $preview );
		self::assertSame( 'SKU-20', $preview[19]['sku'] );
	}

	private function temporary( string $contents, string $extension ): string {
		$base = tempnam( sys_get_temp_dir(), 'tds-' );
		$file = $base . '.' . $extension;
		rename( $base, $file );
		file_put_contents( $file, $contents );
		$this->files[] = $file;
		return $file;
	}
}
