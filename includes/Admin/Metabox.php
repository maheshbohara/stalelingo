<?php
/**
 * Classic editor metabox.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Admin;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Domain\Status;
use TranslationDrift\Providers\TranslationProvider;
use TranslationDrift\Services\Permissions;
use TranslationDrift\Services\Repositories\SyncRepository;
use TranslationDrift\Services\TrackedFields;

/**
 * Shows translation status in the classic editor. The block editor uses a sidebar panel instead.
 *
 * @since 0.1.0
 */
class Metabox {

	/**
	 * Metabox ID.
	 *
	 * @since 0.1.0
	 */
	public const ID = 'tdrift-status';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param TrackedFields       $tracked     Tracked fields.
	 * @param TranslationProvider $provider    Provider.
	 * @param SyncRepository      $sync        Sync rows.
	 * @param Permissions         $permissions Permissions.
	 */
	public function __construct(
		private TrackedFields $tracked,
		private TranslationProvider $provider,
		private SyncRepository $sync,
		private Permissions $permissions
	) {
	}

	/**
	 * Registers hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
	}

	/**
	 * Adds the metabox to tracked post types, in the classic editor only.
	 *
	 * @since 0.1.0
	 *
	 * @param string $post_type Post type.
	 */
	public function add( $post_type ): void {
		if ( ! $this->tracked->is_tracked_post_type( (string) $post_type ) ) {
			return;
		}

		add_meta_box(
			self::ID,
			__( 'Translation Drift', 'translation-drift' ),
			array( $this, 'render' ),
			(string) $post_type,
			'side',
			'default',
			array( '__back_compat_meta_box' => true )
		);
		add_action( 'admin_enqueue_scripts', array( Assets::class, 'enqueue_admin_style' ) );
	}

	/**
	 * Prints the metabox.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Post $post Post being edited.
	 */
	public function render( $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$source_id = $this->provider->get_source( $post->ID );
		if ( null === $source_id ) {
			echo '<p>' . esc_html__( 'This post has no source to compare with yet.', 'translation-drift' ) . '</p>';
			return;
		}

		if ( $source_id === $post->ID ) {
			$this->render_source( $post );
		} else {
			$this->render_translation( $post );
		}
	}

	/**
	 * A source: the status of each translation.
	 *
	 * @param \WP_Post $post Source.
	 */
	private function render_source( \WP_Post $post ): void {
		$rows = $this->sync->for_source( $post->ID );

		echo '<p>' . esc_html__( 'This is the source. Changing its tracked fields marks its translations as outdated.', 'translation-drift' ) . '</p>';
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'Translation status will appear once the baseline reaches this post.', 'translation-drift' ) . '</p>';
			return;
		}

		$badges = array();
		foreach ( $rows as $lang => $row ) {
			$badges[] = StatusView::badge( $lang, $row->status, Actions::translation_url( $row ) );
		}
		echo '<div class="tdrift-badges">' . implode( '', $badges ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- StatusView::badge() escapes every part.
	}

	/**
	 * A translation: its status, what changed, and the "Mark as up to date" link.
	 *
	 * @param \WP_Post $post Translation.
	 */
	private function render_translation( \WP_Post $post ): void {
		$row = $this->sync->find_by_translation( $post->ID );
		if ( null === $row ) {
			echo '<p>' . esc_html__( 'Not tracked yet. It will be once the baseline reaches it, or when you mark it as up to date.', 'translation-drift' ) . '</p>';
		} else {
			printf(
				'<p>%1$s %2$s</p>',
				esc_html__( 'Status:', 'translation-drift' ),
				StatusView::badge( $row->lang, $row->status ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by StatusView::badge().
			);

			if ( Status::Outdated === $row->status && array() !== $row->changed_fields ) {
				echo '<p>' . esc_html__( 'Changed in the source since the last sync:', 'translation-drift' ) . '</p><ul class="tdrift-metabox-fields">';
				foreach ( $row->changed_fields as $field ) {
					echo '<li>' . esc_html( StatusView::field_label( $field ) ) . '</li>';
				}
				echo '</ul>';
			}

			if ( null !== $row->synced_at ) {
				$user = $row->synced_by > 0 ? get_userdata( $row->synced_by ) : false;
				$when = human_time_diff( (int) strtotime( $row->synced_at . ' UTC' ) );
				$text = $user instanceof \WP_User
					/* translators: 1: time ago, e.g. "3 days". 2: user display name. */
					? sprintf( __( 'Last marked up to date %1$s ago by %2$s.', 'translation-drift' ), $when, $user->display_name )
					/* translators: %s: time ago, e.g. "3 days". */
					: sprintf( __( 'Last marked up to date %s ago.', 'translation-drift' ), $when );
				echo '<p class="description">' . esc_html( $text ) . '</p>';
			}
		}//end if

		if ( ( null === $row || Status::InSync !== $row->status ) && $this->permissions->can_mark_synced( get_current_user_id(), $post->ID ) ) {
			printf(
				'<p><a class="button" href="%1$s">%2$s</a></p>',
				esc_url( Actions::mark_synced_url( $post->ID ) ),
				esc_html__( 'Mark as up to date', 'translation-drift' )
			);
		}
	}
}
