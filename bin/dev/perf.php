<?php
/**
 * Development only: the performance run behind `make perf`.
 *
 * Run with `wp eval-file bin/dev/perf.php` on a site seeded with `make seed`.
 * Measures, against the spec's targets:
 *
 * - baseline: batches finish well inside a request timeout (reports the slowest batch);
 * - dashboard: the first page of `GET tdrift/v1/status` answers in under 500 ms;
 * - list table: the status column adds at most one query for a page of 20 posts;
 * - EXPLAIN of the main queries.
 *
 * It rebuilds the baseline (forced) as part of the measurement.
 *
 * @package TranslationDrift
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Development script.

use TranslationDrift\Plugin;

global $wpdb;

$container = Plugin::container();
if ( ! $container->has_provider() ) {
	WP_CLI::error( 'No multilingual plugin is ready.' );
}

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) ( $admins[0] ?? 1 ) );

$baseline = $container->baseline();
$failures = array();

WP_CLI::log( '# Translation Drift performance run' );
WP_CLI::log( '' );
WP_CLI::log( sprintf( 'Tracked posts: %d (batch size %d)', $baseline->count_posts(), $baseline->batch_size() ) );

// 1. Baseline, batch by batch.
$times  = array();
$last   = microtime( true );
$start  = $last;
$result = $baseline->run_baseline_now(
	true,
	static function () use ( &$times, &$last ): void {
		$now     = microtime( true );
		$times[] = $now - $last;
		$last    = $now;
	}
);
$total  = microtime( true ) - $start;
rsort( $times );
WP_CLI::log( '' );
WP_CLI::log( '## Baseline (forced)' );
WP_CLI::log( sprintf( '- %d posts, %d translations marked, %d batches in %.1f s', $result['posts'], $result['marked'], count( $times ), $total ) );
WP_CLI::log( sprintf( '- Slowest batch: %.2f s; median: %.2f s', $times[0] ?? 0, $times[ (int) floor( count( $times ) / 2 ) ] ?? 0 ) );
if ( ( $times[0] ?? 0 ) > 10 ) {
	$failures[] = 'A baseline batch took over 10 s.';
}
$counts = $container->sync_repository()->count_by_status();
WP_CLI::log( '- Rows: ' . implode( ', ', array_map( static fn( $k, $v ) => "{$k} {$v}", array_keys( $counts ), $counts ) ) );

// 2. Dashboard REST, first page, cold object cache each time.
$rest_times = array();
for ( $i = 0; $i < 5; $i++ ) {
	wp_cache_flush();
	$request      = new WP_REST_Request( 'GET', '/tdrift/v1/status' );
	$t            = microtime( true );
	$response     = rest_do_request( $request );
	$rest_times[] = ( microtime( true ) - $t ) * 1000;
}
sort( $rest_times );
$filtered = new WP_REST_Request( 'GET', '/tdrift/v1/status' );
$filtered->set_query_params(
	array(
		'status' => array( 'outdated' ),
		'lang'   => array( 'fr' ),
	)
);
wp_cache_flush();
$t = microtime( true );
rest_do_request( $filtered );
$filtered_time = ( microtime( true ) - $t ) * 1000;
wp_cache_flush();
$t = microtime( true );
rest_do_request( new WP_REST_Request( 'GET', '/tdrift/v1/status/summary' ) );
$summary_time = ( microtime( true ) - $t ) * 1000;

WP_CLI::log( '' );
WP_CLI::log( '## Dashboard REST (cold cache)' );
WP_CLI::log( sprintf( '- GET status, first page: median %.0f ms, worst %.0f ms (target < 500 ms); total %s sources', $rest_times[2], $rest_times[4], (string) ( $response->get_headers()['X-WP-Total'] ?? '?' ) ) );
WP_CLI::log( sprintf( '- GET status?status=outdated&lang=fr: %.0f ms', $filtered_time ) );
WP_CLI::log( sprintf( '- GET status/summary: %.0f ms', $summary_time ) );
if ( $rest_times[2] >= 500 ) {
	$failures[] = 'The dashboard first page took 500 ms or more.';
}

// 3. List table column: queries for a page of 20 posts.
require_once ABSPATH . 'wp-admin/includes/template.php';
$posts                      = get_posts(
	array(
		'post_type'   => 'post',
		'numberposts' => 20,
		'lang'        => '',
	)
);
$GLOBALS['wp_query']->posts = $posts;
$table                      = $container->list_table();
$before                     = $wpdb->num_queries;
ob_start();
foreach ( $posts as $post ) {
	$table->render_column( \TranslationDrift\Admin\ListTable::COLUMN, $post->ID );
}
ob_end_clean();
$column_queries = $wpdb->num_queries - $before;
WP_CLI::log( '' );
WP_CLI::log( '## List table' );
WP_CLI::log( sprintf( '- Status column for %d posts: %d queries (target <= 1)', count( $posts ), $column_queries ) );
if ( $column_queries > 1 ) {
	$failures[] = 'The list-table column ran more than one query.';
}

// 4. EXPLAIN of the main queries, captured as the plugin runs them.
$captured = array();
$capture  = static function ( string $sql ) use ( &$captured ): string {
	if ( str_contains( $sql, 'tdrift_' ) && 0 === stripos( ltrim( $sql ), 'SELECT' ) ) {
		$captured[] = $sql;
	}
	return $sql;
};
add_filter( 'query', $capture );
$sync = $container->sync_repository();
$page = $sync->query_sources(
	array(
		'post_types' => $container->tracked_fields()->post_types(),
		'per_page'   => 20,
	)
);
$sync->query_sources(
	array(
		'post_types' => $container->tracked_fields()->post_types(),
		'statuses'   => array( 'outdated' ),
		'langs'      => array( 'fr' ),
		'per_page'   => 20,
	)
);
$sync->for_sources( $page['ids'] );
$sync->summary();
$sync->find_with_status( array( \TranslationDrift\Domain\Status::Outdated ), array( 'fr' ), 101 );
remove_filter( 'query', $capture );

WP_CLI::log( '' );
WP_CLI::log( '## EXPLAIN' );
foreach ( array_values( array_unique( $captured ) ) as $sql ) {
	$flat = preg_replace( '/\s+/', ' ', $sql );
	WP_CLI::log( '' );
	WP_CLI::log( '```sql' );
	WP_CLI::log( strlen( $flat ) > 400 ? substr( $flat, 0, 400 ) . ' …' : $flat );
	WP_CLI::log( '```' );
	WP_CLI::log( '| table | type | key | rows | Extra |' );
	WP_CLI::log( '|---|---|---|---|---|' );
	foreach ( (array) $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A ) as $row ) {
		WP_CLI::log( sprintf( '| %s | %s | %s | %s | %s |', $row['table'] ?? '', $row['type'] ?? '', $row['key'] ?? '', $row['rows'] ?? '', $row['Extra'] ?? '' ) );
	}
}

WP_CLI::log( '' );
if ( array() !== $failures ) {
	WP_CLI::error( implode( ' ', $failures ) );
}
WP_CLI::success( 'All performance targets met.' );
