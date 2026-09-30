<?php
/**
 * Admin form and link handlers.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Admin;

defined( 'ABSPATH' ) || exit;

use Stalelingo\Capabilities;
use Stalelingo\Providers\TranslationProvider;
use Stalelingo\Services\BaselineJob;
use Stalelingo\Services\Permissions;
use Stalelingo\Services\Repositories\SyncRow;
use Stalelingo\Services\SyncService;

/**
 * Handles `admin-post.php` requests: mark as up to date, build the baseline, open a translation.
 *
 * Every handler checks a nonce and the user's permission.
 *
 * @since 1.0.0
 */
class Actions {

	/**
	 * Action names.
	 *
	 * @since 1.0.0
	 */
	public const MARK_SYNCED        = 'stalelingo_mark_synced';
	public const BUILD_BASELINE     = 'stalelingo_build_baseline';
	public const CREATE_TRANSLATION = 'stalelingo_translation';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param TranslationProvider $provider    Provider.
	 * @param SyncService         $syncer      Sync service.
	 * @param BaselineJob         $baseline    Baseline job.
	 * @param Permissions         $permissions Permissions.
	 */
	public function __construct(
		private TranslationProvider $provider,
		private SyncService $syncer,
		private BaselineJob $baseline,
		private Permissions $permissions
	) {
	}

	/**
	 * Registers hooks.
	 *
	 * @since 1.0.0
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::MARK_SYNCED, array( $this, 'mark_synced' ) );
		add_action( 'admin_post_' . self::BUILD_BASELINE, array( $this, 'build_baseline' ) );
		add_action( 'admin_post_' . self::CREATE_TRANSLATION, array( $this, 'open_translation' ) );
		add_action( 'admin_notices', array( $this, 'marked_notice' ) );
	}

	/**
	 * URL of the "Mark as up to date" link for a translation.
	 *
	 * @since 1.0.0
	 *
	 * @param int $translation_id Translation post ID.
	 */
	public static function mark_synced_url( int $translation_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::MARK_SYNCED,
					'post'   => $translation_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::MARK_SYNCED . '_' . $translation_id
		);
	}

	/**
	 * Link of a status badge: the translation's edit screen, or a redirect to the provider's
	 * "add translation" screen when it's missing. Builds the URL without any query.
	 *
	 * @since 1.0.0
	 *
	 * @param SyncRow $row Row.
	 */
	public static function translation_url( SyncRow $row ): string {
		if ( $row->translation_id > 0 ) {
			return add_query_arg(
				array(
					'post'   => $row->translation_id,
					'action' => 'edit',
				),
				admin_url( 'post.php' )
			);
		}

		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::CREATE_TRANSLATION,
					'source' => $row->source_id,
					'lang'   => $row->lang,
				),
				admin_url( 'admin-post.php' )
			),
			self::CREATE_TRANSLATION . '_' . $row->source_id . '_' . $row->lang
		);
	}

	/**
	 * Marks one translation as up to date and returns to where the user was.
	 *
	 * @since 1.0.0
	 */
	public function mark_synced(): void {
		$translation_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified on the next line.
		check_admin_referer( self::MARK_SYNCED . '_' . $translation_id );

		if ( ! $this->permissions->can_mark_synced( get_current_user_id(), $translation_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to update this translation.', 'stalelingo' ), 403 );
		}

		$done = $this->syncer->mark_synced( $translation_id, get_current_user_id() );

		$back = wp_get_referer();
		wp_safe_redirect( add_query_arg( 'stalelingo_marked', $done ? 1 : 0, false === $back ? admin_url() : $back ) );
		$this->finish();
	}

	/**
	 * Queues a baseline build from the settings screen.
	 *
	 * @since 1.0.0
	 */
	public function build_baseline(): void {
		check_admin_referer( self::BUILD_BASELINE );

		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'stalelingo' ), 403 );
		}

		$force = ! empty( $_POST['stalelingo_force'] );
		$this->baseline->start_baseline( $force );

		wp_safe_redirect( add_query_arg( 'stalelingo_baseline', 'queued', admin_url( 'options-general.php?page=' . SettingsPage::SLUG ) ) );
		$this->finish();
	}

	/**
	 * Redirects to a translation's edit screen, or to the provider's screen to create it.
	 *
	 * @since 1.0.0
	 */
	public function open_translation(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified right below.
		$source = isset( $_GET['source'] ) ? absint( wp_unslash( $_GET['source'] ) ) : 0;
		$lang   = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
		// phpcs:enable
		check_admin_referer( self::CREATE_TRANSLATION . '_' . $source . '_' . $lang );

		$type = get_post_type_object( (string) get_post_type( $source ) );
		if ( null === $type || ! current_user_can( $type->cap->create_posts ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to create this translation.', 'stalelingo' ), 403 );
		}

		$url = $source > 0 && in_array( $lang, $this->provider->get_languages(), true )
			? $this->provider->get_edit_translation_url( $source, $lang )
			: null;

		if ( null === $url ) {
			wp_die( esc_html__( 'This translation can’t be opened from here. Create it from the source post’s language settings.', 'stalelingo' ), 404 );
		}

		wp_safe_redirect( $url );
		$this->finish();
	}

	/**
	 * Confirms a single "Mark as up to date".
	 *
	 * @since 1.0.0
	 */
	public function marked_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag added to the redirect by mark_synced().
		if ( ! isset( $_GET['stalelingo_marked'] ) || isset( $_GET['stalelingo_denied'] ) ) {
			return;
		}

		$done = '1' === sanitize_key( wp_unslash( $_GET['stalelingo_marked'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_admin_notice(
			$done ? esc_html__( 'Translation marked as up to date.', 'stalelingo' ) : esc_html__( 'This post isn’t a translation of a tracked source.', 'stalelingo' ),
			array(
				'type'        => $done ? 'success' : 'warning',
				'dismissible' => true,
			)
		);
	}

	/**
	 * Ends the request after a redirect. Overridden in tests.
	 *
	 * @since 1.0.0
	 */
	protected function finish(): void {
		exit;
	}
}
