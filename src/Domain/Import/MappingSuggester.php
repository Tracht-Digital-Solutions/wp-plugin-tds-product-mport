<?php
/**
 * Mapping suggestions.
 *
 * @package TDS\ProductImporter
 */

namespace TDS\ProductImporter\Domain\Import;

/**
 * Suggests WooCommerce targets for common German and English feed fields.
 */
final class MappingSuggester {
	/**
	 * Source aliases grouped by target.
	 *
	 * @var array<string,string[]>
	 */
	private const ALIASES = array(
		'name'              => array( 'name', 'title', 'product name', 'product title', 'produktname', 'bezeichnung', 'titel' ),
		'sku'               => array( 'sku', 'article number', 'article no', 'item number', 'artikelnummer', 'art nr', 'artnr' ),
		'external_id'       => array( 'external id', 'product id', 'item id', 'source id', 'externe id', 'produkt id' ),
		'type'              => array( 'type', 'product type', 'produkttyp', 'typ' ),
		'parent'            => array( 'parent', 'parent sku', 'parent id', 'parent_sku', 'hauptartikel', 'vaterartikel' ),
		'description'       => array( 'description', 'long description', 'beschreibung', 'langtext' ),
		'short_description' => array( 'short description', 'summary', 'kurzbeschreibung', 'kurztext' ),
		'regular_price'     => array( 'price', 'regular price', 'standard price', 'preis', 'verkaufspreis', 'bruttopreis' ),
		'sale_price'        => array( 'sale price', 'special price', 'aktionspreis', 'angebotspreis', 'sonderpreis' ),
		'stock_quantity'    => array( 'stock', 'quantity', 'stock quantity', 'inventory', 'bestand', 'lagerbestand', 'menge' ),
		'stock_status'      => array( 'stock status', 'availability', 'lagerstatus', 'verfuegbarkeit', 'verfügbarkeit' ),
		'categories'        => array( 'category', 'categories', 'product category', 'kategorie', 'kategorien', 'warengruppe' ),
		'tags'              => array( 'tags', 'keywords', 'schlagworte', 'stichworte' ),
		'image'             => array( 'image', 'image url', 'main image', 'featured image', 'bild', 'bild url', 'hauptbild' ),
		'gallery_images'    => array( 'gallery', 'gallery images', 'additional images', 'galerie', 'weitere bilder' ),
		'attributes'        => array( 'attributes', 'properties', 'attribute', 'eigenschaften', 'merkmale' ),
		'weight'            => array( 'weight', 'gewicht' ),
		'length'            => array( 'length', 'laenge', 'länge' ),
		'width'             => array( 'width', 'breite' ),
		'height'            => array( 'height', 'hoehe', 'höhe' ),
	);

	/**
	 * Build suggestions for detected source fields.
	 *
	 * @param string[]                  $fields   Source fields.
	 * @param array<int,array<string,mixed>> $existing Existing mappings.
	 * @return array<int,array{source:string,target:string,confidence:float,reason:string}>
	 */
	public function suggest( array $fields, array $existing = array() ): array {
		$used_sources = array();
		$used_targets = array();
		foreach ( $existing as $mapping ) {
			$used_sources[ (string) ( $mapping['source'] ?? '' ) ] = true;
			$used_targets[ (string) ( $mapping['target'] ?? '' ) ] = true;
		}

		$suggestions = array();
		foreach ( $fields as $field ) {
			if ( isset( $used_sources[ $field ] ) ) {
				continue;
			}
			$normalized = self::normalize( $field );
			$best       = null;
			foreach ( self::ALIASES as $target => $aliases ) {
				if ( isset( $used_targets[ $target ] ) ) {
					continue;
				}
				foreach ( $aliases as $alias ) {
					$alias_normalized = self::normalize( $alias );
					$confidence       = 0.0;
					$reason           = 'similar';
					if ( $normalized === $alias_normalized ) {
						$confidence = self::normalize( $target ) === $normalized ? 1.0 : 0.95;
						$reason     = 'alias';
					} else {
						similar_text( $normalized, $alias_normalized, $similarity );
						if ( $similarity >= 82 ) {
							$confidence = round( $similarity / 100, 2 );
						}
					}
					if ( $confidence >= 0.7 && ( null === $best || $confidence > $best['confidence'] ) ) {
						$best = compact( 'target', 'confidence', 'reason' );
					}
				}
			}
			if ( $best ) {
				$used_targets[ $best['target'] ] = true;
				$suggestions[]                   = array(
					'source'     => $field,
					'target'     => $best['target'],
					'confidence' => $best['confidence'],
					'reason'     => $best['reason'],
				);
			}
		}
		usort( $suggestions, static fn( array $left, array $right ): int => $right['confidence'] <=> $left['confidence'] );
		return $suggestions;
	}

	/**
	 * Normalize source field labels.
	 */
	public static function normalize( string $value ): string {
		$value = mb_strtolower( trim( $value ) );
		$value = function_exists( 'remove_accents' )
			? remove_accents( $value )
			: strtr(
				$value,
				array(
					'ä' => 'a',
					'ö' => 'o',
					'ü' => 'u',
					'ß' => 'ss',
				)
			);
		$value = preg_replace( '/[^a-z0-9]+/', ' ', $value );
		return trim( preg_replace( '/\s+/', ' ', $value ) );
	}
}
