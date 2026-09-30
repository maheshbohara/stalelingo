<?php
/**
 * Validates readme.txt against the WordPress.org rules the web validator enforces,
 * and checks that every version number in the project agrees.
 *
 * Usage: php bin/readme-validate.php  (exit code 1 on failure)
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals

$root   = dirname( __DIR__ );
$readme = (string) file_get_contents( $root . '/readme.txt' );
$main   = (string) file_get_contents( $root . '/stalelingo.php' );
$errors = array();

/**
 * Reads a "Key: value" header line.
 */
$header = static function ( string $text, string $key ): ?string {
	return preg_match( '/^[ \t\/*#@]*' . preg_quote( $key, '/' ) . ':\s*(.+)$/mi', $text, $m ) ? trim( $m[1] ) : null;
};

if ( ! preg_match( '/^=== (.+) ===$/m', $readme, $m ) ) {
	$errors[] = 'Missing "=== Plugin Name ===" line.';
} elseif ( trim( $m[1] ) !== $header( $main, 'Plugin Name' ) ) {
	$errors[] = 'readme name does not match the Plugin Name header.';
}

foreach ( array( 'Tags', 'Requires at least', 'Tested up to', 'Requires PHP', 'Stable tag', 'License' ) as $key ) {
	if ( null === $header( $readme, $key ) ) {
		$errors[] = "Missing readme header: {$key}.";
	}
}

$tags = array_filter( array_map( 'trim', explode( ',', (string) $header( $readme, 'Tags' ) ) ) );
if ( count( $tags ) > 5 ) {
	$errors[] = sprintf( 'Too many tags (%d); WordPress.org uses the first 5.', count( $tags ) );
}
foreach ( $tags as $tag ) {
	if ( preg_match( '/polylang|wpml|elementor|acf/i', $tag ) ) {
		$errors[] = "Tag '{$tag}' names another plugin; WordPress.org disallows that.";
	}
}

// The short description is the first paragraph after the header block.
$blocks = preg_split( '/\R\R/', $readme );
$short  = trim( (string) ( $blocks[1] ?? '' ) );
if ( '' === $short || str_starts_with( $short, '==' ) ) {
	$errors[] = 'Missing short description.';
} elseif ( mb_strlen( $short ) > 150 ) {
	$errors[] = sprintf( 'Short description is %d characters (max 150).', mb_strlen( $short ) );
}

foreach ( array( 'Description', 'Installation', 'Frequently Asked Questions', 'Screenshots', 'Changelog', 'Upgrade Notice' ) as $section ) {
	if ( ! preg_match( '/^== ' . preg_quote( $section, '/' ) . ' ==$/m', $readme ) ) {
		$errors[] = "Missing section: == {$section} ==.";
	}
}

$tested = (string) $header( $readme, 'Tested up to' );
if ( ! preg_match( '/^\d+\.\d+$/', $tested ) ) {
	$errors[] = "Tested up to must be a major version like 7.1 (got '{$tested}').";
}
if ( null !== $header( $main, 'Tested up to' ) ) {
	$errors[] = 'Tested up to belongs in readme.txt only; Plugin Check reports it in the plugin header as an error.';
}

// Version agreement.
$versions = array(
	'readme Stable tag'  => $header( $readme, 'Stable tag' ),
	'plugin header'      => $header( $main, 'Version' ),
	'STALELINGO_VERSION' => preg_match( "/define\( 'STALELINGO_VERSION', '([^']+)' \)/", $main, $m ) ? $m[1] : null,
	'package.json'       => json_decode( (string) file_get_contents( $root . '/package.json' ), true )['version'] ?? null,
);
if ( count( array_unique( $versions ) ) !== 1 ) {
	$errors[] = 'Version mismatch: ' . json_encode( $versions );
}
$stable = (string) $versions['readme Stable tag'];
if ( ! preg_match( '/^= ' . preg_quote( $stable, '/' ) . ' =$/m', $readme ) ) {
	$errors[] = "Changelog has no entry for the stable tag {$stable}.";
}

foreach ( array( 'Requires at least', 'Requires PHP' ) as $key ) {
	if ( $header( $readme, $key ) !== $header( $main, $key ) ) {
		$errors[] = "{$key} differs between readme.txt and the plugin header.";
	}
}

if ( $errors ) {
	fwrite( STDERR, "readme.txt validation failed:\n  - " . implode( "\n  - ", $errors ) . "\n" );
	exit( 1 );
}

echo "readme.txt OK (version {$stable}, " . count( $tags ) . ' tags, short description ' . mb_strlen( $short ) . " chars).\n";
