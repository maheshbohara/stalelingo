<?php
/**
 * Development only: route all outgoing mail to Mailpit.
 *
 * This file lives in docker/ and is never shipped in the plugin zip.
 *
 * @package TranslationDrift
 */

defined( 'ABSPATH' ) || exit;

// The dev site runs on "localhost", so WordPress's default sender (wordpress@localhost) fails
// PHPMailer's address check and wp_mail() returns false. Use a valid address instead.
add_filter( 'wp_mail_from', static fn(): string => 'wordpress@example.test' );

add_action(
	'phpmailer_init',
	static function ( $phpmailer ): void {
		$phpmailer->isSMTP();
		$phpmailer->Host     = 'mailpit'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->Port     = 1025; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->SMTPAuth = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}
);
