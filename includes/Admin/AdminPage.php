<?php
/**
 * Tools → Translation Drift screen.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Admin;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Capabilities;

/**
 * Registers the dashboard screen and loads its assets on that screen only.
 *
 * @since 0.1.0
 */
final class AdminPage {

	/**
	 * Menu slug of the dashboard.
	 *
	 * @since 0.1.0
	 */
	public const SLUG = 'translation-drift';

	/**
	 * Script and style handle of the dashboard bundle.
	 *
	 * @since 0.1.0
	 */
	public const HANDLE = 'tdrift-dashboard';

	/**
	 * Hook suffix returned by add_management_page().
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Registers hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Adds the page under Tools.
	 *
	 * @since 0.1.0
	 */
	public function add_page(): void {
		$hook_suffix = add_management_page(
			__( 'Translation Drift', 'translation-drift' ),
			__( 'Translation Drift', 'translation-drift' ),
			Capabilities::MANAGE,
			self::SLUG,
			array( $this, 'render' )
		);

		$this->hook_suffix = is_string( $hook_suffix ) ? $hook_suffix : '';
	}

	/**
	 * Whether the given screen ID belongs to one of the plugin's own screens.
	 *
	 * @since 0.1.0
	 *
	 * @param string $screen_id Screen ID or hook suffix.
	 */
	public static function is_own_screen( string $screen_id ): bool {
		return in_array(
			$screen_id,
			array( 'tools_page_' . self::SLUG, 'settings_page_' . SettingsPage::SLUG ),
			true
		);
	}

	/**
	 * Enqueues the dashboard bundle on the dashboard screen only.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		$asset_file = TDRIFT_DIR . 'build/dashboard/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}

		$asset   = require $asset_file;
		$deps    = is_array( $asset ) && is_array( $asset['dependencies'] ?? null ) ? array_values( array_filter( $asset['dependencies'], 'is_string' ) ) : array();
		$version = is_array( $asset ) && is_string( $asset['version'] ?? null ) ? $asset['version'] : TDRIFT_VERSION;

		wp_enqueue_script(
			self::HANDLE,
			TDRIFT_URL . 'build/dashboard/index.js',
			$deps,
			$version,
			array( 'in_footer' => true )
		);
		wp_set_script_translations( self::HANDLE, 'translation-drift' );

		if ( is_readable( TDRIFT_DIR . 'build/dashboard/index.css' ) ) {
			wp_enqueue_style( self::HANDLE, TDRIFT_URL . 'build/dashboard/index.css', array( 'wp-components' ), $version );
		}
	}

	/**
	 * Renders the mount point for the React dashboard.
	 *
	 * @since 0.1.0
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'translation-drift' ), 403 );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Translation Drift', 'translation-drift' ); ?></h1>
			<div id="tdrift-dashboard-root" class="tdrift-dashboard"></div>
		</div>
		<?php
	}
}
