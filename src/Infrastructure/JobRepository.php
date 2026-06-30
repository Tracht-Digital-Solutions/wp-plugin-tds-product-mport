<?php
/**
 * Import job persistence.
 *
 * @package TDS\ProductImporter
 */

namespace TDS\ProductImporter\Infrastructure;

/**
 * Provides all job, staging, link, and snapshot database operations.
 */
final class JobRepository {
	public function __construct( private Database $database ) {}

	/**
	 * Create a queued job.
	 */
	public function create( int $preset_id, bool $scheduled = false ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert(
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
	 */
	public function can_run( int $id ): bool {
		global $wpdb;
		$older = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->database->table( 'jobs' )} WHERE id<>%d AND id<%d AND status IN ('queued','running','rollback')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id,
				$id
			)
		);
		return 0 === $older;
	}

	/**
	 * Bulk-stage parsed records.
	 *
	 * @param array<int,array<string,mixed>> $records Records.
	 */
	public function stage( int $job_id, array $records ): void {
		global $wpdb;
		$table = $this->database->table( 'items' );
		$now   = current_time( 'mysql', true );
		foreach ( array_chunk( $records, 200 ) as $chunk ) {
			$values = array();
			$args   = array();
			foreach ( $chunk as $record ) {
				$values[] = '(%d,%d,%s,%s,%s,%s,%s,%s)';
				array_push(
					$args,
					$job_id,
					(int) $record['sequence_no'],
					(string) ( $record['record_type'] ?? 'simple' ),
					(string) ( $record['source_key'] ?? '' ),
					(string) ( $record['parent_key'] ?? '' ),
					wp_json_encode( $record['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					'pending',
					$now
				);
			}
			$sql = "INSERT INTO $table (job_id,sequence_no,record_type,source_key,parent_key,payload,status,updated_at) VALUES " . implode( ',', $values );
			$wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}
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
		$wpdb->update(
			$this->database->table( 'items' ),
			array(
				'status'        => $failed ? 'relation_failed' : 'related',
				'error_code'    => $failed ? 'relationship_error' : null,
				'error_message' => $message,
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => $item_id )
		);
		if ( $failed ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->database->table( 'jobs' )} j JOIN {$this->database->table( 'items' )} i ON i.job_id=j.id SET j.failed=j.failed+1,j.updated_at=%s WHERE i.id=%d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					current_time( 'mysql', true ),
					$item_id
				)
			);
		}
	}

	/**
	 * Mark an item result and update aggregate counters.
	 */
	public function complete_item( int $item_id, int $job_id, string $result, ?int $product_id = null, ?string $code = null, ?string $message = null ): void {
		global $wpdb;
		$status = 'failed' === $result ? 'failed' : 'completed';
		$wpdb->update(
			$this->database->table( 'items' ),
			array(
				'status'        => $status,
				'product_id'    => $product_id,
				'attempts'      => 1,
				'error_code'    => $code,
				'error_message' => $message,
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => $item_id )
		);
		$counter = in_array( $result, array( 'created', 'updated', 'skipped', 'failed' ), true ) ? $result : 'skipped';
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->database->table( 'jobs' )} SET processed=processed+1, $counter=$counter+1, updated_at=%s WHERE id=%d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql', true ),
				$job_id
			)
		);
	}

	/**
	 * Retry a transient item failure up to three times.
	 */
	public function retry_item( int $item_id, int $job_id, string $code, string $message ): bool {
		global $wpdb;
		$attempts = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT attempts FROM {$this->database->table( 'items' )} WHERE id=%d AND job_id=%d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$item_id,
				$job_id
			)
		);
		++$attempts;
		if ( $attempts >= 3 ) {
			$this->complete_item( $item_id, $job_id, 'failed', null, $code, $message );
			return false;
		}
		$wpdb->update(
			$this->database->table( 'items' ),
			array(
				'attempts'      => $attempts,
				'error_code'    => $code,
				'error_message' => $message,
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => $item_id )
		);
		return true;
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
		$wpdb->update(
			$this->database->table( 'snapshots' ),
			array( 'created_media' => wp_json_encode( array_values( array_unique( array_map( 'intval', $media ) ) ) ) ),
			array(
				'job_id'     => $job_id,
				'product_id' => $product_id,
			)
		);
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
	 * Cast numeric job fields.
	 *
	 * @param array<string,mixed> $row Database row.
	 * @return array<string,mixed>
	 */
	private function cast_job( array $row ): array {
		foreach ( array( 'id', 'preset_id', 'total', 'processed', 'created', 'updated', 'skipped', 'failed' ) as $key ) {
			if ( isset( $row[ $key ] ) ) {
				$row[ $key ] = (int) $row[ $key ];
			}
		}
		$row['is_scheduled'] = ! empty( $row['is_scheduled'] );
		return $row;
	}
}
