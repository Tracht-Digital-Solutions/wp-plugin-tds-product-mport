<?php
/**
 * CSV streaming parser.
 *
 * @package TDS\ProductImporter
 */

namespace TDS\ProductImporter\Domain\Parsing;

use Generator;
use InvalidArgumentException;
use SplFileObject;

/**
 * Parses CSV, including multiline quoted fields.
 */
final class CsvParser implements Parser {
	/**
	 * Yield associative CSV rows.
	 *
	 * @param array<string,mixed> $config Preset configuration.
	 * @return Generator<int,array<string,mixed>>
	 */
	public function records( string $path, array $config ): Generator {
		$csv       = is_array( $config['csv'] ?? null ) ? $config['csv'] : array();
		$delimiter = (string) ( $csv['delimiter'] ?? '' );
		if ( '' === $delimiter ) {
			$delimiter = $this->detect_delimiter( $path );
		}
		$enclosure = (string) ( $csv['enclosure'] ?? '"' ) ?: '"';
		$encoding  = (string) ( $csv['encoding'] ?? 'auto' );

		$file = new SplFileObject( $path, 'rb' );
		$file->setFlags( SplFileObject::READ_CSV | SplFileObject::DROP_NEW_LINE | SplFileObject::SKIP_EMPTY );
		$file->setCsvControl( $delimiter, $enclosure, '\\' );

		$headers = null;
		$index   = 0;
		foreach ( $file as $row ) {
			if ( ! is_array( $row ) || array( null ) === $row ) {
				continue;
			}
			$row = array_map( fn( $value ) => $this->convert( (string) $value, $encoding ), $row );
			if ( null === $headers ) {
				$headers = $this->headers( $row );
				continue;
			}
			++$index;
			$row = array_pad( $row, count( $headers ), '' );
			yield $index => array_combine( $headers, array_slice( $row, 0, count( $headers ) ) );
		}
	}

	/**
	 * Detect the delimiter from the first non-empty line.
	 */
	private function detect_delimiter( string $path ): string {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$line   = false === $handle ? '' : (string) fgets( $handle );
		if ( is_resource( $handle ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		$scores = array();
		foreach ( array( ',', ';', "\t", '|' ) as $candidate ) {
			$scores[ $candidate ] = count( str_getcsv( $line, $candidate ) );
		}
		arsort( $scores );
		$delimiter = (string) array_key_first( $scores );
		if ( (int) reset( $scores ) < 2 ) {
			throw new InvalidArgumentException( 'CSV delimiter could not be detected.' );
		}
		return $delimiter;
	}

	/**
	 * Normalize duplicate and empty column names.
	 *
	 * @param string[] $headers Headers.
	 * @return string[]
	 */
	private function headers( array $headers ): array {
		$used = array();
		return array_map(
			static function ( string $header, int $index ) use ( &$used ): string {
				$header = trim( preg_replace( '/^\xEF\xBB\xBF/', '', $header ) );
				$header = '' === $header ? 'column_' . ( $index + 1 ) : $header;
				$base   = $header;
				$count  = 2;
				while ( isset( $used[ $header ] ) ) {
					$header = $base . '_' . $count;
					++$count;
				}
				$used[ $header ] = true;
				return $header;
			},
			$headers,
			array_keys( $headers )
		);
	}

	/**
	 * Convert legacy source encodings to UTF-8.
	 */
	private function convert( string $value, string $encoding ): string {
		if ( 'auto' === $encoding ) {
			$encoding = mb_detect_encoding( $value, array( 'UTF-8', 'Windows-1252', 'ISO-8859-1' ), true ) ?: 'UTF-8';
		}
		return 'UTF-8' === strtoupper( $encoding ) ? $value : mb_convert_encoding( $value, 'UTF-8', $encoding );
	}
}
