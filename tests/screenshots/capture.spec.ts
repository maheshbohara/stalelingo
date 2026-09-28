/**
 * Captures the WordPress.org screenshots listed in readme.txt into .wordpress-org/.
 *
 * Not part of the test suite: run with `make screenshots` on the seeded dev site.
 */

/**
 * External dependencies
 */
import { expect, test, type Page } from '@playwright/test';

/**
 * Internal dependencies
 */
import {
	editorPanel,
	findSource,
	openDashboard,
	openEditor,
	rest,
	runCronUntil,
} from '../e2e/helpers/wp';

const OUT = '.wordpress-org';

/**
 * Dismisses Polylang's own setup-wizard notice, which isn't part of this plugin, and reloads.
 *
 * @param page Page.
 */
async function dismissPolylangWizard( page: Page ): Promise< void > {
	const skip = page.getByRole( 'link', { name: 'Skip setup' } );
	if ( await skip.isVisible() ) {
		await skip.click();
		await page.reload();
	}
}

test.use( { viewport: { width: 1400, height: 900 } } );
test.describe.configure( { timeout: 180_000 } );

test( 'readme screenshots', async ( { page } ) => {
	// Make sure the dashboard has a fresh, readable outdated item with a title diff.
	await openDashboard( page );
	const source = await findSource( page, 'fr', 'in_sync' );
	const fr = source.translations.fr?.translation_id as number;
	await rest( page, `/wp/v2/posts/${ source.id }`, {
		method: 'POST',
		data: { title: `${ source.title } – updated for spring` },
	} );
	await runCronUntil( page, source.id, 'fr', 'outdated' );

	// 1. Dashboard.
	await page.goto( '/wp-admin/tools.php?page=translation-drift' );
	await expect( page.locator( '.dataviews-view-table' ) ).toBeVisible();
	await page.screenshot( { path: `${ OUT }/screenshot-1.png` } );

	// 2. Diff modal.
	await page
		.getByRole( 'button', { name: 'FR: Outdated – View changes' } )
		.first()
		.click();
	const dialog = page.getByRole( 'dialog', { name: /^Changes to/ } );
	await expect(
		dialog.getByRole( 'heading', { name: 'Title' } )
	).toBeVisible();
	await page.screenshot( { path: `${ OUT }/screenshot-2.png` } );
	await page.keyboard.press( 'Escape' );

	// 3. Editor panel on the outdated translation.
	await openEditor( page, fr );
	await expect(
		editorPanel( page ).getByText( 'FR: Outdated' )
	).toBeAttached();
	await editorPanel( page ).scrollIntoViewIfNeeded();
	await page.screenshot( { path: `${ OUT }/screenshot-3.png` } );

	// 4. Posts list badges.
	await page.goto( '/wp-admin/edit.php?post_type=post&lang=en' );
	await dismissPolylangWizard( page );
	await expect(
		page.locator( '.column-tdrift-status' ).first()
	).toBeVisible();
	await page.screenshot( { path: `${ OUT }/screenshot-4.png` } );

	// 5. Settings.
	await page.goto(
		'/wp-admin/options-general.php?page=translation-drift-settings'
	);
	await expect(
		page.getByRole( 'heading', { name: 'Translation Drift settings' } )
	).toBeVisible();
	await page.screenshot( { path: `${ OUT }/screenshot-5.png` } );

	// Leave the site as it was: the original title brings the translation back up to date.
	await openDashboard( page );
	await rest( page, `/wp/v2/posts/${ source.id }`, {
		method: 'POST',
		data: { title: source.title },
	} );
	await runCronUntil( page, source.id, 'fr', 'in_sync' );
} );
