/**
 * External dependencies
 */
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import { Dashboard, markableTranslations } from '../../src/dashboard/dashboard';
import {
	config,
	pagedResponse,
	sourceItem,
	summary,
	translation,
	type FetchOptions,
} from './fixtures';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

const mockFetch = apiFetch as unknown as jest.Mock;

/**
 * Routes apiFetch calls to canned responses.
 *
 * @param status      Response to the status list; a function gets the options.
 * @param extra       Handlers for other paths.
 * @param summaryData Response to the summary.
 */
function routes(
	status: ( options: FetchOptions ) => unknown,
	extra: Record< string, ( options: FetchOptions ) => unknown > = {},
	summaryData: typeof summary = summary
) {
	mockFetch.mockImplementation( async ( options: FetchOptions ) => {
		const path = options.path.split( '?' )[ 0 ] ?? '';
		if ( path === '/tdrift/v1/status' ) {
			return status( options );
		}
		if ( path === '/tdrift/v1/status/summary' ) {
			return summaryData;
		}
		const handler = extra[ path ];
		if ( handler ) {
			return handler( options );
		}

		throw new Error( `Unexpected ${ path }` );
	} );
}

function statusCalls(): string[] {
	return mockFetch.mock.calls
		.map( ( [ options ] ) => ( options as FetchOptions ).path )
		.filter(
			( path ) =>
				path.startsWith( '/tdrift/v1/status?' ) ||
				path === '/tdrift/v1/status'
		);
}

describe( 'Dashboard', () => {
	afterEach( async () => {
		// DataViews' search box updates its state after a debounce; let it land inside act().
		await act(
			() => new Promise( ( resolve ) => setTimeout( resolve, 350 ) )
		);
	} );

	beforeEach( () => {
		mockFetch.mockReset();
		( speak as jest.Mock ).mockReset();
		window.history.replaceState(
			{},
			'',
			'/wp-admin/tools.php?page=translation-drift'
		);
	} );

	it( 'shows the summary and one badge per language, without accessibility violations', async () => {
		routes( () => pagedResponse( [ sourceItem() ], 1 ) );
		const { container } = render( <Dashboard config={ config } /> );

		expect(
			await screen.findByRole( 'link', { name: 'Hello world' } )
		).toHaveAttribute( 'href', '/wp-admin/post.php?post=10&action=edit' );
		expect(
			screen.getByRole( 'button', {
				name: 'FR: Outdated – View changes',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'ES: Missing' } )
		).toHaveAttribute(
			'href',
			expect.stringContaining( 'action=tdrift_translation' )
		);
		expect(
			screen.getByRole( 'table', { name: 'By language' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', {
				name: /1 Outdated – show only these/,
			} )
		).toBeInTheDocument();

		// Axe takes a while; DataViews' debounced search state update may land meanwhile.
		let results;
		await act( async () => {
			results = await axe( container );
		} );
		expect( results ).toHaveNoViolations();
	} );

	it( 'starts filtered by the status in the URL', async () => {
		window.history.replaceState(
			{},
			'',
			'/wp-admin/tools.php?page=translation-drift&status=outdated'
		);
		routes( () => pagedResponse( [], 0 ) );

		render( <Dashboard config={ config } /> );

		await waitFor( () => expect( statusCalls() ).toHaveLength( 1 ) );
		expect( decodeURIComponent( statusCalls()[ 0 ] ?? '' ) ).toContain(
			'status[0]=outdated'
		);
		expect(
			await screen.findByText( 'No posts match these filters.' )
		).toBeInTheDocument();
	} );

	it( 'filters by status from the summary and asks the server again', async () => {
		routes( () => pagedResponse( [ sourceItem() ], 1 ) );
		render( <Dashboard config={ config } /> );
		await screen.findByRole( 'link', { name: 'Hello world' } );

		await userEvent.click(
			screen.getByRole( 'button', {
				name: /0 Not tracked yet – show only these/,
			} )
		);

		await waitFor( () => expect( statusCalls() ).toHaveLength( 2 ) );
		expect( decodeURIComponent( statusCalls()[ 1 ] ?? '' ) ).toContain(
			'status[0]=untracked'
		);
	} );

	it( 'shows an empty state when nothing is tracked, with the baseline button', async () => {
		routes(
			() => pagedResponse( [], 0 ),
			{
				'/tdrift/v1/baseline': () => ( {
					queued: true,
					state: { status: 'queued', processed: 0, updated_at: '' },
				} ),
			},
			{ ...summary, totals: {} }
		);
		render( <Dashboard config={ config } /> );

		expect(
			await screen.findByText( 'No tracked translations yet.' )
		).toBeInTheDocument();
		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Build baseline' } )
		);

		await waitFor( () =>
			expect( speak ).toHaveBeenCalledWith(
				expect.stringContaining( 'baseline build is queued' ),
				'polite'
			)
		);
		expect( mockFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/tdrift/v1/baseline',
				method: 'POST',
			} )
		);
	} );

	it( 'shows an error with a retry button when loading fails', async () => {
		let fail = true;
		routes( () => {
			if ( fail ) {
				throw { message: 'Server exploded' };
			}
			return pagedResponse( [ sourceItem() ], 1 );
		} );

		render( <Dashboard config={ config } /> );

		expect(
			await screen.findByText( 'Server exploded' )
		).toBeInTheDocument();
		expect( speak ).toHaveBeenCalledWith( 'Server exploded', 'assertive' );

		fail = false;
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Try again' } )
		);
		expect(
			await screen.findByRole( 'link', { name: 'Hello world' } )
		).toBeInTheDocument();
		expect(
			screen.queryByText( 'Server exploded' )
		).not.toBeInTheDocument();
	} );

	it( 'marks the selected sources’ outdated translations as up to date in bulk', async () => {
		const second = sourceItem( {
			id: 20,
			title: 'Second post',
			translations: { fr: translation( { translation_id: 21 } ) },
		} );
		routes( () => pagedResponse( [ sourceItem(), second ], 2 ), {
			'/tdrift/v1/mark-synced': ( options ) => ( {
				updated: options.data?.ids,
				failed: [],
				translations: {},
			} ),
		} );
		render( <Dashboard config={ config } /> );
		await screen.findByRole( 'link', { name: 'Second post' } );

		await userEvent.click(
			screen.getAllByRole( 'checkbox', {
				name: 'Select all',
			} )[ 0 ] as HTMLElement
		);
		const footer = document.querySelector< HTMLElement >(
			'.dataviews-bulk-actions-footer__action-buttons'
		);
		expect( footer ).not.toBeNull();
		await userEvent.click(
			within( footer as HTMLElement ).getByRole( 'button', {
				name: 'Mark as up to date',
			} )
		);

		await waitFor( () =>
			expect( mockFetch ).toHaveBeenCalledWith(
				expect.objectContaining( {
					path: '/tdrift/v1/mark-synced',
					method: 'POST',
					data: { ids: [ 11, 21 ] },
				} )
			)
		);
		await waitFor( () =>
			expect( speak ).toHaveBeenCalledWith(
				'2 translations marked as up to date.',
				'polite'
			)
		);
		expect(
			await screen.findByText( '2 translations marked as up to date.' )
		).toBeInTheDocument();
		await waitFor( () =>
			expect( statusCalls().length ).toBeGreaterThanOrEqual( 2 )
		);
	} );

	it( 'opens the diff modal from an outdated badge', async () => {
		routes( () => pagedResponse( [ sourceItem() ], 1 ), {
			'/tdrift/v1/diff/11': () => ( {
				...translation(),
				source_id: 10,
				synced_by: '',
				fields: [],
				source: {
					id: 10,
					title: 'Hello world',
					edit_url: null,
					view_url: null,
				},
				translation: {
					id: 11,
					title: 'Bonjour',
					edit_url: null,
					view_url: null,
				},
			} ),
		} );
		render( <Dashboard config={ config } /> );

		await userEvent.click(
			await screen.findByRole( 'button', {
				name: 'FR: Outdated – View changes',
			} )
		);

		expect(
			await screen.findByRole( 'dialog', {
				name: /Changes to “Hello world” for Français/,
			} )
		).toBeInTheDocument();
		await act( async () => {
			await userEvent.keyboard( '{Escape}' );
		} );
		await waitFor( () =>
			expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument()
		);
	} );

	it( 'warns when no multilingual plugin is ready', () => {
		render( <Dashboard config={ { ...config, ready: false } } /> );

		expect(
			screen.getByText( /needs Polylang or WPML/ )
		).toBeInTheDocument();
		expect( mockFetch ).not.toHaveBeenCalled();
	} );
} );

describe( 'markableTranslations', () => {
	it( 'keeps outdated or untracked translations the user may mark, in the filtered languages', () => {
		const item = sourceItem( {
			translations: {
				fr: translation(),
				es: translation( {
					lang: 'es',
					translation_id: 12,
					status: 'untracked',
				} ),
				de: translation( {
					lang: 'de',
					translation_id: 13,
					status: 'in_sync',
				} ),
				it: translation( {
					lang: 'it',
					translation_id: 14,
					can_mark: false,
				} ),
			},
		} );

		expect(
			markableTranslations( item, null ).map( ( t ) => t.translation_id )
		).toEqual( [ 11, 12 ] );
		expect(
			markableTranslations( item, [ 'es' ] ).map(
				( t ) => t.translation_id
			)
		).toEqual( [ 12 ] );
	} );
} );
