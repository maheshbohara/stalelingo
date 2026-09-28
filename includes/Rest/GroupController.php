<?php
/**
 * `tdrift/v1/group/{id}` route.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Rest;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Providers\TranslationProvider;
use TranslationDrift\Services\Permissions;
use TranslationDrift\Services\Repositories\SyncRepository;
use TranslationDrift\Services\TrackedFields;

/**
 * The translation group of a post, as the block editor panel shows it.
 *
 * For a source: the status of each translation. For a translation: its own
 * status and its source. Open to anyone who may edit the post, so translators
 * without `tdrift_manage` see their own translations.
 *
 * @since 0.1.0
 */
class GroupController extends Controller {

	/**
	 * Roles a post can have.
	 *
	 * @since 0.1.0
	 */
	public const ROLE_SOURCE      = 'source';
	public const ROLE_TRANSLATION = 'translation';
	public const ROLE_NONE        = 'none';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Permissions         $permissions Permissions.
	 * @param TranslationProvider $provider    Provider.
	 * @param TrackedFields       $tracked     Tracked fields.
	 * @param SyncRepository      $sync        Sync rows.
	 * @param ItemPresenter       $presenter   Presenter.
	 */
	public function __construct(
		Permissions $permissions,
		private TranslationProvider $provider,
		private TrackedFields $tracked,
		private SyncRepository $sync,
		private ItemPresenter $presenter
	) {
		parent::__construct( $permissions );
		$this->rest_base = 'group';
	}

	/**
	 * Registers the route.
	 *
	 * @since 0.1.0
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'id' => self::id_arg( __( 'ID of any post in the group.', 'translation-drift' ) ),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * The user must be allowed to edit the post, or manage translations.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return true|\WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		return $this->post_permissions_check( (int) $request['id'] );
	}

	/**
	 * The group of a post.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'translation-drift' ), array( 'status' => 404 ) );
		}

		$lang      = $this->provider->get_language( $post_id );
		$tracked   = $this->tracked->is_tracked_post_type( $post->post_type );
		$source_id = $tracked ? $this->provider->get_source( $post_id ) : null;
		$data      = array(
			'post_id'      => $post_id,
			'tracked'      => $tracked,
			'role'         => self::ROLE_NONE,
			'lang'         => (string) $lang,
			'language'     => null === $lang ? '' : $this->presenter->language_name( $lang ),
			'source'       => null,
			'translation'  => null,
			'translations' => (object) array(),
			'can_mark'     => false,
		);

		if ( null === $source_id ) {
			return rest_ensure_response( $data );
		}

		if ( $source_id === $post_id ) {
			$data['role']         = self::ROLE_SOURCE;
			$data['translations'] = $this->presenter->source( $post, $this->sync->for_source( $post_id ) )['translations'];

			return rest_ensure_response( $data );
		}

		$source = get_post( $source_id );
		$row    = $this->sync->find_by_translation( $post_id );

		$data['role']        = self::ROLE_TRANSLATION;
		$data['translation'] = null === $row ? null : $this->presenter->translation( $row );
		$data['can_mark']    = $this->permissions->can_mark_synced( get_current_user_id(), $post_id );
		if ( $source instanceof \WP_Post ) {
			$source_lang    = (string) $this->provider->get_language( $source_id );
			$data['source'] = array(
				'id'       => $source_id,
				'title'    => current_user_can( 'read_post', $source_id ) ? $this->presenter->title( $source ) : '',
				'lang'     => $source_lang,
				'language' => $this->presenter->language_name( $source_lang ),
				'edit_url' => $this->presenter->edit_url( $source_id ),
			);
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Schema of a group.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		if ( null === $this->schema ) {
			$translation  = ItemPresenter::translation_schema();
			$this->schema = array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'tdrift-group',
				'type'       => 'object',
				'properties' => array(
					'post_id'      => array(
						'description' => __( 'The requested post.', 'translation-drift' ),
						'type'        => 'integer',
					),
					'tracked'      => array(
						'description' => __( 'Whether the post type is tracked.', 'translation-drift' ),
						'type'        => 'boolean',
					),
					'role'         => array(
						'description' => __( 'Whether the post is the source of its group, a translation, or neither.', 'translation-drift' ),
						'type'        => 'string',
						'enum'        => array( self::ROLE_SOURCE, self::ROLE_TRANSLATION, self::ROLE_NONE ),
					),
					'lang'         => array(
						'description' => __( 'Language code of the post.', 'translation-drift' ),
						'type'        => 'string',
					),
					'language'     => array(
						'description' => __( 'Language name of the post.', 'translation-drift' ),
						'type'        => 'string',
					),
					'source'       => array(
						'description' => __( 'The source, for a translation.', 'translation-drift' ),
						'type'        => array( 'object', 'null' ),
						'properties'  => array(
							'id'       => array( 'type' => 'integer' ),
							'title'    => array( 'type' => 'string' ),
							'lang'     => array( 'type' => 'string' ),
							'language' => array( 'type' => 'string' ),
							'edit_url' => array(
								'type'   => array( 'string', 'null' ),
								'format' => 'uri',
							),
						),
					),
					'translation'  => array_merge(
						$translation,
						array(
							'description' => __( 'Status of the post, for a tracked translation.', 'translation-drift' ),
							'type'        => array( 'object', 'null' ),
						)
					),
					'translations' => array(
						'description'          => __( 'Status of each translation, for a source.', 'translation-drift' ),
						'type'                 => 'object',
						'additionalProperties' => $translation,
					),
					'can_mark'     => array(
						'description' => __( 'Whether the current user may mark this translation as up to date.', 'translation-drift' ),
						'type'        => 'boolean',
					),
				),
			);
		}//end if

		return $this->add_additional_fields_schema( $this->schema );
	}
}
