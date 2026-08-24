<?php
/**
 * Parser factory.
 *
 * @package TDS\ProductImporter
 */

namespace TDS\ProductImporter\Domain\Parsing;

use InvalidArgumentException;
use XMLReader;

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
		$format = $this->detect_format( $path, $config );
		return match ( $format ) {
			'csv' => new CsvParser(),
			'xml' => new XmlParser(),
			default => throw new InvalidArgumentException( 'Unsupported source format.' ),
		};
	}

	/**
	 * Resolve the effective source format.
	 *
	 * @param array<string,mixed> $config Preset configuration.
	 */
	public function detect_format( string $path, array $config ): string {
		$format = (string) ( $config['format'] ?? 'auto' );
		if ( 'auto' !== $format ) {
			return $format;
		}
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return in_array( $extension, array( 'xml', 'csv' ), true ) ? $extension : $this->sniff( $path );
	}

	/**
	 * Detect operator-editable source structure settings.
	 *
	 * @param array<string,mixed> $config Import configuration.
	 * @return array<string,mixed>
	 */
	public function detect_structure( string $path, array $config ): array {
		$format = $this->detect_format( $path, $config );
		if ( 'csv' === $format ) {
			$sample = (string) file_get_contents( $path, false, null, 0, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$first  = strtok( $sample, "\r\n" ) ?: '';
			$scores = array();
			foreach ( array( ',', ';', "\t", '|' ) as $candidate ) {
				$scores[ $candidate ] = count( str_getcsv( $first, $candidate ) );
			}
			arsort( $scores );
			$encoding = mb_detect_encoding( $sample, array( 'UTF-8', 'Windows-1252', 'ISO-8859-1' ), true ) ?: 'UTF-8';
			return array(
				'delimiter' => (string) array_key_first( $scores ),
				'encoding'  => 'ISO-8859-1' === $encoding ? 'Windows-1252' : $encoding,
			);
		}

		$reader = new XMLReader();
		if ( ! $reader->open( $path, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE ) ) {
			throw new InvalidArgumentException( 'XML source cannot be opened.' );
		}
		$root        = '';
		$record_path = '';
		while ( $reader->read() ) {
			if ( XMLReader::ELEMENT !== $reader->nodeType ) {
				continue;
			}
			if ( 0 === $reader->depth ) {
				$root = $reader->localName;
			} elseif ( 1 === $reader->depth ) {
				$record_path = '/' . $root . '/' . $reader->localName;
				break;
			}
		}
		$reader->close();
		return array( 'record_path' => $record_path );
	}

	/**
	 * Read a small sample.
	 *
	 * @param array<string,mixed> $config Preset configuration.
	 * @return array<int,array<string,mixed>>
	 */
	public function preview( string $path, array $config, int $limit = 5 ): array {
		$limit = max( 1, min( 20, $limit ) );
		$rows  = array();
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
