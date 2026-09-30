<?php
/**
 * Personal data exporters, eraser and privacy policy text.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Tests\Integration;

/**
 * @covers \Stalelingo\Privacy
 * @covers \Stalelingo\Services\Repositories\SyncRepository
 * @covers \Stalelingo\Services\Repositories\EventRepository
 */
final class PrivacyTest extends TestCase {

	/**
	 * A user who marked the FR translation of a group as up to date.
	 *
	 * @return array{user: \WP_User, group: array<string, int>}
	 */
	private function marked_by_user(): array {
		$user_id = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'marker@example.test',
			)
		);
		$group   = $this->create_group( array( 'en', 'fr' ), array( 'post_title' => 'Hello' ) );
		$this->container()->sync_service()->mark_synced( $group['fr'], $user_id );

		return array(
			'user'  => get_userdata( $user_id ),
			'group' => $group,
		);
	}

	public function test_exporters_and_eraser_are_registered(): void {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		$this->assertArrayHasKey( 'stalelingo-sync', $exporters );
		$this->assertArrayHasKey( 'stalelingo-events', $exporters );
		$this->assertArrayHasKey( 'stalelingo', $erasers );
		$this->assertIsCallable( $erasers['stalelingo']['callback'] );
	}

	public function test_exports_what_the_user_marked_and_did(): void {
		$data = $this->marked_by_user();

		$sync = $this->container()->privacy()->export_sync( 'marker@example.test', 1 );
		$this->assertTrue( $sync['done'] );
		$this->assertCount( 1, $sync['data'] );
		$this->assertSame( 'stalelingo', $sync['data'][0]['group_id'] );
		$this->assertSame( 'Hello (FR, #' . $data['group']['fr'] . ')', $sync['data'][0]['data'][0]['value'] );

		$events = $this->container()->privacy()->export_events( 'marker@example.test', 1 );
		$this->assertCount( 1, $events['data'] );
		$this->assertSame( 'Marked as up to date', $events['data'][0]['data'][0]['value'] );
	}

	public function test_export_pages_through_many_records(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'many@example.test' ) );
		for ( $i = 0; $i < \Stalelingo\Privacy::PAGE_SIZE + 1; $i++ ) {
			$this->container()->event_repository()->log( 'marked_synced', 1, 2, 'fr', $user_id );
		}

		$first  = $this->container()->privacy()->export_events( 'many@example.test', 1 );
		$second = $this->container()->privacy()->export_events( 'many@example.test', 2 );

		$this->assertCount( \Stalelingo\Privacy::PAGE_SIZE, $first['data'] );
		$this->assertFalse( $first['done'] );
		$this->assertCount( 1, $second['data'] );
		$this->assertTrue( $second['done'] );
	}

	public function test_eraser_anonymizes_the_user(): void {
		$data = $this->marked_by_user();

		$result = $this->container()->privacy()->erase( 'marker@example.test', 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 0, $this->row( $data['group']['fr'] )?->synced_by );
		$this->assertSame( array(), $this->container()->event_repository()->for_user( $data['user']->ID, 10 ) );
		$this->assertSame( array(), $this->container()->privacy()->export_sync( 'marker@example.test', 1 )['data'] );
		$this->assertNotNull( $this->row( $data['group']['fr'] )?->synced_at, 'The sync point itself is kept.' );
	}

	public function test_unknown_email_exports_and_erases_nothing(): void {
		$this->assertSame(
			array(
				'data' => array(),
				'done' => true,
			),
			$this->container()->privacy()->export_sync( 'nobody@example.test', 1 )
		);
		$this->assertFalse( $this->container()->privacy()->erase( 'nobody@example.test', 1 )['items_removed'] );
	}

	public function test_suggests_privacy_policy_text(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		set_current_screen( 'dashboard' );

		// Core accepts suggestions only while admin_init runs (or after); mark it as running.
		$GLOBALS['wp_current_filter'][] = 'admin_init';
		$this->container()->privacy()->add_policy_content();
		array_pop( $GLOBALS['wp_current_filter'] );

		$suggested = \WP_Privacy_Policy_Content::get_suggested_policy_text();
		$names     = array_column( $suggested, 'plugin_name' );
		$this->assertContains( 'Stalelingo', $names );
		set_current_screen( 'front' );
	}
}
