/**
 * External dependencies
 */
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

/**
 * Internal dependencies
 */
import { findSource, openDashboard, rest, runCronUntil } from './helpers/wp';

test.describe( 'Admin UI', () => {
	test( 'settings page saves and passes axe', async ( { page } ) => {
		await page.goto(
			'/wp-admin/options-general.php?page=translation-drift-settings'
		);
		await expect(
			page.getByRole( 'heading', {
				name: 'Translation Drift settings',
				level: 1,
			} )
		).toBeVisible();

		// WPML adds its own notices (site key) inside .wrap; they aren't this plugin's markup.
		const results = await new AxeBuilder( { page } )
			.include( '#wpbody-content .wrap' )
			.exclude( '.otgs-notice' )
			.analyze();
		expect( results.violations ).toEqual( [] );

		const recipients = page.getByLabel( 'Also send the digest to' );
		await recipients.fill( 'e2e@example.test' );
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();
		await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();
		await expect( recipients ).toHaveValue( 'e2e@example.test' );

		await recipients.fill( '' );
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();
	} );

	test( 'list table shows statuses, filters and bulk-marks', async ( {
		page,
	} ) => {
		// Make sure an outdated post exists, however many runs came before.
		test.setTimeout( 120_000 );
		await openDashboard( page );
		const source = await findSource( page, 'fr', 'in_sync' );
		await rest( page, `/wp/v2/posts/${ source.id }`, {
			method: 'POST',
			data: { title: `${ source.title } (list e2e)` },
		} );
		await runCronUntil( page, source.id, 'fr', 'outdated' );

		await page.goto( '/wp-admin/edit.php?post_type=post&lang=en' );

		const outdated = page
			.locator( '.column-tdrift-status .tdrift-badge-outdated' )
			.first();
		await expect( outdated ).toBeVisible();
		await expect( outdated ).toContainText( /Outdated/ );

		// Filter to outdated posts.
		await page
			.getByLabel( 'Filter by translation status' )
			.selectOption( 'outdated' );
		await page.getByRole( 'button', { name: 'Filter' } ).click();
		await expect( page ).toHaveURL( /tdrift_status=outdated/ );
		const rows = page.locator( '#the-list tr' );
		const count = await rows.count();
		expect( count ).toBeGreaterThan( 0 );
		for ( let i = 0; i < count; i++ ) {
			await expect(
				rows.nth( i ).locator( '.tdrift-badge-outdated' ).first()
			).toBeVisible();
		}

		// Bulk-mark the first outdated source's translations as up to date.
		const firstRow = rows.first();
		const title = (
			await firstRow.locator( 'a.row-title' ).innerText()
		).trim();
		await firstRow.locator( 'input[type="checkbox"]' ).first().check();
		await page
			.locator( '#bulk-action-selector-top' )
			.selectOption( 'tdrift_mark_synced' );
		await page.locator( '#doaction' ).click();
		await expect(
			page.getByText( /translations? marked as up to date/ )
		).toBeVisible();

		await page.goto( '/wp-admin/edit.php?post_type=post&lang=en' );
		const row = page.locator( '#the-list tr', {
			has: page.getByRole( 'link', { name: title, exact: true } ),
		} );
		await expect( row.locator( '.tdrift-badge-outdated' ) ).toHaveCount(
			0
		);
	} );

	test( 'admin bar shows the outdated count', async ( { page } ) => {
		await page.goto( '/wp-admin/' );
		const node = page.locator( '#wp-admin-bar-tdrift-outdated' );
		await expect( node ).toBeVisible();
		await expect( node ).toContainText( /outdated translations?/ );
	} );
} );
