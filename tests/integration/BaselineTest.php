<?php
/**
 * Baseline job tests.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Tests\Integration;

use TranslationDrift\Domain\Status;
use TranslationDrift\Services\BaselineJob;
use TranslationDrift\Services\Repositories\EventRepository;

/**
 * @covers \TranslationDrift\Services\BaselineJob
 * @covers \TranslationDrift\Services\SyncService
 * @covers \TranslationDrift\Services\DriftService
 * @covers \TranslationDrift\Services\Repositories\SyncRepository
 */
final class BaselineTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'tdrift_batch_size', array( $this, 'batch_of_two' ) );
	}

	public function tear_down(): void {
		remove_filter( 'tdrift_batch_size', array( $this, 'batch_of_two' ) );
		parent::tear_down();
	}

	public function batch_of_two(): int {
		return 2;
	}

	public function test_baseline_runs_in_batches_and_marks_everything(): void {
		$full    = $this->create_group();
		$partial = $this->create_group( array( 'en', 'fr' ) );
		$this->clear_jobs();

		$baseline = $this->container()->baseline();
		$this->assertTrue( $baseline->start_baseline() );
		$this->assertSame( 'queued', $baseline->state()['status'] );

		// One job at a time: each batch queues the next.
		$this->assertCount( 1, $this->queued_jobs() );
		$runs = $this->run_jobs();

		$this->assertGreaterThanOrEqual( 3, $runs, '5 posts in batches of 2.' );
		$this->assertSame( 'done', $baseline->state()['status'] );
		$this->assertSame( 5, $baseline->state()['processed'] );

		$this->assertSame( Status::InSync, $this->status_of( $full['en'], 'fr' ) );
		$this->assertSame( Status::InSync, $this->status_of( $full['en'], 'es' ) );
		$this->assertSame( Status::InSync, $this->status_of( $partial['en'], 'fr' ) );
		$this->assertSame( Status::Missing, $this->status_of( $partial['en'], 'es' ) );
		$this->assertSame( array( EventRepository::MARKED_SYNCED ), $this->events_of( $full['fr'] ) );
	}

	public function test_baseline_is_idempotent(): void {
		$group = $this->create_group();
		$this->clear_jobs();
		$baseline = $this->container()->baseline();

		$baseline->start_baseline();
		$this->run_jobs();
		$first = $this->row( $group['fr'] );

		$baseline->start_baseline();
		$this->run_jobs();
		$second = $this->row( $group['fr'] );

		$this->assertNotNull( $first );
		$this->assertEquals( $first, $second );
		$this->assertCount( 1, $this->events_of( $group['fr'] ), 'No second sync point.' );
	}

	public function test_forced_baseline_resets_outdated_translations(): void {
		$group = $this->create_synced_group();
		wp_update_post(
			array(
				'ID'         => $group['en'],
				'post_title' => 'Changed',
			)
		);
		$this->run_jobs();
		$this->assertSame( Status::Outdated, $this->status_of( $group['en'], 'fr' ) );

		$this->container()->baseline()->start_baseline( true );
		$this->run_jobs();

		$this->assertSame( Status::InSync, $this->status_of( $group['en'], 'fr' ) );
	}

	public function test_recalculation_walks_every_source(): void {
		$group = $this->create_synced_group();
		// Change the source without triggering hooks, then recalculate everything.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_title' => 'Silent change' ), array( 'ID' => $group['en'] ) );
		clean_post_cache( $group['en'] );

		$this->container()->baseline()->start_recalculation();
		$this->assertSame( BaselineJob::RECALC_HOOK, $this->queued_jobs()[0]['hook'] );
		$this->run_jobs();

		$this->assertSame( Status::Outdated, $this->status_of( $group['en'], 'fr' ) );
	}
}
