/**
 * External dependencies
 */
import { expect, test, type Page } from '@playwright/test';

/**
 * Internal dependencies
 */
import { findSource, openDashboard, rest, runCronUntil } from './helpers/wp';

// Mailpit's API, reachable from the Playwright container (it shares the WordPress container's network).
const MAILPIT = 'http://mailpit:8025/api/v1';

interface MailpitSummary {
	ID: string;
	Subject: string;
	To: { Address: string }[];
}

/**
 * Sets the FR translator and the digest frequency on the settings screen.
 *
 * @param page       Page.
 * @param translator Display name to select, or null to clear.
 * @param frequency  'off', 'daily' or 'weekly'.
 */
async function setDigestSettings(
	page: Page,
	translator: string | null,
	frequency: string
): Promise< void > {
	await page.goto( '/wp-admin/options-general.php?page=stalelingo-settings' );
	await page
		.getByLabel( 'Translators for FR' )
		.selectOption( translator ? { label: translator } : [] );
	await page
		.locator( '#stalelingo-digest-frequency' )
		.selectOption( frequency );
	await page.getByRole( 'button', { name: 'Save Changes' } ).click();
	await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();
}

/**
 * Requests wp-cron.php until the source's recalculation is no longer queued.
 *
 * @param page     Page on a wp-admin screen.
 * @param sourceId Source post ID.
 */
async function runCronUntilIdle(
	page: Page,
	sourceId: number
): Promise< void > {
	await expect
		.poll(
			async () => {
				await page.request.get( '/wp-cron.php' );
				const state = await rest< { queued: boolean } >(
					page,
					`/stalelingo-dev/v1/queued/${ sourceId }`
				);

				return state.queued;
			},
			{ timeout: 75_000, intervals: [ 500, 1000, 2000 ] }
		)
		.toBe( false );
}

test.describe( 'Notifications and Elementor', () => {
	test.describe.configure( { timeout: 180_000 } );

	test( 'the digest reaches the FR translator with their outdated translations', async ( {
		page,
	} ) => {
		await page.request.delete( `${ MAILPIT }/messages` );
		await setDigestSettings( page, 'Translator FR', 'daily' );

		await openDashboard( page );
		const source = await findSource( page, 'fr', 'in_sync' );
		const fr = source.translations.fr?.translation_id as number;
		await rest( page, `/wp/v2/posts/${ source.id }`, {
			method: 'POST',
			data: { title: `${ source.title } (digest e2e)` },
		} );
		await runCronUntil( page, source.id, 'fr', 'outdated' );

		const sent = await rest< { sent: number } >(
			page,
			'/stalelingo-dev/v1/digest',
			{ method: 'POST' }
		);
		expect( sent.sent ).toBeGreaterThan( 0 );

		await expect
			.poll(
				async () => {
					const response = await page.request.get(
						`${ MAILPIT }/search?query=${ encodeURIComponent(
							'to:translator-fr@example.test'
						) }`
					);
					const body = ( await response.json() ) as {
						messages: MailpitSummary[];
					};

					return body.messages.length;
				},
				{ timeout: 15_000 }
			)
			.toBeGreaterThan( 0 );

		const search = ( await (
			await page.request.get(
				`${ MAILPIT }/search?query=${ encodeURIComponent(
					'to:translator-fr@example.test'
				) }`
			)
		).json() ) as { messages: MailpitSummary[] };
		const summary = search.messages[ 0 ] as MailpitSummary;
		expect( summary.Subject ).toMatch( /translations? needs? updating/ );

		const message = ( await (
			await page.request.get( `${ MAILPIT }/message/${ summary.ID }` )
		).json() ) as { Text: string };
		expect( message.Text ).toContain( `post.php?post=${ fr }&action=edit` );
		expect( message.Text ).toContain( 'Changed: Title' );
		expect( message.Text ).toContain( 'translator for FR' );

		// Nobody else is configured, so nobody else got a digest.
		const all = ( await (
			await page.request.get( `${ MAILPIT }/messages` )
		).json() ) as { messages: MailpitSummary[] };
		for ( const mail of all.messages ) {
			expect( mail.To.map( ( to ) => to.Address ) ).toEqual( [
				'translator-fr@example.test',
			] );
		}

		await setDigestSettings( page, null, 'off' );
		await openDashboard( page );
		await rest( page, '/stalelingo/v1/mark-synced', {
			method: 'POST',
			data: { ids: [ fr ] },
		} );
	} );

	test( 'an Elementor style-only change does not flag drift; a text change does', async ( {
		page,
	} ) => {
		await openDashboard( page );

		// First save through Elementor: it rewrites the page's plain-text copy, which counts as a change.
		const page1 = await rest< { source: number; fr: number } >(
			page,
			'/stalelingo-dev/v1/elementor',
			{ method: 'POST', data: { mode: 'style' } }
		);
		await runCronUntilIdle( page, page1.source );
		await rest( page, '/stalelingo/v1/mark-synced', {
			method: 'POST',
			data: { ids: [ page1.fr ] },
		} );

		// A style-only edit: the translation stays up to date.
		await rest( page, '/stalelingo-dev/v1/elementor', {
			method: 'POST',
			data: { mode: 'style' },
		} );
		await runCronUntilIdle( page, page1.source );
		const afterStyle = await rest< {
			translations: Record< string, { status: string } >;
		} >( page, `/stalelingo/v1/group/${ page1.source }` );
		expect( afterStyle.translations.fr?.status ).toBe( 'in_sync' );

		// A text edit: the translation becomes outdated.
		await rest( page, '/stalelingo-dev/v1/elementor', {
			method: 'POST',
			data: { mode: 'text' },
		} );
		await runCronUntil( page, page1.source, 'fr', 'outdated' );

		const diff = await rest< { fields: { key: string }[] } >(
			page,
			`/stalelingo/v1/diff/${ page1.fr }`
		);
		expect( diff.fields.map( ( f ) => f.key ) ).toContain( 'elementor' );

		await rest( page, '/stalelingo/v1/mark-synced', {
			method: 'POST',
			data: { ids: [ page1.fr ] },
		} );
	} );
} );
