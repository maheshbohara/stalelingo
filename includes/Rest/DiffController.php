<?php
/**
 * `tdrift/v1/diff/{translation_id}` route.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Rest;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Services\DiffService;
use TranslationDrift\Services\Permissions;
use TranslationDrift\Services\Repositories\SyncRepository;

/**
 * What changed in a translation's source since it was last marked up to date, field by field.
 *
 * @since 1.0.0
 */
class DiffController extends Controller {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Permissions    $permissions Permissions.
	 * @param SyncRepository $sync        Sync rows.
	 * @param DiffService    $diffs       Diff service.
	 * @param ItemPresenter  $presenter   Presenter.
	 */
	public function __construct(
		Permissions $permissions,
		private SyncRepository $sync,
		private DiffService $diffs,
		private ItemPresenter $presenter
	) {
		parent::__construct( $permissions );
		$this->rest_base = 'diff';
	}

	/**
	 * Registers the route.
	 *
	 * @since 1.0.0
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . $this->rest_base . '/(?P<translation_id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'translation_id' => self::id_arg( __( 'Translation post ID.', 'translation-drift' ) ),
						'split'          => array(
							'description' => __( 'Show old and new text in two columns.', 'translation-drift' ),
							'type'        => 'boolean',
							'default'     => true,
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * The user must be allowed to edit the translation, or manage translations.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return true|\WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		return $this->post_permissions_check( (int) $request['translation_id'] );
	}

	/**
	 * The diff of a translation.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$translation_id = (int) $request['translation_id'];
		$row            = $this->sync->find_by_translation( $translation_id );
		$source         = null === $row ? null : get_post( $row->source_id );
		$translation    = get_post( $translation_id );

		if ( null === $row || ! $source instanceof \WP_Post || ! $translation instanceof \WP_Post ) {
			return new \WP_Error( 'tdrift_not_tracked', __( 'This post is not a tracked translation.', 'translation-drift' ), array( 'status' => 404 ) );
		}

		$synced_by = $row->synced_by > 0 ? get_userdata( $row->synced_by ) : false;

		return rest_ensure_response(
			array_merge(
				$this->presenter->translation( $row ),
				array(
					'source_id'   => $source->ID,
					'synced_by'   => $synced_by instanceof \WP_User ? $synced_by->display_name : '',
					'fields'      => $this->diffs->diff( $row, $source, (bool) $request['split'] ),
					'source'      => $this->post_links( $source ),
					'translation' => $this->post_links( $translation ),
				)
			)
		);
	}

	/**
	 * Title and links of a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{id: int, title: string, edit_url: string|null, view_url: string|null}
	 */
	private function post_links( \WP_Post $post ): array {
		$view = current_user_can( 'read_post', $post->ID ) ? get_permalink( $post ) : false;
		if ( 'publish' !== $post->post_status && current_user_can( 'edit_post', $post->ID ) ) {
			$view = get_preview_post_link( $post );
		}

		return array(
			'id'       => $post->ID,
			'title'    => current_user_can( 'read_post', $post->ID ) ? $this->presenter->title( $post ) : '',
			'edit_url' => $this->presenter->edit_url( $post->ID ),
			'view_url' => is_string( $view ) && '' !== $view ? $view : null,
		);
	}

	/**
	 * Schema of a diff.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		if ( null === $this->schema ) {
			$post = array(
				'type'       => 'object',
				'properties' => array(
					'id'       => array( 'type' => 'integer' ),
					'title'    => array( 'type' => 'string' ),
					'edit_url' => array(
						'type'   => array( 'string', 'null' ),
						'format' => 'uri',
					),
					'view_url' => array(
						'type'   => array( 'string', 'null' ),
						'format' => 'uri',
					),
				),
			);

			$translation  = ItemPresenter::translation_schema();
			$this->schema = array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'tdrift-diff',
				'type'       => 'object',
				'properties' => array_merge(
					$translation['properties'],
					array(
						'source_id'   => array(
							'description' => __( 'Source post ID.', 'translation-drift' ),
							'type'        => 'integer',
						),
						'synced_by'   => array(
							'description' => __( 'Name of the user who last marked the translation up to date.', 'translation-drift' ),
							'type'        => 'string',
						),
						'fields'      => array(
							'description' => __( 'Changed fields with their diffs.', 'translation-drift' ),
							'type'        => 'array',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'key'       => array( 'type' => 'string' ),
									'label'     => array( 'type' => 'string' ),
									'available' => array(
										'description' => __( 'False when no copy of the old value was stored.', 'translation-drift' ),
										'type'        => 'boolean',
									),
									'truncated' => array(
										'description' => __( 'Whether the stored old value was cut short.', 'translation-drift' ),
										'type'        => 'boolean',
									),
									'diff'      => array(
										'description' => __( 'Diff table (HTML); empty when only formatting changed.', 'translation-drift' ),
										'type'        => 'string',
									),
								),
							),
						),
						'source'      => array_merge( $post, array( 'description' => __( 'The source post.', 'translation-drift' ) ) ),
						'translation' => array_merge( $post, array( 'description' => __( 'The translation post.', 'translation-drift' ) ) ),
					)
				),
			);
		}//end if

		return $this->add_additional_fields_schema( $this->schema );
	}
}
