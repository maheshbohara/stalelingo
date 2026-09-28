<?php
/**
 * Deletion, language changes and maintenance.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Tests\Integration;

use TranslationDrift\Domain\Status;
use TranslationDrift\Services\PostHooks;

/**
 * @covers \TranslationDrift\Services\PostHooks
 * @covers \TranslationDrift\Services\DriftService
 * @covers \TranslationDrift\Services\Repositories\SyncRepository
 * @covers \TranslationDrift\Services\Repositories\SnapshotRepository
 * @covers \TranslationDrift\Services\Repositories\EventRepository
 * @covers \TranslationDrift\Providers\PolylangProvider
 */
final class LifecycleTest extends TestCase {

	public function tear_down(): void {
		parent::tear_down();
		// Restore the three languages if a test added or removed one.
		self::ensure_languages();
	}

	public function test_deleting_a_translation_removes_its_records_and_marks_it_missing(): void {
		$this->set_settings( array( 'meta_keys' => array( 'subtitle' ) ) );
		$group = $this->create_group();
		update_post_meta( $group['en'], 'subtitle', 'x' );
		$this->create_synced_group_from( $group );
		$sync_id = $this->row( $group['fr'] )->id;

		wp_delete_post( $group['fr'], true );

		$this->assertNull( $this->row( $group['fr'] ) );
		$this->assertSame( array(), $this->container()->snapshot_repository()->for_sync( $sync_id ) );

		$this->run_jobs();
		$this->assertSame( Status::Missing, $this->status_of( $group['en'], 'fr' ) );
		$this->assertSame( Status::InSync, $this->status_of( $group['en'], 'es' ) );
	}

	public function test_deleting_the_source_removes_every_record_of_the_group(): void {
		$group = $this->create_synced_group();

		wp_delete_post( $group['en'], true );
		$this->run_jobs();

		$this->assertSame( array(), $this->container()->sync_repository()->for_source( $group['en'] ) );
		$this->assertNull( $this->row( $group['fr'] ) );

		$new_source = $this->container()->provider()->get_source( $group['fr'] );
		if ( 'wpml' === self::provider_name() ) {
			// WPML promotes a remaining translation to be the new original.
			$this->assertContains( $new_source, array( $group['fr'], $group['es'] ) );
		} else {
			$this->assertNull( $new_source, 'Polylang: the group has no post in the source language left.' );
		}
	}

	public function test_adding_a_language_marks_existing_posts_missing_for_it(): void {
		$group = $this->create_synced_group();

		self::add_language( 'de_DE', 'de' );

		try {
			$this->assertNotEmpty( $this->queued_jobs(), 'A recalculation is queued.' );
			$this->run_jobs();

			$this->assertSame( Status::Missing, $this->status_of( $group['en'], 'de' ) );
			$this->assertSame( Status::InSync, $this->status_of( $group['en'], 'fr' ) );
		} finally {
			self::remove_language( 'de' );
		}
	}

	public function test_untracked_translations_get_a_row_until_the_baseline_reaches_them(): void {
		$group = $this->create_group( array( 'en', 'fr' ) );

		$this->container()->drift_service()->recalculate_source( $group['en'] );

		$this->assertSame( Status::Untracked, $this->status_of( $group['en'], 'fr' ) );
		$this->assertSame( Status::Missing, $this->status_of( $group['en'], 'es' ) );
	}

	public function test_a_post_that_is_no_longer_a_source_loses_its_rows(): void {
		$this->only_for( 'polylang' ); // WPML's original can't be overridden.
		$group = $this->create_synced_group();

		$this->container()->provider()->set_source_override( $group['fr'], true );
		$this->container()->drift_service()->recalculate_source( $group['en'] );

		$this->assertSame( array(), $this->container()->sync_repository()->for_source( $group['en'] ) );

		$this->container()->provider()->set_source_override( $group['fr'], false );
	}

	public function test_prune_removes_orphaned_snapshots_and_old_events(): void {
		global $wpdb;

		$this->set_settings( array( 'meta_keys' => array( 'subtitle' ) ) );
		$group = $this->create_group( array( 'en', 'fr' ) );
		update_post_meta( $group['en'], 'subtitle', 'x' );
		$this->container()->sync_service()->mark_synced( $group['fr'] );
		$sync_id = $this->row( $group['fr'] )->id;

		$this->container()->sync_repository()->delete_ids( array( $sync_id ) );
		$wpdb->update(
			\TranslationDrift\Database\Schema::table( \TranslationDrift\Database\Schema::TABLE_EVENTS ),
			array( 'created_at' => '2000-01-01 00:00:00' ),
			array( 'translation_id' => $group['fr'] )
		);

		do_action( PostHooks::PRUNE_HOOK );

		$this->assertSame( array(), $this->container()->snapshot_repository()->for_sync( $sync_id ) );
		$this->assertSame( array(), $this->events_of( $group['fr'] ) );
	}

	public function test_snapshot_field_cap(): void {
		add_filter( 'tdrift_snapshot_max_fields', static fn(): int => 1 );
		$this->set_settings( array( 'meta_keys' => array( 'a', 'b' ) ) );
		$group = $this->create_group( array( 'en', 'fr' ) );
		update_post_meta( $group['en'], 'a', '1' );
		update_post_meta( $group['en'], 'b', '2' );

		wp_update_post(
			array(
				'ID'         => $group['en'],
				'post_title' => 'Revised',
			)
		);

		$this->container()->sync_service()->mark_synced( $group['fr'] );

		$this->assertSame( array( 'meta:a' ), array_keys( $this->container()->snapshot_repository()->for_sync( $this->row( $group['fr'] )->id ) ) );
	}

	public function test_status_counts(): void {
		$this->create_synced_group( array( 'en', 'fr' ) );

		$counts = $this->container()->sync_repository()->count_by_status();

		$this->assertSame( 1, $counts['in_sync'] );
		$this->assertSame( 1, $counts['missing'] );
	}

	/**
	 * Gives an existing group sync points.
	 *
	 * @param array<string, int> $group Group.
	 */
	private function create_synced_group_from( array $group ): void {
		foreach ( $group as $lang => $id ) {
			if ( 'en' !== $lang ) {
				$this->container()->sync_service()->mark_synced( $id );
			}
		}
		$this->container()->drift_service()->recalculate_source( $group['en'] );
		$this->clear_jobs();
	}
}
