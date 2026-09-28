<?php
/**
 * `tdrift/v1/mark-synced` route.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Rest;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Services\Permissions;
use TranslationDrift\Services\Repositories\SyncRepository;
use TranslationDrift\Services\SyncService;

/**
 * Marks one or more translations as up to date.
 *
 * Every translation is checked with {@see Permissions::can_mark_synced()}, so
 * translators may mark the translations they can edit.
 *
 * @since 1.0.0
 */
class MarkSyncedController extends Controller {

	/**
	 * Most translations per request.
	 *
	 * @since 1.0.0
	 */
	public const MAX_IDS = 100;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Permissions    $permissions Permissions.
	 * @param SyncService    $syncer      Sync service.
	 * @param SyncRepository $sync        Sync rows.
	 * @param ItemPresenter  $presenter   Presenter.
	 */
	public function __construct(
		Permissions $permissions,
		private SyncService $syncer,
		private SyncRepository $sync,
		private ItemPresenter $presenter
	) {
		parent::__construct( $permissions );
		$this->rest_base = 'mark-synced';
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
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'ids' => array(
							'description' => __( 'Translation post IDs.', 'translation-drift' ),
							'type'        => 'array',
							'items'       => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'minItems'    => 1,
							'maxItems'    => self::MAX_IDS,
							'uniqueItems' => true,
							'required'    => true,
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * The user must be allowed to mark every requested translation.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return true|\WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		$user_id = get_current_user_id();
		if ( 0 === $user_id ) {
			return $this->forbidden();
		}

		foreach ( (array) $request['ids'] as $id ) {
			if ( ! $this->permissions->can_mark_synced( $user_id, (int) $id ) ) {
				return $this->forbidden(
					/* translators: %d: post ID. */
					sprintf( __( 'Sorry, you are not allowed to mark post %d as up to date.', 'translation-drift' ), (int) $id )
				);
			}
		}

		return true;
	}

	/**
	 * Marks the translations.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response
	 */
	public function create_item( $request ) {
		$user_id      = get_current_user_id();
		$updated      = array();
		$failed       = array();
		$translations = array();

		foreach ( array_map( 'intval', (array) $request['ids'] ) as $id ) {
			if ( ! $this->syncer->mark_synced( $id, $user_id, SyncService::CONTEXT_MANUAL ) ) {
				$failed[] = array(
					'id'      => $id,
					'code'    => 'tdrift_not_translation',
					'message' => __( 'This post is not a translation of a tracked source.', 'translation-drift' ),
				);
				continue;
			}

			$updated[] = $id;
			$row       = $this->sync->find_by_translation( $id );
			if ( null !== $row ) {
				$translations[ (string) $id ] = $this->presenter->translation( $row );
			}
		}

		return rest_ensure_response(
			array(
				'updated'      => $updated,
				'failed'       => $failed,
				'translations' => (object) $translations,
			)
		);
	}

	/**
	 * Schema of the result.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		if ( null === $this->schema ) {
			$this->schema = array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'tdrift-mark-synced',
				'type'       => 'object',
				'properties' => array(
					'updated'      => array(
						'description' => __( 'Translations marked as up to date.', 'translation-drift' ),
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
					),
					'failed'       => array(
						'description' => __( 'Translations that could not be marked, with the reason.', 'translation-drift' ),
						'type'        => 'array',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'id'      => array( 'type' => 'integer' ),
								'code'    => array( 'type' => 'string' ),
								'message' => array( 'type' => 'string' ),
							),
						),
					),
					'translations' => array(
						'description'          => __( 'New status of each updated translation, keyed by ID.', 'translation-drift' ),
						'type'                 => 'object',
						'additionalProperties' => ItemPresenter::translation_schema(),
					),
				),
			);
		}//end if

		return $this->add_additional_fields_schema( $this->schema );
	}
}
