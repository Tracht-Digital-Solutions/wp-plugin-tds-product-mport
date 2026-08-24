<?php
/**
 * Wizard integration workflow.
 *
 * @package TDS\ProductImporter\Tests
 */

namespace TDS\ProductImporter\Tests\Integration;

use TDS\ProductImporter\Api\RestController;
use TDS\ProductImporter\Domain\Expression\Evaluator;
use TDS\ProductImporter\Domain\Import\JobRunner;
use TDS\ProductImporter\Domain\Import\Mapper;
use TDS\ProductImporter\Domain\Import\MappingSuggester;
use TDS\ProductImporter\Domain\Import\ProductWriter;
use TDS\ProductImporter\Domain\Import\RollbackService;
use TDS\ProductImporter\Domain\Parsing\ParserFactory;
use TDS\ProductImporter\Infrastructure\ConflictException;
use TDS\ProductImporter\Infrastructure\Cleanup;
use TDS\ProductImporter\Infrastructure\Database;
use TDS\ProductImporter\Infrastructure\Installer;
use TDS\ProductImporter\Infrastructure\JobRepository;
use TDS\ProductImporter\Infrastructure\PresetRepository;
use TDS\ProductImporter\Infrastructure\Scheduler;
use TDS\ProductImporter\Infrastructure\SecretBox;
use TDS\ProductImporter\Infrastructure\SourceManager;
use WP_REST_Request;
use WP_REST_Response;

final class WizardWorkflowTest extends \WP_UnitTestCase {
	private Database $database;
	private PresetRepository $presets;
	private JobRepository $jobs;
	private RestController $controller;
	/** @var string[] */
	private array $files = array();

	public function setUp(): void {
		parent::setUp();
		Installer::activate();
		$this->database   = new Database();
		global $wpdb;
		foreach ( array( 'logs', 'snapshots', 'items', 'links', 'jobs', 'presets' ) as $table ) {
			$wpdb->query( "DELETE FROM {$this->database->table( $table )}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		}
		$secrets          = new SecretBox();
		$this->presets    = new PresetRepository( $this->database, $secrets );
		$this->jobs       = new JobRepository( $this->database );
		$rollback         = new RollbackService( $this->jobs );
		$scheduler        = new Scheduler( $this->presets, $this->jobs );
		$this->controller = new RestController(
			$this->presets,
			$this->jobs,
			new SourceManager( $secrets ),
			new ParserFactory(),
			new Mapper( new Evaluator() ),
			new MappingSuggester(),
			$rollback,
			$scheduler
		);
	}

	public function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		parent::tearDown();
	}

	public function test_csv_draft_preview_suggestions_preflight_and_atomic_start(): void {
		$file                            = $this->source_file( "Artikelnummer;Produktname;Preis\nTDS-1;Erstes Produkt;19,90\n", 'csv' );
		$draft                           = $this->presets->create_draft();
		$config                          = $draft['config'];
		$config['source']['upload_path'] = $file;
		$config['mappings']              = array(
			array(
				'target'     => 'sku',
				'source'     => 'Artikelnummer',
				'expression' => '',
				'empty'      => 'keep',
				'default'    => '',
			),
			array(
				'target'     => 'name',
				'source'     => 'Produktname',
				'expression' => '',
				'empty'      => 'keep',
				'default'    => '',
			),
		);
		$draft                           = $this->presets->update_draft(
			(int) $draft['id'],
			array(
				'revision'    => $draft['revision'],
				'wizard_step' => 2,
				'config'      => $config,
			)
		);

		$preview = $this->response_data( $this->controller->source_preview( $this->request( (int) $draft['id'] ) ) );
		self::assertSame( 'csv', $preview['format'] );
		self::assertSame( ';', $preview['structure']['delimiter'] );
		self::assertSame( 'TDS-1', $preview['records'][0]['Artikelnummer'] );
		self::assertFalse( $preview['truncated'] );
		self::assertSame( 'full', $preview['hash_scope'] );
		self::assertSame( 8, $preview['limits']['records'] );
		self::assertSame( 200, $preview['limits']['fields'] );

		$suggestion_request = $this->request( (int) $draft['id'], array( 'fields' => $preview['fields'] ) );
		$suggestions        = $this->response_data( $this->controller->mapping_suggestions( $suggestion_request ) );
		self::assertIsArray( $suggestions );

		$preflight = $this->response_data( $this->controller->preflight( $this->request( (int) $draft['id'] ) ) );
		self::assertTrue( $preflight['valid'] );

		$start = $this->response_data(
			$this->controller->start_draft( $this->request( (int) $draft['id'], array( 'revision' => $draft['revision'] ) ) )
		);
		self::assertSame( 'active', $start['preset']['status'] );
		self::assertSame( 'queued', $start['job']['status'] );
		self::assertNotNull( $this->jobs->find( (int) $start['job']['id'] ) );

		$runner = new JobRunner(
			$this->presets,
			$this->jobs,
			new SourceManager( new SecretBox() ),
			new ParserFactory(),
			new Mapper( new Evaluator() ),
			new ProductWriter( $this->jobs ),
			new RollbackService( $this->jobs )
		);
		for ( $attempt = 0; $attempt < 10; ++$attempt ) {
			$runner->run( (int) $start['job']['id'] );
			$job = $this->jobs->find( (int) $start['job']['id'] );
			if ( in_array( $job['status'], array( 'completed', 'partial', 'failed' ), true ) ) {
				break;
			}
		}
		self::assertSame( 'completed', $job['status'] );
		self::assertGreaterThan( 0, wc_get_product_id_by_sku( 'TDS-1' ) );
	}

	public function test_destructive_missing_policy_requires_matching_start_confirmation(): void {
		$draft                    = $this->presets->create_draft();
		$config                   = $draft['config'];
		$config['missing_policy'] = 'trash';
		$draft                    = $this->presets->update_draft(
			(int) $draft['id'],
			array(
				'revision' => $draft['revision'],
				'config'   => $config,
			)
		);

		$missing_confirmation = $this->controller->start_draft(
			$this->request( (int) $draft['id'], array( 'revision' => $draft['revision'] ) )
		);
		self::assertWPError( $missing_confirmation );
		self::assertSame( 'tds_importer_destructive_confirmation_required', $missing_confirmation->get_error_code() );

		$confirmed = $this->controller->start_draft(
			$this->request(
				(int) $draft['id'],
				array(
					'revision'               => $draft['revision'],
					'confirm_missing_policy' => 'trash',
				)
			)
		);
		self::assertWPError( $confirmed );
		self::assertSame( 'tds_importer_preflight_failed', $confirmed->get_error_code() );
	}

	public function test_preview_and_mapping_suggestions_are_strictly_bounded(): void {
		$headers = array();
		$values  = array();
		for ( $index = 1; $index <= 205; ++$index ) {
			$headers[] = 'field_' . $index;
			$values[]  = 1 === $index ? str_repeat( 'x', 600 ) : 'value';
		}
		$file                            = $this->source_file( implode( ',', $headers ) . "\n" . implode( ',', $values ) . "\n", 'csv' );
		$draft                           = $this->presets->create_draft();
		$config                          = $draft['config'];
		$config['source']['upload_path'] = $file;
		$draft                           = $this->presets->update_draft(
			(int) $draft['id'],
			array(
				'revision' => $draft['revision'],
				'config'   => $config,
			)
		);

		$preview = $this->response_data( $this->controller->source_preview( $this->request( (int) $draft['id'] ) ) );
		self::assertTrue( $preview['truncated'] );
		self::assertCount( 200, $preview['fields'] );
		self::assertSame( 512, mb_strlen( $preview['records'][0]['field_1'] ) );

		$fields      = array_map( static fn( int $index ): string => 'unknown_' . $index, range( 1, 200 ) );
		$fields[]    = 'sku';
		$suggestions = $this->response_data(
			$this->controller->mapping_suggestions( $this->request( (int) $draft['id'], array( 'fields' => $fields ) ) )
		);
		self::assertSame( array(), $suggestions );
	}

	public function test_upload_preview_streams_only_eight_mebibytes_and_reports_full_size(): void {
		$contents = "sku,name\nLIMIT-1," . str_repeat( 'x', SourceManager::MAX_PREVIEW_BYTES ) . "\n";
		$file     = $this->source_file( $contents, 'csv' );
		$preview  = ( new SourceManager( new SecretBox() ) )->preview(
			array(
				'source' => array(
					'type'        => 'upload',
					'upload_path' => $file,
				),
			)
		);
		$this->files[] = $preview['path'];

		self::assertTrue( $preview['truncated'] );
		self::assertSame( 'prefix', $preview['hash_scope'] );
		self::assertSame( strlen( $contents ), $preview['size'] );
		self::assertSame( SourceManager::MAX_PREVIEW_BYTES, filesize( $preview['path'] ) );
	}

	public function test_parsing_is_resumed_after_one_thousand_source_records(): void {
		$rows = array( 'sku,name' );
		for ( $index = 1; $index <= 1001; ++$index ) {
			$rows[] = 'CHECK-' . $index . ',Product ' . $index;
		}
		$file   = $this->source_file( implode( "\n", $rows ) . "\n", 'csv' );
		$preset = $this->presets->save(
			array(
				'name'   => 'Checkpoint import',
				'config' => array(
					'source'   => array(
						'type'        => 'upload',
						'upload_path' => $file,
					),
					'format'   => 'csv',
					'identity' => 'sku',
					'mappings' => array(
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
		$runner = $this->runner();

		$runner->run( $job_id );
		$runner->run( $job_id );
		$job = $this->jobs->find( $job_id );
		self::assertSame( 'parse', $job['phase'] );
		self::assertSame( 1000, $job['parse_cursor'] );
		self::assertSame( 1000, $job['staged_total'] );
		self::assertSame( 0, $job['total'] );

		$runner->run( $job_id );
		$job = $this->jobs->find( $job_id );
		self::assertSame( 'import', $job['phase'] );
		self::assertSame( 1001, $job['parse_cursor'] );
		self::assertSame( 1001, $job['staged_total'] );
		self::assertSame( 1001, $job['total'] );
	}

	public function test_staging_rejects_duplicate_hash_and_job_detail_adds_metrics(): void {
		$preset = $this->presets->save( array( 'name' => 'Metrics' ) );
		$job_id = $this->jobs->create( (int) $preset['id'] );
		$this->jobs->stage_checkpoint(
			$job_id,
			array(
				array(
					'sequence_no' => 1,
					'source_key'  => 'duplicate-1',
					'payload'     => array( 'sku' => 'duplicate-1' ),
				),
			),
			1,
			1
		);
		$this->jobs->update(
			$job_id,
			array(
				'phase'      => 'import',
				'total'      => 100,
				'processed'  => 50,
				'started_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
			)
		);
		for ( $index = 1; $index <= 6; ++$index ) {
			$this->jobs->log( $job_id, 'warning', 'Warning ' . $index );
		}
		$detail = $this->response_data( $this->controller->job_detail( $this->request( $job_id ) ) );
		self::assertSame( 'import', $detail['metrics']['current_phase'] );
		self::assertGreaterThan( 0, $detail['metrics']['records_per_minute'] );
		self::assertGreaterThan( 0, $detail['metrics']['eta_seconds'] );
		self::assertSame( 50.0, $detail['metrics']['progress_percent'] );
		self::assertCount( 5, $detail['recent_warnings'] );
		self::assertSame( 'Warning 2', $detail['recent_warnings'][0]['message'] );

		$this->expectException( \InvalidArgumentException::class );
		$this->jobs->stage_checkpoint(
			$job_id,
			array(
				array(
					'sequence_no' => 2,
					'source_key'  => 'DUPLICATE-1',
					'payload'     => array( 'sku' => 'DUPLICATE-1' ),
				),
			),
			2,
			2
		);
	}

	public function test_external_identity_survives_media_failure_without_duplicate_product(): void {
		$preset = $this->presets->save(
			array(
				'name'   => 'External retry',
				'config' => array( 'identity' => 'external_id' ),
			)
		);
		$job_id = $this->jobs->create( (int) $preset['id'] );
		$item   = array(
			'source_key' => 'EXTERNAL-RETRY-1',
			'record_type' => 'simple',
		);
		$fields = array(
			'external_id' => 'EXTERNAL-RETRY-1',
			'name'        => 'External retry product',
			'image'       => 'https://example.com/unavailable.jpg',
		);
		$block_http = static fn() => new \WP_Error( 'blocked_test_download', 'Expected media failure.' );
		add_filter( 'pre_http_request', $block_http, 10, 3 );
		$writer = new ProductWriter( $this->jobs );
		try {
			$writer->write( $fields, $item, $preset, $job_id );
			self::fail( 'The first media attempt should fail.' );
		} catch ( \RuntimeException $error ) {
			self::assertStringContainsString( 'Image download failed', $error->getMessage() );
		} finally {
			remove_filter( 'pre_http_request', $block_http, 10 );
		}

		$product_id = $this->jobs->linked_product( (int) $preset['id'], 'EXTERNAL-RETRY-1' );
		self::assertNotNull( $product_id );
		$retry = ( new ProductWriter( $this->jobs ) )->write(
			array(
				'external_id' => 'EXTERNAL-RETRY-1',
				'name'        => 'External retry product',
			),
			$item,
			$preset,
			$job_id
		);
		self::assertSame( $product_id, $retry['product_id'] );
		self::assertSame( 'created', $retry['result'] );
		self::assertCount(
			1,
			get_posts(
				array(
					'post_type'      => array( 'product', 'product_variation' ),
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => '_tds_import_external_id',
					'meta_value'     => 'EXTERNAL-RETRY-1',
				)
			)
		);

		$this->jobs->set_created_media( $job_id, $product_id, array( 111 ) );
		$this->jobs->set_created_media( $job_id, $product_id, array() );
		$snapshots = $this->jobs->snapshots( $job_id );
		self::assertSame( array( 111 ), json_decode( $snapshots[0]['created_media'], true ) );
	}

	public function test_attachment_hash_reuse_finds_inherit_attachments_across_writers(): void {
		$url           = 'https://EXAMPLE.com:443/media/reused.jpg#old';
		$normalized    = 'https://example.com/media/reused.jpg';
		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => 'Reusable test attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
			)
		);
		update_post_meta( $attachment_id, '_tds_import_source_hash', hash( 'sha256', $normalized ) );

		$method  = new \ReflectionMethod( ProductWriter::class, 'image_id' );
		$created = array();
		$first   = $method->invokeArgs( new ProductWriter( $this->jobs ), array( $url, 0, &$created ) );
		$second  = $method->invokeArgs( new ProductWriter( $this->jobs ), array( $normalized, 0, &$created ) );

		self::assertSame( $attachment_id, $first );
		self::assertSame( $attachment_id, $second );
		self::assertSame( array(), $created );
	}

	public function test_three_retries_and_item_completion_are_idempotent(): void {
		global $wpdb;
		$preset = $this->presets->save( array( 'name' => 'Retries' ) );
		$job_id = $this->jobs->create( (int) $preset['id'] );
		$this->jobs->stage_checkpoint(
			$job_id,
			array(
				array(
					'sequence_no' => 1,
					'source_key'  => 'RETRY-1',
					'payload'     => array( 'sku' => 'RETRY-1' ),
				),
			),
			1,
			1
		);
		$item_id = (int) $this->jobs->pending_items( $job_id, 1 )[0]['id'];

		self::assertTrue( $this->jobs->retry_item( $item_id, $job_id, 'network', 'Retry 1' ) );
		self::assertTrue( $this->jobs->retry_item( $item_id, $job_id, 'network', 'Retry 2' ) );
		self::assertTrue( $this->jobs->retry_item( $item_id, $job_id, 'network', 'Retry 3' ) );
		self::assertFalse( $this->jobs->retry_item( $item_id, $job_id, 'network', 'Final failure' ) );
		self::assertFalse( $this->jobs->retry_item( $item_id, $job_id, 'network', 'Duplicate completion' ) );

		$item = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status,attempts FROM {$this->database->table( 'items' )} WHERE id=%d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$item_id
			),
			ARRAY_A
		);
		$job  = $this->jobs->find( $job_id );
		self::assertSame( 'failed', $item['status'] );
		self::assertSame( '4', $item['attempts'] );
		self::assertSame( 1, $job['processed'] );
		self::assertSame( 1, $job['failed'] );
	}

	public function test_xml_draft_can_be_resumed_with_revision_and_disabled_schedule(): void {
		$active = $this->presets->save(
			array(
				'name'    => 'XML Vorlage',
				'enabled' => true,
				'config'  => array(
					'schedule' => array(
						'enabled' => true,
						'period'  => 'daily',
						'time'    => '03:00',
					),
				),
			)
		);
		$draft  = $this->presets->create_draft( (int) $active['id'] );
		self::assertFalse( $draft['enabled'] );
		self::assertFalse( $draft['config']['schedule']['enabled'] );
		self::assertSame( (int) $active['id'], $draft['parent_preset_id'] );

		$file                            = $this->source_file( '<catalog><product><sku>X-1</sku><name>XML Produkt</name></product></catalog>', 'xml' );
		$config                          = $draft['config'];
		$config['source']['upload_path'] = $file;
		$resumed                         = $this->presets->update_draft(
			(int) $draft['id'],
			array(
				'revision'    => $draft['revision'],
				'wizard_step' => 2,
				'config'      => $config,
			)
		);
		$reloaded                        = $this->presets->find( (int) $draft['id'], true );
		self::assertSame( 2, $reloaded['wizard_step'] );
		self::assertSame( $resumed['revision'], $reloaded['revision'] );

		$preview = $this->response_data( $this->controller->source_preview( $this->request( (int) $draft['id'] ) ) );
		self::assertSame( 'xml', $preview['format'] );
		self::assertSame( '/catalog/product', $preview['structure']['record_path'] );

		$this->expectException( ConflictException::class );
		$this->presets->update_draft(
			(int) $draft['id'],
			array(
				'revision'    => $draft['revision'],
				'wizard_step' => 3,
				'config'      => $config,
			)
		);
	}

	public function test_expired_draft_and_unreferenced_source_are_cleaned_up(): void {
		global $wpdb;
		$file   = $this->source_file( "sku,name\nOLD-1,Expired\n", 'csv' );
		$draft  = $this->presets->create_draft();
		$config = $draft['config'];
		$config['source']['upload_path'] = $file;
		$draft = $this->presets->update_draft(
			(int) $draft['id'],
			array(
				'revision' => $draft['revision'],
				'config'   => $config,
			)
		);
		$wpdb->update(
			$this->database->table( 'presets' ),
			array( 'expires_at' => '2000-01-01 00:00:00' ),
			array( 'id' => $draft['id'] )
		);

		( new Cleanup( $this->database, $this->presets ) )->run();

		self::assertNull( $this->presets->find( (int) $draft['id'] ) );
		self::assertFileDoesNotExist( $file );
	}

	public function test_shared_upload_is_deleted_only_after_its_last_draft_reference(): void {
		$file   = $this->source_file( "sku,name\nSHARED-1,Shared\n", 'csv' );
		$drafts = array();
		foreach ( array( 'First shared draft', 'Second shared draft' ) as $name ) {
			$draft                            = $this->presets->create_draft();
			$config                           = $draft['config'];
			$config['source']['upload_path']  = $file;
			$drafts[]                         = $this->presets->update_draft(
				(int) $draft['id'],
				array(
					'name'     => $name,
					'revision' => $draft['revision'],
					'config'   => $config,
				)
			);
		}

		$this->controller->delete_draft( $this->request( (int) $drafts[0]['id'] ) );
		self::assertFileExists( $file );
		$this->controller->delete_draft( $this->request( (int) $drafts[1]['id'] ) );
		self::assertFileDoesNotExist( $file );
	}

	public function test_stored_upload_paths_cannot_escape_the_protected_directory(): void {
		$outside       = trailingslashit( dirname( Installer::storage_dir() ) ) . 'tds-outside-' . wp_generate_uuid4() . '.csv';
		$traversal     = trailingslashit( Installer::storage_dir() ) . '../' . basename( $outside );
		$this->files[] = $outside;
		file_put_contents( $outside, "sku,name\nOUTSIDE-1,Outside\n" );

		self::assertNull( Installer::resolve_storage_file( $traversal ) );
		$draft                           = $this->presets->create_draft();
		$config                          = $draft['config'];
		$config['source']['upload_path'] = $traversal;
		$saved                           = $this->presets->update_draft(
			(int) $draft['id'],
			array(
				'revision' => $draft['revision'],
				'config'   => $config,
			)
		);
		self::assertSame( '', $saved['config']['source']['upload_path'] );
	}

	public function test_schema_migration_backfills_hashes_and_staged_totals(): void {
		global $wpdb;
		$preset = $this->presets->save( array( 'name' => 'Migration preset' ) );
		$job_id = $this->jobs->create( (int) $preset['id'] );
		$this->jobs->stage_checkpoint(
			$job_id,
			array(
				array(
					'sequence_no' => 1,
					'source_key'  => 'MIGRATE-1',
					'payload'     => array( 'sku' => 'MIGRATE-1' ),
				),
			),
			1,
			1
		);
		$wpdb->update( $this->database->table( 'items' ), array( 'source_key_hash' => null ), array( 'job_id' => $job_id ) );
		$wpdb->update( $this->database->table( 'jobs' ), array( 'staged_total' => 0 ), array( 'id' => $job_id ) );
		$duplicate_job = $this->jobs->create( (int) $preset['id'] );
		foreach ( array( 1, 2 ) as $sequence ) {
			$wpdb->insert(
				$this->database->table( 'items' ),
				array(
					'job_id'          => $duplicate_job,
					'sequence_no'     => $sequence,
					'record_type'     => 'simple',
					'source_key'      => 'LEGACY-DUPLICATE',
					'source_key_hash' => null,
					'payload'         => wp_json_encode( array( 'sku' => 'LEGACY-DUPLICATE' ) ),
					'status'          => 'pending',
					'updated_at'      => current_time( 'mysql', true ),
				)
			);
		}

		Installer::activate();

		$hash = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT source_key_hash FROM {$this->database->table( 'items' )} WHERE job_id=%d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$job_id
			)
		);
		self::assertSame( hash( 'sha256', 'migrate-1' ), $hash );
		self::assertSame( 1, $this->jobs->find( $job_id )['staged_total'] );
		$duplicate_counts = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total,COUNT(DISTINCT source_key_hash) AS unique_hashes,SUM(status='failed') AS failed FROM {$this->database->table( 'items' )} WHERE job_id=%d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$duplicate_job
			),
			ARRAY_A
		);
		self::assertSame( '2', $duplicate_counts['total'] );
		self::assertSame( '2', $duplicate_counts['unique_hashes'] );
		self::assertSame( '1', $duplicate_counts['failed'] );
		$migrated_job = $this->jobs->find( $duplicate_job );
		self::assertSame( 'failed', $migrated_job['status'] );
		self::assertNotEmpty( $migrated_job['rollback_until'] );
		foreach ( array( 'presets', 'jobs', 'items', 'links', 'snapshots', 'logs' ) as $suffix ) {
			$engine = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
					$this->database->table( $suffix )
				)
			);
			self::assertSame( 'InnoDB', $engine );
		}
	}

	public function test_failed_migration_does_not_advance_database_version(): void {
		global $wpdb;
		update_option( 'tds_importer_db_version', '1.0.0', false );
		$break_backfill = static function ( string $query ): string {
			return str_contains( $query, 'first_rows ON first_rows.first_id' )
				? 'UPDATE table_that_does_not_exist SET broken=1'
				: $query;
		};
		add_filter( 'query', $break_backfill );
		$previous_suppression = $wpdb->suppress_errors( true );
		try {
			Installer::activate();
			self::fail( 'A failed migration must throw.' );
		} catch ( \RuntimeException $error ) {
			self::assertStringContainsString( 'Unable to migrate importer data', $error->getMessage() );
			self::assertSame( '1.0.0', get_option( 'tds_importer_db_version' ) );
		} finally {
			remove_filter( 'query', $break_backfill );
			$wpdb->suppress_errors( $previous_suppression );
			Installer::activate();
		}
	}

	public function test_global_lock_preserves_job_order_and_completed_metrics_use_completion_time(): void {
		$preset = $this->presets->save( array( 'name' => 'Lock preset' ) );
		$older  = $this->jobs->create( (int) $preset['id'] );
		$newer  = $this->jobs->create( (int) $preset['id'] );

		self::assertTrue( $this->jobs->acquire_run_lock( $older ) );
		$this->jobs->release_run_lock();
		self::assertFalse( $this->jobs->acquire_run_lock( $newer ) );
		$this->jobs->update( $older, array( 'status' => 'completed' ) );
		self::assertTrue( $this->jobs->acquire_run_lock( $newer ) );
		$this->jobs->release_run_lock();
		$this->jobs->update( $newer, array( 'status' => 'completed' ) );

		$stale_older = $this->jobs->create( (int) $preset['id'] );
		$after_stale = $this->jobs->create( (int) $preset['id'] );
		$this->jobs->update(
			$stale_older,
			array(
				'status'      => 'running',
				'lease_until' => '2000-01-01 00:00:00',
			)
		);
		self::assertTrue( $this->jobs->acquire_run_lock( $after_stale ) );
		$this->jobs->release_run_lock();
		$recovered = $this->jobs->find( $stale_older );
		self::assertSame( 'failed', $recovered['status'] );
		self::assertNotEmpty( $recovered['rollback_until'] );

		$metrics = $this->jobs->metrics(
			array(
				'phase'        => 'complete',
				'total'        => 100,
				'processed'    => 100,
				'started_at'   => '2026-08-24 10:00:00',
				'completed_at' => '2026-08-24 10:02:00',
			)
		);
		self::assertSame( 120, $metrics['elapsed_seconds'] );
		self::assertSame( 100.0, $metrics['progress_percent'] );
		self::assertNull( $metrics['eta_seconds'] );
	}

	public function test_pause_is_rejected_during_rollback(): void {
		$preset = $this->presets->save( array( 'name' => 'Rollback preset' ) );
		$job_id = $this->jobs->create( (int) $preset['id'] );
		$this->jobs->update(
			$job_id,
			array(
				'status' => 'rollback',
				'phase'  => 'rollback',
			)
		);
		$response = $this->controller->control_job( $this->request( $job_id, array( 'action' => 'pause' ) ) );

		self::assertWPError( $response );
		self::assertSame( 400, $response->get_error_data()['status'] );
	}

	public function test_cancelled_jobs_receive_retention_deadline(): void {
		$preset = $this->presets->save(
			array(
				'name'   => 'Cancelled retention',
				'config' => array( 'retention_days' => 14 ),
			)
		);
		$job_id   = $this->jobs->create( (int) $preset['id'] );
		$response = $this->controller->control_job( $this->request( $job_id, array( 'action' => 'cancel' ) ) );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		$job = $this->jobs->find( $job_id );
		self::assertSame( 'cancelled', $job['status'] );
		self::assertNotEmpty( $job['rollback_until'] );
		self::assertEqualsWithDelta( time() + 14 * DAY_IN_SECONDS, strtotime( $job['rollback_until'] . ' UTC' ), 5 );
	}

	public function test_https_materialization_rejects_reported_and_streamed_overflow(): void {
		$limit = static fn(): int => 1048576;
		add_filter( 'tds_importer_max_source_bytes', $limit );
		$manager = new SourceManager( new SecretBox() );
		try {
			foreach ( array( 'reported', 'streamed' ) as $mode ) {
				$mock = static function ( mixed $preempt, array $arguments ) use ( $mode ): array {
					$bytes = 'streamed' === $mode ? 1048577 : 1;
					file_put_contents( (string) $arguments['filename'], str_repeat( 'x', $bytes ) );
					return array(
						'headers'  => array( 'content-length' => 'reported' === $mode ? 1048577 : 0 ),
						'body'     => '',
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'cookies'  => array(),
					);
				};
				add_filter( 'pre_http_request', $mock, 10, 2 );
				try {
					$manager->materialize(
						array(
							'source' => array(
								'type' => 'https',
								'url'  => 'https://example.com/oversized.csv',
							),
						)
					);
					self::fail( 'Oversized HTTPS source should be rejected.' );
				} catch ( \RuntimeException $error ) {
					self::assertStringContainsString( 'exceeds the configured size limit', $error->getMessage() );
				} finally {
					remove_filter( 'pre_http_request', $mock, 10 );
				}
			}
		} finally {
			remove_filter( 'tds_importer_max_source_bytes', $limit );
		}
	}

	private function source_file( string $contents, string $extension ): string {
		wp_mkdir_p( Installer::storage_dir() );
		$file = trailingslashit( Installer::storage_dir() ) . wp_generate_uuid4() . '.' . $extension;
		file_put_contents( $file, $contents );
		$this->files[] = $file;
		return $file;
	}

	private function runner(): JobRunner {
		return new JobRunner(
			$this->presets,
			$this->jobs,
			new SourceManager( new SecretBox() ),
			new ParserFactory(),
			new Mapper( new Evaluator() ),
			new ProductWriter( $this->jobs ),
			new RollbackService( $this->jobs )
		);
	}

	/**
	 * Create a REST request with route and JSON parameters.
	 *
	 * @param array<string,mixed> $body Body.
	 */
	private function request( int $id, array $body = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/tds-import/v1/wizard/drafts/' . $id );
		$request->set_url_params( array( 'id' => $id ) );
		$request->set_body_params( $body );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return $request;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function response_data( WP_REST_Response $response ): array {
		return (array) $response->get_data();
	}
}
