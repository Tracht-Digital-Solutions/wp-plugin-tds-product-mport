<?php
/**
 * Plugin activation and schema installation.
 *
 * @package TDS\ProductImporter
 */

namespace TDS\ProductImporter\Infrastructure;

/**
 * Installs custom high-volume import tables.
 */
final class Installer {
	/**
	 * Activation callback.
	 */
	public static function activate(): void {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			deactivate_plugins( plugin_basename( TDS_IMPORTER_FILE ) );
			wp_die( esc_html__( 'TDS Product Importer requires PHP 8.1 or newer.', 'tds-product-importer' ) );
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$db      = new Database();
		$charset = $wpdb->get_charset_collate();

		$sql = array(
			"CREATE TABLE {$db->table( 'presets' )} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(190) NOT NULL,
				config longtext NOT NULL,
				enabled tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY enabled (enabled)
			) $charset;",
			"CREATE TABLE {$db->table( 'jobs' )} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				preset_id bigint(20) unsigned NOT NULL,
				status varchar(30) NOT NULL,
				phase varchar(30) NOT NULL,
				source_path text NULL,
				source_hash char(64) NULL,
				total bigint(20) unsigned NOT NULL DEFAULT 0,
				processed bigint(20) unsigned NOT NULL DEFAULT 0,
				created bigint(20) unsigned NOT NULL DEFAULT 0,
				updated bigint(20) unsigned NOT NULL DEFAULT 0,
				skipped bigint(20) unsigned NOT NULL DEFAULT 0,
				failed bigint(20) unsigned NOT NULL DEFAULT 0,
				message text NULL,
				is_scheduled tinyint(1) NOT NULL DEFAULT 0,
				started_at datetime NULL,
				completed_at datetime NULL,
				rollback_until datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY preset_status (preset_id,status),
				KEY status_created (status,created_at)
			) $charset;",
			"CREATE TABLE {$db->table( 'items' )} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				job_id bigint(20) unsigned NOT NULL,
				sequence_no bigint(20) unsigned NOT NULL,
				record_type varchar(30) NOT NULL DEFAULT 'simple',
				source_key varchar(190) NULL,
				parent_key varchar(190) NULL,
				payload longtext NOT NULL,
				status varchar(30) NOT NULL DEFAULT 'pending',
				product_id bigint(20) unsigned NULL,
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				error_code varchar(100) NULL,
				error_message text NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY job_sequence (job_id,sequence_no),
				KEY job_status (job_id,status,id),
				KEY job_source (job_id,source_key)
			) $charset;",
			"CREATE TABLE {$db->table( 'links' )} (
				preset_id bigint(20) unsigned NOT NULL,
				source_key varchar(190) NOT NULL,
				product_id bigint(20) unsigned NOT NULL,
				last_seen_job bigint(20) unsigned NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (preset_id,source_key),
				KEY product_id (product_id),
				KEY last_seen (preset_id,last_seen_job)
			) $charset;",
			"CREATE TABLE {$db->table( 'snapshots' )} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				job_id bigint(20) unsigned NOT NULL,
				product_id bigint(20) unsigned NOT NULL,
				is_created tinyint(1) NOT NULL DEFAULT 0,
				snapshot longtext NULL,
				post_fingerprint char(64) NULL,
				created_media longtext NULL,
				rolled_back_at datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY job_product (job_id,product_id),
				KEY retention (created_at)
			) $charset;",
			"CREATE TABLE {$db->table( 'logs' )} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				job_id bigint(20) unsigned NOT NULL,
				level varchar(20) NOT NULL,
				code varchar(100) NULL,
				message text NOT NULL,
				context longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY job_level (job_id,level,id)
			) $charset;",
		);

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( 'tds_importer_db_version', TDS_IMPORTER_VERSION, false );
		wp_mkdir_p( self::storage_dir() );
		self::protect_storage();
	}

	/**
	 * Deactivation callback.
	 */
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'tds_importer_cleanup', array(), 'tds-importer' );
		}
	}

	/**
	 * Get the protected source storage directory.
	 */
	public static function storage_dir(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'tds-product-importer';
	}

	/**
	 * Prevent direct web access to source files.
	 */
	private static function protect_storage(): void {
		$dir = self::storage_dir();
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}
}
