<?php
/**
 * End-to-end drift detection through WordPress hooks.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Tests\Integration;

use TranslationDrift\Domain\Status;
use TranslationDrift\Services\PostHooks;
use TranslationDrift\Services\Repositories\EventRepository;

/**
 * @covers \TranslationDrift\Services\PostHooks
 * @covers \TranslationDrift\Services\DriftService
 * @covers \TranslationDrift\Services\SyncService
 * @covers \TranslationDrift\Services\Fingerprinter
 * @covers \TranslationDrift\Services\Queue
 * @covers \TranslationDrift\Services\Repositories\SyncRepository
 * @covers \TranslationDrift\Services\Repositories\EventRepository
 */
final class DriftFlowTest extends TestCase {

	/**
	 * @param array<string, mixed> $changes Post fields.
	 */
	private function update( int $id, array $changes ): void {
		wp_update_post( array_merge( array( 'ID' => $id ), $changes ) );
	}

	public function test_saving_the_source_queues_a_recalculation_and_does_not_block_the_save(): void {
		$group = $this->create_synced_group();

		$this->update( $group['en'], array( 'post_title' => 'New title' ) );

		$this->assertNotFalse( wp_next_scheduled( PostHooks::RECALC_SOURCE_HOOK, array( $group['en'] ) ) );
		$this->assertSame( Status::InSync, $this->status_of( $group['en'], 'fr' ), 'Nothing recalculated during the save.' );

		$this->run_jobs();

		$row = $this->row( $group['fr'] );
		$this->assertSame( Status::Outdated, $row->status );
		$this->assertSame( array( 'title' ), $row->changed_fields );
		$this->assertSame( Status::Outdated, $this->status_of( $group['en'], 'es' ) );
	}

	public function test_repeated_saves_queue_one_job(): void {
		$group = $this->create_synced_group();

		$this->update( $group['en'], array( 'post_title' => 'One' ) );
		$this->update( $group['en'], array( 'post_title' => 'Two' ) );

		$this->assertCount( 1, $this->queued_jobs() );
	}

	public function test_drift_detected_event_and_action(): void {
		$group = $this->create_synced_group();
		$fired = array();
		add_action(
			'tdrift_drift_detected',
			static function ( ...$args ) use ( &$fired ): void {
				$fired[] = $args;
			},
			10,
			4
		);

		$this->update( $group['en'], array( 'post_excerpt' => 'Different excerpt' ) );
		$this->run_jobs();

		$this->assertContains( array( $group['fr'], $group['en'], 'fr', array( 'excerpt' ) ), $fired );
		$this->assertSame( array( EventRepository::MARKED_SYNCED, EventRepository::DRIFT_DETECTED ), $this->events_of( $group['fr'] ) );
	}

	public function test_saving_a_translation_does_not_flag_it(): void {
		$group = $this->create_synced_group();

		$this->update( $group['fr'], array( 'post_title' => 'Nouveau titre' ) );
		$this->run_jobs();

		$this->assertSame( Status::InSync, $this->status_of( $group['en'], 'fr' ) );
	}

	public function test_autosaves_and_revisions_do_not_queue_anything(): void {
		$group = $this->create_synced_group();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		wp_create_post_autosave(
			array(
				'post_ID'      => $group['en'],
				'post_title'   => 'Autosaved title',
				'post_content' => 'Autosaved',
				'post_excerpt' => '',
				'post_type'    => 'post',
			)
		);
		_wp_put_post_revision( get_post( $group['en'] ) );

		$this->assertSame( array(), $this->queued_jobs() );
	}

	public function test_mark_synced_clears_the_status_and_logs_an_event(): void {
		$group = $this->create_synced_group();
		$user  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->update( $group['en'], array( 'post_title' => 'Changed' ) );
		$this->run_jobs();
		$fired = 0;
		add_action(
			'tdrift_marked_synced',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$this->assertTrue( $this->container()->sync_service()->mark_synced( $group['fr'], $user ) );

		$row = $this->row( $group['fr'] );
		$this->assertSame( Status::InSync, $row->status );
		$this->assertSame( array(), $row->changed_fields );
		$this->assertSame( $user, $row->synced_by );
		$this->assertSame( EventRepository::MARKED_SYNCED, $this->events_of( $group['fr'] )[2] );
		$this->assertSame( 1, $fired );
		$this->assertSame( Status::Outdated, $this->status_of( $group['en'], 'es' ), 'Other languages are untouched.' );
	}

	public function test_mark_synced_refuses_a_source_or_unrelated_post(): void {
		$group = $this->create_synced_group();
		$loner = self::factory()->post->create();

		$this->assertFalse( $this->container()->sync_service()->mark_synced( $group['en'] ) );
		$this->assertFalse( $this->container()->sync_service()->mark_synced( $loner ) );
	}

	public function test_reverting_the_source_resolves_drift(): void {
		$group = $this->create_synced_group();
		$this->update( $group['en'], array( 'post_title' => 'Temporary' ) );
		$this->run_jobs();
		$this->update( $group['en'], array( 'post_title' => 'Title en' ) );
		$this->run_jobs();

		$this->assertSame( Status::InSync, $this->status_of( $group['en'], 'fr' ) );
		$this->assertSame( EventRepository::DRIFT_RESOLVED, array_slice( $this->events_of( $group['fr'] ), -1 )[0] );
	}

	public function test_auto_clear_off_keeps_the_translation_outdated(): void {
		$group = $this->create_synced_group();
		$this->update( $group['en'], array( 'post_title' => 'Changed' ) );
		$this->run_jobs();

		$this->update( $group['fr'], array( 'post_title' => 'Titre mis à jour' ) );
		$this->run_jobs();

		$this->assertSame( Status::Outdated, $this->status_of( $group['en'], 'fr' ) );
	}

	public function test_auto_clear_on_clears_the_translation_when_it_is_saved(): void {
		$this->set_settings( array( 'auto_clear' => true ) );
		$group = $this->create_synced_group();
		$this->update( $group['en'], array( 'post_title' => 'Changed' ) );
		$this->run_jobs();

		$this->update( $group['fr'], array( 'post_title' => 'Titre mis à jour' ) );

		$this->assertSame( Status::InSync, $this->status_of( $group['en'], 'fr' ) );
		$this->assertSame( EventRepository::AUTO_CLEARED, array_slice( $this->events_of( $group['fr'] ), -1 )[0] );
		$this->assertSame( Status::Outdated, $this->status_of( $group['en'], 'es' ) );
	}

	public function test_a_new_translation_starts_in_sync(): void {
		$group = $this->create_synced_group( array( 'en', 'fr' ) );
		$this->assertSame( Status::Missing, $this->status_of( $group['en'], 'es' ) );

		$es = self::factory()->post->create( array( 'post_title' => 'Título' ) );
		self::link_group( $group + array( 'es' => $es ) );
		// The multilingual plugin announces the save (pll_save_post / wpml_after_save_post).
		$this->update( $es, array( 'post_content' => 'Contenido' ) );

		$row = $this->row( $es );
		$this->assertNotNull( $row );
		$this->assertSame( Status::InSync, $row->status );
	}

	public function test_block_formatting_changes_do_not_drift_but_text_changes_do(): void {
		$content = "<!-- wp:paragraph {\"align\":\"left\"} -->\n<p class=\"has-text-align-left\">Hello world</p>\n<!-- /wp:paragraph -->";
		$group   = $this->create_synced_group( array( 'en', 'fr' ), array( 'post_content' => $content ) );

		$this->update( $group['en'], array( 'post_content' => "<!-- wp:paragraph {\"align\":\"center\",\"fontSize\":\"large\"} -->\n<p class=\"has-text-align-center has-large-font-size\">Hello   world</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:spacer -->\n<div style=\"height:100px\" aria-hidden=\"true\" class=\"wp-block-spacer\"></div>\n<!-- /wp:spacer -->" ) );
		$this->run_jobs();
		$this->assertSame( Status::InSync, $this->status_of( $group['en'], 'fr' ) );

		$this->update( $group['en'], array( 'post_content' => "<!-- wp:paragraph -->\n<p>Hello there</p>\n<!-- /wp:paragraph -->" ) );
		$this->run_jobs();
		$this->assertSame( Status::Outdated, $this->status_of( $group['en'], 'fr' ) );
		$this->assertSame( array( 'content' ), $this->row( $group['fr'] )->changed_fields );
	}

	public function test_strict_mode_counts_formatting_changes(): void {
		$this->set_settings( array( 'strict' => true ) );
		$group = $this->create_synced_group( array( 'en', 'fr' ), array( 'post_content' => "<!-- wp:paragraph -->\n<p>Hi</p>\n<!-- /wp:paragraph -->" ) );

		$this->update( $group['en'], array( 'post_content' => "<!-- wp:paragraph {\"align\":\"center\"} -->\n<p class=\"has-text-align-center\">Hi</p>\n<!-- /wp:paragraph -->" ) );
		$this->run_jobs();

		$this->assertSame( Status::Outdated, $this->status_of( $group['en'], 'fr' ) );
	}

	public function test_tracked_meta_drifts_and_untracked_meta_does_not(): void {
		$this->set_settings( array( 'meta_keys' => array( 'subtitle' ) ) );
		$group = $this->create_synced_group( array( 'en', 'fr' ) );

		update_post_meta( $group['en'], 'internal_note', 'ignored' );
		$this->update( $group['en'], array() );
		$this->run_jobs();
		$this->assertSame( Status::InSync, $this->status_of( $group['en'], 'fr' ) );

		update_post_meta( $group['en'], 'subtitle', 'A subtitle' );
		$this->update( $group['en'], array() );
		$this->run_jobs();
		$this->assertSame( Status::Outdated, $this->status_of( $group['en'], 'fr' ) );
		$this->assertSame( array( 'meta:subtitle' ), $this->row( $group['fr'] )->changed_fields );
	}

	public function test_meta_values_are_snapshotted_for_diffs(): void {
		$this->set_settings( array( 'meta_keys' => array( 'subtitle' ) ) );
		$group = $this->create_group( array( 'en', 'fr' ) );
		update_post_meta( $group['en'], 'subtitle', '<b>Sub</b>  title' );
		// An update creates the first revision, which covers title, content and excerpt.
		$this->update( $group['en'], array( 'post_title' => 'Revised' ) );

		$this->container()->sync_service()->mark_synced( $group['fr'] );
		$snapshots = $this->container()->snapshot_repository()->for_sync( $this->row( $group['fr'] )->id );

		$this->assertSame( array( 'meta:subtitle' ), array_keys( $snapshots ), 'Revisioned fields are not snapshotted.' );
		$this->assertSame( 'Sub title', $snapshots['meta:subtitle']['value'] );
	}

	public function test_every_field_is_snapshotted_when_the_source_has_no_revision(): void {
		$group = $this->create_group( array( 'en', 'fr' ) );

		$this->container()->sync_service()->mark_synced( $group['fr'] );
		$snapshots = $this->container()->snapshot_repository()->for_sync( $this->row( $group['fr'] )->id );

		$this->assertSame( 0, $this->row( $group['fr'] )->source_rev_id );
		$this->assertSame( array( 'content', 'excerpt', 'title' ), array_keys( $snapshots ) );
		$this->assertSame( '[core/paragraph] Content en', $snapshots['content']['value'] );
	}

	public function test_new_tracked_field_does_not_flag_existing_translations(): void {
		$group = $this->create_synced_group( array( 'en', 'fr' ) );
		$this->set_settings( array( 'fields' => array( 'title', 'content', 'excerpt', 'slug' ) ) );

		$this->container()->drift_service()->recalculate_source( $group['en'] );

		$this->assertSame( Status::InSync, $this->status_of( $group['en'], 'fr' ) );
	}
}
