<?php

namespace {
	if ( ! class_exists( 'WC_Product' ) ) {
		class WC_Product {
			private string $sku = '';

			public function __construct( private int $id = 0 ) {}

			public function get_id(): int {
				return $this->id;
			}

			public function get_sku(): string {
				return $this->sku;
			}

			public function set_sku( string $sku ): void {
				$this->sku = $sku;
			}
		}
	}
}

namespace TDS\ProductImporter\Domain\Import {
	final class ProductWriterTestFunctions {
		public static int $get_posts_calls = 0;
		public static int $sku_calls = 0;
		public static int $term_exists_calls = 0;
		public static int $insert_term_calls = 0;
		/** @var callable|null */
		public static $get_posts = null;
		/** @var array<int,array{post_id:int,key:string,value:mixed}> */
		public static array $meta_updates = array();

		public static function reset(): void {
			self::$get_posts_calls    = 0;
			self::$sku_calls          = 0;
			self::$term_exists_calls  = 0;
			self::$insert_term_calls  = 0;
			self::$get_posts          = null;
			self::$meta_updates       = array();
		}
	}

	function esc_url_raw( string $url, ?array $protocols = null ): string {
		return $url;
	}

	function wp_parse_url( string $url ): array|false {
		return parse_url( $url );
	}

	function get_posts( array $arguments ): array {
		++ProductWriterTestFunctions::$get_posts_calls;
		if ( is_callable( ProductWriterTestFunctions::$get_posts ) ) {
			return (array) call_user_func( ProductWriterTestFunctions::$get_posts, $arguments );
		}
		return array();
	}

	function wc_get_product_id_by_sku( string $sku ): int {
		++ProductWriterTestFunctions::$sku_calls;
		return 0;
	}

	function update_post_meta( int $post_id, string $key, mixed $value ): void {
		ProductWriterTestFunctions::$meta_updates[] = array(
			'post_id' => $post_id,
			'key'     => $key,
			'value'   => $value,
		);
	}

	function term_exists( string $name, string $taxonomy ): bool {
		++ProductWriterTestFunctions::$term_exists_calls;
		return false;
	}

	function wp_insert_term( string $name, string $taxonomy ): array {
		++ProductWriterTestFunctions::$insert_term_calls;
		return array( 'term_id' => 44 );
	}

	function is_wp_error( mixed $value ): bool {
		return false;
	}
}

namespace TDS\ProductImporter\Tests\Unit {
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;
	use ReflectionMethod;
	use TDS\ProductImporter\Domain\Import\ProductWriter;
	use TDS\ProductImporter\Domain\Import\ProductWriterTestFunctions;
	use TDS\ProductImporter\Infrastructure\Database;
	use TDS\ProductImporter\Infrastructure\JobRepository;

	final class ProductWriterWpdbStub {
		public string $prefix = 'wp_';
		public int $get_var_calls = 0;

		public function prepare( string $query, mixed ...$arguments ): string {
			return $query;
		}

		public function get_var( string $query ): ?int {
			++$this->get_var_calls;
			return null;
		}
	}

	final class ProductWriterCacheTest extends TestCase {
		private bool $had_wpdb;
		private mixed $previous_wpdb;

		protected function setUp(): void {
			ProductWriterTestFunctions::reset();
			$this->had_wpdb      = array_key_exists( 'wpdb', $GLOBALS );
			$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
			$GLOBALS['wpdb']     = new ProductWriterWpdbStub();
		}

		protected function tearDown(): void {
			if ( $this->had_wpdb ) {
				$GLOBALS['wpdb'] = $this->previous_wpdb;
			} else {
				unset( $GLOBALS['wpdb'] );
			}
		}

		public function test_product_lookup_misses_are_cached_and_saved_products_are_written_through(): void {
			$writer = new ProductWriter( new JobRepository( new Database() ) );
			$link   = new ReflectionMethod( $writer, 'linked_product' );
			$sku    = new ReflectionMethod( $writer, 'sku_product' );

			self::assertNull( $link->invoke( $writer, 7, 'SOURCE-99' ) );
			self::assertNull( $link->invoke( $writer, 7, 'SOURCE-99' ) );
			self::assertSame( 1, $GLOBALS['wpdb']->get_var_calls );
			self::assertNull( $sku->invoke( $writer, 'SKU-99' ) );
			self::assertNull( $sku->invoke( $writer, 'SKU-99' ) );
			self::assertSame( 1, ProductWriterTestFunctions::$sku_calls );

			$product = new \WC_Product( 99 );
			$product->set_sku( 'SKU-99' );
			$remember = new ReflectionMethod( $writer, 'remember_product' );
			$remember->invoke( $writer, 7, 'SOURCE-99', $product, '' );

			self::assertSame( 99, $link->invoke( $writer, 7, 'SOURCE-99' ) );
			self::assertSame( 99, $sku->invoke( $writer, 'SKU-99' ) );
			self::assertSame( 1, $GLOBALS['wpdb']->get_var_calls );
			self::assertSame( 1, ProductWriterTestFunctions::$sku_calls );

			$writer->reset_caches();
			self::assertNull( $link->invoke( $writer, 7, 'SOURCE-99' ) );
			self::assertNull( $sku->invoke( $writer, 'SKU-99' ) );
			self::assertSame( 2, $GLOBALS['wpdb']->get_var_calls );
			self::assertSame( 2, ProductWriterTestFunctions::$sku_calls );
		}

		public function test_normalized_media_url_reuses_one_lookup(): void {
			ProductWriterTestFunctions::$get_posts = static fn(): array => array( 71 );
			$writer                                = $this->writer_without_constructor();
			$method                                = new ReflectionMethod( $writer, 'image_id' );
			$created                               = array();

			$first  = $method->invokeArgs( $writer, array( 'HTTPS://Example.COM:443/media/Product.JPG?size=2#first', 10, &$created ) );
			$second = $method->invokeArgs( $writer, array( 'https://example.com/media/Product.JPG?size=2#second', 10, &$created ) );

			self::assertSame( 71, $first );
			self::assertSame( 71, $second );
			self::assertSame( 1, ProductWriterTestFunctions::$get_posts_calls );
		}

		public function test_legacy_raw_media_hash_is_backfilled(): void {
			$raw        = 'https://EXAMPLE.com:443/image.jpg#legacy';
			$legacy_hash = hash( 'sha256', $raw );
			ProductWriterTestFunctions::$get_posts = static function ( array $arguments ) use ( $legacy_hash ): array {
				return $legacy_hash === $arguments['meta_value'] ? array( 72 ) : array();
			};
			$writer  = $this->writer_without_constructor();
			$method  = new ReflectionMethod( $writer, 'image_id' );
			$created = array();

			$result = $method->invokeArgs( $writer, array( $raw, 10, &$created ) );

			self::assertSame( 72, $result );
			self::assertSame( 2, ProductWriterTestFunctions::$get_posts_calls );
			self::assertContains(
				array(
					'post_id' => 72,
					'key'     => '_tds_import_source_hash',
					'value'   => hash( 'sha256', 'https://example.com/image.jpg' ),
				),
				ProductWriterTestFunctions::$meta_updates
			);
		}

		public function test_media_normalization_preserves_path_query_and_non_default_port(): void {
			$method = new ReflectionMethod( ProductWriter::class, 'normalize_media_url' );

			self::assertSame(
				'https://example.com:8443/Case/Product.JPG?Token=ABC',
				$method->invoke( null, 'HTTPS://Example.COM:8443/Case/Product.JPG?Token=ABC#preview' )
			);
			self::assertSame( '', $method->invoke( null, 'http://example.com/image.jpg' ) );
		}

		public function test_inserted_term_is_cached_case_insensitively(): void {
			$writer = $this->writer_without_constructor();
			$method = new ReflectionMethod( $writer, 'term_id' );

			self::assertSame( 44, $method->invoke( $writer, 'Accessories', 'product_cat' ) );
			self::assertSame( 44, $method->invoke( $writer, 'accessories', 'product_cat' ) );
			self::assertSame( 1, ProductWriterTestFunctions::$term_exists_calls );
			self::assertSame( 1, ProductWriterTestFunctions::$insert_term_calls );
		}

		private function writer_without_constructor(): ProductWriter {
			$reflection = new ReflectionClass( ProductWriter::class );
			return $reflection->newInstanceWithoutConstructor();
		}
	}
}
