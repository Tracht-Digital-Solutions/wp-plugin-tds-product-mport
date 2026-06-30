<?php

namespace TDS\ProductImporter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TDS\ProductImporter\Domain\Expression\Evaluator;
use TDS\ProductImporter\Domain\Import\Mapper;

final class MapperTest extends TestCase {
	public function test_empty_value_policies_and_expressions(): void {
		$mapper = new Mapper( new Evaluator() );
		$config = array(
			'identity' => 'sku',
			'mappings' => array(
				array( 'target' => 'sku', 'source' => 'id', 'expression' => '', 'empty' => 'keep' ),
				array( 'target' => 'name', 'source' => 'title', 'expression' => 'trim($title)', 'empty' => 'keep' ),
				array( 'target' => 'description', 'source' => 'description', 'expression' => '', 'empty' => 'clear' ),
				array( 'target' => 'stock_status', 'source' => 'state', 'expression' => '', 'empty' => 'default', 'default' => 'outofstock' ),
			),
		);
		$result = $mapper->map( array( 'id' => 'A-1', 'title' => ' Widget ', 'description' => '', 'state' => '' ), $config );
		self::assertSame(
			array( 'sku' => 'A-1', 'name' => 'Widget', 'description' => '', 'stock_status' => 'outofstock' ),
			$result
		);
		self::assertSame( array(), $mapper->validate( $config ) );
	}

	public function test_validation_detects_duplicate_targets(): void {
		$mapper = new Mapper( new Evaluator() );
		$errors = $mapper->validate(
			array(
				'identity' => 'sku',
				'mappings' => array(
					array( 'target' => 'sku' ),
					array( 'target' => 'name' ),
					array( 'target' => 'name' ),
				),
			)
		);
		self::assertNotEmpty( $errors );
	}
}

