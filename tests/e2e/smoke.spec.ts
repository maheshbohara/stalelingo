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
			'tr[data-plugin="stalelingo/stalelingo.php"]'
		);
		await expect( row ).toHaveClass( /active/ );
		await expect(
			page.locator( '.stalelingo-dependency-notice' )
		).toHaveCount( 0 );
	} );

	test( 'dashboard screen loads its app and passes axe', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/tools.php?page=stalelingo' );

		await expect(
			page.getByRole( 'heading', { name: 'Stalelingo', level: 1 } )
		).toBeVisible();
		await expect(
			page.locator( '#stalelingo-dashboard-root .dataviews-wrapper' )
		).toBeVisible();
		await expect(
			page.getByRole( 'region', { name: 'Summary' } )
		).toBeVisible();

		const results = await new AxeBuilder( { page } )
			.include( '#stalelingo-dashboard-root' )
			.analyze();
		expect( results.violations ).toEqual( [] );
	} );
} );
