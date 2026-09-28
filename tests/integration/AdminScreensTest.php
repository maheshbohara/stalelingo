<?php
/**
 * Admin screen and notice tests.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Tests\Integration;

use TranslationDrift\Admin\AdminPage;

/**
 * @covers \TranslationDrift\Admin\AdminPage
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

		$this->assertStringContainsString( 'id="tdrift-dashboard-root"', $html );
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
		if ( ! is_readable( TDRIFT_DIR . 'build/dashboard/index.asset.php' ) ) {
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
		$this->assertSame( 'translation-drift', wp_scripts()->registered[ AdminPage::HANDLE ]->textdomain );
	}

	public function test_dependency_notice_is_silent_while_polylang_is_active(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'plugins' );

		ob_start();
		( new \TranslationDrift\Admin\DependencyNotice( new \TranslationDrift\Providers\ProviderDetector() ) )->maybe_render();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_dependency_notice_shows_when_no_provider_is_active(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'plugins' );
		add_filter( 'tdrift_provider', '__return_null' );
		$detector = new class() extends \TranslationDrift\Providers\ProviderDetector {
			public function available(): array {
				return array();
			}
		};

		ob_start();
		( new \TranslationDrift\Admin\DependencyNotice( $detector ) )->maybe_render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'tdrift-dependency-notice', $html );
		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'Polylang or WPML', $html );
	}
}
