<?php

namespace TDS\ProductImporter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TDS\ProductImporter\Domain\Parsing\XmlParser;

final class XmlParserTest extends TestCase {
	private string $path;

	protected function tearDown(): void {
		if ( isset( $this->path ) && file_exists( $this->path ) ) {
			unlink( $this->path );
		}
	}

	public function test_streams_selected_record_path_and_retains_nested_variations(): void {
		$this->path = tempnam( sys_get_temp_dir(), 'tds-xml-' );
		file_put_contents(
			$this->path,
			'<?xml version="1.0"?><catalog><product id="1"><sku>P-1</sku><name>Parent</name><variations><variation><sku>V-1</sku><color>red</color></variation><variation><sku>V-2</sku><color>blue</color></variation></variations></product><product id="2"><sku>P-2</sku><name>Simple</name></product></catalog>'
		);
		$rows = iterator_to_array( ( new XmlParser() )->records( $this->path, array( 'xml' => array( 'record_path' => '/catalog/product' ) ) ) );
		self::assertCount( 2, $rows );
		self::assertSame( '1', $rows[1]['@id'] );
		self::assertSame( 'V-2', $rows[1]['variations.variation'][1]['sku'] );
		self::assertSame( 'Simple', $rows[2]['name'] );
	}
}

