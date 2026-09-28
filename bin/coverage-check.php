<?php
/**
 * Enforces coverage thresholds from a Clover report.
 *
 * Usage: php bin/coverage-check.php coverage/clover.xml
 *
 * - At least 70% line coverage overall.
 * - At least 85% on domain and service classes (includes/Domain, includes/Services).
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals

const OVERALL_MIN = 70.0;
const CORE_MIN    = 85.0;
const CORE_DIRS   = array( '/includes/Domain/', '/includes/Services/' );

$file = $argv[1] ?? 'coverage/clover.xml';
if ( ! is_readable( $file ) ) {
	fwrite( STDERR, "Clover report not found: {$file}\n" );
	exit( 1 );
}

$xml      = simplexml_load_file( $file );
$totals   = array(
	'all'  => array( 0, 0 ),
	'core' => array( 0, 0 ),
);
$per_file = array();

foreach ( $xml->xpath( '//file' ) as $node ) {
	$metrics    = $node->metrics;
	$statements = (int) $metrics['statements'];
	$covered    = (int) $metrics['coveredstatements'];
	$path       = (string) $node['name'];

	$totals['all'][0] += $covered;
	$totals['all'][1] += $statements;

	foreach ( CORE_DIRS as $dir ) {
		if ( str_contains( $path, $dir ) ) {
			$totals['core'][0] += $covered;
			$totals['core'][1] += $statements;
			$per_file[ $path ]  = $statements ? 100 * $covered / $statements : 100.0;
		}
	}
}

$pct = static fn( array $t ): float => $t[1] ? 100 * $t[0] / $t[1] : 100.0;

$overall = $pct( $totals['all'] );
$core    = $pct( $totals['core'] );
$failed  = false;

printf( "Overall line coverage: %.1f%% (min %.0f%%)\n", $overall, OVERALL_MIN );
if ( $totals['core'][1] > 0 ) {
	printf( "Domain/service coverage: %.1f%% (min %.0f%%)\n", $core, CORE_MIN );
} else {
	echo "Domain/service coverage: no domain or service classes yet.\n";
}

if ( $overall < OVERALL_MIN ) {
	fwrite( STDERR, "Overall coverage is below the threshold.\n" );
	$failed = true;
}
if ( $totals['core'][1] > 0 && $core < CORE_MIN ) {
	fwrite( STDERR, "Domain/service coverage is below the threshold.\n" );
	arsort( $per_file );
	foreach ( $per_file as $path => $value ) {
		fprintf( STDERR, "  %5.1f%%  %s\n", $value, $path );
	}
	$failed = true;
}

exit( $failed ? 1 : 0 );
