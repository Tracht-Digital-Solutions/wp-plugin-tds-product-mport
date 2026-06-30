<?php
/**
 * Parser factory.
 *
 * @package TDS\ProductImporter
 */

namespace TDS\ProductImporter\Domain\Parsing;

use InvalidArgumentException;

/**
 * Detects and creates CSV/XML streaming parsers.
 */
final class ParserFactory {
	/**
	 * Resolve a parser for a source.
	 *
	 * @param array<string,mixed> $config Preset configuration.
	 */
	public function create( string $path, array $config ): Parser {
		$format = (string) ( $config['format'] ?? 'auto' );
		if ( 'auto' === $format ) {
			$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			$format    = in_array( $extension, array( 'xml', 'csv' ), true ) ? $extension : $this->sniff( $path );
		}
		return match ( $format ) {
			'csv' => new CsvParser(),
			'xml' => new XmlParser(),
			default => throw new InvalidArgumentException( 'Unsupported source format.' ),
		};
	}

	/**
	 * Read a small sample.
	 *
	 * @param array<string,mixed> $config Preset configuration.
	 * @return array<int,array<string,mixed>>
	 */
	public function preview( string $path, array $config, int $limit = 5 ): array {
		$rows = array();
		foreach ( $this->create( $path, $config )->records( $path, $config ) as $row ) {
			$rows[] = $row;
			if ( count( $rows ) >= $limit ) {
				break;
			}
		}
		return $rows;
	}

	/**
	 * Detect XML by its first non-whitespace byte, otherwise CSV.
	 */
	private function sniff( string $path ): string {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			throw new InvalidArgumentException( 'Source file cannot be opened.' );
		}
		$sample = (string) fread( $handle, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$sample = ltrim( preg_replace( '/^\xEF\xBB\xBF/', '', $sample ) );
		return str_starts_with( $sample, '<' ) ? 'xml' : 'csv';
	}
}
