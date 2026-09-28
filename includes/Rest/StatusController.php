<?php
/**
 * `tdrift/v1/status` routes.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Rest;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Admin\StatusView;
use TranslationDrift\Providers\TranslationProvider;
use TranslationDrift\Services\BaselineJob;
use TranslationDrift\Services\Permissions;
use TranslationDrift\Services\Repositories\SyncRepository;
use TranslationDrift\Services\TrackedFields;
use TranslationDrift\Settings;

/**
 * Lists sources with the status of each translation, and summary counts, for the dashboard.
 *
 * Reads only the plugin's cached status; nothing is recalculated here.
 *
 * @since 0.1.0
 */
class StatusController extends Controller {

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Permissions         $permissions Permissions.
	 * @param TranslationProvider $provider    Provider.
	 * @param TrackedFields       $tracked     Tracked fields.
	 * @param Settings            $settings    Settings.
	 * @param SyncRepository      $sync        Sync rows.
	 * @param BaselineJob         $baseline    Baseline job.
	 * @param ItemPresenter       $presenter   Presenter.
	 */
	public function __construct(
		Permissions $permissions,
		private TranslationProvider $provider,
		private TrackedFields $tracked,
		private Settings $settings,
		private SyncRepository $sync,
		private BaselineJob $baseline,
		private ItemPresenter $presenter
	) {
		parent::__construct( $permissions );
		$this->rest_base = 'status';
	}

	/**
	 * Registers the routes.
	 *
	 * @since 0.1.0
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . $this->rest_base . '/summary',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_summary' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
				'schema' => array( $this, 'get_summary_schema' ),
			)
		);
	}

	/**
	 * Sources matching the filters, one page at a time.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$args     = $this->query_args( $request );
		$result   = $this->sync->query_sources( $args );
		$ids      = $result['ids'];
		$per_page = (int) $args['per_page'];

		_prime_post_caches( $ids, false, false );
		$rows = $this->sync->for_sources( $ids );

		// Capability checks on translations (edit links, "mark as up to date") need their posts.
		$translation_ids = array();
		$author_ids      = array();
		foreach ( $rows as $by_lang ) {
			foreach ( $by_lang as $row ) {
				$translation_ids[] = $row->translation_id;
			}
		}
		_prime_post_caches( array_values( array_filter( $translation_ids ) ), false, false );

		$posts = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post instanceof \WP_Post ) {
				$posts[]      = $post;
				$author_ids[] = (int) $post->post_author;
			}
		}
		cache_users( array_values( array_unique( $author_ids ) ) );

		$items = array();
		foreach ( $posts as $post ) {
			$items[] = $this->prepare_response_for_collection(
				$this->prepare_item_for_response( $post, $request, $rows[ $post->ID ] ?? array() )
			);
		}

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $result['total'] / $per_page ) );

		return $response;
	}

	/**
	 * One source.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Post                                                       $item    Source post.
	 * @param \WP_REST_Request                                               $request Request.
	 * @param array<string, \TranslationDrift\Services\Repositories\SyncRow> $rows    Its rows.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response
	 */
	public function prepare_item_for_response( $item, $request, array $rows = array() ) {
		return rest_ensure_response( $this->presenter->source( $item, $rows ) );
	}

	/**
	 * Counts per status, overall and by language and post type, plus the baseline progress.
	 *
	 * @since 0.1.0
	 *
	 * @return \WP_REST_Response
	 */
	public function get_summary() {
		$summary = $this->sync->summary();

		$languages = array();
		foreach ( $this->provider->get_languages() as $lang ) {
			$languages[] = array(
				'code'   => $lang,
				'name'   => $this->presenter->language_name( $lang ),
				'counts' => (object) ( $summary['by_language'][ $lang ] ?? array() ),
			);
		}

		$post_types = array();
		foreach ( StatusView::post_type_labels( $this->tracked->post_types() ) as $type => $label ) {
			$post_types[] = array(
				'slug'   => $type,
				'label'  => $label,
				'counts' => (object) ( $summary['by_post_type'][ $type ] ?? array() ),
			);
		}

		return rest_ensure_response(
			array(
				'totals'     => $summary['totals'],
				'languages'  => $languages,
				'post_types' => $post_types,
				'baseline'   => $this->baseline->state(),
			)
		);
	}

	/**
	 * Converts request parameters to repository query arguments.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array{langs?: list<string>, statuses?: list<string>, post_types: list<string>, author?: int, search?: string, changed_after?: string, changed_before?: string, orderby: string, order: string, page: int, per_page: int}
	 */
	private function query_args( \WP_REST_Request $request ): array {
		$post_types = self::strings( $request['post_type'] );
		$tracked    = $this->tracked->post_types();

		$args = array(
			'post_types' => array() === $post_types ? $tracked : array_values( array_intersect( $post_types, $tracked ) ),
			'orderby'    => (string) $request['orderby'],
			'order'      => (string) $request['order'],
			'page'       => (int) $request['page'],
			'per_page'   => (int) $request['per_page'],
		);

		$langs = self::strings( $request['lang'] );
		if ( ! empty( $request['translator'] ) ) {
			$assigned = $this->languages_of_translator( (int) $request['translator'] );
			$langs    = array() === $langs ? $assigned : array_values( array_intersect( $langs, $assigned ) );
			if ( array() === $langs ) {
				// No languages left: match nothing rather than everything.
				$args['langs'] = array();
			}
		}
		if ( array() !== $langs ) {
			$args['langs'] = $langs;
		}

		$statuses = self::strings( $request['status'] );
		if ( array() !== $statuses ) {
			$args['statuses'] = $statuses;
		}
		if ( ! empty( $request['author'] ) ) {
			$args['author'] = (int) $request['author'];
		}
		if ( is_string( $request['search'] ) && '' !== trim( $request['search'] ) ) {
			$args['search'] = trim( $request['search'] );
		}
		foreach ( array( 'changed_after', 'changed_before' ) as $key ) {
			$time = is_string( $request[ $key ] ) ? rest_parse_date( $request[ $key ] ) : false;
			if ( false !== $time ) {
				$args[ $key ] = gmdate( 'Y-m-d H:i:s', $time );
			}
		}

		return $args;
	}

	/**
	 * Languages assigned to a translator in the settings.
	 *
	 * @param int $user_id User ID.
	 * @return list<string>
	 */
	private function languages_of_translator( int $user_id ): array {
		$langs = array();
		foreach ( (array) $this->settings->get( 'translators' ) as $lang => $users ) {
			if ( in_array( $user_id, array_map( 'intval', (array) $users ), true ) ) {
				$langs[] = (string) $lang;
			}
		}

		return $langs;
	}

	/**
	 * A list parameter as strings.
	 *
	 * @param mixed $value Parameter value.
	 * @return list<string>
	 */
	private static function strings( mixed $value ): array {
		return array_values( array_filter( array_map( 'strval', (array) $value ), static fn( string $v ): bool => '' !== $v ) );
	}

	/**
	 * Query parameters of the collection.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_collection_params(): array {
		return array(
			'page'           => array(
				'description' => __( 'Page of the collection.', 'translation-drift' ),
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
			),
			'per_page'       => array(
				'description' => __( 'Sources per page.', 'translation-drift' ),
				'type'        => 'integer',
				'default'     => 20,
				'minimum'     => 1,
				'maximum'     => 100,
			),
			'search'         => array(
				'description' => __( 'Only sources whose title contains this text.', 'translation-drift' ),
				'type'        => 'string',
			),
			'lang'           => array(
				'description' => __( 'Only translations in these languages.', 'translation-drift' ),
				'type'        => 'array',
				'items'       => array(
					'type' => 'string',
					'enum' => $this->provider->get_languages(),
				),
			),
			'status'         => array(
				'description' => __( 'Only sources with a translation in one of these statuses.', 'translation-drift' ),
				'type'        => 'array',
				'items'       => array(
					'type' => 'string',
					'enum' => ItemPresenter::statuses(),
				),
			),
			'post_type'      => array(
				'description' => __( 'Only these post types.', 'translation-drift' ),
				'type'        => 'array',
				'items'       => array(
					'type' => 'string',
					'enum' => $this->tracked->post_types(),
				),
			),
			'author'         => array(
				'description' => __( 'Only sources by this author (user ID).', 'translation-drift' ),
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'translator'     => array(
				'description' => __( 'Only the languages assigned to this translator (user ID) in the settings.', 'translation-drift' ),
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'changed_after'  => array(
				'description' => __( 'Only sources changed at or after this time.', 'translation-drift' ),
				'type'        => 'string',
				'format'      => 'date-time',
			),
			'changed_before' => array(
				'description' => __( 'Only sources changed at or before this time.', 'translation-drift' ),
				'type'        => 'string',
				'format'      => 'date-time',
			),
			'orderby'        => array(
				'description' => __( 'Sort by the time the source last changed, or by title.', 'translation-drift' ),
				'type'        => 'string',
				'enum'        => array( 'modified', 'title' ),
				'default'     => 'modified',
			),
			'order'          => array(
				'description' => __( 'Sort direction.', 'translation-drift' ),
				'type'        => 'string',
				'enum'        => array( 'asc', 'desc' ),
				'default'     => 'desc',
			),
		);
	}

	/**
	 * Schema of a source item.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		if ( null === $this->schema ) {
			$this->schema = array_merge(
				array(
					'$schema' => 'http://json-schema.org/draft-04/schema#',
					'title'   => 'tdrift-source',
				),
				ItemPresenter::source_schema()
			);
		}

		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * Schema of the summary.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_summary_schema(): array {
		$counts = array(
			'type'                 => 'object',
			'additionalProperties' => array( 'type' => 'integer' ),
		);

		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'tdrift-summary',
			'type'       => 'object',
			'properties' => array(
				'totals'     => array_merge( $counts, array( 'description' => __( 'Translations per status.', 'translation-drift' ) ) ),
				'languages'  => array(
					'description' => __( 'Languages with their counts per status.', 'translation-drift' ),
					'type'        => 'array',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'code'   => array( 'type' => 'string' ),
							'name'   => array( 'type' => 'string' ),
							'counts' => $counts,
						),
					),
				),
				'post_types' => array(
					'description' => __( 'Tracked post types with their counts per status.', 'translation-drift' ),
					'type'        => 'array',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'slug'   => array( 'type' => 'string' ),
							'label'  => array( 'type' => 'string' ),
							'counts' => $counts,
						),
					),
				),
				'baseline'   => BaselineController::state_schema(),
			),
		);
	}
}
