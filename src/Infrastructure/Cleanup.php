<?php
/**
 * Retention cleanup.
 *
 * @package TDS\ProductImporter
 */

namespace TDS\ProductImporter\Infrastructure;

/**
 * Removes expired job details and immutable source snapshots.
 */
final class Cleanup {
	public function __construct(
		private Database $database
	) {}

	public function register(): void {
		add_action( 'tds_importer_cleanup', array( $this, 'run' ) );
		add_action( 'action_scheduler_init', array( $this, 'ensure_schedule' ) );
	}

	/**
	 * Schedule retention cleanup only after Action Scheduler is initialized.
	 */
	public function ensure_schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( 'tds_importer_cleanup', array(), 'tds-importer' ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, 'tds_importer_cleanup', array(), 'tds-importer', true );
		}
	}

	/**
	 * Purge data whose job rollback window has expired.
	 */
	public function run(): void {
		global $wpdb;
		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id,source_path FROM {$this->database->table( 'jobs' )} WHERE rollback_until IS NOT NULL AND rollback_until<%s LIMIT 100", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql', true )
			),
			ARRAY_A
		);
		foreach ( $jobs ?: array() as $job ) {
			$id = (int) $job['id'];
			$wpdb->delete( $this->database->table( 'items' ), array( 'job_id' => $id ) );
			$wpdb->delete( $this->database->table( 'snapshots' ), array( 'job_id' => $id ) );
			if ( ! empty( $job['source_path'] ) && str_starts_with( wp_normalize_path( $job['source_path'] ), wp_normalize_path( Installer::storage_dir() ) . '/' ) ) {
				wp_delete_file( $job['source_path'] );
			}
			$wpdb->update(
				$this->database->table( 'jobs' ),
				array(
					'source_path'    => null,
					'rollback_until' => null,
				),
				array( 'id' => $id )
			);
		}
	}
}
