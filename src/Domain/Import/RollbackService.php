<?php
/**
 * Conflict-aware rollback.
 *
 * @package TDS\ProductImporter
 */

namespace TDS\ProductImporter\Domain\Import;

use InvalidArgumentException;
use TDS\ProductImporter\Infrastructure\JobRepository;

/**
 * Restores captured product state in background batches.
 */
final class RollbackService {
	public function __construct( private JobRepository $jobs ) {}

	/**
	 * Mark a job for rollback.
	 */
	public function start( int $job_id ): void {
		$job = $this->jobs->find( $job_id );
		if ( ! $job || ! in_array( $job['status'], array( 'completed', 'partial', 'failed', 'cancelled' ), true ) ) {
			throw new InvalidArgumentException( 'This job cannot be rolled back.' );
		}
		if ( ! empty( $job['rollback_until'] ) && strtotime( (string) $job['rollback_until'] . ' UTC' ) < time() ) {
			throw new InvalidArgumentException( 'The rollback retention period has expired.' );
		}
		$this->jobs->update(
			$job_id,
			array(
				'status'  => 'rollback',
				'phase'   => 'rollback',
				'message' => null,
			)
		);
	}

	/**
	 * Restore one bounded snapshot batch.
	 *
	 * @return bool True when rollback is complete.
	 */
	public function process( int $job_id, int $limit = 50 ): bool {
		$snapshots = $this->jobs->snapshots( $job_id, $limit );
		if ( ! $snapshots ) {
			$this->jobs->update(
				$job_id,
				array(
					'status'       => 'rolled_back',
					'phase'        => 'complete',
					'completed_at' => current_time( 'mysql', true ),
					'message'      => 'Rollback completed.',
				)
			);
			return true;
		}

		foreach ( $snapshots as $row ) {
			$product_id = (int) $row['product_id'];
			if ( get_post( $product_id ) && ! hash_equals( (string) $row['post_fingerprint'], ProductWriter::fingerprint( $product_id ) ) ) {
				$this->jobs->log( $job_id, 'warning', "Product $product_id changed after import and was not rolled back.", 'rollback_conflict' );
				$this->jobs->mark_rolled_back( (int) $row['id'] );
				continue;
			}
			if ( ! empty( $row['is_created'] ) ) {
				wp_trash_post( $product_id );
			} else {
				$snapshot = json_decode( (string) $row['snapshot'], true );
				if ( is_array( $snapshot ) ) {
					$this->restore( $product_id, $snapshot );
				}
			}
			$this->remove_unreferenced_media( json_decode( (string) $row['created_media'], true ) ?: array() );
			$this->jobs->mark_rolled_back( (int) $row['id'] );
		}
		return false;
	}

	/**
	 * Restore raw post, metadata, and terms.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 */
	private function restore( int $product_id, array $snapshot ): void {
		$post       = (array) ( $snapshot['post'] ?? array() );
		$post['ID'] = $product_id;
		wp_update_post( wp_slash( $post ) );

		foreach ( array_keys( get_post_meta( $product_id ) ) as $key ) {
			delete_post_meta( $product_id, $key );
		}
		foreach ( (array) ( $snapshot['meta'] ?? array() ) as $key => $values ) {
			foreach ( (array) $values as $value ) {
				add_post_meta( $product_id, $key, maybe_unserialize( $value ) );
			}
		}
		foreach ( (array) ( $snapshot['terms'] ?? array() ) as $taxonomy => $ids ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				wp_set_object_terms( $product_id, array_map( 'intval', (array) $ids ), $taxonomy );
			}
		}
		clean_post_cache( $product_id );
	}

	/**
	 * Delete only attachments no longer referenced by any post metadata.
	 *
	 * @param int[] $media Attachment IDs.
	 */
	private function remove_unreferenced_media( array $media ): void {
		global $wpdb;
		foreach ( array_map( 'intval', $media ) as $attachment_id ) {
			$references = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id<>%d AND (meta_value=%s OR meta_value LIKE %s)",
					$attachment_id,
					(string) $attachment_id,
					'%' . $wpdb->esc_like( (string) $attachment_id ) . '%'
				)
			);
			if ( 0 === $references ) {
				wp_delete_attachment( $attachment_id, true );
			}
		}
	}
}
