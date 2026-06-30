<?php
/**
 * Resumable import job state machine.
 *
 * @package TDS\ProductImporter
 */

namespace TDS\ProductImporter\Domain\Import;

use InvalidArgumentException;
use RuntimeException;
use TDS\ProductImporter\Domain\Parsing\ParserFactory;
use TDS\ProductImporter\Infrastructure\JobRepository;
use TDS\ProductImporter\Infrastructure\PresetRepository;
use TDS\ProductImporter\Infrastructure\SourceManager;

/**
 * Advances one import phase per background action.
 */
final class JobRunner {
	public function __construct(
		private PresetRepository $presets,
		private JobRepository $jobs,
		private SourceManager $sources,
		private ParserFactory $parsers,
		private Mapper $mapper,
		private ProductWriter $writer,
		private RollbackService $rollback
	) {}

	/**
	 * Register the worker hook.
	 */
	public function register(): void {
		add_action( 'tds_importer_run_job', array( $this, 'run' ) );
	}

	/**
	 * Run one bounded job step.
	 */
	public function run( int $job_id ): void {
		$job = $this->jobs->find( $job_id );
		if ( ! $job || in_array( $job['status'], array( 'paused', 'cancelled', 'completed', 'partial', 'failed', 'rolled_back' ), true ) ) {
			return;
		}
		if ( ! $this->jobs->can_run( $job_id ) ) {
			$this->enqueue( $job_id, 60 );
			return;
		}
		if ( 'rollback' === $job['phase'] ) {
			if ( ! $this->rollback->process( $job_id ) ) {
				$this->enqueue( $job_id );
			}
			return;
		}
		$preset = $this->presets->find( (int) $job['preset_id'] );
		if ( ! $preset ) {
			$this->fail( $job_id, 'Preset no longer exists.' );
			return;
		}

		try {
			$this->jobs->update(
				$job_id,
				array(
					'status'     => 'running',
					'started_at' => $job['started_at'] ?: current_time( 'mysql', true ),
				)
			);
			match ( $job['phase'] ) {
				'fetch' => $this->fetch( $job_id, $preset ),
				'parse' => $this->parse( $job_id, $preset, $job ),
				'import' => $this->import( $job_id, $preset ),
				'relationships' => $this->relationships( $job_id, $preset ),
				'missing' => $this->missing( $job_id, $preset ),
				default => throw new RuntimeException( 'Unknown import phase.' ),
			};
		} catch ( \Throwable $error ) {
			$this->fail( $job_id, $error->getMessage() );
		}
	}

	/**
	 * Download or copy the job source.
	 *
	 * @param array<string,mixed> $preset Preset.
	 */
	private function fetch( int $job_id, array $preset ): void {
		$path = $this->sources->materialize( (array) $preset['config'] );
		$this->jobs->update(
			$job_id,
			array(
				'phase'       => 'parse',
				'source_path' => $path,
				'source_hash' => hash_file( 'sha256', $path ),
			)
		);
		$this->jobs->log( $job_id, 'info', 'Source snapshot created.', 'source_ready', array( 'size' => filesize( $path ) ) );
		$this->enqueue( $job_id );
	}

	/**
	 * Stream and stage the source file.
	 *
	 * @param array<string,mixed> $preset Preset.
	 * @param array<string,mixed> $job    Job.
	 */
	private function parse( int $job_id, array $preset, array $job ): void {
		$config = (array) $preset['config'];
		$errors = $this->mapper->validate( $config );
		if ( $errors ) {
			throw new InvalidArgumentException( implode( ' ', $errors ) );
		}
		$parser = $this->parsers->create( (string) $job['source_path'], $config );
		$chunk  = array();
		$seen   = array();
		$count  = 0;
		foreach ( $parser->records( (string) $job['source_path'], $config ) as $source_sequence => $payload ) {
			$records = array(
				array(
					'payload' => $payload,
					'parent'  => null,
					'type'    => null,
				),
			);
			foreach ( $payload as $field => $value ) {
				if ( is_array( $value ) && preg_match( '/(?:variations?|variants?)(?:\.|$)/i', (string) $field )
					&& $value && count( array_filter( $value, 'is_array' ) ) === count( $value ) ) {
					foreach ( $value as $variation ) {
						$records[] = array(
							'payload' => array_merge( $payload, $variation ),
							'parent'  => true,
							'type'    => 'variation',
						);
					}
					unset( $records[0]['payload'][ $field ] );
				}
			}
			$parent_key = null;
			foreach ( $records as $record ) {
				$mapped = $this->mapper->map( $record['payload'], $config );
				$key    = trim( (string) ( $mapped[ 'external_id' === $config['identity'] ? 'external_id' : 'sku' ] ?? '' ) );
				if ( '' === $key ) {
					throw new InvalidArgumentException( "Source record $source_sequence has no product identifier." );
				}
				if ( mb_strlen( $key ) > 190 ) {
					throw new InvalidArgumentException( "Source identifier in record $source_sequence exceeds 190 characters." );
				}
				if ( isset( $seen[ $key ] ) ) {
					throw new InvalidArgumentException( "Duplicate source identifier '$key'." );
				}
				$seen[ $key ] = true;
				$type         = $record['type'] ?: sanitize_key( (string) ( $mapped['type'] ?? $record['payload'][ $config['type_field'] ] ?? 'simple' ) );
				if ( null === $parent_key ) {
					$parent_key = $key;
				}
				$chunk[] = array(
					'sequence_no' => $count + 1,
					'record_type' => $type,
					'source_key'  => $key,
					'parent_key'  => $record['parent'] ? $parent_key : (string) ( $mapped['parent'] ?? $record['payload'][ $config['parent_field'] ] ?? '' ),
					'payload'     => $record['payload'],
				);
				++$count;
				if ( count( $chunk ) >= 200 ) {
					$this->jobs->stage( $job_id, $chunk );
					$chunk = array();
				}
			}
		}
		if ( $chunk ) {
			$this->jobs->stage( $job_id, $chunk );
		}
		if ( 0 === $count ) {
			throw new InvalidArgumentException( 'The source contains no product records.' );
		}
		$this->jobs->update(
			$job_id,
			array(
				'phase' => 'import',
				'total' => $count,
			)
		);
		$this->jobs->log( $job_id, 'info', "$count source records staged.", 'source_parsed' );
		$this->enqueue( $job_id );
	}

	/**
	 * Process a time- and memory-bounded product batch.
	 *
	 * @param array<string,mixed> $preset Preset.
	 */
	private function import( int $job_id, array $preset ): void {
		$config  = (array) $preset['config'];
		$items   = $this->jobs->pending_items( $job_id, (int) ( $config['batch_size'] ?? 50 ) );
		$started = microtime( true );
		$limit   = $this->memory_limit();
		foreach ( $items as $item ) {
			try {
				$fields = $this->mapper->map( (array) $item['payload'], $config );
				$result = $this->writer->write( $fields, $item, $preset, $job_id );
				$this->jobs->complete_item( (int) $item['id'], $job_id, $result['result'], $result['product_id'] );
			} catch ( RuntimeException $error ) {
				$this->jobs->retry_item( (int) $item['id'], $job_id, 'transient_error', $error->getMessage() );
				$this->jobs->log( $job_id, 'warning', $error->getMessage(), 'transient_error', array( 'record' => $item['sequence_no'] ) );
			} catch ( \Throwable $error ) {
				$this->jobs->complete_item( (int) $item['id'], $job_id, 'failed', null, 'record_error', $error->getMessage() );
				$this->jobs->log( $job_id, 'error', $error->getMessage(), 'record_error', array( 'record' => $item['sequence_no'] ) );
			}
			if ( microtime( true ) - $started >= 20 || ( $limit > 0 && memory_get_usage( true ) >= (int) ( $limit * 0.7 ) ) ) {
				break;
			}
		}
		if ( $this->jobs->pending_items( $job_id, 1 ) ) {
			$this->enqueue( $job_id );
			return;
		}
		$this->jobs->update( $job_id, array( 'phase' => 'relationships' ) );
		$this->enqueue( $job_id );
	}

	/**
	 * Resolve cross-record relationships in a second pass.
	 *
	 * @param array<string,mixed> $preset Preset.
	 */
	private function relationships( int $job_id, array $preset ): void {
		$config = (array) $preset['config'];
		$items  = $this->jobs->relationship_items( $job_id, (int) ( $config['batch_size'] ?? 50 ) );
		foreach ( $items as $item ) {
			try {
				$fields = $this->mapper->map( (array) $item['payload'], $config );
				$this->writer->apply_relationships( (int) $item['product_id'], $fields, (int) $preset['id'], $job_id );
				$this->jobs->complete_relationships( (int) $item['id'] );
			} catch ( \Throwable $error ) {
				$this->jobs->complete_relationships( (int) $item['id'], true, $error->getMessage() );
				$this->jobs->log( $job_id, 'error', $error->getMessage(), 'relationship_error', array( 'record' => $item['sequence_no'] ) );
			}
		}
		if ( $this->jobs->relationship_items( $job_id, 1 ) ) {
			$this->enqueue( $job_id );
			return;
		}
		$job = $this->jobs->find( $job_id );
		if ( ! $job || $job['failed'] > 0 ) {
			$this->finish( $job_id, true );
			return;
		}
		$this->jobs->update( $job_id, array( 'phase' => 'missing' ) );
		$this->enqueue( $job_id );
	}

	/**
	 * Apply the configured missing-product lifecycle action.
	 *
	 * @param array<string,mixed> $preset Preset.
	 */
	private function missing( int $job_id, array $preset ): void {
		$policy = (string) ( $preset['config']['missing_policy'] ?? 'keep' );
		if ( 'keep' === $policy ) {
			$this->finish( $job_id, false );
			return;
		}
		$links = $this->jobs->missing_links( (int) $preset['id'], $job_id, 50 );
		foreach ( $links as $link ) {
			if ( $this->jobs->has_other_preset_link( (int) $preset['id'], $link['product_id'] ) ) {
				$this->jobs->log( $job_id, 'warning', "Product {$link['product_id']} is linked to another preset; missing action skipped.", 'missing_conflict' );
				$this->jobs->link( (int) $preset['id'], $link['source_key'], $link['product_id'], $job_id );
				continue;
			}
			$product = wc_get_product( $link['product_id'] );
			if ( ! $product ) {
				$this->jobs->link( (int) $preset['id'], $link['source_key'], $link['product_id'], $job_id );
				continue;
			}
			$this->jobs->snapshot( $job_id, $product->get_id(), false, ProductWriter::capture( $product->get_id() ) );
			match ( $policy ) {
				'draft' => $product->set_status( 'draft' ),
				'outofstock' => $product->set_stock_status( 'outofstock' ),
				'trash' => wp_trash_post( $product->get_id() ),
				default => null,
			};
			if ( 'trash' !== $policy ) {
				$product->save();
			}
			$this->jobs->set_snapshot_fingerprint( $job_id, $product->get_id(), ProductWriter::fingerprint( $product->get_id() ) );
			$this->jobs->link( (int) $preset['id'], $link['source_key'], $link['product_id'], $job_id );
		}
		if ( count( $links ) >= 50 ) {
			$this->enqueue( $job_id );
			return;
		}
		$this->finish( $job_id, false );
	}

	/**
	 * Finish and optionally notify on errors.
	 */
	private function finish( int $job_id, bool $partial ): void {
		$job = $this->jobs->find( $job_id );
		if ( ! $job ) {
			return;
		}
		$preset = $this->presets->find( (int) $job['preset_id'] );
		$days   = (int) ( $preset['config']['retention_days'] ?? 30 );
		$status = $partial ? 'partial' : 'completed';
		$this->jobs->update(
			$job_id,
			array(
				'status'         => $status,
				'phase'          => 'complete',
				'completed_at'   => current_time( 'mysql', true ),
				'rollback_until' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS * $days ),
				'message'        => $partial ? 'Completed with record errors. Missing-product actions were skipped.' : 'Import completed.',
			)
		);
		do_action( 'tds_importer_job_completed', $job_id, $status );
		if ( $partial && ! empty( $preset['config']['email'] ) ) {
			wp_mail(
				(string) $preset['config']['email'],
				sprintf( '[%s] TDS import %d completed with errors', wp_specialchars_decode( get_bloginfo( 'name' ) ), $job_id ),
				sprintf( 'Import %d completed with %d failed records. Review WooCommerce > TDS Import > Jobs.', $job_id, $job['failed'] )
			);
		}
	}

	/**
	 * Mark a fatal job error and send scheduled-run notification.
	 */
	private function fail( int $job_id, string $message ): void {
		$this->jobs->update(
			$job_id,
			array(
				'status'       => 'failed',
				'completed_at' => current_time( 'mysql', true ),
				'message'      => $message,
			)
		);
		$this->jobs->log( $job_id, 'error', $message, 'job_failed' );
		$job    = $this->jobs->find( $job_id );
		$preset = $job ? $this->presets->find( (int) $job['preset_id'] ) : null;
		if ( $preset && ! empty( $preset['config']['email'] ) ) {
			wp_mail(
				(string) $preset['config']['email'],
				sprintf( '[%s] TDS import %d failed', wp_specialchars_decode( get_bloginfo( 'name' ) ), $job_id ),
				$message
			);
		}
	}

	/**
	 * Queue the next worker tick.
	 */
	public function enqueue( int $job_id, int $delay = 0 ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, 'tds_importer_run_job', array( $job_id ), 'tds-importer', false );
		} else {
			wp_schedule_single_event( time() + max( 1, $delay ), 'tds_importer_run_job', array( $job_id ) );
		}
	}

	/**
	 * Convert PHP memory_limit to bytes.
	 */
	private function memory_limit(): int {
		$value = ini_get( 'memory_limit' );
		if ( false === $value || '-1' === $value ) {
			return -1;
		}
		$unit = strtolower( substr( $value, -1 ) );
		$size = (int) $value;
		return $size * match ( $unit ) {
			'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
	}
}
