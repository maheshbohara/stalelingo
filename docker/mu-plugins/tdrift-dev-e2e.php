<?php
/**
 * Development only: settings that keep repeated e2e runs independent.
 *
 * The e2e tests open the same posts as different users in quick succession. Post
 * locks (150 seconds by default) would show "Someone else is editing this post"
 * dialogs, so the lock window is cut to a few seconds on the dev site.
 *
 * This file lives in docker/ and is never shipped in the plugin zip.
 *
 * @package TranslationDrift
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'wp_check_post_lock_window', static fn(): int => 2 );
