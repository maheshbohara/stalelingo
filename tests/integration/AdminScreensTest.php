<?php
/**
 * Admin screen and notice tests.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Tests\Integration;

use Stalelingo\Admin\AdminPage;
use Stalelingo\Admin\EditorPanel;

/**
 * @covers \Stalelingo\Admin\AdminPage
 * @covers \Stalelingo\Admin\EditorPanel
 * @covers \Stalelingo\Admin\Assets
 * @covers \Stalelingo\Admin\StatusView
 */
final class AdminScreensTest extends TestCase {

	public function test_dashboard_requires_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$page = new AdminPage();

		$this->expectException( \WPDieException::class );
		$page->render();
	}

	public function test_dashboard_renders_mount_point_for_editors(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		ob_start();
		( new AdminPage() )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="stalelingo-dashboard-root"', $html );
	}

	public function test_assets_are_not_enqueued_on_other_screens(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$page = new AdminPage();
		$page->add_page();

		$page->enqueue( 'edit.php' );

		$this->assertFalse( wp_script_is( AdminPage::HANDLE, 'enqueued' ) );
	}

	public function test_assets_load_with_translations_on_the_dashboard_screen(): void {
		if ( ! is_readable( STALELINGO_DIR . 'build/dashboard/index.asset.php' ) ) {
			$this->markTestSkipped( 'Run `make build` first.' );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$page = new AdminPage();
		$page->add_page();

		// Without the Tools menu registered, WordPress names the hook admin_page_* instead of tools_page_*.
		$page->enqueue( get_plugin_page_hookname( AdminPage::SLUG, 'tools.php' ) );

		$this->assertTrue( wp_script_is( AdminPage::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( AdminPage::HANDLE, 'enqueued' ) );
		$this->assertContains( 'wp-element', wp_scripts()->registered[ AdminPage::HANDLE ]->deps );
		$this->assertSame( 'stalelingo', wp_scripts()->registered[ AdminPage::HANDLE ]->textdomain );
	}

	public function test_dashboard_config_lists_filter_choices(): void {
		$translator = self::factory()->user->create(
			array(
				'role'         => 'author',
				'display_name' => 'Translator Fr',
			)
		);
		$this->set_settings( array( 'translators' => array( 'fr' => array( $translator ) ) ) );
		$this->create_group( array( 'en', 'fr' ), array( 'post_author' => $translator ) );

		$config = ( new AdminPage( $this->container() ) )->config();

		$this->assertTrue( $config['ready'] );
		$this->assertSame( 'stalelingo/v1', $config['namespace'] );
		$this->assertSame( array( 'en', 'fr', 'es' ), array_column( $config['languages'], 'code' ) );
		$this->assertContains( 'post', array_column( $config['postTypes'], 'slug' ) );
		$this->assertNotContains( 'attachment', array_column( $config['postTypes'], 'slug' ) );
		$this->assertSame( 'en', $config['sourceLanguage'] );
		$this->assertContains( $translator, array_column( $config['authors'], 'id' ) );
		$this->assertSame(
			array(
				array(
					'id'    => $translator,
					'name'  => 'Translator Fr',
					'langs' => array( 'fr' ),
				),
			),
			$config['translators']
		);
	}

	public function test_post_types_sharing_a_name_are_told_apart(): void {
		register_post_type(
			'events',
			array(
				'labels' => array(
					'name'          => 'Events',
					'singular_name' => 'Event',
				),
			)
		);
		register_post_type(
			'mec-events',
			array(
				'labels' => array(
					'name'          => 'Events',
					'singular_name' => 'Event',
				),
			)
		);

		$labels = \Stalelingo\Admin\StatusView::post_type_labels( array( 'post', 'events', 'mec-events' ) );

		$this->assertSame( 'Posts', $labels['post'] );
		$this->assertSame( 'Events (events)', $labels['events'] );
		$this->assertSame( 'Events (mec-events)', $labels['mec-events'] );
		$this->assertSame( 'Event (mec-events)', \Stalelingo\Admin\StatusView::post_type_labels( array( 'events', 'mec-events' ), true )['mec-events'] );

		unregister_post_type( 'events' );
		unregister_post_type( 'mec-events' );
	}

	public function test_dashboard_script_gets_its_config_inline(): void {
		if ( ! is_readable( STALELINGO_DIR . 'build/dashboard/index.asset.php' ) ) {
			$this->markTestSkipped( 'Run `make build` first.' );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$page = new AdminPage( $this->container() );
		$page->add_page();

		$page->enqueue( get_plugin_page_hookname( AdminPage::SLUG, 'tools.php' ) );

		$before = implode( '', (array) wp_scripts()->get_data( AdminPage::HANDLE, 'before' ) );
		$this->assertStringContainsString( 'window.stalelingoDashboard = {', $before );
		$this->assertTrue( wp_style_is( \Stalelingo\Admin\Assets::ADMIN_STYLE, 'enqueued' ), 'Badge styles.' );
	}

	public function test_editor_panel_loads_for_tracked_post_types_only(): void {
		if ( ! is_readable( STALELINGO_DIR . 'build/editor/index.asset.php' ) ) {
			$this->markTestSkipped( 'Run `make build` first.' );
		}
		$panel = $this->container()->editor_panel();

		set_current_screen( 'attachment' );
		$panel->enqueue();
		$this->assertFalse( wp_script_is( EditorPanel::HANDLE, 'enqueued' ), 'Attachments are not tracked.' );

		set_current_screen( 'site-editor' );
		$panel->enqueue();
		$this->assertFalse( wp_script_is( EditorPanel::HANDLE, 'enqueued' ), 'No post type in the site editor.' );

		set_current_screen( 'post' );
		$panel->enqueue();
		$this->assertTrue( wp_script_is( EditorPanel::HANDLE, 'enqueued' ) );
		$deps = wp_scripts()->registered[ EditorPanel::HANDLE ]->deps;
		$this->assertContains( 'wp-editor', $deps );
		$this->assertContains( 'wp-plugins', $deps );
		$this->assertNotContains( 'wp-edit-post', $deps, 'The deprecated edit-post slots are not used.' );
		$this->assertSame( 'stalelingo', wp_scripts()->registered[ EditorPanel::HANDLE ]->textdomain );

		set_current_screen( 'front' );
	}

	public function test_dependency_notice_is_silent_while_polylang_is_active(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'plugins' );

		ob_start();
		( new \Stalelingo\Admin\DependencyNotice( new \Stalelingo\Providers\ProviderDetector() ) )->maybe_render();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_dependency_notice_shows_when_no_provider_is_active(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'plugins' );
		add_filter( 'stalelingo_provider', '__return_null' );
		$detector = new class() extends \Stalelingo\Providers\ProviderDetector {
			public function available(): array {
				return array();
			}
		};

		ob_start();
		( new \Stalelingo\Admin\DependencyNotice( $detector ) )->maybe_render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'stalelingo-dependency-notice', $html );
		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'Polylang or WPML', $html );
	}
}
