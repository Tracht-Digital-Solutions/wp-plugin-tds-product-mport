<?php
/**
 * Database name helper.
 *
 * @package TDS\ProductImporter
 */

namespace TDS\ProductImporter\Infrastructure;

/**
 * Resolves plugin table names using the active WordPress prefix.
 */
final class Database {
	/**
	 * Resolve a plugin table name.
	 */
	public function table( string $suffix ): string {
		global $wpdb;
		return $wpdb->prefix . 'tds_import_' . $suffix;
	}
}
