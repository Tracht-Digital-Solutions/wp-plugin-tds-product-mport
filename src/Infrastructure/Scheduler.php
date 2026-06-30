<?php
/**
 * Preset schedules.
 *
 * @package TDS\ProductImporter
 */

namespace TDS\ProductImporter\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Maintains DST-safe one-shot schedules and starts scheduled jobs.
 */
final class Scheduler {
	public function __construct(
		private PresetRepository $presets,
		private JobRepository $jobs
	) {}

	public function register(): void {
		add_action( 'tds_importer_scheduled_preset', array( $this, 'run_preset' ) );
		add_action( 'action_scheduler_ensure_recurring_actions', array( $this, 'ensure' ) );
	}

	/**
	 * Replace a preset's scheduled action.
	 */
	public function sync( int $preset_id ): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		as_unschedule_all_actions( 'tds_importer_scheduled_preset', array( $preset_id ), 'tds-importer-schedules' );
		$preset = $this->presets->find( $preset_id );
		if ( ! $preset || empty( $preset['enabled'] ) || empty( $preset['config']['schedule']['enabled'] ) ) {
			return;
		}
		as_schedule_single_action(
			$this->next_timestamp( (array) $preset['config']['schedule'] ),
			'tds_importer_scheduled_preset',
			array( $preset_id ),
			'tds-importer-schedules',
			true
		);
	}

	/**
	 * Start a scheduled import and calculate the next local occurrence.
	 */
	public function run_preset( int $preset_id ): void {
		$preset = $this->presets->find( $preset_id );
		if ( ! $preset || empty( $preset['enabled'] ) || empty( $preset['config']['schedule']['enabled'] ) ) {
			return;
		}
		$job_id = $this->jobs->create( $preset_id, true );
		as_enqueue_async_action( 'tds_importer_run_job', array( $job_id ), 'tds-importer', false );
		$this->sync( $preset_id );
	}

	/**
	 * Repair schedules removed by a queue cleanup.
	 */
	public function ensure(): void {
		foreach ( $this->presets->all() as $preset ) {
			if ( ! empty( $preset['config']['schedule']['enabled'] ) ) {
				$this->sync( (int) $preset['id'] );
			}
		}
	}

	/**
	 * Compute the next occurrence in the configured WordPress timezone.
	 *
	 * @param array<string,mixed> $schedule Schedule.
	 */
	public function next_timestamp( array $schedule, ?DateTimeImmutable $now = null ): int {
		$timezone = wp_timezone();
		$now      = $now ? $now->setTimezone( $timezone ) : new DateTimeImmutable( 'now', $timezone );
		$period   = (string) ( $schedule['period'] ?? 'daily' );
		if ( 'hourly' === $period ) {
			return $now->modify( '+1 hour' )->getTimestamp();
		}
		$time      = (string) ( $schedule['time'] ?? '02:00' );
		$candidate = new DateTimeImmutable( $now->format( 'Y-m-d' ) . ' ' . $time, $timezone );
		if ( 'weekly' === $period ) {
			$target    = (int) ( $schedule['weekday'] ?? 1 );
			$days      = ( $target - (int) $candidate->format( 'w' ) + 7 ) % 7;
			$candidate = $candidate->modify( "+$days days" );
		}
		if ( $candidate <= $now ) {
			$candidate = $candidate->modify( 'weekly' === $period ? '+7 days' : '+1 day' );
		}
		return $candidate->getTimestamp();
	}
}
