/**
 * External dependencies
 */
import { expect, type Page } from '@playwright/test';

/**
 * Minimal shape of the REST items the tests read.
 */
export interface E2eTranslation {
	lang: string;
	status: string;
	translation_id: number;
}

export interface E2eSource {
	id: number;
	title: string;
	translations: Record< string, E2eTranslation >;
}

declare global {
	interface Window {
		wp: {
			apiFetch: < T >(
				options: Record< string, unknown >
			) => Promise< T >;
			data: {
				dispatch: (
					store: string
				) => Record< string, ( ...args: unknown[] ) => unknown >;
			};
		};
	}
}

/**
 * Calls the REST API through wp.apiFetch, which adds the cookie nonce.
 * The page must be a wp-admin screen that loads wp-api-fetch (the dashboard or the editor).
 *
 * @param page           Page.
 * @param path           REST path.
 * @param options        Request options.
 * @param options.method HTTP method.
 * @param options.data   Request body.
 * @return Parsed response.
 */
export async function rest< T >(
	page: Page,
	path: string,
	options: { method?: string; data?: Record< string, unknown > } = {}
): Promise< T > {
	return page.evaluate( ( args ) => window.wp.apiFetch< T >( args ), {
		path,
		...options,
	} );
}

/**
 * Opens the dashboard, which also gives later rest() calls an admin page to run in.
 *
 * @param page Page.
 */
export async function openDashboard( page: Page ): Promise< void > {
	await page.goto( '/wp-admin/tools.php?page=stalelingo' );
	await expect( page.locator( '.dataviews-wrapper' ) ).toBeVisible();
}

/**
 * First source of a post type whose translation in `lang` has a status.
 *
 * @param page     Page on the dashboard.
 * @param lang     Language code.
 * @param status   Status.
 * @param postType Post type.
 * @param skipIds  Sources to leave out.
 * @return Source.
 */
export async function findSource(
	page: Page,
	lang: string,
	status: string,
	postType = 'post',
	skipIds: number[] = []
): Promise< E2eSource > {
	const matching = async ( wanted: string ) =>
		(
			await rest< E2eSource[] >(
				page,
				`/stalelingo/v1/status?status=${ wanted }&lang=${ lang }&post_type=${ postType }&per_page=100`
			)
		).find(
			( item ) =>
				item.translations[ lang ]?.status === wanted &&
				! skipIds.includes( item.id )
		);

	let found = await matching( status );
	if ( ! found && status === 'in_sync' ) {
		// Earlier runs may have left every seeded translation outdated: bring one back.
		const outdated = await matching( 'outdated' );
		const id = outdated?.translations[ lang ]?.translation_id;
		if ( outdated && id ) {
			await rest( page, '/stalelingo/v1/mark-synced', {
				method: 'POST',
				data: { ids: [ id ] },
			} );
			found = await matching( status );
		}
	}
	expect(
		found,
		`a ${ postType } whose ${ lang } translation is ${ status }`
	).toBeTruthy();

	return found as E2eSource;
}

/**
 * Runs WP-Cron (like `make cron-run`) until the translation reaches a status.
 *
 * @param page     Page on a wp-admin screen.
 * @param sourceId Source post ID.
 * @param lang     Language code.
 * @param status   Expected status.
 */
export async function runCronUntil(
	page: Page,
	sourceId: number,
	lang: string,
	status: string
): Promise< void > {
	await expect
		.poll(
			async () => {
				await page.request.get( '/wp-cron.php' );
				const group = await rest< {
					translations: Record< string, E2eTranslation >;
				} >( page, `/stalelingo/v1/group/${ sourceId }` );

				return group.translations[ lang ]?.status;
			},
			// Longer than WP_CRON_LOCK_TIMEOUT (60 s): while a cron run WordPress spawned itself
			// holds the lock, requests to wp-cron.php return without running anything.
			{ timeout: 75_000, intervals: [ 500, 1000, 2000 ] }
		)
		.toBe( status );
}

/**
 * Opens a post in the block editor with the Stalelingo panel expanded.
 *
 * @param page   Page.
 * @param postId Post ID.
 */
export async function openEditor(
	page: Page,
	postId: number
): Promise< void > {
	await page.goto( `/wp-admin/post.php?post=${ postId }&action=edit` );
	await page.waitForFunction( () => !! window.wp?.data );
	await page.evaluate( () => {
		const preferences = window.wp.data.dispatch( 'core/preferences' );
		preferences.set?.( 'core/edit-post', 'welcomeGuide', false );
		preferences.set?.( 'core', 'welcomeGuide', false );
	} );
	const guide = page.getByRole( 'dialog', { name: /welcome/i } );
	if ( await guide.isVisible() ) {
		await page.keyboard.press( 'Escape' );
	}

	const settings = page
		.getByRole( 'region', { name: 'Editor top bar' } )
		.getByRole( 'button', { name: 'Settings', exact: true } );
	await expect( settings ).toBeVisible();
	if ( ( await settings.getAttribute( 'aria-expanded' ) ) === 'false' ) {
		await settings.click();
	}
	const toggle = page.getByRole( 'button', {
		name: 'Stalelingo',
		exact: true,
	} );
	await expect( toggle ).toBeVisible();
	if ( ( await toggle.getAttribute( 'aria-expanded' ) ) === 'false' ) {
		await toggle.click();
	}
}

/**
 * The Stalelingo panel in the block editor.
 *
 * @param page Page.
 * @return Locator.
 */
export function editorPanel( page: Page ) {
	return page.locator( '.stalelingo-editor-panel' );
}
