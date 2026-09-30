<?php
/**
 * `stalelingo/v1/baseline` route.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Rest;

defined( 'ABSPATH' ) || exit;

use Stalelingo\Services\BaselineJob;
use Stalelingo\Services\Permissions;

/**
 * Reads the baseline progress and queues a new baseline build.
 *
 * @since 1.0.0
 */
class BaselineController extends Controller {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Permissions $permissions Permissions.
	 * @param BaselineJob $baseline    Baseline job.
	 */
	public function __construct( Permissions $permissions, private BaselineJob $baseline ) {
		parent::__construct( $permissions );
		$this->rest_base = 'baseline';
	}

	/**
	 * Registers the route.
	 *
	 * @since 1.0.0
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
					'args'                => array(
						'force' => array(
							'description' => __( 'Also reset translations that already have a sync point, including outdated ones.', 'stalelingo' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Baseline progress.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response
	 */
	public function get_item( $request ) {
		return rest_ensure_response(
			array(
				'queued' => false,
				'state'  => $this->baseline->state(),
			)
		);
	}

	/**
	 * Queues a baseline build.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response
	 */
	public function create_item( $request ) {
		$queued = $this->baseline->start_baseline( (bool) $request['force'] );

		return rest_ensure_response(
			array(
				'queued' => $queued,
				'state'  => $this->baseline->state(),
			)
		);
	}

	/**
	 * Schema of the baseline progress.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public static function state_schema(): array {
		return array(
			'description' => __( 'Baseline progress.', 'stalelingo' ),
			'type'        => 'object',
			'properties'  => array(
				'status'     => array(
					'type' => 'string',
					'enum' => array( 'none', 'queued', 'running', 'done' ),
				),
				'processed'  => array( 'type' => 'integer' ),
				'updated_at' => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Schema of the response.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		if ( null === $this->schema ) {
			$this->schema = array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'stalelingo-baseline',
				'type'       => 'object',
				'properties' => array(
					'queued' => array(
						'description' => __( 'Whether this request queued a new build (false when one was already queued).', 'stalelingo' ),
						'type'        => 'boolean',
					),
					'state'  => self::state_schema(),
				),
			);
		}

		return $this->add_additional_fields_schema( $this->schema );
	}
}
