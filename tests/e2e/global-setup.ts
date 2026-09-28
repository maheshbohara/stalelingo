/**
 * External dependencies
 */
import { chromium, type FullConfig } from '@playwright/test';

/**
 * Internal dependencies
 */
import { ADMIN, login } from './helpers/auth';

export default async function globalSetup( config: FullConfig ) {
	const project = config.projects[ 0 ];
	const baseURL = project?.use.baseURL;
	const storageState = project?.use.storageState;

	const browser = await chromium.launch();
	const page = await browser.newPage( { baseURL } );
	await login( page, ADMIN.username, ADMIN.password );
	if ( typeof storageState === 'string' ) {
		await page.context().storageState( { path: storageState } );
	}
	await browser.close();
}
