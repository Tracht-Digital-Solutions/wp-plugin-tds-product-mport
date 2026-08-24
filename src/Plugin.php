<?php
/**
 * Main plugin composition root.
 *
 * @package TDS\ProductImporter
 */

namespace TDS\ProductImporter;

use TDS\ProductImporter\Admin\AdminPage;
use TDS\ProductImporter\Api\RestController;
use TDS\ProductImporter\Domain\Expression\Evaluator;
use TDS\ProductImporter\Domain\Import\JobRunner;
use TDS\ProductImporter\Domain\Import\MappingSuggester;
use TDS\ProductImporter\Domain\Import\Mapper;
use TDS\ProductImporter\Domain\Import\ProductWriter;
use TDS\ProductImporter\Domain\Import\RollbackService;
use TDS\ProductImporter\Domain\Parsing\ParserFactory;
use TDS\ProductImporter\Infrastructure\Cleanup;
use TDS\ProductImporter\Infrastructure\Database;
use TDS\ProductImporter\Infrastructure\JobRepository;
use TDS\ProductImporter\Infrastructure\PresetRepository;
use TDS\ProductImporter\Infrastructure\Scheduler;
use TDS\ProductImporter\Infrastructure\SecretBox;
use TDS\ProductImporter\Infrastructure\SourceManager;

/**
 * Wires all plugin services.
 */
final class Plugin {
	private static ?self $instance   = null;
	private ?string $migration_error = null;

	/**
	 * Get the singleton.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Register hooks after all plugins are available.
	 */
	public function boot(): void {
		if ( ! $this->requirements_met() ) {
			add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
			return;
		}
		if ( TDS_IMPORTER_VERSION !== get_option( 'tds_importer_db_version' ) ) {
			try {
				Infrastructure\Installer::activate();
			} catch ( \Throwable $error ) {
				$this->migration_error = $error->getMessage();
				error_log( 'TDS Product Importer migration failed: ' . $this->migration_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				add_action( 'admin_notices', array( $this, 'migration_notice' ) );
				return;
			}
		}

		$database  = new Database();
		$presets   = new PresetRepository( $database, new SecretBox() );
		$jobs      = new JobRepository( $database );
		$sources   = new SourceManager( new SecretBox() );
		$parsers   = new ParserFactory();
		$mapper    = new Mapper( new Evaluator() );
		$suggester = new MappingSuggester();
		$writer    = new ProductWriter( $jobs );
		$rollback  = new RollbackService( $jobs );
		$runner    = new JobRunner( $presets, $jobs, $sources, $parsers, $mapper, $writer, $rollback );
		$schedule  = new Scheduler( $presets, $jobs );

		( new AdminPage() )->register();
		( new RestController( $presets, $jobs, $sources, $parsers, $mapper, $suggester, $rollback, $schedule ) )->register();
		$runner->register();
		$schedule->register();
		( new Cleanup( $database, $presets ) )->register();

		add_filter(
			'plugin_action_links_' . plugin_basename( TDS_IMPORTER_FILE ),
			static function ( array $links ): array {
				array_unshift(
					$links,
					'<a href="' . esc_url( admin_url( 'admin.php?page=tds-product-importer' ) ) . '">' .
					esc_html__( 'Open importer', 'tds-product-importer' ) .
					'</a>'
				);
				return $links;
			}
		);
	}

	/**
	 * Check hard requirements.
	 */
	private function requirements_met(): bool {
		return version_compare( PHP_VERSION, '8.1', '>=' )
			&& defined( 'WC_VERSION' )
			&& version_compare( WC_VERSION, '8.2', '>=' );
	}

	/**
	 * Render an actionable dependency notice.
	 */
	public function dependency_notice(): void {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__(
			'TDS Product Importer requires PHP 8.1 or newer and WooCommerce 8.2 or newer.',
			'tds-product-importer'
		);
		echo '</p></div>';
	}

	/**
	 * Render a safe database migration error without exposing SQL details.
	 */
	public function migration_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo esc_html__(
			'TDS Product Importer could not update its database. Check the WordPress error log and database permissions, then reload this page.',
			'tds-product-importer'
		);
		echo '</p></div>';
	}
}
