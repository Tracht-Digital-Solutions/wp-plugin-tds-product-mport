<?php

namespace TDS\ProductImporter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TDS\ProductImporter\Domain\Parsing\CsvParser;

final class ScaleParserTest extends TestCase {
	public function test_streams_twenty_five_thousand_records_with_bounded_memory(): void {
		$path   = tempnam( sys_get_temp_dir(), 'tds-scale-' );
		$handle = fopen( $path, 'wb' );
		fwrite( $handle, "sku,name,price\n" );
		for ( $i = 1; $i <= 25000; ++$i ) {
			fwrite( $handle, "SKU-$i,Product $i," . ( $i / 100 ) . "\n" );
		}
		fclose( $handle );

		$before = memory_get_usage( true );
		$count  = 0;
		foreach ( ( new CsvParser() )->records( $path, array( 'csv' => array( 'delimiter' => ',', 'encoding' => 'UTF-8' ) ) ) as $row ) {
			++$count;
			if ( 25000 === $count ) {
				self::assertSame( 'SKU-25000', $row['sku'] );
			}
		}
		$growth = memory_get_usage( true ) - $before;
		unlink( $path );

		self::assertSame( 25000, $count );
		self::assertLessThan( 12 * 1024 * 1024, $growth );
	}
}

