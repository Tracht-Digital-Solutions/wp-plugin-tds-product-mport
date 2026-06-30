<?php
/**
 * Importer REST API.
 *
 * @package TDS\ProductImporter
 */

namespace TDS\ProductImporter\Api;

use InvalidArgumentException;
use TDS\ProductImporter\Domain\Expression\Evaluator;
use TDS\ProductImporter\Domain\Import\Mapper;
use TDS\ProductImporter\Domain\Import\RollbackService;
use TDS\ProductImporter\Domain\Parsing\ParserFactory;
use TDS\ProductImporter\Infrastructure\JobRepository;
use TDS\ProductImporter\Infrastructure\PresetRepository;
use TDS\ProductImporter\Infrastructure\Scheduler;
use TDS\ProductImporter\Infrastructure\SourceManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Provides the authenticated admin API.
 */
final class RestController {
	private const NS = 'tds-import/v1';

	public function __construct(
		private PresetRepository $presets,
		private JobRepository $jobs,
		private SourceManager $sources,
		private ParserFactory $parsers,
		private Mapper $mapper,
		private RollbackService $rollback,
		private Scheduler $scheduler
	) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		register_rest_route(
			self::NS,
			'/presets',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn() => $this->presets->all(),
					'permission_callback' => array( $this, 'permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_preset' ),
					'permission_callback' => array( $this, 'permission' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/presets/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save_preset' ),
					'permission_callback' => array( $this, 'permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_preset' ),
					'permission_callback' => array( $this, 'permission' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/upload',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/preflight/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preflight' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/map-preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'map_preview' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/formula',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'formula' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/jobs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn() => $this->jobs->recent(),
					'permission_callback' => array( $this, 'permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'start_job' ),
					'permission_callback' => array( $this, 'permission' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/jobs/(?P<id>\d+)/control',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'control_job' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/jobs/(?P<id>\d+)/rollback',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rollback_job' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/jobs/(?P<id>\d+)/logs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'job_logs' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/targets',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'targets' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}

	public function permission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	public function save_preset( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$id     = $request['id'] ? (int) $request['id'] : null;
			$preset = $this->presets->save( (array) $request->get_json_params(), $id );
			$this->scheduler->sync( (int) $preset['id'] );
			return new WP_REST_Response( $preset, $id ? 200 : 201 );
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	public function delete_preset( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$this->presets->delete( (int) $request['id'] );
			return new WP_REST_Response( null, 204 );
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	public function upload( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$files = $request->get_file_params();
			if ( empty( $files['source'] ) ) {
				throw new InvalidArgumentException( 'A source file is required.' );
			}
			$path = $this->sources->store_upload( $files['source'] );
			return new WP_REST_Response(
				array(
					'path' => $path,
					'name' => basename( $path ),
				),
				201
			);
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	public function preflight( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$path = null;
		try {
			$preset = $this->presets->find( (int) $request['id'] );
			if ( ! $preset ) {
				throw new InvalidArgumentException( 'Preset not found.' );
			}
			$config = (array) $preset['config'];
			$errors = $this->mapper->validate( $config );
			if ( $errors ) {
				return new WP_REST_Response(
					array(
						'valid'   => false,
						'errors'  => $errors,
						'samples' => array(),
					)
				);
			}
			$path    = $this->sources->materialize( $config );
			$records = $this->parsers->preview( $path, $config, 20 );
			$mapped  = array_map( fn( array $row ): array => $this->mapper->map( $row, $config ), $records );
			$keys    = array_map(
				fn( array $row ): string => trim( (string) ( $row[ 'external_id' === $config['identity'] ? 'external_id' : 'sku' ] ?? '' ) ),
				$mapped
			);
			if ( count( $keys ) !== count( array_unique( $keys ) ) ) {
				$errors[] = 'Duplicate identifiers occur in the preflight sample.';
			}
			if ( in_array( '', $keys, true ) ) {
				$errors[] = 'At least one sample record has an empty identifier.';
			}
			return new WP_REST_Response(
				array(
					'valid'   => ! $errors,
					'errors'  => $errors,
					'samples' => array_map(
						static fn( array $raw, array $result ): array => array(
							'raw'    => $raw,
							'result' => $result,
						),
						$records,
						$mapped
					),
					'hash'    => hash_file( 'sha256', $path ),
				)
			);
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		} finally {
			if ( $path && is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	public function map_preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$params  = (array) $request->get_json_params();
			$config  = is_array( $params['config'] ?? null ) ? $params['config'] : array();
			$records = is_array( $params['records'] ?? null ) ? array_slice( $params['records'], 0, 20 ) : array();
			return new WP_REST_Response( array_map( fn( array $row ): array => $this->mapper->map( $row, $config ), $records ) );
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	public function formula( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$params = (array) $request->get_json_params();
			$engine = new Evaluator();
			$ast    = $engine->parse( (string) ( $params['expression'] ?? '' ) );
			$value  = $engine->evaluate_ast( $ast, is_array( $params['record'] ?? null ) ? $params['record'] : array() );
			return new WP_REST_Response( compact( 'ast', 'value' ) );
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	public function start_job( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$preset_id = (int) $request->get_param( 'preset_id' );
			if ( ! $this->presets->find( $preset_id ) ) {
				throw new InvalidArgumentException( 'Preset not found.' );
			}
			$job_id = $this->jobs->create( $preset_id );
			$this->enqueue( $job_id );
			return new WP_REST_Response( $this->jobs->find( $job_id ), 201 );
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	public function control_job( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$id     = (int) $request['id'];
			$job    = $this->jobs->find( $id );
			$action = sanitize_key( (string) $request->get_param( 'action' ) );
			if ( ! $job ) {
				throw new InvalidArgumentException( 'Job not found.' );
			}
			if ( 'pause' === $action && in_array( $job['status'], array( 'queued', 'running' ), true ) ) {
				$this->jobs->update( $id, array( 'status' => 'paused' ) );
			} elseif ( 'resume' === $action && 'paused' === $job['status'] ) {
				$this->jobs->update( $id, array( 'status' => 'queued' ) );
				$this->enqueue( $id );
			} elseif ( 'cancel' === $action && in_array( $job['status'], array( 'queued', 'running', 'paused' ), true ) ) {
				$this->jobs->update(
					$id,
					array(
						'status'       => 'cancelled',
						'completed_at' => current_time( 'mysql', true ),
					)
				);
			} else {
				throw new InvalidArgumentException( 'Action is not valid for the current job status.' );
			}
			return new WP_REST_Response( $this->jobs->find( $id ) );
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	public function rollback_job( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$id = (int) $request['id'];
			$this->rollback->start( $id );
			$this->enqueue( $id );
			return new WP_REST_Response( $this->jobs->find( $id ) );
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	public function job_logs( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->jobs->logs( (int) $request['id'] ) );
	}

	/**
	 * Return mapping targets, including discovered ACF fields.
	 */
	public function targets(): WP_REST_Response {
		$core = array(
			'name',
			'type',
			'sku',
			'external_id',
			'parent',
			'slug',
			'status',
			'description',
			'short_description',
			'regular_price',
			'sale_price',
			'date_on_sale_from',
			'date_on_sale_to',
			'manage_stock',
			'stock_quantity',
			'stock_status',
			'backorders',
			'sold_individually',
			'weight',
			'length',
			'width',
			'height',
			'tax_status',
			'tax_class',
			'shipping_class',
			'virtual',
			'downloadable',
			'downloads',
			'purchase_note',
			'menu_order',
			'reviews_allowed',
			'product_url',
			'button_text',
			'categories',
			'tags',
			'attributes',
			'image',
			'gallery_images',
			'upsells',
			'cross_sells',
			'grouped_children',
		);
		$acf  = array();
		if ( function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
			foreach ( acf_get_field_groups() as $group ) {
				foreach ( acf_get_fields( $group ) ?: array() as $field ) {
					if ( ! empty( $field['key'] ) ) {
						$acf[] = 'acf:' . $field['key'];
					}
				}
			}
		}
		return new WP_REST_Response(
			array(
				'core'        => $core,
				'acf'         => $acf,
				'meta_prefix' => 'meta:',
			)
		);
	}

	private function enqueue( int $job_id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'tds_importer_run_job', array( $job_id ), 'tds-importer', false );
		} else {
			wp_schedule_single_event( time() + 1, 'tds_importer_run_job', array( $job_id ) );
		}
	}

	private function error( \Throwable $error ): WP_Error {
		$status = $error instanceof InvalidArgumentException ? 400 : 500;
		return new WP_Error( 'tds_importer_error', $error->getMessage(), array( 'status' => $status ) );
	}
}
