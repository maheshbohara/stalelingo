<?php
/**
 * Development only: validates Translation Drift against a real WPML site.
 *
 * Run from the site root: `wp eval-file wp-content/plugins/translation-drift/bin/dev/wpml-check.php`.
 * Pass `--skip-baseline` as an argument to skip building the baseline.
 *
 * What it does:
 * 1. Read-only: checks the WPML adapter (languages, groups, sources) against WPML's own data.
 * 2. Lists which ACF fields are tracked per post type, with their WPML preference.
 * 3. Builds the baseline in-process (writes only the plugin's own tables).
 * 4. Creates temporary DRAFT posts with WPML translations, checks that text edits
 *    drift and layout edits don't, then permanently deletes them and their records.
 *
 * Use on a development copy with a database backup.
 *
 * @package TranslationDrift
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions

use TranslationDrift\Domain\Status;
use TranslationDrift\Plugin;

$container = Plugin::container();
$provider  = $container->provider();
$failures  = 0;

// Keep every job in WP-Cron for this run, so the script can drive it even if Action Scheduler is loaded.
add_filter( 'tdrift_use_action_scheduler', '__return_false' );
$skip_base = in_array( '--skip-baseline', $args ?? array(), true );

$check = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	WP_CLI::log( sprintf( '  %s %s%s', $ok ? 'PASS' : 'FAIL', $label, '' === $detail ? '' : " ({$detail})" ) );
};

WP_CLI::log( '1. WPML adapter (read-only)' );
$check( 'provider is WPML', null !== $provider && 'wpml' === $provider->id() );
if ( null === $provider || 'wpml' !== $provider->id() ) {
	WP_CLI::error( 'WPML provider not ready; stopping.' );
}
$languages = $provider->get_languages();
$check( 'languages match wpml_active_languages', array_keys( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) ) === $languages, implode( ',', $languages ) );
$check( 'default language', apply_filters( 'wpml_default_language', null ) === $provider->get_default_language(), $provider->get_default_language() );

$tracked_types = $container->tracked_fields()->post_types();
$check( 'attachments are not tracked', ! in_array( 'attachment', $tracked_types, true ), implode( ',', $tracked_types ) );

$sample     = get_posts(
	array(
		'post_type'        => $tracked_types,
		'numberposts'      => 300, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts -- One-off dev check.
		'post_status'      => 'any',
		'suppress_filters' => true,
		'orderby'          => 'rand',
	)
);
$mismatches = 0;
$groups     = 0;
foreach ( $sample as $post ) {
	$type    = apply_filters( 'wpml_element_type', $post->post_type );
	$trid    = apply_filters( 'wpml_element_trid', null, $post->ID, $type );
	$wpml    = $trid ? (array) apply_filters( 'wpml_get_element_translations', null, $trid, $type ) : array();
	$expect  = null;
	$members = array();
	foreach ( $wpml as $lang => $row ) {
		if ( ! empty( $row->element_id ) ) {
			$members[ $lang ] = (int) $row->element_id;
			if ( null === $row->source_language_code ) {
				$expect = (int) $row->element_id;
			}
		}
	}
	ksort( $members );
	$group = $provider->get_group( $post->ID );
	ksort( $group );
	if ( $group !== $members || $provider->get_source( $post->ID ) !== $expect ) {
		++$mismatches;
	}
	$groups += $trid ? 1 : 0;
}
$check( 'groups and sources agree with WPML for ' . count( $sample ) . ' random posts', 0 === $mismatches, "{$mismatches} mismatches" );

WP_CLI::log( '2. Tracked ACF fields' );
$acf = $container->acf();
if ( $acf->is_enabled() ) {
	foreach ( $tracked_types as $type ) {
		$names = array_keys( $acf->tracked_fields( $type ) );
		if ( array() !== $names ) {
			WP_CLI::log( sprintf( '  %-18s %s', $type, implode( ', ', $names ) ) );
		}
	}
} else {
	WP_CLI::log( '  ACF not active.' );
}

if ( ! $skip_base ) {
	WP_CLI::log( '3. Baseline (in-process)' );
	$start = microtime( true );
	$batch = $container->baseline();
	$hook  = TranslationDrift\Services\BaselineJob::BASELINE_HOOK;
	$batch->start_baseline();
	// Follow the job's own queue: each batch schedules the next with its arguments.
	for ( $loops = 0; $loops < 10000; $loops++ ) {
		$next = null;
		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( (array) ( $hooks[ $hook ] ?? array() ) as $event ) {
				$next = $event['args'];
			}
		}
		if ( null === $next ) {
			break;
		}
		wp_unschedule_hook( $hook );
		$batch->run_baseline_batch( (int) $next[0], (bool) ( $next[1] ?? false ) );
	}
	$counts = $container->sync_repository()->count_by_status();
	$check( 'baseline finished', 'done' === $batch->state()['status'], sprintf( '%d posts in %.1fs', $batch->state()['processed'], microtime( true ) - $start ) );
	$check( 'no translation left untracked', 0 === $counts['untracked'], wp_json_encode( $counts ) );
}

WP_CLI::log( '4. Drift scenarios on temporary drafts' );
$created = array();
$link    = static function ( int $source, int $translation, string $post_type ) use ( &$created ): void {
	$type = apply_filters( 'wpml_element_type', $post_type );
	do_action(
		'wpml_set_element_language_details',
		array(
			'element_id'    => $source,
			'element_type'  => $type,
			'trid'          => false,
			'language_code' => 'en',
		)
	);
	$trid = apply_filters( 'wpml_element_trid', null, $source, $type );
	do_action(
		'wpml_set_element_language_details',
		array(
			'element_id'           => $translation,
			'element_type'         => $type,
			'trid'                 => $trid,
			'language_code'        => 'fr',
			'source_language_code' => 'en',
		)
	);
	array_push( $created, $source, $translation );
};
$status  = static fn( int $source ): ?Status => ( $container->sync_repository()->for_source( $source )['fr'] ?? null )?->status;
$recalc  = static function ( int $source ) use ( $container ): void {
	wp_unschedule_hook( TranslationDrift\Services\PostHooks::RECALC_SOURCE_HOOK );
	$container->drift_service()->recalculate_source( $source );
};

try {
	// 4a. ACF block, copied from a real page when one exists.
	$banner = null;
	foreach ( get_posts(
		array(
			'post_type'        => 'page',
			'numberposts'      => 50,
			'suppress_filters' => false,
			'lang'             => 'en',
		)
	) as $page ) {
		foreach ( parse_blocks( $page->post_content ) as $block ) {
			if ( is_string( $block['blockName'] ) && $acf->is_acf_block( $block['blockName'] ) && ! empty( $block['attrs']['data']['heading'] ) ) {
				$banner = $block;
				break 2;
			}
		}
	}
	if ( null === $banner ) {
		WP_CLI::log( '  SKIP no ACF block with a heading found in English pages.' );
	} else {
		$render = static fn( array $block ): string => serialize_block( $block );
		$en     = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'TDRIFT CHECK source',
				'post_content' => wp_slash( $render( $banner ) ),
			)
		);
		$fr     = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'TDRIFT CHECK traduction',
				'post_content' => '',
			)
		);
		$link( $en, $fr, 'page' );
		$container->sync_service()->mark_synced( $fr );
		$check( 'new ACF-block page pair starts in sync', Status::InSync === $status( $en ), $banner['blockName'] );

		$layout = $banner;
		foreach ( $layout['attrs']['data'] as $key => $value ) {
			$field = str_starts_with( (string) $key, '_' ) ? null : acf_get_field( (string) ( $layout['attrs']['data'][ '_' . $key ] ?? '' ) );
			if ( is_array( $field ) && in_array( $field['type'], array( 'number', 'true_false', 'select', 'button_group' ), true ) ) {
				$layout['attrs']['data'][ $key ] = 'number' === $field['type'] ? (int) $value + 7 : ( 'true_false' === $field['type'] ? ( $value ? '0' : '1' ) : $value . '-changed' );
			}
		}
		wp_update_post(
			array(
				'ID'           => $en,
				'post_content' => wp_slash( $render( $layout ) ),
			)
		);
		$recalc( $en );
		$check( 'layout-only ACF block edit does not drift', Status::InSync === $status( $en ) );

		$text                             = $layout;
		$text['attrs']['data']['heading'] = $text['attrs']['data']['heading'] . ' (edited)';
		wp_update_post(
			array(
				'ID'           => $en,
				'post_content' => wp_slash( $render( $text ) ),
			)
		);
		$recalc( $en );
		$row = $container->sync_repository()->for_source( $en )['fr'] ?? null;
		$check( 'ACF block heading edit drifts', Status::Outdated === $row?->status, wp_json_encode( $row?->changed_fields ) );
	}

	// 4b. ACF post fields with WPML preferences, on the first post type that has both kinds.
	$management = apply_filters( 'wpml_setting', null, 'translation-management' );
	$prefs      = (array) ( $management['custom_fields_translation'] ?? array() );
	$scenario   = null;
	foreach ( $tracked_types as $type ) {
		$translate = null;
		$copy      = null;
		foreach ( acf_get_field_groups( array( 'post_type' => $type ) ) as $group_def ) {
			foreach ( (array) acf_get_fields( $group_def ) as $field ) {
				$pref = $prefs[ $field['name'] ] ?? null;
				if ( null === $translate && 2 === (int) $pref && 'text' === $field['type'] ) {
					$translate = $field['name'];
				}
				if ( null === $copy && null !== $pref && 1 === (int) $pref && in_array( $field['type'], array( 'text', 'url' ), true ) ) {
					$copy = $field['name'];
				}
			}
		}
		if ( $translate && $copy ) {
			$scenario = array( $type, $translate, $copy );
			break;
		}
	}
	if ( null === $scenario ) {
		WP_CLI::log( '  SKIP no post type with both a "translate" and a "copy" ACF text field.' );
	} else {
		list( $type, $translate, $copy ) = $scenario;
		$en                              = wp_insert_post(
			array(
				'post_type'   => $type,
				'post_status' => 'draft',
				'post_title'  => 'TDRIFT CHECK source',
			)
		);
		$fr                              = wp_insert_post(
			array(
				'post_type'   => $type,
				'post_status' => 'draft',
				'post_title'  => 'TDRIFT CHECK traduction',
			)
		);
		$link( $en, $fr, $type );
		update_field( $translate, 'Original', $en );
		update_field( $copy, 'https://example.com/a', $en );
		$container->acf()->flush_cache();
		$container->sync_service()->mark_synced( $fr );

		update_field( $copy, 'https://example.com/b', $en );
		$recalc( $en );
		$check( "\"copy\" field {$type}.{$copy} does not drift", Status::InSync === $status( $en ) );

		update_field( $translate, 'Changed', $en );
		$recalc( $en );
		$row = $container->sync_repository()->for_source( $en )['fr'] ?? null;
		$check( "\"translate\" field {$type}.{$translate} drifts", Status::Outdated === $row?->status && in_array( 'acf:' . $translate, $row->changed_fields, true ), wp_json_encode( $row?->changed_fields ) );
	}
} finally {
	foreach ( array_reverse( $created ) as $id ) {
		wp_delete_post( $id, true );
	}
	wp_unschedule_hook( TranslationDrift\Services\PostHooks::RECALC_SOURCE_HOOK );
	$left = 0;
	foreach ( $created as $id ) {
		$left += null === $container->sync_repository()->find_by_translation( $id ) && array() === $container->sync_repository()->for_source( $id ) ? 0 : 1;
	}
	$check( 'temporary posts and their records removed', 0 === $left && array() === array_filter( array_map( 'get_post', $created ) ), count( $created ) . ' posts' );
}

if ( $failures > 0 ) {
	WP_CLI::error( "{$failures} check(s) failed." );
}
WP_CLI::success( 'All WPML checks passed.' );
