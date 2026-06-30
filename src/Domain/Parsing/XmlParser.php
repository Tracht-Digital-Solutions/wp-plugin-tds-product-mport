<?php
/**
 * XML streaming parser.
 *
 * @package TDS\ProductImporter
 */

namespace TDS\ProductImporter\Domain\Parsing;

use Generator;
use InvalidArgumentException;
use SimpleXMLElement;
use XMLReader;

/**
 * Streams repeated XML record elements with XMLReader.
 */
final class XmlParser implements Parser {
	/**
	 * Yield flattened XML records.
	 *
	 * @param array<string,mixed> $config Preset configuration.
	 * @return Generator<int,array<string,mixed>>
	 */
	public function records( string $path, array $config ): Generator {
		if ( ! class_exists( XMLReader::class ) ) {
			throw new InvalidArgumentException( 'The XMLReader PHP extension is required.' );
		}
		$record_path  = trim( (string) ( $config['xml']['record_path'] ?? '' ), '/' );
		$path_parts   = '' === $record_path ? array() : array_values( array_filter( preg_split( '#[/\.]+#', $record_path ) ?: array() ) );
		$record_name  = $path_parts ? (string) end( $path_parts ) : '';
		$record_depth = null;
		$stack        = array();
		$reader       = new XMLReader();
		if ( ! $reader->open( $path, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE ) ) {
			throw new InvalidArgumentException( 'XML source cannot be opened.' );
		}

		$index = 0;
		while ( $reader->read() ) {
			if ( XMLReader::ELEMENT !== $reader->nodeType ) {
				continue;
			}
			$stack[ $reader->depth ] = $reader->localName;
			$stack                   = array_slice( $stack, 0, $reader->depth + 1 );
			if ( '' === $record_name ) {
				if ( 1 !== $reader->depth ) {
					continue;
				}
				$record_name  = $reader->localName;
				$record_depth = 1;
			}
			if ( $reader->localName !== $record_name || ( null !== $record_depth && $reader->depth !== $record_depth ) ) {
				continue;
			}
			if ( $path_parts && array_slice( $stack, -count( $path_parts ) ) !== $path_parts ) {
				continue;
			}
			$xml = $reader->readOuterXml();
			if ( '' === $xml ) {
				continue;
			}
			$element = simplexml_load_string( $xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA );
			if ( false === $element ) {
				throw new InvalidArgumentException( 'A malformed XML record was encountered.' );
			}
			++$index;
			yield $index => $this->flatten( $element );
		}
		$reader->close();
	}

	/**
	 * Flatten element paths while retaining repeated children as arrays.
	 *
	 * @return array<string,mixed>
	 */
	private function flatten( SimpleXMLElement $element ): array {
		$output = array();
		foreach ( $element->attributes() as $name => $value ) {
			$output[ '@' . $name ] = (string) $value;
		}
		$this->walk( $element, '', $output );
		return $output;
	}

	/**
	 * Recursively flatten nodes.
	 *
	 * @param array<string,mixed> $output Output.
	 */
	private function walk( SimpleXMLElement $node, string $prefix, array &$output ): void {
		$groups = array();
		foreach ( $node->children() as $name => $child ) {
			$groups[ (string) $name ][] = $child;
		}
		foreach ( $groups as $name => $children ) {
			$path = '' === $prefix ? $name : $prefix . '.' . $name;
			if ( count( $children ) > 1 ) {
				$output[ $path ] = array_map(
					function ( SimpleXMLElement $child ): mixed {
						if ( 0 === $child->count() ) {
							return trim( (string) $child );
						}
						return $this->flatten( $child );
					},
					$children
				);
			} elseif ( 0 === $children[0]->count() ) {
				$output[ $path ] = trim( (string) $children[0] );
				foreach ( $children[0]->attributes() as $attribute => $value ) {
					$output[ $path . '.@' . $attribute ] = (string) $value;
				}
			} else {
				$this->walk( $children[0], $path, $output );
			}
		}
	}
}
