<?php
/**
 * Admin UI: list table, metabox, admin-post actions, admin bar and settings.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Tests\Integration;

use Stalelingo\Admin\Actions;
use Stalelingo\Admin\AdminBar;
use Stalelingo\Admin\ListTable;
use Stalelingo\Admin\SettingsPage;
use Stalelingo\Domain\Status;
use Stalelingo\Services\BaselineJob;
use Stalelingo\Settings;
use Stalelingo\Tests\Integration\Support\RedirectStop;
use Stalelingo\Tests\Integration\Support\TestableActions;

/**
 * @covers \Stalelingo\Admin\ListTable
 * @covers \Stalelingo\Admin\Metabox
 * @covers \Stalelingo\Admin\Actions
 * @covers \Stalelingo\Admin\AdminBar
 * @covers \Stalelingo\Admin\SettingsPage
 * @covers \Stalelingo\Admin\StatusView
 * @covers \Stalelingo\Services\Repositories\SyncRepository
 */
final class AdminUiTest extends TestCase {

	/**
	 * Last redirect location.
	 *
	 * @var string|null
	 */
	private ?string $redirect = null;

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->redirect = null;
		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );
	}

	public function tear_down(): void {
		remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );
		unset( $_GET['stalelingo_status'], $_GET['post'], $_GET['_wpnonce'], $_REQUEST['_wpnonce'], $_POST['_wpnonce'], $_REQUEST['stalelingo_force'], $_POST['stalelingo_force'], $_GET['source'], $_GET['lang'] );
		delete_transient( AdminBar::CACHE_KEY );
		parent::tear_down();
	}

	/**
	 * Records the redirect and cancels it, so no header is sent.
	 */
	public function capture_redirect( string $location ): string {
		$this->redirect = $location;

		return '';
	}

	private function as_role( string $role ): int {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $id );

		return $id;
	}

	/**
	 * A synced group whose FR translation is outdated (title changed) and ES missing.
	 *
	 * @return array<string, int>
	 */
	private function outdated_group(): array {
		$group = $this->create_synced_group( array( 'en', 'fr' ) );
		wp_update_post(
			array(
				'ID'         => $group['en'],
				'post_title' => 'Changed title',
			)
		);
		$this->run_jobs();

		return $group;
	}

	private function render_column( ListTable $table, int $post_id ): string {
		ob_start();
		$table->render_column( ListTable::COLUMN, $post_id );

		return (string) ob_get_clean();
	}

	public function test_column_is_added_after_the_title_on_tracked_list_screens(): void {
		$table = $this->container()->list_table();
		$table->setup_screen( \WP_Screen::get( 'edit-post' ) );

		$columns = $table->add_column(
			array(
				'cb'    => '',
				'title' => 'Title',
				'date'  => 'Date',
			)
		);

		$this->assertSame( array( 'cb', 'title', ListTable::COLUMN, 'date' ), array_keys( $columns ) );
		$this->assertNotFalse( has_filter( 'manage_post_posts_columns', array( $table, 'add_column' ) ) );
		$this->assertFalse( has_filter( 'manage_attachment_posts_columns', array( $table, 'add_column' ) ) );
	}

	public function test_column_shows_a_labelled_badge_per_language(): void {
		$group                      = $this->outdated_group();
		$GLOBALS['wp_query']->posts = array( get_post( $group['en'] ), get_post( $group['fr'] ) );
		$table                      = new ListTable( $this->container()->tracked_fields(), $this->container()->provider(), $this->container()->sync_repository(), $this->container()->sync_service(), $this->container()->permissions() );

		$source = $this->render_column( $table, $group['en'] );

		$this->assertStringContainsString( 'stalelingo-badge-outdated', $source );
		$this->assertStringContainsString( 'FR: Outdated', $source, 'Screen-reader text, never color alone.' );
		$this->assertStringContainsString( 'ES: Missing', $source );
		$this->assertStringContainsString( 'post.php?post=' . $group['fr'] . '&#038;action=edit', $source );
		$this->assertStringContainsString( 'action=' . Actions::CREATE_TRANSLATION, $source );
		$this->assertStringContainsString( '_wpnonce=', $source );

		$translation = $this->render_column( $table, $group['fr'] );
		$this->assertStringContainsString( 'FR: Outdated', $translation );
		$this->assertStringNotContainsString( 'ES:', $translation );
	}

	public function test_column_adds_at_most_one_query_for_a_page_of_posts(): void {
		global $wpdb;

		$posts = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$group   = $this->create_synced_group();
			$posts[] = get_post( $group['en'] );
			$posts[] = get_post( $group['fr'] );
		}
		$GLOBALS['wp_query']->posts = $posts;
		$table                      = new ListTable( $this->container()->tracked_fields(), $this->container()->provider(), $this->container()->sync_repository(), $this->container()->sync_service(), $this->container()->permissions() );

		$before = $wpdb->num_queries;
		foreach ( $posts as $post ) {
			$this->render_column( $table, $post->ID );
		}

		$this->assertLessThanOrEqual( 1, $wpdb->num_queries - $before );
	}

	public function test_status_filter_dropdown_and_query(): void {
		$outdated                  = $this->outdated_group();
		$in_sync                   = $this->create_synced_group( array( 'en', 'fr' ) );
		$table                     = $this->container()->list_table();
		$_GET['stalelingo_status'] = 'outdated';

		ob_start();
		$table->render_filter( 'post' );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '<label class="screen-reader-text" for="stalelingo-status-filter">', $html );
		$this->assertMatchesRegularExpression( '/<option value="outdated" selected=\'selected\'>Outdated<\/option>/', $html );

		$query                   = new \WP_Query();
		$GLOBALS['wp_the_query'] = $query;
		$query->set( 'post_type', 'post' );
		set_current_screen( 'edit-post' );
		$table->filter_query( $query );

		$ids = $query->get( 'post__in' );
		$this->assertContains( $outdated['en'], $ids );
		$this->assertContains( $outdated['fr'], $ids );
		$this->assertNotContains( $in_sync['en'], $ids );
	}

	public function test_bulk_action_marks_translations_of_selected_sources(): void {
		$group = $this->outdated_group();
		$this->as_role( 'editor' );

		$url = $this->container()->list_table()->handle_bulk_action( 'edit.php', ListTable::BULK_ACTION, array( $group['en'] ) );

		$this->assertStringContainsString( 'stalelingo_marked=1', $url );
		$this->assertStringContainsString( 'stalelingo_denied=0', $url );
		$this->assertSame( Status::InSync, $this->status_of( $group['en'], 'fr' ) );
	}

	public function test_bulk_action_respects_permissions(): void {
		$group = $this->outdated_group();
		$this->as_role( 'author' ); // Can't edit other users' posts and lacks stalelingo_manage.

		$url = $this->container()->list_table()->handle_bulk_action( 'edit.php', ListTable::BULK_ACTION, array( $group['fr'] ) );

		$this->assertStringContainsString( 'stalelingo_denied=1', $url );
		$this->assertSame( Status::Outdated, $this->status_of( $group['en'], 'fr' ) );
		$this->assertSame( 'other.php', $this->container()->list_table()->handle_bulk_action( 'other.php', 'trash', array( $group['fr'] ) ) );
	}

	public function test_metabox_for_an_outdated_translation(): void {
		$group = $this->outdated_group();
		$this->as_role( 'editor' );

		ob_start();
		$this->container()->metabox()->render( get_post( $group['fr'] ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'FR: Outdated', $html );
		$this->assertStringContainsString( '<li>Title</li>', $html );
		$this->assertStringContainsString( 'action=' . Actions::MARK_SYNCED, $html );
		$this->assertStringContainsString( '_wpnonce=', $html );
		$this->assertStringContainsString( 'Last marked up to date', $html );
	}

	public function test_metabox_for_a_source_and_without_permission(): void {
		$group = $this->outdated_group();
		$this->as_role( 'author' );

		ob_start();
		$this->container()->metabox()->render( get_post( $group['en'] ) );
		$this->container()->metabox()->render( get_post( $group['fr'] ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'This is the source.', $html );
		$this->assertStringContainsString( 'ES: Missing', $html );
		$this->assertStringNotContainsString( 'Mark as up to date</a>', $html );
	}

	public function test_metabox_is_classic_only_and_on_tracked_types(): void {
		global $wp_meta_boxes;
		set_current_screen( 'post' );

		$this->container()->metabox()->add( 'post' );
		$this->container()->metabox()->add( 'attachment' );

		$box = $wp_meta_boxes['post']['side']['default']['stalelingo-status'] ?? null;
		$this->assertNotNull( $box );
		$this->assertTrue( $box['args']['__back_compat_meta_box'] );
		$this->assertArrayNotHasKey( 'attachment', (array) $wp_meta_boxes );
	}

	public function test_mark_synced_link_checks_the_nonce_and_permission(): void {
		$group   = $this->outdated_group();
		$actions = new TestableActions( $this->container()->provider(), $this->container()->sync_service(), $this->container()->baseline(), $this->container()->permissions() );

		// No nonce.
		$this->as_role( 'editor' );
		$_GET['post'] = (string) $group['fr'];
		try {
			$actions->mark_synced();
			$this->fail( 'Expected wp_die.' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( Status::Outdated, $this->status_of( $group['en'], 'fr' ) );
		}

		// Valid nonce, no permission.
		$this->as_role( 'author' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Actions::MARK_SYNCED . '_' . $group['fr'] );
		try {
			$actions->mark_synced();
			$this->fail( 'Expected wp_die.' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( Status::Outdated, $this->status_of( $group['en'], 'fr' ) );
		}

		// Valid nonce and permission.
		$this->as_role( 'editor' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Actions::MARK_SYNCED . '_' . $group['fr'] );
		try {
			$actions->mark_synced();
		} catch ( RedirectStop $e ) {
			$this->assertSame( Status::InSync, $this->status_of( $group['en'], 'fr' ) );
			$this->assertStringContainsString( 'stalelingo_marked=1', (string) $this->redirect );
		}
	}

	public function test_translator_can_mark_their_own_translation(): void {
		$translator = $this->as_role( 'author' );
		$group      = $this->create_synced_group( array( 'en', 'fr' ) );
		wp_update_post(
			array(
				'ID'          => $group['fr'],
				'post_author' => $translator,
			)
		);
		wp_update_post(
			array(
				'ID'         => $group['en'],
				'post_title' => 'Changed',
			)
		);
		$this->run_jobs();

		$this->assertTrue( $this->container()->permissions()->can_mark_synced( $translator, $group['fr'] ) );
		$this->assertFalse( $this->container()->permissions()->can_manage( $translator ) );
	}

	public function test_build_baseline_requires_nonce_and_capability(): void {
		$actions = new TestableActions( $this->container()->provider(), $this->container()->sync_service(), $this->container()->baseline(), $this->container()->permissions() );

		$this->as_role( 'author' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Actions::BUILD_BASELINE );
		try {
			$actions->build_baseline();
			$this->fail( 'Expected wp_die.' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( array(), $this->queued_jobs() );
		}

		$this->as_role( 'administrator' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Actions::BUILD_BASELINE );
		try {
			$actions->build_baseline();
		} catch ( RedirectStop $e ) {
			$this->assertSame( BaselineJob::BASELINE_HOOK, $this->queued_jobs()[0]['hook'] );
			$this->assertStringContainsString( 'page=' . SettingsPage::SLUG, (string) $this->redirect );
		}
	}

	public function test_open_translation_redirects_to_the_translation(): void {
		$group   = $this->create_synced_group( array( 'en', 'fr' ) );
		$actions = new TestableActions( $this->container()->provider(), $this->container()->sync_service(), $this->container()->baseline(), $this->container()->permissions() );
		$this->as_role( 'administrator' );
		$_GET['source']       = (string) $group['en'];
		$_GET['lang']         = 'fr';
		$_REQUEST['_wpnonce'] = wp_create_nonce( Actions::CREATE_TRANSLATION . '_' . $group['en'] . '_fr' );

		try {
			$actions->open_translation();
		} catch ( RedirectStop $e ) {
			$this->assertStringContainsString( 'post=' . $group['fr'], (string) $this->redirect );
		}

		$_GET['lang']         = 'xx';
		$_REQUEST['_wpnonce'] = wp_create_nonce( Actions::CREATE_TRANSLATION . '_' . $group['en'] . '_xx' );
		$this->expectException( \WPDieException::class );
		$actions->open_translation();
	}

	public function test_open_translation_needs_a_nonce_and_the_create_capability(): void {
		$group          = $this->create_synced_group( array( 'en', 'fr' ) );
		$actions        = new TestableActions( $this->container()->provider(), $this->container()->sync_service(), $this->container()->baseline(), $this->container()->permissions() );
		$_GET['source'] = (string) $group['en'];
		$_GET['lang']   = 'fr';

		$this->as_role( 'administrator' );
		$_REQUEST['_wpnonce'] = 'bad';
		try {
			$actions->open_translation();
			$this->fail( 'Expected wp_die for a bad nonce.' );
		} catch ( \WPDieException $e ) {
			$this->assertNull( $this->redirect );
		}

		$this->as_role( 'subscriber' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Actions::CREATE_TRANSLATION . '_' . $group['en'] . '_fr' );
		try {
			$actions->open_translation();
			$this->fail( 'Expected wp_die for a user who can\'t create posts.' );
		} catch ( \WPDieException $e ) {
			$this->assertNull( $this->redirect );
		}
	}

	public function test_admin_bar_counter_for_managers_only(): void {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		$this->outdated_group();

		$this->as_role( 'editor' );
		$bar = new \WP_Admin_Bar();
		$this->container()->admin_bar()->add_node( $bar );
		$node = $bar->get_node( AdminBar::NODE );
		$this->assertNotNull( $node );
		$this->assertStringContainsString( '1 outdated translation', (string) $node->title );

		$this->as_role( 'author' );
		$bar = new \WP_Admin_Bar();
		$this->container()->admin_bar()->add_node( $bar );
		$this->assertNull( $bar->get_node( AdminBar::NODE ) );
	}

	public function test_admin_bar_hides_when_nothing_is_outdated(): void {
		$this->create_synced_group( array( 'en', 'fr' ) );
		$this->as_role( 'administrator' );
		$bar = new \WP_Admin_Bar();

		$this->container()->admin_bar()->add_node( $bar );

		$this->assertNull( $bar->get_node( AdminBar::NODE ) );
	}

	public function test_settings_sanitize_keeps_valid_values_only(): void {
		$page  = new SettingsPage( $this->container() );
		$clean = $page->sanitize(
			array(
				'post_types'           => array( 'post', 'attachment', 'nope' ),
				'fields'               => array( 'title', 'slug', 'bogus' ),
				'meta_keys'            => "subtitle\n  hero_text , \n",
				'acf'                  => '1',
				'source_language'      => 'fr',
				'strict'               => 'on',
				'digest_frequency'     => 'hourly',
				'digest_recipients'    => "ok@example.test, not-an-email\nsecond@example.test",
				'immediate_post_types' => array( 'page', 'attachment' ),
				'translators'          => array(
					'fr' => array( '3', 'x', '0' ),
					'zz' => array( 1 ),
				),
				'unknown'              => 'dropped',
			)
		);

		$this->assertSame( array( 'post' ), $clean['post_types'] );
		$this->assertSame( array( 'title', 'slug' ), $clean['fields'] );
		$this->assertSame( array( 'subtitle', 'hero_text' ), $clean['meta_keys'] );
		$this->assertTrue( $clean['acf'] );
		$this->assertFalse( $clean['elementor'] );
		$this->assertSame( 'fr', $clean['source_language'] );
		$this->assertTrue( $clean['strict'] );
		$this->assertSame( 'off', $clean['digest_frequency'] );
		$this->assertSame( array( 'ok@example.test', 'second@example.test' ), $clean['digest_recipients'] );
		$this->assertSame( array( 'page' ), $clean['immediate_post_types'] );
		$this->assertSame( array( 'fr' => array( 3 ) ), $clean['translators'] );
		$this->assertArrayNotHasKey( 'unknown', $clean );
		$this->assertSame( Settings::defaults()['fields'], $page->sanitize( array() )['fields'], 'At least the default fields stay tracked.' );
		$this->assertSame( '', $page->sanitize( array( 'source_language' => 'xx' ) )['source_language'] );
	}

	public function test_sanitize_is_idempotent(): void {
		$page  = new SettingsPage( $this->container() );
		$input = array(
			'meta_keys'         => "subtitle\nhero_text",
			'digest_recipients' => 'a@example.test b@example.test',
			'post_types'        => array( 'post' ),
		);

		$once = $page->sanitize( $input );

		$this->assertSame( $once, $page->sanitize( $once ), 'WordPress can run the callback twice on one save.' );
	}

	public function test_first_save_through_the_settings_api_stores_lists(): void {
		delete_option( Settings::OPTION );
		$page = new SettingsPage( $this->container() );
		$page->register_settings();

		update_option(
			Settings::OPTION,
			array(
				'meta_keys'         => "subtitle\nhero_text",
				'digest_recipients' => 'a@example.test',
			)
		);

		$stored = get_option( Settings::OPTION );
		$this->assertSame( array( 'subtitle', 'hero_text' ), $stored['meta_keys'] );
		$this->assertSame( array( 'a@example.test' ), $stored['digest_recipients'] );
	}

	public function test_changing_what_is_tracked_recalculates_everything(): void {
		$page = new SettingsPage( $this->container() );

		$page->on_update( Settings::defaults(), array_merge( Settings::defaults(), array( 'digest_frequency' => 'daily' ) ) );
		$this->assertSame( array(), $this->queued_jobs() );

		$page->on_update( Settings::defaults(), array_merge( Settings::defaults(), array( 'meta_keys' => array( 'subtitle' ) ) ) );
		$this->assertSame( BaselineJob::RECALC_HOOK, $this->queued_jobs()[0]['hook'] );
	}

	public function test_settings_page_renders_accessible_fields(): void {
		$this->as_role( 'administrator' );
		$page = new SettingsPage( $this->container() );
		$page->register_settings();

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/name=[\'"]option_page[\'"] value=[\'"]' . SettingsPage::GROUP . '[\'"]/', $html );
		$this->assertStringContainsString( 'name="stalelingo_settings[post_types][]"', $html );
		$this->assertStringContainsString( '<label for="stalelingo-translators-fr">', $html );
		$this->assertStringContainsString( 'name="action" value="' . Actions::BUILD_BASELINE . '"', $html );
		$this->assertSame( 'stalelingo_manage', $page->capability() );
	}

	public function test_toggling_strict_mode_does_not_flag_translations(): void {
		$group = $this->create_synced_group( array( 'en', 'fr' ), array( 'post_content' => "<!-- wp:paragraph -->\n<p>Hi</p>\n<!-- /wp:paragraph -->" ) );

		$this->set_settings( array( 'strict' => true ) );
		$this->container()->drift_service()->recalculate_source( $group['en'] );
		$this->assertSame( Status::InSync, $this->status_of( $group['en'], 'fr' ), 'Both modes were hashed at the sync point.' );

		wp_update_post(
			array(
				'ID'           => $group['en'],
				'post_content' => "<!-- wp:paragraph {\"align\":\"center\"} -->\n<p class=\"has-text-align-center\">Hi</p>\n<!-- /wp:paragraph -->",
			)
		);
		$this->run_jobs();
		$this->assertSame( Status::Outdated, $this->status_of( $group['en'], 'fr' ), 'Strict mode sees the formatting change.' );

		$this->set_settings( array( 'strict' => false ) );
		$this->container()->drift_service()->recalculate_source( $group['en'] );
		$this->assertSame( Status::InSync, $this->status_of( $group['en'], 'fr' ), 'Normal mode ignores it.' );
	}
}
