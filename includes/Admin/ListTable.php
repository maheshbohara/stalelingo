<?php
/**
 * Posts list table integration.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Admin;

defined( 'ABSPATH' ) || exit;

use Stalelingo\Domain\Status;
use Stalelingo\Providers\TranslationProvider;
use Stalelingo\Services\Permissions;
use Stalelingo\Services\Repositories\SyncRepository;
use Stalelingo\Services\Repositories\SyncRow;
use Stalelingo\Services\SyncService;
use Stalelingo\Services\TrackedFields;

/**
 * Adds a translation status column, a status filter and a bulk action to tracked post types.
 *
 * The column reads the cached status only. Rows for every post on the page
 * are loaded in one query the first time the column renders.
 *
 * @since 1.0.0
 */
class ListTable {

	/**
	 * Column key.
	 *
	 * @since 1.0.0
	 */
	public const COLUMN = 'stalelingo-status';

	/**
	 * Filter query var.
	 *
	 * @since 1.0.0
	 */
	public const FILTER = 'stalelingo_status';

	/**
	 * Bulk action key.
	 *
	 * @since 1.0.0
	 */
	public const BULK_ACTION = 'stalelingo_mark_synced';

	/**
	 * Rows of the posts on the current page: keyed by source ID, then language.
	 *
	 * @var array<int, array<string, SyncRow>>|null
	 */
	private ?array $by_source = null;

	/**
	 * Rows keyed by translation ID.
	 *
	 * @var array<int, SyncRow>
	 */
	private array $by_translation = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param TrackedFields       $tracked     Tracked fields.
	 * @param TranslationProvider $provider    Provider.
	 * @param SyncRepository      $sync        Sync rows.
	 * @param SyncService         $syncer      Sync service.
	 * @param Permissions         $permissions Permissions.
	 */
	public function __construct(
		private TrackedFields $tracked,
		private TranslationProvider $provider,
		private SyncRepository $sync,
		private SyncService $syncer,
		private Permissions $permissions
	) {
	}

	/**
	 * Registers hooks.
	 *
	 * @since 1.0.0
	 */
	public function register(): void {
		add_action( 'current_screen', array( $this, 'setup_screen' ) );
	}

	/**
	 * Hooks into the list screen of a tracked post type.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Screen $screen Current screen.
	 */
	public function setup_screen( $screen ): void {
		if ( ! $screen instanceof \WP_Screen || 'edit' !== $screen->base || ! $this->tracked->is_tracked_post_type( (string) $screen->post_type ) ) {
			return;
		}

		$post_type = (string) $screen->post_type;
		add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_column' ) );
		add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'render_filter' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_query' ) );
		add_filter( "bulk_actions-edit-{$post_type}", array( $this, 'add_bulk_action' ) );
		add_filter( "handle_bulk_actions-edit-{$post_type}", array( $this, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
		add_action( 'admin_enqueue_scripts', array( Assets::class, 'enqueue_admin_style' ) );
	}

	/**
	 * Adds the column after the title.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function add_column( $columns ): array {
		$added = array();
		foreach ( (array) $columns as $key => $label ) {
			$added[ $key ] = $label;
			if ( 'title' === $key ) {
				$added[ self::COLUMN ] = __( 'Translations', 'stalelingo' );
			}
		}
		if ( ! isset( $added[ self::COLUMN ] ) ) {
			$added[ self::COLUMN ] = __( 'Translations', 'stalelingo' );
		}

		return $added;
	}

	/**
	 * Prints the badges of a post.
	 *
	 * Sources show one badge per language; a translation shows its own status.
	 *
	 * @since 1.0.0
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_column( $column, $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$this->load_page();
		$post_id = (int) $post_id;
		$badges  = array();

		if ( isset( $this->by_source[ $post_id ] ) ) {
			foreach ( $this->by_source[ $post_id ] as $lang => $row ) {
				$badges[] = StatusView::badge( $lang, $row->status, Actions::translation_url( $row ) );
			}
		} elseif ( isset( $this->by_translation[ $post_id ] ) ) {
			$row      = $this->by_translation[ $post_id ];
			$badges[] = StatusView::badge( $row->lang, $row->status );
		}

		if ( array() === $badges ) {
			echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'No translation status', 'stalelingo' ) . '</span>';
			return;
		}

		echo '<div class="stalelingo-badges">' . implode( '', $badges ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- StatusView::badge() escapes every part.
	}

	/**
	 * Prints the status filter dropdown.
	 *
	 * @since 1.0.0
	 *
	 * @param string $post_type Post type of the list.
	 */
	public function render_filter( $post_type ): void {
		if ( ! $this->tracked->is_tracked_post_type( (string) $post_type ) ) {
			return;
		}

		$current = $this->requested_status();
		echo '<label class="screen-reader-text" for="stalelingo-status-filter">' . esc_html__( 'Filter by translation status', 'stalelingo' ) . '</label>';
		echo '<select name="' . esc_attr( self::FILTER ) . '" id="stalelingo-status-filter">';
		echo '<option value="">' . esc_html__( 'All translation statuses', 'stalelingo' ) . '</option>';
		foreach ( Status::cases() as $status ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $status->value ),
				selected( null !== $current && $current === $status, true, false ),
				esc_html( StatusView::label( $status ) )
			);
		}
		echo '</select>';
	}

	/**
	 * Limits the list to posts with the requested status.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Query $query Query.
	 */
	public function filter_query( $query ): void {
		$status = $this->requested_status();
		if ( null === $status || ! $query instanceof \WP_Query || ! $query->is_main_query() || ! is_admin() ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		$post_type = is_string( $post_type ) && '' !== $post_type ? $post_type : 'post';
		$ids       = $this->sync->post_ids_with_status( $status, $post_type );

		$query->set( 'post__in', array() === $ids ? array( 0 ) : $ids );
	}

	/**
	 * Adds the bulk action.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string> $actions Bulk actions.
	 * @return array<string, string>
	 */
	public function add_bulk_action( $actions ): array {
		$actions                      = (array) $actions;
		$actions[ self::BULK_ACTION ] = __( 'Mark translations as up to date', 'stalelingo' );

		return $actions;
	}

	/**
	 * Marks the selected translations, and the translations of selected sources, as up to date.
	 *
	 * WordPress verifies the list table's nonce before this runs. Each
	 * translation is checked against the user's permission.
	 *
	 * @since 1.0.0
	 *
	 * @param string    $redirect Redirect URL.
	 * @param string    $action   Bulk action.
	 * @param list<int> $post_ids Selected post IDs.
	 */
	public function handle_bulk_action( $redirect, $action, $post_ids ): string {
		if ( self::BULK_ACTION !== $action ) {
			return (string) $redirect;
		}

		$user_id = get_current_user_id();
		$marked  = 0;
		$denied  = 0;

		foreach ( $this->translations_of( array_map( 'intval', (array) $post_ids ) ) as $translation_id ) {
			if ( ! $this->permissions->can_mark_synced( $user_id, $translation_id ) ) {
				++$denied;
				continue;
			}
			if ( $this->syncer->mark_synced( $translation_id, $user_id ) ) {
				++$marked;
			}
		}

		return add_query_arg(
			array(
				'stalelingo_marked' => $marked,
				'stalelingo_denied' => $denied,
			),
			remove_query_arg( array( 'stalelingo_marked', 'stalelingo_denied' ), (string) $redirect )
		);
	}

	/**
	 * Reports the result of the bulk action.
	 *
	 * @since 1.0.0
	 */
	public function bulk_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only counts added to the redirect by handle_bulk_action().
		if ( ! isset( $_GET['stalelingo_marked'] ) ) {
			return;
		}
		$marked = absint( wp_unslash( $_GET['stalelingo_marked'] ) );
		$denied = isset( $_GET['stalelingo_denied'] ) ? absint( wp_unslash( $_GET['stalelingo_denied'] ) ) : 0;
		// phpcs:enable

		/* translators: %d: number of translations. */
		$message = sprintf( _n( '%d translation marked as up to date.', '%d translations marked as up to date.', $marked, 'stalelingo' ), $marked );
		if ( $denied > 0 ) {
			/* translators: %d: number of translations. */
			$message .= ' ' . sprintf( _n( 'You are not allowed to update %d translation.', 'You are not allowed to update %d translations.', $denied, 'stalelingo' ), $denied );
		}

		wp_admin_notice(
			esc_html( $message ),
			array(
				'type'        => $denied > 0 ? 'warning' : 'success',
				'dismissible' => true,
			)
		);
	}

	/**
	 * The status requested by the filter dropdown, if valid.
	 *
	 * @since 1.0.0
	 */
	public function requested_status(): ?Status {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A list filter, like core's own; it only narrows what the user can already see.
		$value = isset( $_GET[ self::FILTER ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER ] ) ) : '';

		return '' === $value ? null : Status::tryFrom( $value );
	}

	/**
	 * Translation IDs selected directly, or through their source.
	 *
	 * @param list<int> $post_ids Post IDs.
	 * @return list<int>
	 */
	private function translations_of( array $post_ids ): array {
		$ids = array();
		foreach ( $post_ids as $post_id ) {
			$source = $this->provider->get_source( $post_id );
			if ( null === $source ) {
				continue;
			}
			if ( $source !== $post_id ) {
				$ids[] = $post_id;
				continue;
			}
			foreach ( $this->provider->get_group( $post_id ) as $translation_id ) {
				if ( $translation_id !== $post_id ) {
					$ids[] = $translation_id;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Loads the rows of every post in the main query, once.
	 */
	private function load_page(): void {
		if ( null !== $this->by_source ) {
			return;
		}

		global $wp_query;
		$ids = array();
		foreach ( (array) ( $wp_query->posts ?? array() ) as $post ) {
			$ids[] = $post instanceof \WP_Post ? $post->ID : (int) $post;
		}

		$this->by_source = array();
		foreach ( $this->sync->for_posts( $ids ) as $row ) {
			if ( in_array( $row->source_id, $ids, true ) ) {
				$this->by_source[ $row->source_id ][ $row->lang ] = $row;
			}
			if ( $row->translation_id > 0 ) {
				$this->by_translation[ $row->translation_id ] = $row;
			}
		}
	}
}
