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
use TranslationDrift\Container;
use TranslationDrift\Plugin;
use TranslationDrift\Rest\Controller;

/**
 * Registers the dashboard screen and loads its assets on that screen only.
 *
 * @since 1.0.0
 */
final class AdminPage {

	/**
	 * Menu slug of the dashboard.
	 *
	 * @since 1.0.0
	 */
	public const SLUG = 'translation-drift';

	/**
	 * Script and style handle of the dashboard bundle.
	 *
	 * @since 1.0.0
	 */
	public const HANDLE = 'tdrift-dashboard';

	/**
	 * Hook suffix returned by add_management_page().
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Container|null $container Service container; defaults to the shared one.
	 */
	public function __construct( private ?Container $container = null ) {
	}

	/**
	 * Registers hooks.
	 *
	 * @since 1.0.0
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Adds the page under Tools.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		if ( ! Assets::enqueue_bundle( self::HANDLE, 'dashboard/index', array( 'wp-components' ) ) ) {
			return;
		}

		// JSON_HEX_TAG keeps user-controlled names (authors, translators) from ever closing the script tag.
		wp_add_inline_script( self::HANDLE, 'window.tdriftDashboard = ' . wp_json_encode( $this->config(), JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
	}

	/**
	 * Settings the dashboard needs before its first request: filter choices and links.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function config(): array {
		$container = $this->container ?? Plugin::container();
		$config    = array(
			'ready'          => $container->has_provider(),
			'namespace'      => Controller::REST_NAMESPACE,
			'settingsUrl'    => admin_url( 'options-general.php?page=' . SettingsPage::SLUG ),
			'languages'      => array(),
			// Hidden column by default: nearly every row is a source there.
			'sourceLanguage' => '',
			'postTypes'      => array(),
			'authors'        => array(),
			'translators'    => array(),
		);

		$provider = $container->provider();
		if ( null === $provider ) {
			return $config;
		}

		$source                   = $container->settings()->get( 'source_language' );
		$config['sourceLanguage'] = is_string( $source ) && '' !== $source ? $source : $provider->get_default_language();

		$names = $provider->get_language_names();
		foreach ( $provider->get_languages() as $code ) {
			$config['languages'][] = array(
				'code' => $code,
				'name' => $names[ $code ] ?? strtoupper( $code ),
			);
		}

		$post_types = $container->tracked_fields()->post_types();
		foreach ( StatusView::post_type_labels( $post_types ) as $type => $label ) {
			$config['postTypes'][] = array(
				'slug'  => $type,
				'label' => $label,
			);
		}

		if ( array() !== $post_types ) {
			$authors = get_users(
				array(
					'has_published_posts' => $post_types,
					'fields'              => array( 'ID', 'display_name' ),
					'orderby'             => 'display_name',
					'number'              => 200,
				)
			);
			foreach ( $authors as $author ) {
				$config['authors'][] = array(
					'id'   => (int) $author->ID,
					'name' => (string) $author->display_name,
				);
			}
		}

		$langs_by_user = array();
		foreach ( (array) $container->settings()->get( 'translators' ) as $lang => $users ) {
			foreach ( array_map( 'intval', (array) $users ) as $user_id ) {
				$langs_by_user[ $user_id ][] = (string) $lang;
			}
		}
		foreach ( $langs_by_user as $user_id => $langs ) {
			$user = get_userdata( $user_id );
			if ( $user instanceof \WP_User ) {
				$config['translators'][] = array(
					'id'    => $user_id,
					'name'  => $user->display_name,
					'langs' => $langs,
				);
			}
		}

		return $config;
	}

	/**
	 * Renders the mount point for the React dashboard.
	 *
	 * @since 1.0.0
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
