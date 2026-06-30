<?php

namespace TDS\ProductImporter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TDS\ProductImporter\Domain\Parsing\CsvParser;

final class CsvParserTest extends TestCase {
	private string $path;

	protected function tearDown(): void {
		if ( isset( $this->path ) && file_exists( $this->path ) ) {
			unlink( $this->path );
		}
	}

	public function test_detects_delimiter_and_handles_multiline_fields(): void {
		$this->path = tempnam( sys_get_temp_dir(), 'tds-csv-' );
		file_put_contents( $this->path, "sku;name;description\nA-1;Widget;\"Line one\nLine two\"\nA-2;Other;Text\n" );
		$rows = iterator_to_array( ( new CsvParser() )->records( $this->path, array( 'csv' => array( 'delimiter' => '', 'encoding' => 'auto' ) ) ) );
		self::assertCount( 2, $rows );
		self::assertSame( "Line one\nLine two", $rows[1]['description'] );
		self::assertSame( 'A-2', $rows[2]['sku'] );
	}

	public function test_duplicate_headers_are_made_unique(): void {
		$this->path = tempnam( sys_get_temp_dir(), 'tds-csv-' );
		file_put_contents( $this->path, "sku,price,price\nA-1,10,12\n" );
		$rows = iterator_to_array( ( new CsvParser() )->records( $this->path, array( 'csv' => array( 'delimiter' => ',', 'encoding' => 'UTF-8' ) ) ) );
		self::assertSame( '12', $rows[1]['price_2'] );
	}
}

