<?php
/**
 * Mapping engine.
 *
 * @package TDS\ProductImporter
 */

namespace TDS\ProductImporter\Domain\Import;

use TDS\ProductImporter\Domain\Expression\Evaluator;

/**
 * Applies preset mapping rules to normalized source records.
 */
final class Mapper {
	public function __construct( private Evaluator $evaluator ) {}

	/**
	 * Map one source record to WooCommerce fields.
	 *
	 * @param array<string,mixed> $record Source record.
	 * @param array<string,mixed> $config Preset configuration.
	 * @return array<string,mixed>
	 */
	public function map( array $record, array $config ): array {
		$output = array();
		foreach ( (array) ( $config['mappings'] ?? array() ) as $mapping ) {
			if ( ! is_array( $mapping ) || empty( $mapping['target'] ) ) {
				continue;
			}
			$value = $this->mapping_value( $mapping, $record );
			$empty = null === $value || '' === $value || array() === $value;
			if ( $empty && 'keep' === ( $mapping['empty'] ?? 'keep' ) ) {
				continue;
			}
			if ( $empty && 'default' === ( $mapping['empty'] ?? '' ) ) {
				$value = $mapping['default'] ?? '';
			}
			if ( $empty && 'clear' === ( $mapping['empty'] ?? '' ) ) {
				$value = '';
			}
			$output[ (string) $mapping['target'] ] = $value;
		}
		return $output;
	}

	/**
	 * Parse all expressions and validate required mappings.
	 *
	 * @param array<string,mixed> $config Configuration.
	 * @return string[] Validation errors.
	 */
	public function validate( array $config ): array {
		$errors  = array();
		$targets = array();
		foreach ( (array) ( $config['mappings'] ?? array() ) as $index => $mapping ) {
			$target = (string) ( $mapping['target'] ?? '' );
			if ( isset( $targets[ $target ] ) ) {
				$errors[] = "Mapping target '$target' is configured more than once.";
			}
			$targets[ $target ] = true;
			try {
				if ( ! empty( $mapping['expression'] ) ) {
					$this->evaluator->parse( (string) $mapping['expression'] );
				}
			} catch ( \Throwable $error ) {
				$errors[] = 'Mapping ' . ( $index + 1 ) . ': ' . $error->getMessage();
			}
		}
		if ( ! isset( $targets['name'] ) ) {
			$errors[] = 'A product name mapping is required for new products.';
		}
		if ( 'sku' === ( $config['identity'] ?? 'sku' ) && ! isset( $targets['sku'] ) ) {
			$errors[] = 'An SKU mapping is required when SKU is the identifier.';
		}
		if ( 'external_id' === ( $config['identity'] ?? '' ) && ! isset( $targets['external_id'] ) ) {
			$errors[] = 'An external ID mapping is required when external ID is the identifier.';
		}
		return $errors;
	}

	/**
	 * Evaluate a single mapping rule.
	 *
	 * @param array<string,mixed> $mapping Mapping.
	 * @param array<string,mixed> $record  Record.
	 */
	private function mapping_value( array $mapping, array $record ): mixed {
		if ( ! empty( $mapping['ast'] ) && is_array( $mapping['ast'] ) ) {
			return $this->evaluator->evaluate_ast( $mapping['ast'], $record );
		}
		if ( '' !== (string) ( $mapping['expression'] ?? '' ) ) {
			return $this->evaluator->evaluate( (string) $mapping['expression'], $record );
		}
		$source = (string) ( $mapping['source'] ?? '' );
		return '' === $source ? null : ( $record[ $source ] ?? null );
	}
}
