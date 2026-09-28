/**
 * External dependencies
 */
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

test.describe( 'Plugin smoke test', () => {
	test( 'is active without the missing-provider notice', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/plugins.php' );

		const row = page.locator(
			'tr[data-plugin="translation-drift/translation-drift.php"]'
		);
		await expect( row ).toHaveClass( /active/ );
		await expect( page.locator( '.tdrift-dependency-notice' ) ).toHaveCount(
			0
		);
	} );

	test( 'dashboard screen loads its app and passes axe', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/tools.php?page=translation-drift' );

		await expect(
			page.getByRole( 'heading', { name: 'Translation Drift', level: 1 } )
		).toBeVisible();
		await expect(
			page.locator( '#tdrift-dashboard-root .components-notice' )
		).toBeVisible();

		const results = await new AxeBuilder( { page } )
			.include( '#tdrift-dashboard-root' )
			.analyze();
		expect( results.violations ).toEqual( [] );
	} );
} );
