<?php
/**
 * WooCommerce product writer.
 *
 * @package TDS\ProductImporter
 */

namespace TDS\ProductImporter\Domain\Import;

use InvalidArgumentException;
use RuntimeException;
use TDS\ProductImporter\Infrastructure\JobRepository;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_External;
use WC_Product_Grouped;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * Creates and updates WooCommerce products through CRUD APIs.
 */
final class ProductWriter implements ProductWriterInterface {
	/** @var array<string,int|null> */
	private array $linked_product_cache = array();

	/** @var array<string,int|null> */
	private array $sku_product_cache = array();

	/** @var array<string,int|null> */
	private array $term_cache = array();

	/** @var array<string,int|null> */
	private array $media_cache = array();

	/** @var array<int,WC_Product|null> */
	private array $product_cache = array();

	/** @var array<string,int|null> */
	private array $external_product_cache = array();

	public function __construct( private JobRepository $jobs ) {}

	/**
	 * Reset request-local lookup state at the start of a worker batch.
	 */
	public function reset_caches(): void {
		$this->linked_product_cache   = array();
		$this->sku_product_cache      = array();
		$this->term_cache             = array();
		$this->media_cache            = array();
		$this->product_cache          = array();
		$this->external_product_cache = array();
	}

	/**
	 * Persist one mapped product.
	 *
	 * @param array<string,mixed> $fields Mapped values.
	 * @param array<string,mixed> $item   Staged item.
	 * @param array<string,mixed> $preset Preset.
	 * @return array{product_id:int,result:string}
	 */
	public function write( array $fields, array $item, array $preset, int $job_id ): array {
		$config     = (array) $preset['config'];
		$source_key = trim( (string) ( $item['source_key'] ?? '' ) );
		if ( '' === $source_key ) {
			throw new InvalidArgumentException( 'The product identifier is empty.' );
		}
		$product_id = $this->find_product( (int) $preset['id'], $source_key, $config );
		$is_new     = ! $product_id;
		$type       = sanitize_key( (string) ( $fields['type'] ?? $item['record_type'] ?? 'simple' ) );
		$product    = $is_new ? $this->new_product( $type ) : $this->product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			throw new InvalidArgumentException( 'The matched WooCommerce product no longer exists.' );
		}

		$previous_sku = '';
		if ( ! $is_new ) {
			$this->jobs->snapshot( $job_id, $product->get_id(), false, self::capture( $product->get_id() ) );
			if ( 'variation' !== $type && $product->get_type() !== $type && in_array( $type, array( 'simple', 'variable', 'grouped', 'external' ), true ) ) {
				wp_set_object_terms( $product->get_id(), $type, 'product_type' );
				clean_post_cache( $product->get_id() );
				$product = $this->new_product( $type, $product->get_id() );
			}
			$previous_sku = trim( (string) $product->get_sku() );
		}
		if ( $product instanceof WC_Product_Variation ) {
			$parent_id = $this->resolve_reference( (int) $preset['id'], (string) ( $item['parent_key'] ?? '' ) );
			if ( ! $parent_id ) {
				throw new InvalidArgumentException( 'Variation parent could not be resolved.' );
			}
			$product->set_parent_id( $parent_id );
		}

		$created_media = array();
		$this->set_core_fields( $product, $fields );
		$this->set_taxonomies( $product, $fields );
		$this->set_attributes( $product, $fields );
		$this->set_downloads( $product, $fields );
		$this->set_meta( $product, $fields );
		$product->update_meta_data( '_tds_import_last_job', (string) $job_id );
		if ( $is_new ) {
			$product->update_meta_data( '_tds_import_created_job', (string) $job_id );
		}
		if ( 'external_id' === ( $config['identity'] ?? 'sku' ) ) {
			$product->update_meta_data( '_tds_import_external_id', $source_key );
			$product->update_meta_data( '_tds_import_external_identity', $this->external_identity( (int) $preset['id'], $source_key ) );
		}
		$product_id = $product->save();
		$this->set_acf( $product_id, $fields );

		if ( $is_new ) {
			$this->jobs->snapshot( $job_id, $product_id, true, null );
		}
		$this->jobs->link( (int) $preset['id'], $source_key, $product_id, $job_id );
		$this->remember_product( (int) $preset['id'], $source_key, $product, $previous_sku );
		if ( 'external_id' === ( $config['identity'] ?? 'sku' ) ) {
			$this->external_product_cache[ $this->external_identity( (int) $preset['id'], $source_key ) ] = $product_id;
		}
		// Record a post-core fingerprint so a media failure can still be rolled back safely.
		$this->jobs->set_snapshot_fingerprint( $job_id, $product_id, self::fingerprint( $product_id ) );
		$this->set_media( $product, $fields, $created_media, $job_id );
		$product->save();
		$this->jobs->set_snapshot_fingerprint( $job_id, $product_id, self::fingerprint( $product_id ) );

		do_action( 'tds_importer_product_saved', $product_id, $job_id, $fields );
		$created_in_job = $is_new || $job_id === (int) $product->get_meta( '_tds_import_created_job', true );
		return array(
			'product_id' => $product_id,
			'result'     => $created_in_job ? 'created' : 'updated',
		);
	}

	/**
	 * Find an existing product by the selected identity.
	 *
	 * @param array<string,mixed> $config Configuration.
	 */
	private function find_product( int $preset_id, string $source_key, array $config ): ?int {
		if ( 'external_id' === ( $config['identity'] ?? 'sku' ) ) {
			return $this->linked_product( $preset_id, $source_key ) ?: $this->external_product( $preset_id, $source_key );
		}
		return $this->sku_product( $source_key );
	}

	/**
	 * Instantiate the requested native WooCommerce product class.
	 */
	private function new_product( string $type, int $id = 0 ): WC_Product {
		return match ( $type ) {
			'variable' => new WC_Product_Variable( $id ),
			'grouped' => new WC_Product_Grouped( $id ),
			'external' => new WC_Product_External( $id ),
			'variation' => new WC_Product_Variation( $id ),
			default => new WC_Product_Simple( $id ),
		};
	}

	/**
	 * Apply scalar WooCommerce properties.
	 *
	 * @param array<string,mixed> $fields Fields.
	 */
	private function set_core_fields( WC_Product $product, array $fields ): void {
		$setters = array(
			'name'              => 'set_name',
			'slug'              => 'set_slug',
			'status'            => 'set_status',
			'description'       => 'set_description',
			'short_description' => 'set_short_description',
			'sku'               => 'set_sku',
			'regular_price'     => 'set_regular_price',
			'sale_price'        => 'set_sale_price',
			'manage_stock'      => 'set_manage_stock',
			'stock_quantity'    => 'set_stock_quantity',
			'stock_status'      => 'set_stock_status',
			'backorders'        => 'set_backorders',
			'sold_individually' => 'set_sold_individually',
			'weight'            => 'set_weight',
			'length'            => 'set_length',
			'width'             => 'set_width',
			'height'            => 'set_height',
			'tax_status'        => 'set_tax_status',
			'tax_class'         => 'set_tax_class',
			'shipping_class'    => 'set_shipping_class_id',
			'virtual'           => 'set_virtual',
			'downloadable'      => 'set_downloadable',
			'purchase_note'     => 'set_purchase_note',
			'menu_order'        => 'set_menu_order',
			'reviews_allowed'   => 'set_reviews_allowed',
			'product_url'       => 'set_product_url',
			'button_text'       => 'set_button_text',
			'date_on_sale_from' => 'set_date_on_sale_from',
			'date_on_sale_to'   => 'set_date_on_sale_to',
		);
		foreach ( $setters as $field => $setter ) {
			if ( ! array_key_exists( $field, $fields ) || ! is_callable( array( $product, $setter ) ) ) {
				continue;
			}
			$value = $fields[ $field ];
			if ( in_array( $field, array( 'manage_stock', 'sold_individually', 'virtual', 'downloadable', 'reviews_allowed' ), true ) ) {
				$value = $this->boolean( $value );
			}
			if ( 'shipping_class' === $field ) {
				$value = '' === (string) $value ? 0 : $this->term_id( (string) $value, 'product_shipping_class' );
			}
			$product->{$setter}( $value );
		}
		if ( ! $product->get_id() && ! $product instanceof WC_Product_Variation && '' === $product->get_name() ) {
			throw new InvalidArgumentException( 'A product name is required when creating products.' );
		}
	}

	/**
	 * Set product category and tag IDs.
	 *
	 * @param array<string,mixed> $fields Fields.
	 */
	private function set_taxonomies( WC_Product $product, array $fields ): void {
		foreach ( array(
			'categories' => 'product_cat',
			'tags'       => 'product_tag',
		) as $field => $taxonomy ) {
			if ( ! array_key_exists( $field, $fields ) ) {
				continue;
			}
			$ids = array();
			foreach ( $this->list_value( $fields[ $field ] ) as $name ) {
				$ids[] = $this->term_id( $name, $taxonomy );
			}
			'categories' === $field ? $product->set_category_ids( $ids ) : $product->set_tag_ids( $ids );
		}
	}

	/**
	 * Set global or custom attributes.
	 *
	 * @param array<string,mixed> $fields Fields.
	 */
	private function set_attributes( WC_Product $product, array $fields ): void {
		if ( ! array_key_exists( 'attributes', $fields ) ) {
			return;
		}
		$definitions = $this->structured( $fields['attributes'] );
		if ( $product instanceof WC_Product_Variation ) {
			$values = array();
			foreach ( $definitions as $name => $definition ) {
				$values[ sanitize_title( is_string( $name ) ? $name : (string) ( $definition['name'] ?? '' ) ) ] =
					is_array( $definition ) ? (string) ( $definition['value'] ?? '' ) : (string) $definition;
			}
			$product->set_attributes( $values );
			return;
		}
		$attributes = array();
		foreach ( $definitions as $name => $definition ) {
			$definition = is_array( $definition ) ? $definition : array( 'options' => $definition );
			$name       = is_string( $name ) ? $name : (string) ( $definition['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$attribute = new WC_Product_Attribute();
			$taxonomy  = wc_attribute_taxonomy_name( $name );
			$is_global = taxonomy_exists( $taxonomy );
			$attribute->set_id( $is_global ? wc_attribute_taxonomy_id_by_name( $taxonomy ) : 0 );
			$attribute->set_name( $is_global ? $taxonomy : $name );
			$options = $this->list_value( $definition['options'] ?? $definition['value'] ?? array() );
			$attribute->set_options( $is_global ? array_map( fn( string $option ): int => $this->term_id( $option, $taxonomy ), $options ) : $options );
			$attribute->set_visible( ! isset( $definition['visible'] ) || $this->boolean( $definition['visible'] ) );
			$attribute->set_variation( ! empty( $definition['variation'] ) );
			$attributes[] = $attribute;
		}
		$product->set_attributes( $attributes );
	}

	/**
	 * Set downloadable file definitions.
	 *
	 * @param array<string,mixed> $fields Fields.
	 */
	private function set_downloads( WC_Product $product, array $fields ): void {
		if ( ! array_key_exists( 'downloads', $fields ) ) {
			return;
		}
		$downloads = array();
		foreach ( $this->structured( $fields['downloads'] ) as $key => $entry ) {
			$entry = is_array( $entry ) ? $entry : array(
				'url'  => $entry,
				'name' => is_string( $key ) ? $key : basename( (string) $entry ),
			);
			$file  = new \WC_Product_Download();
			$file->set_id( is_string( $key ) ? $key : md5( (string) ( $entry['url'] ?? '' ) ) );
			$file->set_name( (string) ( $entry['name'] ?? 'Download' ) );
			$file->set_file( esc_url_raw( (string) ( $entry['url'] ?? '' ) ) );
			$downloads[] = $file;
		}
		$product->set_downloads( $downloads );
	}

	/**
	 * Set generic metadata and optional ACF fields.
	 *
	 * @param array<string,mixed> $fields Fields.
	 */
	private function set_meta( WC_Product $product, array $fields ): void {
		foreach ( $fields as $target => $value ) {
			if ( str_starts_with( $target, 'meta:' ) ) {
				$key     = substr( $target, 5 );
				$allowed = '' !== $key && ! str_starts_with( $key, '_' );
				if ( ! apply_filters( 'tds_importer_allow_meta_key', $allowed, $key ) ) {
					continue;
				}
				$product->update_meta_data( $key, $value );
			}
		}
	}

	/**
	 * Update optional ACF fields after a product has an ID.
	 *
	 * @param array<string,mixed> $fields Fields.
	 */
	private function set_acf( int $product_id, array $fields ): void {
		if ( ! function_exists( 'update_field' ) ) {
			return;
		}
		foreach ( $fields as $target => $value ) {
			if ( str_starts_with( $target, 'acf:' ) ) {
				update_field( substr( $target, 4 ), $value, $product_id );
			}
		}
	}

	/**
	 * Sideload featured and gallery images, reusing source URLs.
	 *
	 * @param array<string,mixed> $fields  Fields.
	 * @param int[]               $created Created media IDs.
	 */
	private function set_media( WC_Product $product, array $fields, array &$created, int $job_id ): void {
		if ( array_key_exists( 'image', $fields ) ) {
			$created_before = count( $created );
			$id             = $this->image_id( (string) $fields['image'], $product->get_id(), $created );
			$product->set_image_id( $id );
			if ( count( $created ) > $created_before ) {
				$this->jobs->set_created_media( $job_id, $product->get_id(), $created );
			}
		}
		if ( array_key_exists( 'gallery_images', $fields ) ) {
			$ids = array();
			foreach ( $this->list_value( $fields['gallery_images'] ) as $url ) {
				$created_before = count( $created );
				$ids[]          = $this->image_id( $url, $product->get_id(), $created );
				if ( count( $created ) > $created_before ) {
					$this->jobs->set_created_media( $job_id, $product->get_id(), $created );
				}
			}
			$product->set_gallery_image_ids( array_filter( $ids ) );
		}
	}

	/**
	 * Resolve grouped, upsell, and cross-sell relationships.
	 *
	 * @param array<string,mixed> $fields Fields.
	 */
	private function set_relationships( WC_Product $product, array $fields, int $preset_id ): void {
		foreach ( array(
			'upsells'          => 'set_upsell_ids',
			'cross_sells'      => 'set_cross_sell_ids',
			'grouped_children' => 'set_children',
		) as $field => $setter ) {
			if ( ! array_key_exists( $field, $fields ) || ! is_callable( array( $product, $setter ) ) ) {
				continue;
			}
			$ids = array_filter( array_map( fn( string $key ): ?int => $this->resolve_reference( $preset_id, $key ), $this->list_value( $fields[ $field ] ) ) );
			$product->{$setter}( $ids );
		}
	}

	/**
	 * Apply relationships after every product has been created.
	 *
	 * @param array<string,mixed> $fields Fields.
	 */
	public function apply_relationships( int $product_id, array $fields, int $preset_id, int $job_id ): void {
		$product = $this->product( $product_id );
		if ( ! $product ) {
			throw new InvalidArgumentException( 'Product disappeared before relationship processing.' );
		}
		$this->set_relationships( $product, $fields, $preset_id );
		$product->save();
		$this->jobs->set_snapshot_fingerprint( $job_id, $product_id, self::fingerprint( $product_id ) );
	}

	/**
	 * Resolve a source key or SKU.
	 */
	private function resolve_reference( int $preset_id, string $key ): ?int {
		$key = trim( $key );
		if ( '' === $key ) {
			return null;
		}
		return $this->linked_product( $preset_id, $key ) ?: $this->sku_product( $key );
	}

	/**
	 * Return a cached product link, including a cached miss.
	 */
	private function linked_product( int $preset_id, string $source_key ): ?int {
		$cache_key = $preset_id . "\0" . $source_key;
		if ( array_key_exists( $cache_key, $this->linked_product_cache ) ) {
			return $this->linked_product_cache[ $cache_key ];
		}

		$this->linked_product_cache[ $cache_key ] = $this->jobs->linked_product( $preset_id, $source_key );
		return $this->linked_product_cache[ $cache_key ];
	}

	/**
	 * Return a cached SKU lookup, including a cached miss.
	 */
	private function sku_product( string $sku ): ?int {
		$sku = trim( $sku );
		if ( '' === $sku ) {
			return null;
		}
		if ( array_key_exists( $sku, $this->sku_product_cache ) ) {
			return $this->sku_product_cache[ $sku ];
		}

		$product_id = wc_get_product_id_by_sku( $sku );

		$this->sku_product_cache[ $sku ] = $product_id ? (int) $product_id : null;
		return $this->sku_product_cache[ $sku ];
	}

	/**
	 * Recover an externally identified product saved before its source link.
	 */
	private function external_product( int $preset_id, string $source_key ): ?int {
		$identity = $this->external_identity( $preset_id, $source_key );
		if ( array_key_exists( $identity, $this->external_product_cache ) ) {
			return $this->external_product_cache[ $identity ];
		}
		$found = get_posts(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => 'any',
				'posts_per_page' => 2,
				'fields'         => 'ids',
				'meta_key'       => '_tds_import_external_identity',
				'meta_value'     => $identity,
			)
		);
		if ( count( $found ) > 1 ) {
			throw new InvalidArgumentException( "External identifier '$source_key' matches more than one product." );
		}
		$this->external_product_cache[ $identity ] = $found ? (int) $found[0] : null;
		return $this->external_product_cache[ $identity ];
	}

	/**
	 * Cache product objects and misses for the current worker batch.
	 */
	private function product( int $product_id ): ?WC_Product {
		if ( array_key_exists( $product_id, $this->product_cache ) ) {
			return $this->product_cache[ $product_id ];
		}
		$product                            = wc_get_product( $product_id );
		$this->product_cache[ $product_id ] = $product instanceof WC_Product ? $product : null;
		return $this->product_cache[ $product_id ];
	}

	private function external_identity( int $preset_id, string $source_key ): string {
		return hash( 'sha256', $preset_id . "\0" . mb_strtolower( trim( $source_key ), 'UTF-8' ) );
	}

	/**
	 * Update caches immediately after a product and its source link are saved.
	 */
	private function remember_product( int $preset_id, string $source_key, WC_Product $product, string $previous_sku ): void {
		$product_id = $product->get_id();
		$this->linked_product_cache[ $preset_id . "\0" . $source_key ] = $product_id;
		$this->product_cache[ $product_id ]                            = $product;

		$current_sku = trim( (string) $product->get_sku() );
		if ( '' !== $previous_sku && $previous_sku !== $current_sku ) {
			$this->sku_product_cache[ $previous_sku ] = null;
		}
		if ( '' !== $current_sku ) {
			$this->sku_product_cache[ $current_sku ] = $product_id;
		}
	}

	/**
	 * Sideload one HTTPS image or return its existing attachment.
	 *
	 * @param int[] $created Created attachments.
	 */
	private function image_id( string $url, int $product_id, array &$created ): int {
		$raw_url = esc_url_raw( trim( $url ), array( 'https' ) );
		$url     = self::normalize_media_url( $raw_url );
		if ( '' === $url ) {
			return 0;
		}
		$hash = hash( 'sha256', $url );
		if ( array_key_exists( $hash, $this->media_cache ) && null !== $this->media_cache[ $hash ] ) {
			return (int) $this->media_cache[ $hash ];
		}

		if ( ! array_key_exists( $hash, $this->media_cache ) ) {
			$attachment_id = $this->attachment_by_source_hash( $hash );
			$legacy_hash   = hash( 'sha256', $raw_url );
			if ( ! $attachment_id && ! hash_equals( $hash, $legacy_hash ) ) {
				$attachment_id = $this->attachment_by_source_hash( $legacy_hash );
				if ( $attachment_id ) {
					update_post_meta( $attachment_id, '_tds_import_source_hash', $hash );
					update_post_meta( $attachment_id, '_tds_import_source_url', $url );
				}
			}
			$this->media_cache[ $hash ] = $attachment_id;
			if ( $attachment_id ) {
				return $attachment_id;
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$temp = download_url( $url, 30 );
		if ( is_wp_error( $temp ) ) {
			throw new RuntimeException( 'Image download failed: ' . $temp->get_error_message() );
		}
		$file = array(
			'name'     => sanitize_file_name( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ?: 'product-image.jpg' ),
			'tmp_name' => $temp,
		);
		$id   = media_handle_sideload( $file, $product_id );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $temp );
			throw new RuntimeException( 'Image import failed: ' . $id->get_error_message() );
		}
		update_post_meta( $id, '_tds_import_source_hash', $hash );
		update_post_meta( $id, '_tds_import_source_url', $url );
		$created[] = (int) $id;

		$this->media_cache[ $hash ] = (int) $id;
		return (int) $id;
	}

	/**
	 * Find a previously imported attachment by its source hash.
	 */
	private function attachment_by_source_hash( string $hash ): ?int {
		$found = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_tds_import_source_hash',
				'meta_value'     => $hash,
			)
		);

		return $found ? (int) $found[0] : null;
	}

	/**
	 * Normalize a media URL without changing its path or query string.
	 */
	private static function normalize_media_url( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'https' !== $scheme ) {
			return '';
		}

		$user_info = '';
		if ( isset( $parts['user'] ) ) {
			$user_info = (string) $parts['user'];
			if ( isset( $parts['pass'] ) ) {
				$user_info .= ':' . (string) $parts['pass'];
			}
			$user_info .= '@';
		}
		$host = strtolower( (string) $parts['host'] );
		if ( str_contains( $host, ':' ) && ! str_starts_with( $host, '[' ) ) {
			$host = '[' . $host . ']';
		}
		$port  = isset( $parts['port'] ) && 443 !== (int) $parts['port'] ? ':' . (int) $parts['port'] : '';
		$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$query = isset( $parts['query'] ) ? '?' . (string) $parts['query'] : '';

		return $scheme . '://' . $user_info . $host . $port . $path . $query;
	}

	private function term_id( string $name, string $taxonomy ): int {
		$cache_key = $taxonomy . "\0" . strtolower( trim( $name ) );
		if ( array_key_exists( $cache_key, $this->term_cache ) && null !== $this->term_cache[ $cache_key ] ) {
			return (int) $this->term_cache[ $cache_key ];
		}

		$term = array_key_exists( $cache_key, $this->term_cache ) ? null : term_exists( $name, $taxonomy );
		if ( ! $term ) {
			$this->term_cache[ $cache_key ] = null;

			$term = wp_insert_term( $name, $taxonomy );
		}
		if ( is_wp_error( $term ) ) {
			throw new InvalidArgumentException( $term->get_error_message() );
		}
		$term_id = (int) ( is_array( $term ) ? $term['term_id'] : $term );

		$this->term_cache[ $cache_key ] = $term_id;
		return $term_id;
	}

	/** @return string[] */
	private function list_value( mixed $value ): array {
		if ( is_array( $value ) ) {
			return array_values( array_filter( array_map( 'trim', array_map( 'strval', $value ) ), static fn( string $item ): bool => '' !== $item ) );
		}
		return array_values( array_filter( array_map( 'trim', preg_split( '/[|,;]/', (string) $value ) ?: array() ), static fn( string $item ): bool => '' !== $item ) );
	}

	/** @return array<mixed> */
	private function structured( mixed $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		$decoded = json_decode( (string) $value, true );
		return is_array( $decoded ) ? $decoded : $this->list_value( $value );
	}

	private function boolean( mixed $value ): bool {
		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'ja', 'on' ), true );
	}

	/**
	 * Capture raw post, metadata, and terms for rollback.
	 *
	 * @return array<string,mixed>
	 */
	public static function capture( int $product_id ): array {
		$post       = get_post( $product_id, ARRAY_A );
		$taxonomies = get_object_taxonomies( 'product' );
		$terms      = array();
		foreach ( $taxonomies as $taxonomy ) {
			$terms[ $taxonomy ] = wp_get_object_terms( $product_id, $taxonomy, array( 'fields' => 'ids' ) );
			sort( $terms[ $taxonomy ] );
		}
		$meta = get_post_meta( $product_id );
		ksort( $meta );
		ksort( $terms );
		return array(
			'post'  => $post,
			'meta'  => $meta,
			'terms' => $terms,
		);
	}

	/**
	 * Fingerprint all state the importer may modify.
	 */
	public static function fingerprint( int $product_id ): string {
		return hash( 'sha256', wp_json_encode( self::capture( $product_id ) ) );
	}
}
