/**
 * External dependencies
 */
import type { Page } from '@playwright/test';

export const ADMIN = { username: 'admin', password: 'password' };

/**
 * Logs in through wp-login.php.
 *
 * @param page     Playwright page.
 * @param username User login.
 * @param password User password.
 */
export async function login(
	page: Page,
	username: string,
	password: string
): Promise< void > {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( username );
	await page.locator( '#user_pass' ).fill( password );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /wp-admin/ );
}
