<?php
/**
 * Missing multilingual plugin notice.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Admin;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Capabilities;
use TranslationDrift\Providers\ProviderDetector;

/**
 * Shows one notice when neither Polylang nor WPML is active.
 *
 * The notice appears only on the Plugins screens and the plugin's own screens.
 *
 * @since 1.0.0
 */
final class DependencyNotice {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param ProviderDetector $detector Provider detector.
	 */
	public function __construct( private ProviderDetector $detector ) {
	}

	/**
	 * Registers hooks.
	 *
	 * @since 1.0.0
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'maybe_render' ) );
		add_action( 'network_admin_notices', array( $this, 'maybe_render' ) );
	}

	/**
	 * Whether the notice should show for the given screen.
	 *
	 * @since 1.0.0
	 *
	 * @param string $screen_id Current screen ID.
	 */
	public function should_show( string $screen_id ): bool {
		if ( null !== $this->detector->detect() ) {
			return false;
		}

		$is_plugins_screen = in_array( $screen_id, array( 'plugins', 'plugins-network' ), true );
		if ( ! $is_plugins_screen && ! AdminPage::is_own_screen( $screen_id ) ) {
			return false;
		}

		return current_user_can( 'activate_plugins' ) || current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Prints the notice when appropriate.
	 *
	 * @since 1.0.0
	 */
	public function maybe_render(): void {
		$screen = get_current_screen();
		if ( null === $screen || ! $this->should_show( $screen->id ) ) {
			return;
		}

		wp_admin_notice(
			esc_html__( 'Translation Drift needs Polylang or WPML to be active. It has nothing to track until one of them is.', 'translation-drift' ),
			array(
				'type'               => 'warning',
				'additional_classes' => array( 'tdrift-dependency-notice' ),
			)
		);
	}
}
