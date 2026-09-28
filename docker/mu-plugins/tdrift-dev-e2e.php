<?php
/**
 * Development only: settings that keep repeated e2e runs independent.
 *
 * The e2e tests open the same posts as different users in quick succession. Post
 * locks (150 seconds by default) would show "Someone else is editing this post"
 * dialogs, so the lock window is cut to a few seconds on the dev site.
 *
 * Requests from the e2e browser (marked with an X-Tdrift-E2E header, see
 * playwright.config.ts) don't spawn WP-Cron. Otherwise every request can take the
 * cron lock, and a spawned run can miss the job a test just queued. Tests run jobs by
 * requesting wp-cron.php directly, which ignores DISABLE_WP_CRON. Ordinary browsing of
 * the dev site still spawns cron as usual.
 *
 * This file lives in docker/ and is never shipped in the plugin zip.
 *
 * @package TranslationDrift
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'wp_check_post_lock_window', static fn(): int => 2 );

if ( isset( $_SERVER['HTTP_X_TDRIFT_E2E'] ) && ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}
