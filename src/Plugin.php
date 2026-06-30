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
	private static ?self $instance = null;

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
		load_plugin_textdomain(
			'tds-product-importer',
			false,
			dirname( plugin_basename( TDS_IMPORTER_FILE ) ) . '/languages'
		);

		if ( ! $this->requirements_met() ) {
			add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
			return;
		}
		if ( TDS_IMPORTER_VERSION !== get_option( 'tds_importer_db_version' ) ) {
			Infrastructure\Installer::activate();
		}

		$database = new Database();
		$presets  = new PresetRepository( $database, new SecretBox() );
		$jobs     = new JobRepository( $database );
		$sources  = new SourceManager( new SecretBox() );
		$parsers  = new ParserFactory();
		$mapper   = new Mapper( new Evaluator() );
		$writer   = new ProductWriter( $jobs );
		$rollback = new RollbackService( $jobs );
		$runner   = new JobRunner( $presets, $jobs, $sources, $parsers, $mapper, $writer, $rollback );
		$schedule = new Scheduler( $presets, $jobs );

		( new AdminPage() )->register();
		( new RestController( $presets, $jobs, $sources, $parsers, $mapper, $rollback, $schedule ) )->register();
		$runner->register();
		$schedule->register();
		( new Cleanup( $database ) )->register();

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
}
