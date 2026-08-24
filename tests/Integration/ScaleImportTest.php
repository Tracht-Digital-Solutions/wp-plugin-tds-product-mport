<?php
/**
 * Full resumable-engine scale tests.
 *
 * @package TDS\ProductImporter\Tests
 */

namespace TDS\ProductImporter\Tests\Integration;

use RuntimeException;
use TDS\ProductImporter\Domain\Expression\Evaluator;
use TDS\ProductImporter\Domain\Import\JobRunner;
use TDS\ProductImporter\Domain\Import\Mapper;
use TDS\ProductImporter\Domain\Import\ProductWriterInterface;
use TDS\ProductImporter\Domain\Import\RollbackService;
use TDS\ProductImporter\Domain\Parsing\ParserFactory;
use TDS\ProductImporter\Infrastructure\Database;
use TDS\ProductImporter\Infrastructure\Installer;
use TDS\ProductImporter\Infrastructure\JobRepository;
use TDS\ProductImporter\Infrastructure\PresetRepository;
use TDS\ProductImporter\Infrastructure\SecretBox;
use TDS\ProductImporter\Infrastructure\SourceManager;

final class ScaleWriter implements ProductWriterInterface {
	public function reset_caches(): void {}

	public function write( array $fields, array $item, array $preset, int $job_id ): array {
		if ( empty( $fields['name'] ) || empty( $item['source_key'] ) ) {
			throw new RuntimeException( 'Scale record was not mapped.' );
		}
		return array(
			'product_id' => (int) $item['sequence_no'],
			'result'     => 'created',
		);
	}

	public function apply_relationships( int $product_id, array $fields, int $preset_id, int $job_id ): void {}
}

final class ScaleImportTest extends \WP_UnitTestCase {
	private Database $database;
	private PresetRepository $presets;
	private JobRepository $jobs;
	/** @var string[] */
	private array $files = array();

	public function setUp(): void {
		parent::setUp();
		Installer::activate();
		$this->database = new Database();
		$this->reset_plugin_tables();
		$this->presets  = new PresetRepository( $this->database, new SecretBox() );
		$this->jobs     = new JobRepository( $this->database );
	}

	public function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		parent::tearDown();
	}

	/** @group scale */
	public function test_csv_job_completes_twenty_five_thousand_unique_records(): void {
		$path   = $this->new_source_path( 'csv' );
		$handle = fopen( $path, 'wb' );
		fwrite( $handle, "sku,name\n" );
		for ( $index = 1; $index <= 25000; ++$index ) {
			fwrite( $handle, "CSV-$index,CSV Product $index\n" );
		}
		fclose( $handle );

		$this->run_scale_job( $path, 'csv' );
	}

	/** @group scale */
	public function test_xml_job_completes_twenty_five_thousand_unique_records(): void {
		$path   = $this->new_source_path( 'xml' );
		$handle = fopen( $path, 'wb' );
		fwrite( $handle, '<catalog>' );
		for ( $index = 1; $index <= 25000; ++$index ) {
			fwrite( $handle, "<product><sku>XML-$index</sku><name>XML Product $index</name></product>" );
		}
		fwrite( $handle, '</catalog>' );
		fclose( $handle );

		$this->run_scale_job( $path, 'xml' );
	}

	private function run_scale_job( string $path, string $format ): void {
		global $wpdb;
		$preset = $this->presets->save(
			array(
				'name'   => strtoupper( $format ) . ' scale import',
				'config' => array(
					'source'     => array(
						'type'        => 'upload',
						'upload_path' => $path,
					),
					'format'     => $format,
					'xml'        => array( 'record_path' => '/catalog/product' ),
					'identity'   => 'sku',
					'batch_size' => 250,
					'mappings'   => array(
						array(
							'target' => 'sku',
							'source' => 'sku',
						),
						array(
							'target' => 'name',
							'source' => 'name',
						),
					),
				),
			)
		);
		$job_id = $this->jobs->create( (int) $preset['id'] );
		$runner = new JobRunner(
			$this->presets,
			$this->jobs,
			new SourceManager( new SecretBox() ),
			new ParserFactory(),
			new Mapper( new Evaluator() ),
			new ScaleWriter(),
			new RollbackService( $this->jobs )
		);

		for ( $action = 0; $action < 300; ++$action ) {
			$runner->run( $job_id );
			$job = $this->jobs->find( $job_id );
			if ( in_array( $job['status'], array( 'completed', 'partial', 'failed' ), true ) ) {
				break;
			}
		}

		$job        = $this->jobs->find( $job_id );
		$item_table = $this->database->table( 'items' );
		$counts     = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total,COUNT(DISTINCT source_key_hash) AS unique_total FROM $item_table WHERE job_id=%d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$job_id
			),
			ARRAY_A
		);
		$source_path = (string) ( $job['source_path'] ?? '' );
		if ( '' !== $source_path ) {
			$this->files[] = $source_path;
		}

		self::assertSame( 'completed', $job['status'] );
		self::assertSame( 25000, $job['parse_cursor'] );
		self::assertSame( 25000, $job['staged_total'] );
		self::assertSame( 25000, $job['processed'] );
		self::assertSame( 25000, $job['created'] );
		self::assertSame( '25000', $counts['total'] );
		self::assertSame( '25000', $counts['unique_total'] );
		self::assertLessThan( 160 * 1024 * 1024, memory_get_peak_usage( true ) );
	}

	private function new_source_path( string $extension ): string {
		wp_mkdir_p( Installer::storage_dir() );
		$path          = trailingslashit( Installer::storage_dir() ) . wp_generate_uuid4() . '.' . $extension;
		$this->files[] = $path;
		return $path;
	}

	/**
	 * Keep custom plugin tables isolated from earlier integration-test processes.
	 */
	private function reset_plugin_tables(): void {
		global $wpdb;
		foreach ( array( 'logs', 'snapshots', 'items', 'links', 'jobs', 'presets' ) as $suffix ) {
			$table = $this->database->table( $suffix );
			$wpdb->query( "DELETE FROM $table" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}
