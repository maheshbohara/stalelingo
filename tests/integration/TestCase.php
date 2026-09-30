<?php
/**
 * Base class for integration tests.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Tests\Integration;

use Stalelingo\Container;
use Stalelingo\Deactivator;
use Stalelingo\Domain\Status;
use Stalelingo\Plugin;
use Stalelingo\Services\Repositories\SyncRow;

/**
 * Integration test base.
 */
abstract class TestCase extends \WP_UnitTestCase {

	/**
	 * Whether the test ran real DDL, which MySQL commits implicitly.
	 *
	 * @var bool
	 */
	private bool $ran_ddl = false;

	/**
	 * Languages every test starts with.
	 */
	public const LOCALES = array( 'en_US', 'fr_FR', 'es_ES' );

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		self::ensure_languages();
	}

	/**
	 * Recreates the Polylang languages.
	 *
	 * The WP test suite deletes every term after each test class (`_delete_all_data()`),
	 * including Polylang's language terms, so each class starts by restoring them.
	 */
	public static function ensure_languages(): void {
		if ( 'wpml' === getenv( 'PROVIDER' ) || ! function_exists( 'PLL' ) ) {
			return;
		}

		$model = PLL()->model;
		$model->clean_languages_cache();
		$existing = array_map( static fn( $lang ) => $lang->locale, $model->get_languages_list() );
		foreach ( self::LOCALES as $order => $locale ) {
			if ( ! in_array( $locale, $existing, true ) ) {
				$model->languages->add(
					array(
						'locale'     => $locale,
						'term_group' => $order,
					)
				);
			}
		}
		$model->clean_languages_cache();
	}

	public function set_up(): void {
		parent::set_up();
		$this->clear_jobs();
	}

	/**
	 * Lets DDL statements reach the real tables instead of the suite's temporary copies.
	 *
	 * DDL commits the open transaction, so state written by the test may survive the
	 * rollback. tear_down() rebuilds the schema and options once the rollback is done.
	 */
	protected function allow_real_ddl(): void {
		$this->ran_ddl = true;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	public function tear_down(): void {
		$this->clear_jobs();
		parent::tear_down();

		if ( $this->ran_ddl ) {
			$this->ran_ddl = false;
			remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
			remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
			delete_option( \Stalelingo\Uninstaller::SETTINGS_OPTION );
			delete_option( \Stalelingo\Database\Migrator::OPTION );
			wp_cache_flush();
			\Stalelingo\Activator::activate();
			$this->clear_jobs();
		}
	}

	/**
	 * Whether a table exists in the test database.
	 */
	protected function table_exists( string $table ): bool {
		global $wpdb;

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	protected function container(): Container {
		return Plugin::container();
	}

	/**
	 * Updates plugin settings.
	 *
	 * @param array<string, mixed> $settings Settings to merge.
	 */
	protected function set_settings( array $settings ): void {
		update_option( \Stalelingo\Settings::OPTION, array_merge( (array) get_option( \Stalelingo\Settings::OPTION, array() ), $settings ) );
	}

	/**
	 * Creates a Polylang translation group.
	 *
	 * @param list<string>         $langs Languages to create, source language first.
	 * @param array<string, mixed> $args  Post arguments shared by every post.
	 * @return array<string, int> Post IDs keyed by language.
	 */
	protected function create_group( array $langs = array( 'en', 'fr', 'es' ), array $args = array() ): array {
		$group = array();
		foreach ( $langs as $lang ) {
			$id             = self::factory()->post->create(
				array_merge(
					array(
						'post_title'   => "Title {$lang}",
						'post_content' => "<!-- wp:paragraph -->\n<p>Content {$lang}</p>\n<!-- /wp:paragraph -->",
						'post_excerpt' => "Excerpt {$lang}",
						'post_status'  => 'publish',
					),
					$args
				)
			);
			$group[ $lang ] = $id;
		}
		self::link_group( $group );

		return $group;
	}

	/**
	 * The multilingual plugin under test: 'polylang' (default) or 'wpml'.
	 */
	protected static function provider_name(): string {
		return 'wpml' === getenv( 'PROVIDER' ) ? 'wpml' : 'polylang';
	}

	/**
	 * Skips the test unless the given provider is under test.
	 */
	protected function only_for( string $provider ): void {
		if ( self::provider_name() !== $provider ) {
			$this->markTestSkipped( "{$provider} only." );
		}
	}

	/**
	 * Sets languages and links posts as one translation group, the first being the source.
	 *
	 * @param array<string, int> $group Post IDs keyed by language, source first.
	 */
	protected static function link_group( array $group ): void {
		if ( 'wpml' === self::provider_name() ) {
			$source_lang = (string) array_key_first( $group );
			$type        = apply_filters( 'wpml_element_type', get_post_type( $group[ $source_lang ] ) );
			$trid        = false;
			foreach ( $group as $lang => $id ) {
				do_action(
					'wpml_set_element_language_details',
					array(
						'element_id'           => $id,
						'element_type'         => $type,
						'trid'                 => $trid,
						'language_code'        => $lang,
						'source_language_code' => $lang === $source_lang ? null : $source_lang,
					)
				);
				$trid = apply_filters( 'wpml_element_trid', null, $group[ $source_lang ], $type );
			}
			return;
		}

		foreach ( $group as $lang => $id ) {
			pll_set_post_language( $id, $lang );
		}
		pll_save_post_translations( $group );
	}

	/**
	 * Adds a language the way the multilingual plugin's admin screen does.
	 */
	protected static function add_language( string $locale, string $code ): void {
		if ( 'wpml' === self::provider_name() ) {
			self::set_wpml_languages( array( 'en', 'fr', 'es', $code ) );
			return;
		}
		PLL()->model->languages->add(
			array(
				'locale'     => $locale,
				'term_group' => 9,
			)
		);
		PLL()->model->clean_languages_cache();
	}

	/**
	 * Removes a language added by add_language().
	 */
	protected static function remove_language( string $code ): void {
		if ( 'wpml' === self::provider_name() ) {
			self::set_wpml_languages( array( 'en', 'fr', 'es' ) );
			return;
		}
		$language = PLL()->model->get_language( $code );
		if ( $language ) {
			PLL()->model->languages->delete( $language->term_id );
		}
		PLL()->model->clean_languages_cache();
	}

	/**
	 * Sets WPML's active languages and fires the action its languages screen fires.
	 *
	 * @param list<string> $codes Language codes.
	 */
	private static function set_wpml_languages( array $codes ): void {
		global $wpdb, $sitepress;

		$old = array_keys( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) );
		( new \WPML_Installation( $wpdb, $sitepress ) )->set_active_languages( $codes );
		do_action( 'wpml_update_active_languages', $old );
	}

	/**
	 * Creates a group and gives every translation a sync point, then clears queued jobs.
	 *
	 * @param list<string>         $langs Languages.
	 * @param array<string, mixed> $args  Post arguments.
	 * @return array<string, int>
	 */
	protected function create_synced_group( array $langs = array( 'en', 'fr', 'es' ), array $args = array() ): array {
		$group = $this->create_group( $langs, $args );
		foreach ( $group as $lang => $id ) {
			if ( 'en' !== $lang ) {
				$this->container()->sync_service()->mark_synced( $id );
			}
		}
		$this->container()->drift_service()->recalculate_source( $group['en'] );
		$this->clear_jobs();

		return $group;
	}

	/**
	 * Runs queued plugin jobs (WP-Cron) until none are left.
	 *
	 * @return int Jobs run.
	 */
	protected function run_jobs(): int {
		// Jobs run in a later request (WP-Cron, Action Scheduler), so start from a cold object cache,
		// as that request would: multilingual plugins cache translation groups per request.
		wp_cache_flush();

		$run = 0;
		for ( $i = 0; $i < 200; $i++ ) {
			$job = $this->next_job();
			if ( null === $job ) {
				break;
			}
			wp_unschedule_event( $job['timestamp'], $job['hook'], $job['args'] );
			do_action_ref_array( $job['hook'], $job['args'] );
			++$run;
		}

		return $run;
	}

	/**
	 * Queued plugin jobs.
	 *
	 * @return list<array{timestamp: int, hook: string, args: list<mixed>}>
	 */
	protected function queued_jobs(): array {
		$jobs = array();
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			foreach ( (array) $hooks as $hook => $events ) {
				if ( ! str_starts_with( (string) $hook, 'stalelingo_' ) || 'stalelingo_prune' === $hook ) {
					continue;
				}
				foreach ( (array) $events as $event ) {
					$jobs[] = array(
						'timestamp' => (int) $timestamp,
						'hook'      => (string) $hook,
						'args'      => array_values( (array) $event['args'] ),
					);
				}
			}
		}

		return $jobs;
	}

	/**
	 * Unschedules every plugin job.
	 */
	protected function clear_jobs(): void {
		foreach ( Deactivator::scheduled_hooks() as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	/**
	 * The row of a translation.
	 */
	protected function row( int $translation_id ): ?SyncRow {
		return $this->container()->sync_repository()->find_by_translation( $translation_id );
	}

	/**
	 * The status of a (source, language) pair.
	 */
	protected function status_of( int $source_id, string $lang ): ?Status {
		$rows = $this->container()->sync_repository()->for_source( $source_id );

		return isset( $rows[ $lang ] ) ? $rows[ $lang ]->status : null;
	}

	/**
	 * Event types logged for a translation, oldest first.
	 *
	 * @return list<string>
	 */
	protected function events_of( int $translation_id ): array {
		return array_reverse(
			array_map(
				static fn( object $e ): string => (string) $e->event,
				$this->container()->event_repository()->for_translation( $translation_id )
			)
		);
	}

	/**
	 * First queued job, or null.
	 *
	 * @return array{timestamp: int, hook: string, args: list<mixed>}|null
	 */
	private function next_job(): ?array {
		$jobs = $this->queued_jobs();

		return $jobs[0] ?? null;
	}
}
