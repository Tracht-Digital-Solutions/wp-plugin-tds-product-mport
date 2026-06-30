<?php
/**
 * Preset persistence.
 *
 * @package TDS\ProductImporter
 */

namespace TDS\ProductImporter\Infrastructure;

use InvalidArgumentException;

/**
 * Stores validated preset configuration and protects credentials.
 */
final class PresetRepository {
	public function __construct(
		private Database $database,
		private SecretBox $secrets
	) {}

	/**
	 * List all presets.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT * FROM {$this->database->table( 'presets' )} ORDER BY updated_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return array_map( fn( array $row ): array => $this->hydrate( $row, true ), $rows ?: array() );
	}

	/**
	 * Get a preset.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find( int $id, bool $mask = false ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->database->table( 'presets' )} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			),
			ARRAY_A
		);
		return $row ? $this->hydrate( $row, $mask ) : null;
	}

	/**
	 * Create or update a preset.
	 *
	 * @param array<string,mixed> $input Raw API input.
	 * @return array<string,mixed>
	 */
	public function save( array $input, ?int $id = null ): array {
		global $wpdb;
		$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		if ( '' === $name ) {
			throw new InvalidArgumentException( 'Preset name is required.' );
		}

		$existing = $id ? $this->find( $id ) : null;
		$config   = $this->sanitize_config(
			is_array( $input['config'] ?? null ) ? $input['config'] : array(),
			$existing['config'] ?? array()
		);
		$now      = current_time( 'mysql', true );
		$data     = array(
			'name'       => $name,
			'config'     => wp_json_encode( $config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'enabled'    => empty( $input['enabled'] ) ? 0 : 1,
			'updated_at' => $now,
		);
		if ( $id ) {
			$wpdb->update( $this->database->table( 'presets' ), $data, array( 'id' => $id ) );
		} else {
			$data['created_at'] = $now;
			$wpdb->insert( $this->database->table( 'presets' ), $data );
			$id = (int) $wpdb->insert_id;
		}
		return $this->find( (int) $id, true ) ?? array();
	}

	/**
	 * Delete a preset when no job is active.
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		$active = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->database->table( 'jobs' )} WHERE preset_id=%d AND status IN ('queued','running','paused','rollback')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			)
		);
		if ( $active > 0 ) {
			throw new InvalidArgumentException( 'An active job still uses this preset.' );
		}
		return false !== $wpdb->delete( $this->database->table( 'presets' ), array( 'id' => $id ) );
	}

	/**
	 * Sanitize the public configuration contract.
	 *
	 * @param array<string,mixed> $config   New configuration.
	 * @param array<string,mixed> $existing Existing configuration.
	 * @return array<string,mixed>
	 */
	private function sanitize_config( array $config, array $existing ): array {
		$source   = is_array( $config['source'] ?? null ) ? $config['source'] : array();
		$old      = is_array( $existing['source'] ?? null ) ? $existing['source'] : array();
		$type     = in_array( $source['type'] ?? '', array( 'upload', 'https', 'sftp' ), true ) ? $source['type'] : 'upload';
		$format   = in_array( $config['format'] ?? '', array( 'auto', 'csv', 'xml' ), true ) ? $config['format'] : 'auto';
		$identity = in_array( $config['identity'] ?? '', array( 'sku', 'external_id' ), true ) ? $config['identity'] : 'sku';
		$missing  = in_array( $config['missing_policy'] ?? '', array( 'keep', 'draft', 'outofstock', 'trash' ), true )
			? $config['missing_policy']
			: 'keep';

		$clean_source = array(
			'type'        => $type,
			'url'         => esc_url_raw( (string) ( $source['url'] ?? '' ), array( 'https' ) ),
			'host'        => sanitize_text_field( (string) ( $source['host'] ?? '' ) ),
			'port'        => max( 1, min( 65535, (int) ( $source['port'] ?? 22 ) ) ),
			'username'    => sanitize_text_field( (string) ( $source['username'] ?? '' ) ),
			'remote_path' => sanitize_text_field( (string) ( $source['remote_path'] ?? '' ) ),
			'fingerprint' => preg_replace( '/[^a-zA-Z0-9:+\/=]/', '', (string) ( $source['fingerprint'] ?? '' ) ),
			'upload_path' => $this->safe_stored_path( (string) ( $source['upload_path'] ?? ( $old['upload_path'] ?? '' ) ) ),
		);
		foreach ( array( 'password', 'private_key', 'basic_password' ) as $key ) {
			$value                = (string) ( $source[ $key ] ?? '' );
			$clean_source[ $key ] = ( '' === $value || '••••••••' === $value )
				? (string) ( $old[ $key ] ?? '' )
				: $this->secrets->encrypt( $value );
		}
		$clean_source['basic_username'] = sanitize_text_field( (string) ( $source['basic_username'] ?? '' ) );

		$mappings = array();
		foreach ( is_array( $config['mappings'] ?? null ) ? $config['mappings'] : array() as $mapping ) {
			if ( ! is_array( $mapping ) || empty( $mapping['target'] ) ) {
				continue;
			}
			$mappings[] = array(
				'target'     => preg_replace( '/[^a-zA-Z0-9_.:-]/', '', (string) $mapping['target'] ),
				'source'     => sanitize_text_field( (string) ( $mapping['source'] ?? '' ) ),
				'expression' => sanitize_textarea_field( (string) ( $mapping['expression'] ?? '' ) ),
				'ast'        => is_array( $mapping['ast'] ?? null ) ? $this->sanitize_ast( $mapping['ast'] ) : null,
				'empty'      => in_array( $mapping['empty'] ?? '', array( 'keep', 'clear', 'default' ), true ) ? $mapping['empty'] : 'keep',
				'default'    => sanitize_textarea_field( (string) ( $mapping['default'] ?? '' ) ),
			);
		}

		$schedule = is_array( $config['schedule'] ?? null ) ? $config['schedule'] : array();
		return array(
			'source'         => $clean_source,
			'format'         => $format,
			'csv'            => array(
				'delimiter' => substr( (string) ( $config['csv']['delimiter'] ?? '' ), 0, 1 ),
				'enclosure' => substr( (string) ( $config['csv']['enclosure'] ?? '"' ), 0, 1 ),
				'encoding'  => sanitize_text_field( (string) ( $config['csv']['encoding'] ?? 'auto' ) ),
			),
			'xml'            => array(
				'record_path' => sanitize_text_field( (string) ( $config['xml']['record_path'] ?? '' ) ),
			),
			'identity'       => $identity,
			'identity_field' => sanitize_text_field( (string) ( $config['identity_field'] ?? ( 'sku' === $identity ? 'sku' : 'external_id' ) ) ),
			'parent_field'   => sanitize_text_field( (string) ( $config['parent_field'] ?? 'parent_sku' ) ),
			'type_field'     => sanitize_text_field( (string) ( $config['type_field'] ?? 'type' ) ),
			'mappings'       => $mappings,
			'missing_policy' => $missing,
			'schedule'       => array(
				'enabled' => ! empty( $schedule['enabled'] ),
				'period'  => in_array( $schedule['period'] ?? '', array( 'hourly', 'daily', 'weekly' ), true ) ? $schedule['period'] : 'daily',
				'time'    => preg_match( '/^\d{2}:\d{2}$/', (string) ( $schedule['time'] ?? '' ) ) ? $schedule['time'] : '02:00',
				'weekday' => max( 0, min( 6, (int) ( $schedule['weekday'] ?? 1 ) ) ),
			),
			'email'          => sanitize_email( (string) ( $config['email'] ?? get_option( 'admin_email' ) ) ),
			'retention_days' => max( 7, min( 365, (int) ( $config['retention_days'] ?? 30 ) ) ),
			'batch_size'     => max( 10, min( 250, (int) ( $config['batch_size'] ?? 50 ) ) ),
		);
	}

	/**
	 * Recursively sanitize a formula AST.
	 *
	 * @param array<string,mixed> $node Expression node.
	 * @return array<string,mixed>
	 */
	private function sanitize_ast( array $node, int $depth = 0 ): array {
		if ( $depth > 20 ) {
			throw new InvalidArgumentException( 'Formula is nested too deeply.' );
		}
		$clean = array();
		foreach ( $node as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( is_array( $value ) ) {
				$clean[ $key ] = array_is_list( $value )
					? array_map( fn( $item ) => is_array( $item ) ? $this->sanitize_ast( $item, $depth + 1 ) : sanitize_textarea_field( (string) $item ), $value )
					: $this->sanitize_ast( $value, $depth + 1 );
			} else {
				$clean[ $key ] = sanitize_textarea_field( (string) $value );
			}
		}
		return $clean;
	}

	/**
	 * Ensure a stored upload path remains inside plugin storage.
	 */
	private function safe_stored_path( string $path ): string {
		if ( '' === $path ) {
			return '';
		}
		$root = wp_normalize_path( Installer::storage_dir() );
		$path = wp_normalize_path( $path );
		return str_starts_with( $path, $root . '/' ) ? $path : '';
	}

	/**
	 * Decode a row and optionally mask credentials.
	 *
	 * @param array<string,mixed> $row Database row.
	 * @return array<string,mixed>
	 */
	private function hydrate( array $row, bool $mask ): array {
		$config = json_decode( (string) $row['config'], true ) ?: array();
		if ( $mask ) {
			foreach ( array( 'password', 'private_key', 'basic_password' ) as $key ) {
				if ( ! empty( $config['source'][ $key ] ) ) {
					$config['source'][ $key ] = '••••••••';
				}
			}
		}
		return array(
			'id'         => (int) $row['id'],
			'name'       => $row['name'],
			'config'     => $config,
			'enabled'    => (bool) $row['enabled'],
			'created_at' => $row['created_at'],
			'updated_at' => $row['updated_at'],
		);
	}
}
