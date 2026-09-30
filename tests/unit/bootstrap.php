<?php
/**
 * Unit test bootstrap: no WordPress, functions are mocked with Brain Monkey.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

define( 'ABSPATH', __DIR__ . '/fixtures/wordpress/' );
define( 'STALELINGO_VERSION', '0.0.0-test' );
define( 'STALELINGO_FILE', dirname( __DIR__, 2 ) . '/stalelingo.php' );
define( 'STALELINGO_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'STALELINGO_URL', 'http://example.org/wp-content/plugins/stalelingo/' );
