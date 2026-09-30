<?php
/**
 * `wp stalelingo` commands.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Tests\Integration;

use Stalelingo\Cli\Command;
use Stalelingo\Domain\Status;
use Stalelingo\Services\BaselineJob;
use Stalelingo\Tests\Integration\Support\CliError;

require_once __DIR__ . '/Support/wp-cli-stub.php';

/**
 * @covers \Stalelingo\Cli\Command
 * @covers \Stalelingo\Services\BaselineJob
 * @covers \Stalelingo\Services\Repositories\SyncRepository
 */
final class CliTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		\WP_CLI::reset();
	}

	private function command(): Command {
		return $this->container()->cli_command();
	}

	/**
	 * A synced EN/FR/ES group whose source title then changed.
	 *
	 * @return array<string, int>
	 */
	private function outdated_group(): array {
		$group = $this->create_synced_group();
		wp_update_post(
			array(
				'ID'         => $group['en'],
				'post_title' => 'Changed title',
			)
		);
		$this->run_jobs();

		return $group;
	}

	/**
	 * Rows printed by the last report.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function reported(): array {
		return \WP_CLI::$formatted[1] ?? array();
	}

	/**
	 * Messages of one type.
	 *
	 * @return list<string>
	 */
	private static function messages( string $type ): array {
		return array_values( array_map( static fn( array $m ): string => $m[1], array_filter( \WP_CLI::$messages, static fn( array $m ): bool => $type === $m[0] ) ) );
	}

	public function test_report_lists_one_row_per_translation_with_filters(): void {
		$outdated = $this->outdated_group();
		$this->create_synced_group( array( 'en', 'fr' ), array( 'post_title' => 'Quiet' ) );

		$this->command()->report( array(), array( 'format' => 'json' ) );
		$this->assertSame( 'json', \WP_CLI::$formatted[0] ?? null );
		$this->assertSame( Command::REPORT_FIELDS, \WP_CLI::$formatted[2] ?? null );
		$this->assertCount( 4, self::reported(), 'FR and ES of the first group, FR and a missing ES of the second.' );

		$this->command()->report(
			array(),
			array(
				'status' => 'outdated',
				'lang'   => 'fr',
			)
		);
		$rows = self::reported();
		$this->assertCount( 1, $rows );
		$this->assertSame( $outdated['en'], $rows[0]['source_id'] );
		$this->assertSame( $outdated['fr'], $rows[0]['translation_id'] );
		$this->assertSame( 'Changed title', $rows[0]['title'] );
		$this->assertSame( 'Title', $rows[0]['changed_fields'] );
		$this->assertSame( 'table', \WP_CLI::$formatted[0] ?? null, 'Default format.' );
	}

	public function test_report_rejects_unknown_filters(): void {
		foreach ( array( array( 'lang' => 'xx' ), array( 'post_type' => 'attachment' ), array( 'status' => 'stale' ) ) as $args ) {
			try {
				$this->command()->report( array(), $args );
				$this->fail( 'Expected an error for ' . wp_json_encode( $args ) );
			} catch ( CliError $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_mark_synced_by_id(): void {
		$group = $this->outdated_group();

		$this->command()->mark_synced( array( (string) $group['fr'] ), array() );

		$this->assertSame( Status::InSync, $this->status_of( $group['en'], 'fr' ) );
		$this->assertSame( Status::Outdated, $this->status_of( $group['en'], 'es' ) );
		$this->assertSame( array( '1 marked as up to date.' ), self::messages( 'success' ) );
	}

	public function test_mark_synced_all_in_one_language(): void {
		$first  = $this->outdated_group();
		$second = $this->outdated_group();

		$this->command()->mark_synced( array(), array( 'all' => true, 'lang' => 'fr' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		foreach ( array( $first, $second ) as $group ) {
			$this->assertSame( Status::InSync, $this->status_of( $group['en'], 'fr' ) );
			$this->assertSame( Status::Outdated, $this->status_of( $group['en'], 'es' ) );
		}
		$this->assertSame( array( '2 marked as up to date.' ), self::messages( 'success' ) );
	}

	public function test_mark_synced_reports_skipped_posts_and_bad_usage(): void {
		$group = $this->outdated_group();

		$this->command()->mark_synced( array( (string) $group['fr'], (string) $group['en'] ), array() );
		$this->assertSame( array( '1 marked as up to date, 1 skipped.' ), self::messages( 'success' ) );
		$this->assertCount( 1, self::messages( 'warning' ) );

		foreach ( array( array( array(), array() ), array( array( '5' ), array( 'all' => true ) ) ) as [ $args, $assoc ] ) {
			try {
				$this->command()->mark_synced( $args, $assoc );
				$this->fail( 'Expected a usage error.' );
			} catch ( CliError $e ) {
				$this->assertStringContainsString( 'not both', $e->getMessage() );
			}
		}
	}

	public function test_baseline_dry_run_changes_nothing_then_the_baseline_runs(): void {
		$group = $this->create_group();
		$this->clear_jobs();

		$this->command()->baseline( array(), array( 'dry-run' => true ) );
		$this->assertSame( array( 'Dry run: 2 translations would be marked as up to date.' ), self::messages( 'success' ) );
		$this->assertNull( $this->row( $group['fr'] ) );

		\WP_CLI::reset();
		$this->command()->baseline( array(), array() );

		$this->assertSame( Status::InSync, $this->status_of( $group['en'], 'fr' ) );
		$this->assertSame( Status::InSync, $this->status_of( $group['en'], 'es' ) );
		$this->assertStringContainsString( '2 translations marked as up to date', self::messages( 'success' )[0] ?? '' );
		$this->assertSame( 'done', $this->container()->baseline()->state()['status'] );
		$this->assertSame( array(), $this->queued_jobs(), 'Nothing is left for the background.' );
	}

	public function test_baseline_runs_every_batch(): void {
		add_filter( 'stalelingo_batch_size', static fn(): int => 2 );
		$groups = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$groups[] = $this->create_group( array( 'en', 'fr' ) );
		}
		$this->clear_jobs();

		$this->command()->baseline( array(), array() );
		remove_all_filters( 'stalelingo_batch_size' );

		foreach ( $groups as $group ) {
			$this->assertSame( Status::InSync, $this->status_of( $group['en'], 'fr' ) );
		}
		$this->assertSame( array(), array_filter( $this->queued_jobs(), static fn( array $job ): bool => BaselineJob::BASELINE_HOOK === $job['hook'] ) );
	}

	public function test_recalc_updates_statuses_without_the_queue(): void {
		$group = $this->create_synced_group();
		wp_update_post(
			array(
				'ID'         => $group['en'],
				'post_title' => 'Changed title',
			)
		);
		$this->clear_jobs();

		$this->command()->recalc( array(), array() );

		$this->assertSame( Status::Outdated, $this->status_of( $group['en'], 'fr' ) );
		$this->assertStringContainsString( 'sources recalculated', self::messages( 'success' )[0] ?? '' );
	}
}
