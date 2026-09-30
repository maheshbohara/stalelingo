<?php
/**
 * WP-CLI commands.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Cli;

defined( 'ABSPATH' ) || exit;

use Stalelingo\Admin\StatusView;
use Stalelingo\Domain\Status;
use Stalelingo\Providers\TranslationProvider;
use Stalelingo\Services\BaselineJob;
use Stalelingo\Services\Repositories\SyncRepository;
use Stalelingo\Services\SyncService;
use Stalelingo\Services\TrackedFields;

/**
 * Reports on outdated translations and clears the backlog from the command line.
 *
 * ## EXAMPLES
 *
 *     # Outdated French translations as CSV.
 *     $ wp stalelingo report --lang=fr --status=outdated --format=csv
 *
 *     # Mark two translations as up to date.
 *     $ wp stalelingo mark-synced 123 456
 *
 *     # Mark every outdated Spanish translation as up to date.
 *     $ wp stalelingo mark-synced --all --lang=es
 *
 *     # See what a baseline build would do, then run it.
 *     $ wp stalelingo baseline --dry-run
 *     $ wp stalelingo baseline
 *
 * @since 1.0.0
 */
class Command {

	/**
	 * Columns of `report`.
	 *
	 * @since 1.0.0
	 */
	public const REPORT_FIELDS = array( 'source_id', 'title', 'post_type', 'lang', 'status', 'translation_id', 'changed_fields', 'synced_at' );

	/**
	 * Sources read per query while building a report.
	 *
	 * @since 1.0.0
	 */
	private const PAGE_SIZE = 200;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param TranslationProvider $provider Provider.
	 * @param TrackedFields       $tracked  Tracked fields.
	 * @param SyncRepository      $sync     Sync rows.
	 * @param SyncService         $syncer   Sync service.
	 * @param BaselineJob         $baseline Baseline and recalculation.
	 */
	public function __construct(
		private TranslationProvider $provider,
		private TrackedFields $tracked,
		private SyncRepository $sync,
		private SyncService $syncer,
		private BaselineJob $baseline
	) {
	}

	/**
	 * Lists translations and their status, one row per translation.
	 *
	 * ## OPTIONS
	 *
	 * [--lang=<lang>]
	 * : Only this language code.
	 *
	 * [--post_type=<post_type>]
	 * : Only this post type.
	 *
	 * [--status=<status>]
	 * : Only this status.
	 * ---
	 * options:
	 *   - in_sync
	 *   - outdated
	 *   - missing
	 *   - untracked
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp stalelingo report --status=outdated
	 *     $ wp stalelingo report --lang=fr --post_type=page --format=json
	 *
	 * @since 1.0.0
	 *
	 * @param list<string>          $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Options.
	 */
	public function report( $args, $assoc_args ): void {
		$lang      = (string) ( $assoc_args['lang'] ?? '' );
		$post_type = (string) ( $assoc_args['post_type'] ?? '' );
		$status    = (string) ( $assoc_args['status'] ?? '' );
		$format    = (string) ( $assoc_args['format'] ?? 'table' );

		if ( '' !== $lang && ! in_array( $lang, $this->provider->get_languages(), true ) ) {
			\WP_CLI::error( sprintf( 'Unknown language "%s". Languages: %s.', $lang, implode( ', ', $this->provider->get_languages() ) ) );
		}
		if ( '' !== $post_type && ! $this->tracked->is_tracked_post_type( $post_type ) ) {
			\WP_CLI::error( sprintf( 'Post type "%s" is not tracked. Tracked: %s.', $post_type, implode( ', ', $this->tracked->post_types() ) ) );
		}
		if ( '' !== $status && null === Status::tryFrom( $status ) ) {
			\WP_CLI::error( sprintf( 'Unknown status "%s".', $status ) );
		}

		$rows = $this->report_rows( $lang, $post_type, $status );
		\WP_CLI\Utils\format_items( $format, $rows, self::REPORT_FIELDS );
	}

	/**
	 * Marks translations as up to date with their source's current content.
	 *
	 * ## OPTIONS
	 *
	 * [<ids>...]
	 * : Translation post IDs.
	 *
	 * [--all]
	 * : Mark every outdated or not-yet-tracked translation (of --lang, if given).
	 *
	 * [--lang=<lang>]
	 * : With --all, only this language code.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp stalelingo mark-synced 123 456
	 *     $ wp stalelingo mark-synced --all --lang=fr
	 *
	 * @subcommand mark-synced
	 *
	 * @since 1.0.0
	 *
	 * @param list<string>          $args       Translation post IDs.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function mark_synced( $args, $assoc_args ): void {
		$all  = ! empty( $assoc_args['all'] );
		$lang = (string) ( $assoc_args['lang'] ?? '' );

		$has_ids = array() !== $args;
		if ( $all === $has_ids ) {
			\WP_CLI::error( 'Give translation IDs, or --all (optionally with --lang), but not both.' );
		}
		if ( '' !== $lang && ! in_array( $lang, $this->provider->get_languages(), true ) ) {
			\WP_CLI::error( sprintf( 'Unknown language "%s".', $lang ) );
		}

		if ( $all ) {
			$rows = $this->sync->find_with_status( array( Status::Outdated, Status::Untracked ), '' === $lang ? null : array( $lang ) );
			$ids  = array_map( static fn( $row ): int => $row->translation_id, $rows );
		} else {
			$ids = array_map( 'absint', $args );
		}

		if ( array() === $ids ) {
			\WP_CLI::success( 'Nothing to mark: no outdated translations.' );
			return;
		}

		$user_id = get_current_user_id();
		$marked  = 0;
		$failed  = 0;
		$bar     = $this->progress( 'Marking translations as up to date', count( $ids ) );
		foreach ( $ids as $id ) {
			if ( $id > 0 && $this->syncer->mark_synced( $id, $user_id, SyncService::CONTEXT_MANUAL ) ) {
				++$marked;
			} else {
				++$failed;
				\WP_CLI::warning( sprintf( 'Post %d is not a translation of a tracked source.', $id ) );
			}
			$bar->tick();
		}
		$bar->finish();

		if ( $failed > 0 && 0 === $marked ) {
			\WP_CLI::error( 'No translation was marked as up to date.' );
		}
		\WP_CLI::success( sprintf( '%d marked as up to date%s.', $marked, $failed > 0 ? sprintf( ', %d skipped', $failed ) : '' ) );
	}

	/**
	 * Marks every existing translation without a sync point as up to date with its source.
	 *
	 * Runs now, in batches, instead of in the background.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would happen without changing anything.
	 *
	 * [--force]
	 * : Also reset translations that already have a sync point, including outdated ones.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp stalelingo baseline --dry-run
	 *     $ wp stalelingo baseline
	 *
	 * @since 1.0.0
	 *
	 * @param list<string>          $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Options.
	 */
	public function baseline( $args, $assoc_args ): void {
		$force = ! empty( $assoc_args['force'] );

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			$plan = $this->baseline->plan_baseline( $force );
			\WP_CLI::log( sprintf( 'Posts examined: %d', $plan['posts'] ) );
			\WP_CLI::log( sprintf( 'Sources: %d', $plan['sources'] ) );
			\WP_CLI::success( sprintf( 'Dry run: %d translations would be marked as up to date.', $plan['translations'] ) );
			return;
		}

		$bar    = $this->progress( 'Building the baseline', max( 1, $this->baseline->count_posts() ) );
		$result = $this->baseline->run_baseline_now(
			$force,
			static function ( int $posts ) use ( $bar ): void {
				$bar->tick( $posts );
			}
		);
		$bar->finish();

		\WP_CLI::success( sprintf( 'Baseline built: %d posts examined, %d translations marked as up to date.', $result['posts'], $result['marked'] ) );
	}

	/**
	 * Recalculates the status of every translation now, instead of in the background.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp stalelingo recalc
	 *
	 * @since 1.0.0
	 *
	 * @param list<string>          $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Options (unused).
	 */
	public function recalc( $args, $assoc_args ): void {
		unset( $args, $assoc_args );
		$bar     = $this->progress( 'Recalculating', max( 1, $this->baseline->count_posts() ) );
		$sources = $this->baseline->recalculate_now(
			static function ( int $posts ) use ( $bar ): void {
				$bar->tick( $posts );
			}
		);
		$bar->finish();

		\WP_CLI::success( sprintf( '%d sources recalculated.', $sources ) );
	}

	/**
	 * Report rows: one per translation that matches the filters.
	 *
	 * @param string $lang      Language code, or '' for all.
	 * @param string $post_type Post type, or '' for all tracked types.
	 * @param string $status    Status, or '' for all.
	 * @return list<array<string, int|string>>
	 */
	private function report_rows( string $lang, string $post_type, string $status ): array {
		$query = array(
			'post_types' => '' === $post_type ? $this->tracked->post_types() : array( $post_type ),
			'orderby'    => 'title',
			'order'      => 'asc',
			'per_page'   => self::PAGE_SIZE,
		);
		if ( '' !== $lang ) {
			$query['langs'] = array( $lang );
		}
		if ( '' !== $status ) {
			$query['statuses'] = array( $status );
		}

		$rows = array();
		$page = 1;
		do {
			$query['page'] = $page;
			$result        = $this->sync->query_sources( $query );
			_prime_post_caches( $result['ids'], false, false );
			$by_source = $this->sync->for_sources( $result['ids'] );

			foreach ( $result['ids'] as $source_id ) {
				$source = get_post( $source_id );
				foreach ( $by_source[ $source_id ] ?? array() as $row ) {
					if ( ( '' !== $lang && $row->lang !== $lang ) || ( '' !== $status && $row->status->value !== $status ) ) {
						continue;
					}
					$rows[] = array(
						'source_id'      => $source_id,
						'title'          => $source instanceof \WP_Post ? html_entity_decode( $source->post_title, ENT_QUOTES, 'UTF-8' ) : '',
						'post_type'      => $row->post_type,
						'lang'           => $row->lang,
						'status'         => $row->status->value,
						'translation_id' => $row->translation_id,
						'changed_fields' => implode( ', ', array_map( array( StatusView::class, 'field_label' ), $row->changed_fields ) ),
						'synced_at'      => (string) $row->synced_at,
					);
				}
			}
			++$page;
			$full_page = count( $result['ids'] ) === self::PAGE_SIZE;
		} while ( $full_page );

		return $rows;
	}

	/**
	 * A progress bar (a no-op one when output isn't a terminal).
	 *
	 * @param string $label Label.
	 * @param int    $count Total ticks.
	 * @return \cli\progress\Bar The bar; WP-CLI returns a NoOp with the same methods when quiet.
	 */
	private function progress( string $label, int $count ): object {
		/**
		 * WP-CLI's progress bar, or its NoOp stand-in, which accepts the same calls.
		 *
		 * @var \cli\progress\Bar $bar
		 */
		$bar = \WP_CLI\Utils\make_progress_bar( $label, $count );

		return $bar;
	}
}
