<?php
/**
 * Unit test bootstrap: no WordPress, functions are mocked with Brain Monkey.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

define( 'ABSPATH', __DIR__ . '/fixtures/wordpress/' );
define( 'TDRIFT_VERSION', '0.0.0-test' );
define( 'TDRIFT_FILE', dirname( __DIR__, 2 ) . '/translation-drift.php' );
define( 'TDRIFT_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'TDRIFT_URL', 'http://example.org/wp-content/plugins/translation-drift/' );
