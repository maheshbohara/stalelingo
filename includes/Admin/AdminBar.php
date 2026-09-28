<?php
/**
 * Admin bar counter.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Admin;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Services\Permissions;
use TranslationDrift\Services\Repositories\SyncRepository;

/**
 * Shows the number of outdated translations in the admin bar, for users who manage translations.
 *
 * @since 0.1.0
 */
class AdminBar {

	/**
	 * Node ID.
	 *
	 * @since 0.1.0
	 */
	public const NODE = 'tdrift-outdated';

	/**
	 * Transient caching the count.
	 *
	 * @since 0.1.0
	 */
	public const CACHE_KEY = 'tdrift_outdated_count';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param SyncRepository $sync        Sync rows.
	 * @param Permissions    $permissions Permissions.
	 */
	public function __construct( private SyncRepository $sync, private Permissions $permissions ) {
	}

	/**
	 * Registers hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'add_node' ), 90 );
		foreach ( array( 'tdrift_drift_detected', 'tdrift_marked_synced' ) as $hook ) {
			add_action( $hook, array( self::class, 'flush' ) );
		}
	}

	/**
	 * Number of outdated translations, cached for two minutes.
	 *
	 * @since 0.1.0
	 */
	public function outdated_count(): int {
		$count = get_transient( self::CACHE_KEY );
		if ( false === $count ) {
			$count = $this->sync->count_by_status()['outdated'];
			set_transient( self::CACHE_KEY, $count, 2 * MINUTE_IN_SECONDS );
		}

		return (int) $count;
	}

	/**
	 * Forgets the cached count.
	 *
	 * @since 0.1.0
	 */
	public static function flush(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Adds the counter node.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 */
	public function add_node( $bar ): void {
		if ( ! $bar instanceof \WP_Admin_Bar || ! $this->permissions->can_manage( get_current_user_id() ) ) {
			return;
		}

		$count = $this->outdated_count();
		if ( $count < 1 ) {
			return;
		}

		/* translators: %s: number of outdated translations. */
		$label = sprintf( _n( '%s outdated translation', '%s outdated translations', $count, 'translation-drift' ), number_format_i18n( $count ) );

		$bar->add_node(
			array(
				'id'    => self::NODE,
				'title' => sprintf(
					'<span class="ab-icon dashicons dashicons-translation" aria-hidden="true"></span><span class="ab-label" aria-hidden="true">%1$s</span><span class="screen-reader-text">%2$s</span>',
					esc_html( number_format_i18n( $count ) ),
					esc_html( $label )
				),
				'href'  => admin_url( 'tools.php?page=' . AdminPage::SLUG . '&status=outdated' ),
				'meta'  => array( 'title' => $label ),
			)
		);
	}
}
