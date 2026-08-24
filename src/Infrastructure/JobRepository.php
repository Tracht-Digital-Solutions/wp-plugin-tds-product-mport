<?php
/**
 * Import job persistence.
 *
 * @package TDS\ProductImporter
 */

namespace TDS\ProductImporter\Infrastructure;

use InvalidArgumentException;
use RuntimeException;

/**
 * Provides all job, staging, link, and snapshot database operations.
 */
final class JobRepository {
	private const LEASE_SECONDS = 600;

	public function __construct( private Database $database ) {}

	/**
	 * Create a queued job.
	 */
	public function create( int $preset_id, bool $scheduled = false ): int {
		global $wpdb;
		$now     = current_time( 'mysql', true );
		$created = $wpdb->insert(
			$this->database->table( 'jobs' ),
			array(
				'preset_id'    => $preset_id,
				'status'       => 'queued',
				'phase'        => 'fetch',
				'is_scheduled' => $scheduled ? 1 : 0,
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);
		if ( false === $created || $wpdb->insert_id < 1 ) {
			throw new RuntimeException( 'Unable to create the import job.' );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Find a job.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->database->table( 'jobs' )} WHERE id=%d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			),
			ARRAY_A
		);
		return $row ? $this->cast_job( $row ) : null;
	}

	/**
	 * List recent jobs.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function recent( int $limit = 100 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT j.*, p.name AS preset_name FROM {$this->database->table( 'jobs' )} j LEFT JOIN {$this->database->table( 'presets' )} p ON p.id=j.preset_id ORDER BY j.id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			),
			ARRAY_A
		);
		return array_map( fn( array $row ): array => $this->cast_job( $row ), $rows ?: array() );
	}

	/**
	 * Update allowed job fields.
	 *
	 * @param array<string,mixed> $changes Changes.
	 */
	public function update( int $id, array $changes ): void {
		global $wpdb;
		$allowed = array(
			'status',
			'phase',
			'source_path',
			'source_hash',
			'parse_cursor',
			'staged_total',
			'total',
			'processed',
			'created',
			'updated',
			'skipped',
			'failed',
			'message',
			'started_at',
			'completed_at',
			'rollback_until',
			'lease_until',
		);
		$data    = array_intersect_key( $changes, array_flip( $allowed ) );
		if ( ! $data ) {
			return;
		}
		$data['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( $this->database->table( 'jobs' ), $data, array( 'id' => $id ) );
	}

	/**
	 * Claim the global import lock.
	 *
	 * @phpstan-impure Database state can change between the two ordering checks.
	 */
	public function can_run( int $id ): bool {
		global $wpdb;
		$now   = current_time( 'mysql', true );
		$older = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->database->table( 'jobs' )} WHERE id<>%d AND id<%d AND (status='queued' OR (status IN ('running','rollback') AND lease_until IS NOT NULL AND lease_until>=%s))", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id,
				$id,
				$now
			)
		);
		return 0 === $older;
	}

	/**
	 * Acquire the connection-scoped global writer lock while preserving job order.
	 */
	public function acquire_run_lock( int $id ): bool {
		global $wpdb;
		if ( ! $this->can_run( $id ) ) {
			return false;
		}
		$acquired = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $this->run_lock_name() )
		);
		if ( 1 !== $acquired ) {
			return false;
		}
		try {
			$this->fail_stale_older_jobs( $id );
			if ( $this->can_run( $id ) ) {
				$updated = $wpdb->update(
					$this->database->table( 'jobs' ),
					array(
						'lease_until' => gmdate( 'Y-m-d H:i:s', time() + self::LEASE_SECONDS ),
						'updated_at'  => current_time( 'mysql', true ),
					),
					array( 'id' => $id )
				);
				if ( false !== $updated ) {
					return true;
				}
			}
		} catch ( \Throwable $error ) {
			$this->release_run_lock();
			throw $error;
		}
		$this->release_run_lock();
		return false;
	}

	/**
	 * Terminally fail abandoned workers while holding the advisory lock.
	 */
	private function fail_stale_older_jobs( int $id ): void {
		global $wpdb;
		$now    = current_time( 'mysql', true );
		$expiry = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->database->table( 'jobs' )} SET status='failed',completed_at=COALESCE(completed_at,%s),rollback_until=COALESCE(rollback_until,%s),lease_until=NULL,message='Worker lease expired before the job completed.',updated_at=%s WHERE id<%d AND status IN ('running','rollback') AND (lease_until IS NULL OR lease_until<%s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now,
				$expiry,
				$now,
				$id,
				$now
			)
		);
		if ( false === $result ) {
			throw new RuntimeException( 'Unable to recover abandoned import workers.' );
		}
	}

	/**
	 * Release the connection-scoped global writer lock.
	 */
	public function release_run_lock(): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->run_lock_name() ) );
	}

	/**
	 * Scope the advisory lock to this WordPress database and table prefix.
	 */
	private function run_lock_name(): string {
		global $wpdb;
		$scope = (string) $wpdb->dbname . '|' . $this->database->table( 'jobs' );
		return 'tds_import_' . substr( hash( 'sha256', $scope ), 0, 40 );
	}

	/**
	 * Bulk-stage parsed records.
	 *
	 * @param array<int,array<string,mixed>> $records Records.
	 */
	public function stage( int $job_id, array $records ): void {
		$job = $this->find( $job_id );
		if ( ! $job ) {
			throw new InvalidArgumentException( 'Import job not found.' );
		}
		$this->stage_checkpoint(
			$job_id,
			$records,
			(int) $job['parse_cursor'],
			(int) $job['staged_total'] + count( $records )
		);
	}

	/**
	 * Atomically stage records and persist the source cursor.
	 *
	 * @param array<int,array<string,mixed>> $records Records.
	 */
	public function stage_checkpoint( int $job_id, array $records, int $parse_cursor, int $staged_total ): void {
		global $wpdb;
		$table  = $this->database->table( 'items' );
		$now    = current_time( 'mysql', true );
		$hashes = array();
		foreach ( $records as $record ) {
			$key  = (string) ( $record['source_key'] ?? '' );
			$hash = $this->source_key_hash( $key );
			if ( isset( $hashes[ $hash ] ) ) {
				throw new InvalidArgumentException( "Duplicate source identifier '$key'." );
			}
			$hashes[ $hash ] = $key;
		}
		$wpdb->query( 'START TRANSACTION' );
		try {
			$this->assert_new_source_hashes( $job_id, array_keys( $hashes ), $hashes );
			foreach ( array_chunk( $records, 200 ) as $chunk ) {
				$values = array();
				$args   = array();
				foreach ( $chunk as $record ) {
					$key      = (string) ( $record['source_key'] ?? '' );
					$values[] = '(%d,%d,%s,%s,%s,%s,%s,%s,%s)';
					array_push(
						$args,
						$job_id,
						(int) $record['sequence_no'],
						(string) ( $record['record_type'] ?? 'simple' ),
						$key,
						$this->source_key_hash( $key ),
						(string) ( $record['parent_key'] ?? '' ),
						wp_json_encode( $record['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
						'pending',
						$now
					);
				}
				$sql    = "INSERT INTO $table (job_id,sequence_no,record_type,source_key,source_key_hash,parent_key,payload,status,updated_at) VALUES " . implode( ',', $values );
				$result = $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				if ( false === $result ) {
					throw new RuntimeException( 'Unable to stage import records: ' . $wpdb->last_error );
				}
			}
			$result = $wpdb->update(
				$this->database->table( 'jobs' ),
				array(
					'parse_cursor' => $parse_cursor,
					'staged_total' => $staged_total,
					'updated_at'   => $now,
				),
				array( 'id' => $job_id )
			);
			if ( false === $result ) {
				throw new RuntimeException( 'Unable to save the import parsing checkpoint.' );
			}
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/**
	 * Reject identifiers already staged by an earlier source record.
	 *
	 * @param string[]            $hashes Hashes to check.
	 * @param array<string,string> $keys  Hash-to-key map.
	 */
	private function assert_new_source_hashes( int $job_id, array $hashes, array $keys ): void {
		global $wpdb;
		$table = $this->database->table( 'items' );
		foreach ( array_chunk( $hashes, 200 ) as $chunk ) {
			if ( ! $chunk ) {
				continue;
			}
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$args         = array_merge( array( $job_id ), $chunk );
			$sql          = "SELECT source_key_hash FROM $table WHERE job_id=%d AND source_key_hash IN ($placeholders) LIMIT 1";
			$existing     = $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			if ( is_string( $existing ) && isset( $keys[ $existing ] ) ) {
				throw new InvalidArgumentException( "Duplicate source identifier '{$keys[ $existing ]}'." );
			}
		}
	}

	/**
	 * Match WooCommerce and the default WordPress database collation for keys.
	 */
	private function source_key_hash( string $key ): string {
		return hash( 'sha256', mb_strtolower( trim( $key ), 'UTF-8' ) );
	}

	/**
	 * Get the next pending item batch.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function pending_items( int $job_id, int $limit ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->database->table( 'items' )} WHERE job_id=%d AND status='pending' ORDER BY CASE WHEN record_type='variation' THEN 1 ELSE 0 END, id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$job_id,
				$limit
			),
			ARRAY_A
		);
		return array_map(
			static function ( array $row ): array {
				$row['id']         = (int) $row['id'];
				$row['payload']    = json_decode( (string) $row['payload'], true ) ?: array();
				$row['product_id'] = $row['product_id'] ? (int) $row['product_id'] : null;
				return $row;
			},
			$rows ?: array()
		);
	}

	/**
	 * Return completed products awaiting the relationship pass.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function relationship_items( int $job_id, int $limit ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id,payload,product_id,sequence_no FROM {$this->database->table( 'items' )} WHERE job_id=%d AND status='completed' AND product_id IS NOT NULL ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$job_id,
				$limit
			),
			ARRAY_A
		);
		return array_map(
			static function ( array $row ): array {
				$row['id']         = (int) $row['id'];
				$row['product_id'] = (int) $row['product_id'];
				$row['payload']    = json_decode( (string) $row['payload'], true ) ?: array();
				return $row;
			},
			$rows ?: array()
		);
	}

	/**
	 * Mark an item relationship pass complete.
	 */
	public function complete_relationships( int $item_id, bool $failed = false, ?string $message = null ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$this->begin_transaction();
		try {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT job_id,status FROM {$this->database->table( 'items' )} WHERE id=%d FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$item_id
				),
				ARRAY_A
			);
			if ( ! $row ) {
				throw new RuntimeException( 'Relationship staging item not found.' );
			}
			if ( 'completed' !== $row['status'] ) {
				$this->commit_transaction();
				return;
			}
			$result = $wpdb->update(
				$this->database->table( 'items' ),
				array(
					'status'        => $failed ? 'relation_failed' : 'related',
					'error_code'    => $failed ? 'relationship_error' : null,
					'error_message' => $message,
					'updated_at'    => $now,
				),
				array(
					'id'     => $item_id,
					'status' => 'completed',
				)
			);
			if ( 1 !== $result ) {
				throw new RuntimeException( 'Unable to complete product relationships.' );
			}
			if ( $failed ) {
				$result = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$this->database->table( 'jobs' )} SET failed=failed+1,updated_at=%s WHERE id=%d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$now,
						(int) $row['job_id']
					)
				);
				if ( 1 !== $result ) {
					throw new RuntimeException( 'Unable to update relationship error counters.' );
				}
			}
			$this->commit_transaction();
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/**
	 * Mark an item result and update aggregate counters.
	 */
	public function complete_item( int $item_id, int $job_id, string $result, ?int $product_id = null, ?string $code = null, ?string $message = null ): void {
		global $wpdb;
		$status  = 'failed' === $result ? 'failed' : 'completed';
		$counter = in_array( $result, array( 'created', 'updated', 'skipped', 'failed' ), true ) ? $result : 'skipped';
		$now     = current_time( 'mysql', true );
		$this->begin_transaction();
		try {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT status,attempts FROM {$this->database->table( 'items' )} WHERE id=%d AND job_id=%d FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$item_id,
					$job_id
				),
				ARRAY_A
			);
			if ( ! $row ) {
				throw new RuntimeException( 'Import staging item not found.' );
			}
			if ( 'pending' !== $row['status'] ) {
				$this->commit_transaction();
				return;
			}
			$updated = $wpdb->update(
				$this->database->table( 'items' ),
				array(
					'status'        => $status,
					'product_id'    => $product_id,
					'attempts'      => (int) $row['attempts'] + 1,
					'error_code'    => $code,
					'error_message' => $message,
					'updated_at'    => $now,
				),
				array(
					'id'     => $item_id,
					'job_id' => $job_id,
					'status' => 'pending',
				)
			);
			if ( 1 !== $updated ) {
				throw new RuntimeException( 'Unable to complete the import item.' );
			}
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->database->table( 'jobs' )} SET processed=processed+1, $counter=$counter+1, updated_at=%s WHERE id=%d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$now,
					$job_id
				)
			);
			if ( 1 !== $updated ) {
				throw new RuntimeException( 'Unable to update import counters.' );
			}
			$this->commit_transaction();
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/**
	 * Retry a transient item failure up to three times.
	 */
	public function retry_item( int $item_id, int $job_id, string $code, string $message ): bool {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$this->begin_transaction();
		try {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT status,attempts FROM {$this->database->table( 'items' )} WHERE id=%d AND job_id=%d FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$item_id,
					$job_id
				),
				ARRAY_A
			);
			if ( ! $row || 'pending' !== $row['status'] ) {
				$this->commit_transaction();
				return false;
			}
			$attempts = (int) $row['attempts'] + 1;
			$final    = $attempts >= 4;
			$updated  = $wpdb->update(
				$this->database->table( 'items' ),
				array(
					'status'        => $final ? 'failed' : 'pending',
					'attempts'      => $attempts,
					'error_code'    => $code,
					'error_message' => $message,
					'updated_at'    => $now,
				),
				array(
					'id'     => $item_id,
					'job_id' => $job_id,
					'status' => 'pending',
				)
			);
			if ( 1 !== $updated ) {
				throw new RuntimeException( 'Unable to save the retry state.' );
			}
			if ( $final ) {
				$updated = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$this->database->table( 'jobs' )} SET processed=processed+1,failed=failed+1,updated_at=%s WHERE id=%d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$now,
						$job_id
					)
				);
				if ( 1 !== $updated ) {
					throw new RuntimeException( 'Unable to update exhausted retry counters.' );
				}
			}
			$this->commit_transaction();
			return ! $final;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	private function begin_transaction(): void {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new RuntimeException( 'Unable to start the import transaction.' );
		}
	}

	private function commit_transaction(): void {
		global $wpdb;
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			throw new RuntimeException( 'Unable to commit the import transaction.' );
		}
	}

	/**
	 * Link a source key to a product.
	 */
	public function link( int $preset_id, string $key, int $product_id, int $job_id ): void {
		global $wpdb;
		$wpdb->replace(
			$this->database->table( 'links' ),
			array(
				'preset_id'     => $preset_id,
				'source_key'    => $key,
				'product_id'    => $product_id,
				'last_seen_job' => $job_id,
				'updated_at'    => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Resolve a source link.
	 */
	public function linked_product( int $preset_id, string $key ): ?int {
		global $wpdb;
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT product_id FROM {$this->database->table( 'links' )} WHERE preset_id=%d AND source_key=%s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$preset_id,
				$key
			)
		);
		return $id ? (int) $id : null;
	}

	/**
	 * Return products not seen by a successful full job.
	 *
	 * @return int[]
	 */
	public function missing_products( int $preset_id, int $job_id ): array {
		global $wpdb;
		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT product_id FROM {$this->database->table( 'links' )} WHERE preset_id=%d AND last_seen_job<>%d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$preset_id,
					$job_id
				)
			)
		);
	}

	/**
	 * Return a bounded batch of unseen source links.
	 *
	 * @return array<int,array{source_key:string,product_id:int}>
	 */
	public function missing_links( int $preset_id, int $job_id, int $limit ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_key,product_id FROM {$this->database->table( 'links' )} WHERE preset_id=%d AND last_seen_job<>%d ORDER BY source_key LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$preset_id,
				$job_id,
				$limit
			),
			ARRAY_A
		);
		return array_map(
			static fn( array $row ): array => array(
				'source_key' => $row['source_key'],
				'product_id' => (int) $row['product_id'],
			),
			$rows ?: array()
		);
	}

	/**
	 * Determine whether another active preset also manages a product.
	 */
	public function has_other_preset_link( int $preset_id, int $product_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$this->database->table( 'links' )} WHERE preset_id<>%d AND product_id=%d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$preset_id,
				$product_id
			)
		);
	}

	/**
	 * Persist a rollback snapshot once.
	 *
	 * @param array<string,mixed>|null $snapshot Product snapshot.
	 * @param int[]                    $media    Created attachments.
	 */
	public function snapshot( int $job_id, int $product_id, bool $created, ?array $snapshot, array $media = array() ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$this->database->table( 'snapshots' )} (job_id,product_id,is_created,snapshot,created_media,created_at) VALUES (%d,%d,%d,%s,%s,%s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$job_id,
				$product_id,
				$created ? 1 : 0,
				$snapshot ? wp_json_encode( $snapshot ) : null,
				wp_json_encode( $media ),
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * Set the post-import fingerprint.
	 */
	public function set_snapshot_fingerprint( int $job_id, int $product_id, string $fingerprint ): void {
		global $wpdb;
		$wpdb->update(
			$this->database->table( 'snapshots' ),
			array( 'post_fingerprint' => $fingerprint ),
			array(
				'job_id'     => $job_id,
				'product_id' => $product_id,
			)
		);
	}

	/**
	 * Record media created while importing a product.
	 *
	 * @param int[] $media Attachment IDs.
	 */
	public function set_created_media( int $job_id, int $product_id, array $media ): void {
		global $wpdb;
		$this->begin_transaction();
		try {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT created_media FROM {$this->database->table( 'snapshots' )} WHERE job_id=%d AND product_id=%d FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$job_id,
					$product_id
				),
				ARRAY_A
			);
			if ( ! $row ) {
				throw new RuntimeException( 'Rollback snapshot not found for imported media.' );
			}
			$existing = json_decode( (string) ( $row['created_media'] ?? '' ), true );
			$merged   = array_values( array_unique( array_map( 'intval', array_merge( is_array( $existing ) ? $existing : array(), $media ) ) ) );
			$result   = $wpdb->update(
				$this->database->table( 'snapshots' ),
				array( 'created_media' => wp_json_encode( $merged ) ),
				array(
					'job_id'     => $job_id,
					'product_id' => $product_id,
				)
			);
			if ( false === $result ) {
				throw new RuntimeException( 'Unable to checkpoint imported media.' );
			}
			$this->commit_transaction();
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/**
	 * Return rollback snapshots.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function snapshots( int $job_id, int $limit = 50 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->database->table( 'snapshots' )} WHERE job_id=%d AND rolled_back_at IS NULL ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$job_id,
				$limit
			),
			ARRAY_A
		);
		return $rows ?: array();
	}

	/**
	 * Mark a snapshot restored.
	 */
	public function mark_rolled_back( int $snapshot_id ): void {
		global $wpdb;
		$wpdb->update(
			$this->database->table( 'snapshots' ),
			array( 'rolled_back_at' => current_time( 'mysql', true ) ),
			array( 'id' => $snapshot_id )
		);
	}

	/**
	 * Add a structured log entry.
	 *
	 * @param array<string,mixed> $context Context.
	 */
	public function log( int $job_id, string $level, string $message, ?string $code = null, array $context = array() ): void {
		global $wpdb;
		$wpdb->insert(
			$this->database->table( 'logs' ),
			array(
				'job_id'     => $job_id,
				'level'      => sanitize_key( $level ),
				'code'       => $code ? sanitize_key( $code ) : null,
				'message'    => $message,
				'context'    => $context ? wp_json_encode( $context ) : null,
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Fetch job logs.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function logs( int $job_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->database->table( 'logs' )} WHERE job_id=%d ORDER BY id ASC LIMIT 5000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$job_id
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Fetch the most recent job log entries in chronological order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function recent_logs( int $job_id, int $limit = 10 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->database->table( 'logs' )} WHERE job_id=%d ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$job_id,
				$limit
			),
			ARRAY_A
		) ?: array();
		return array_reverse( $rows );
	}

	/**
	 * Fetch the most recent warning and error entries in chronological order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function recent_warnings( int $job_id, int $limit = 5 ): array {
		global $wpdb;
		$limit = max( 1, min( 20, $limit ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->database->table( 'logs' )} WHERE job_id=%d AND level IN ('warning','error') ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$job_id,
				$limit
			),
			ARRAY_A
		) ?: array();
		return array_reverse( $rows );
	}

	/**
	 * Calculate additive live metrics for a job response.
	 *
	 * @param array<string,mixed> $job Job row.
	 * @return array<string,int|float|string|null>
	 */
	public function metrics( array $job ): array {
		$started = ! empty( $job['started_at'] ) ? strtotime( (string) $job['started_at'] . ' UTC' ) : false;
		$ended   = ! empty( $job['completed_at'] ) ? strtotime( (string) $job['completed_at'] . ' UTC' ) : false;
		$elapsed = false === $started ? 0 : max( 0, ( false === $ended ? time() : $ended ) - $started );
		$total   = max( 0, (int) ( $job['total'] ?? 0 ) );
		$done    = max( 0, (int) ( $job['processed'] ?? 0 ) );
		$rate    = $elapsed > 0 && $done > 0 ? round( $done * 60 / $elapsed, 2 ) : 0.0;
		$eta     = null;
		if ( 'import' === ( $job['phase'] ?? '' ) && $total > $done && $rate > 0 ) {
			$eta = (int) ceil( ( $total - $done ) * 60 / $rate );
		}
		$progress = $total > 0 ? min( 100.0, round( $done * 100 / $total, 1 ) ) : 0.0;

		return array(
			'elapsed_seconds'    => $elapsed,
			'records_per_minute' => $rate,
			'eta_seconds'        => $eta,
			'progress_percent'   => $progress,
			'current_phase'      => (string) ( $job['phase'] ?? '' ),
		);
	}

	/**
	 * Cast numeric job fields.
	 *
	 * @param array<string,mixed> $row Database row.
	 * @return array<string,mixed>
	 */
	private function cast_job( array $row ): array {
		foreach ( array( 'id', 'preset_id', 'parse_cursor', 'staged_total', 'total', 'processed', 'created', 'updated', 'skipped', 'failed' ) as $key ) {
			if ( isset( $row[ $key ] ) ) {
				$row[ $key ] = (int) $row[ $key ];
			}
		}
		$row['is_scheduled'] = ! empty( $row['is_scheduled'] );
		return $row;
	}
}
