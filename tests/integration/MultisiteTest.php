<?php
/**
 * Multisite: network activation, new sites and deleted sites.
 *
 * Runs with `make test-integration-ms` (WP_MULTISITE=1).
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Tests\Integration;

use TranslationDrift\Activator;
use TranslationDrift\Database\Schema;
use TranslationDrift\Multisite;
use TranslationDrift\Uninstaller;

/**
 * @group ms-required
 *
 * @covers \TranslationDrift\Multisite
 * @covers \TranslationDrift\Activator
 * @covers \TranslationDrift\Uninstaller
 */
final class MultisiteTest extends TestCase {

	/**
	 * Sites created by a test, deleted afterwards.
	 *
	 * @var list<int>
	 */
	private array $sites = array();

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only (make test-integration-ms).' );
		}
		$this->allow_real_ddl();
	}

	public function tear_down(): void {
		$sites = $this->sites;
		parent::tear_down();
		// After the rollback: a DDL statement may have committed the network-active flag mid-test.
		wp_cache_flush();
		$this->set_network_active( false );

		// Site deletion runs DROP TABLE, which commits the test transaction; do it after the
		// rollback so no site row survives without its tables.
		global $wpdb;
		foreach ( $sites as $site_id ) {
			if ( get_site( $site_id ) instanceof \WP_Site ) {
				if ( $this->table_exists( $wpdb->get_blog_prefix( $site_id ) . 'options' ) ) {
					wp_delete_site( $site_id );
				} else {
					// A row left without its tables: switching into it would query missing tables.
					$wpdb->delete( $wpdb->blogs, array( 'blog_id' => $site_id ) );
					clean_blog_cache( $site_id );
				}
			}
			// The rollback may have removed the site row but not its (committed) tables; the next
			// test would reuse the ID and find them.
			$prefix = $wpdb->get_blog_prefix( $site_id );
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ) as $table ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			}
		}
		$this->sites = array();
	}

	private function set_network_active( bool $active ): void {
		$plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
		$file    = plugin_basename( TDRIFT_FILE );
		if ( $active ) {
			$plugins[ $file ] = time();
		} else {
			unset( $plugins[ $file ] );
		}
		update_site_option( 'active_sitewide_plugins', $plugins );
	}

	private function create_site( string $slug ): int {
		$site_id       = self::factory()->blog->create( array( 'domain' => 'example.org', 'path' => "/{$slug}/" ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->sites[] = (int) $site_id;

		return (int) $site_id;
	}

	/**
	 * Whether a site has all three plugin tables.
	 */
	private function site_has_tables( int $site_id ): bool {
		global $wpdb;

		$prefix = $wpdb->get_blog_prefix( $site_id );
		foreach ( array( Schema::TABLE_SYNC, Schema::TABLE_SNAPSHOTS, Schema::TABLE_EVENTS ) as $suffix ) {
			if ( ! $this->table_exists( $prefix . $suffix ) ) {
				return false;
			}
		}

		return true;
	}

	public function test_network_activation_sets_up_every_site(): void {
		$first  = $this->create_site( 'first' );
		$second = $this->create_site( 'second' );
		$this->assertFalse( $this->site_has_tables( $first ) );

		Activator::activate( true );

		foreach ( array( get_main_site_id(), $first, $second ) as $site_id ) {
			$this->assertTrue( $this->site_has_tables( $site_id ), "Site {$site_id}" );
			switch_to_blog( $site_id );
			$this->assertTrue( get_role( 'editor' )->has_cap( \TranslationDrift\Capabilities::MANAGE ) );
			restore_current_blog();
		}
	}

	public function test_a_new_site_is_set_up_while_network_active(): void {
		$this->set_network_active( true );

		$site_id = $this->create_site( 'later' );

		$this->assertTrue( $this->site_has_tables( $site_id ) );
	}

	public function test_a_new_site_is_left_alone_when_not_network_active(): void {
		$site_id = $this->create_site( 'plain' );

		$this->assertFalse( $this->site_has_tables( $site_id ) );
	}

	public function test_deleting_a_site_drops_its_tables(): void {
		$this->set_network_active( true );
		$site_id = $this->create_site( 'doomed' );
		$this->assertTrue( $this->site_has_tables( $site_id ) );

		wp_delete_site( $site_id );

		$this->assertFalse( $this->site_has_tables( $site_id ) );
	}

	public function test_drop_tables_filter_names_the_sites_tables(): void {
		global $wpdb;

		$tables = Multisite::drop_tables( array( 'wp_9_posts' ), 9 );

		$this->assertContains( 'wp_9_posts', $tables );
		$this->assertContains( $wpdb->get_blog_prefix( 9 ) . Schema::TABLE_SYNC, $tables );
		$this->assertContains( $wpdb->get_blog_prefix( 9 ) . Schema::TABLE_EVENTS, $tables );
	}

	public function test_uninstall_respects_each_sites_setting(): void {
		$keep = $this->create_site( 'keep' );
		$wipe = $this->create_site( 'wipe' );
		Activator::activate( true );
		switch_to_blog( $wipe );
		update_option( Uninstaller::SETTINGS_OPTION, array( 'delete_data' => true ) );
		restore_current_blog();

		Uninstaller::uninstall();

		$this->assertTrue( $this->site_has_tables( $keep ) );
		$this->assertFalse( $this->site_has_tables( $wipe ) );
	}
}
