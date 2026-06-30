<?php

namespace TDS\ProductImporter\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TDS\ProductImporter\Domain\Expression\Evaluator;

final class EvaluatorTest extends TestCase {
	private Evaluator $evaluator;

	protected function setUp(): void {
		$this->evaluator = new Evaluator();
	}

	public function test_evaluates_mapping_functions_and_fields_with_spaces(): void {
		$record = array( 'Brand Name' => ' TDS ', 'price' => '1.234,50', 'stock' => 2 );
		self::assertSame( 'TDS PRODUCT', $this->evaluator->evaluate( 'concat(upper(trim([Brand Name])), " PRODUCT")', $record ) );
		self::assertSame( 1234.5, $this->evaluator->evaluate( 'number($price, ",", ".")', $record ) );
		self::assertSame( 'instock', $this->evaluator->evaluate( 'if($stock > 0, "instock", "outofstock")', $record ) );
	}

	public function test_ast_round_trip_matches_text_result(): void {
		$expression = 'coalesce(trim($empty), lower($name), "fallback")';
		$record     = array( 'empty' => ' ', 'name' => 'Widget' );
		$ast        = $this->evaluator->parse( $expression );
		self::assertSame( 'widget', $this->evaluator->evaluate_ast( $ast, $record ) );
	}

	public function test_rejects_unknown_functions_and_division_by_zero(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->evaluator->evaluate( 'system("whoami")', array() );
	}

	public function test_arithmetic_tokenization_without_spaces(): void {
		self::assertSame( 5.0, $this->evaluator->evaluate( '10-2*3+1', array() ) );
	}
}

