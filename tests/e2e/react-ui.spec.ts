/**
 * External dependencies
 */
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

/**
 * Internal dependencies
 */
import { login } from './helpers/auth';
import {
	editorPanel,
	findSource,
	openDashboard,
	openEditor,
	rest,
	runCronUntil,
} from './helpers/wp';

test.describe( 'React UI', () => {
	// Each test edits content, waits for the background job, then visits several screens.
	test.describe.configure( { timeout: 180_000 } );

	test( 'a source edit flags FR everywhere, the diff shows it, and marking it clears it', async ( {
		page,
	} ) => {
		await openDashboard( page );
		const source = await findSource( page, 'fr', 'in_sync' );
		const fr = source.translations.fr?.translation_id as number;
		const marker = `e2e ${ Date.now() }`;

		// An editor changes the EN source title in the block editor.
		await openEditor( page, source.id );
		const title = page
			.frameLocator( 'iframe[name="editor-canvas"]' )
			.getByRole( 'textbox', { name: 'Add title' } );
		await title.click();
		await page.keyboard.press( 'End' );
		await page.keyboard.type( ` ${ marker }` );
		await page
			.getByRole( 'region', { name: 'Editor top bar' } )
			.getByRole( 'button', { name: 'Save', exact: true } )
			.click();
		await expect( page.getByText( /updated\./ ).first() ).toBeVisible();

		// The background recalculation runs on WP-Cron.
		await runCronUntil( page, source.id, 'fr', 'outdated' );

		// List table.
		await page.goto( '/wp-admin/edit.php?post_type=post&lang=en' );
		const row = page.locator( '#the-list tr', { hasText: marker } );
		await expect(
			row.getByRole( 'link', { name: 'FR: Outdated' } )
		).toBeVisible();

		// Dashboard, with the diff modal.
		await openDashboard( page );
		await page
			.getByRole( 'searchbox', { name: 'Search titles' } )
			.fill( marker );
		const badge = page.getByRole( 'button', {
			name: 'FR: Outdated – View changes',
		} );
		await expect( badge ).toHaveCount( 1 );
		await expect(
			page.getByRole( 'region', { name: 'Summary' } )
		).toBeVisible();
		const dashboardAxe = await new AxeBuilder( { page } )
			.include( '#stalelingo-dashboard-root' )
			.analyze();
		expect( dashboardAxe.violations ).toEqual( [] );

		await badge.click();
		const dialog = page.getByRole( 'dialog', {
			name: /^Changes to “.*” for /,
		} );
		await expect( dialog ).toBeVisible();
		await expect(
			dialog.getByRole( 'heading', { name: 'Title' } )
		).toBeVisible();
		await expect(
			dialog
				.locator( 'ins', { hasText: marker.split( ' ' )[ 1 ] } )
				.first()
		).toBeVisible();
		await expect(
			dialog.getByRole( 'link', { name: 'Open source' } )
		).toBeVisible();
		await expect(
			dialog.getByRole( 'link', { name: 'Open translation' } )
		).toBeVisible();
		const modalAxe = await new AxeBuilder( { page } )
			.include( '.components-modal__frame' )
			.analyze();
		expect( modalAxe.violations ).toEqual( [] );
		await page.keyboard.press( 'Escape' );
		await expect( dialog ).toBeHidden();

		// Block editor panel on the FR translation: status, inline diff, then mark it up to date.
		await openEditor( page, fr );
		const panel = editorPanel( page );
		await expect( panel.getByText( 'FR: Outdated' ) ).toBeAttached();
		await expect(
			panel
				.locator( 'ins', { hasText: marker.split( ' ' )[ 1 ] } )
				.first()
		).toBeAttached();
		await panel
			.getByRole( 'button', { name: 'Mark as up to date' } )
			.click();
		await expect( panel.getByText( 'FR: Up to date' ) ).toBeAttached();

		// Cleared everywhere.
		await page.goto( '/wp-admin/edit.php?post_type=post&lang=en' );
		await expect(
			row.getByRole( 'link', { name: 'FR: Up to date' } )
		).toBeVisible();
		await openDashboard( page );
		await page
			.getByRole( 'searchbox', { name: 'Search titles' } )
			.fill( marker );
		await expect(
			page.getByRole( 'link', { name: 'FR: Up to date' } )
		).toHaveCount( 1 );
	} );

	test( 'a translator without the capability can’t open the dashboard but can mark their own translation', async ( {
		page,
		browser,
	} ) => {
		await openDashboard( page );
		const source = await findSource( page, 'fr', 'in_sync' );
		const fr = source.translations.fr?.translation_id as number;
		await rest( page, `/wp/v2/posts/${ source.id }`, {
			method: 'POST',
			data: { title: `${ source.title } (translator e2e)` },
		} );
		await runCronUntil( page, source.id, 'fr', 'outdated' );

		const context = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
			extraHTTPHeaders: { 'X-Stalelingo-E2E': '1' },
		} );
		const translator = await context.newPage();
		await login( translator, 'translator-fr', 'password' );

		await translator.goto( '/wp-admin/tools.php?page=stalelingo' );
		await expect(
			translator.getByText( /not allowed to access this page/ )
		).toBeVisible();

		await openEditor( translator, fr );
		const panel = editorPanel( translator );
		await expect( panel.getByText( 'FR: Outdated' ) ).toBeAttached();
		const code = await translator.evaluate( () =>
			window.wp.apiFetch( { path: '/stalelingo/v1/status' } ).then(
				() => 'allowed',
				( e: { code?: string } ) => e.code
			)
		);
		expect( code ).toBe( 'rest_forbidden' );
		await panel
			.getByRole( 'button', { name: 'Mark as up to date' } )
			.click();
		await expect( panel.getByText( 'FR: Up to date' ) ).toBeAttached();
		await context.close();

		const group = await rest< {
			translations: Record< string, { status: string } >;
		} >( page, `/stalelingo/v1/group/${ source.id }` );
		expect( group.translations.fr?.status ).toBe( 'in_sync' );
	} );
} );
