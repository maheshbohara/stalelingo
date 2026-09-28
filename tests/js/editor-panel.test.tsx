/**
 * External dependencies
 */
import { render, screen, waitFor } from '@testing-library/react';
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
import { DriftPanel } from '../../src/editor/panel';
import { diff, group, translation, type FetchOptions } from './fixtures';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

const mockFetch = apiFetch as unknown as jest.Mock;

/**
 * Serves a group, its diff and mark-synced requests.
 *
 * @param data Group returned by `group/{id}`; after marking, the status becomes in_sync.
 */
function serve( data: ReturnType< typeof group > ) {
	let current = data;
	mockFetch.mockImplementation( async ( options: FetchOptions ) => {
		if ( options.path.startsWith( '/tdrift/v1/group/' ) ) {
			return current;
		}
		if ( options.path.startsWith( '/tdrift/v1/diff/' ) ) {
			return diff;
		}
		if ( options.path === '/tdrift/v1/mark-synced' ) {
			current = {
				...current,
				translation: translation( {
					status: 'in_sync',
					changed_fields: [],
				} ),
			};
			return { updated: [ 11 ], failed: [], translations: {} };
		}
		throw new Error( options.path );
	} );
}

describe( 'Editor panel', () => {
	beforeEach( () => {
		mockFetch.mockReset();
		( speak as jest.Mock ).mockReset();
	} );

	it( 'shows an outdated translation with an inline diff, without accessibility violations', async () => {
		serve( group() );
		const { container } = render( <DriftPanel postId={ 11 } /> );

		expect(
			await screen.findByRole( 'heading', { name: 'Title' } )
		).toBeInTheDocument();
		expect( mockFetch ).toHaveBeenCalledWith( {
			path: '/tdrift/v1/group/11',
		} );
		expect( mockFetch ).toHaveBeenCalledWith( {
			path: '/tdrift/v1/diff/11?split=false',
		} );
		expect( screen.getByText( 'FR: Outdated' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'Hello world' } )
		).toHaveAttribute( 'href', '/wp-admin/post.php?post=10&action=edit' );
		expect(
			screen.getByRole( 'button', { name: 'Compare side by side' } )
		).toBeInTheDocument();

		expect( await axe( container ) ).toHaveNoViolations();
	} );

	it( 'marks the translation as up to date and reloads its status', async () => {
		serve( group() );
		render( <DriftPanel postId={ 11 } /> );

		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Mark as up to date' } )
		);

		expect(
			await screen.findByText( 'FR: Up to date' )
		).toBeInTheDocument();
		expect( speak ).toHaveBeenCalledWith(
			'Translation marked as up to date.'
		);
		expect(
			screen.queryByRole( 'button', { name: 'Mark as up to date' } )
		).not.toBeInTheDocument();
	} );

	it( 'opens the side-by-side comparison in a modal', async () => {
		serve( group() );
		render( <DriftPanel postId={ 11 } /> );

		await userEvent.click(
			await screen.findByRole( 'button', {
				name: 'Compare side by side',
			} )
		);

		expect( await screen.findByRole( 'dialog' ) ).toBeInTheDocument();
		expect( mockFetch ).toHaveBeenCalledWith( {
			path: '/tdrift/v1/diff/11?split=true',
		} );
	} );

	it( 'lists the translations a source affects', async () => {
		serve(
			group( {
				role: 'source',
				post_id: 10,
				lang: 'en',
				source: null,
				translation: null,
				can_mark: false,
				translations: {
					fr: translation(),
					es: translation( {
						lang: 'es',
						language: 'Español',
						status: 'missing',
						translation_id: 0,
						edit_url: null,
						create_url: '/create-es',
					} ),
				},
			} )
		);
		const { container } = render( <DriftPanel postId={ 10 } /> );

		expect(
			await screen.findByText( /This is the source/ )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'FR: Outdated' } )
		).toHaveAttribute( 'href', '/wp-admin/post.php?post=11&action=edit' );
		expect(
			screen.getByRole( 'link', { name: 'ES: Missing' } )
		).toHaveAttribute( 'href', '/create-es' );
		expect( mockFetch ).not.toHaveBeenCalledWith(
			expect.objectContaining( {
				path: expect.stringContaining( '/diff/' ),
			} )
		);
		expect( await axe( container ) ).toHaveNoViolations();
	} );

	it( 'explains untracked translations and posts without a source', async () => {
		serve( group( { translation: null } ) );
		const { unmount } = render( <DriftPanel postId={ 11 } /> );
		expect(
			await screen.findByText( /Not tracked yet/ )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Mark as up to date' } )
		).toBeInTheDocument();
		unmount();

		serve(
			group( {
				role: 'none',
				source: null,
				translation: null,
				can_mark: false,
			} )
		);
		render( <DriftPanel postId={ 11 } /> );
		expect(
			await screen.findByText( /no source to compare with yet/ )
		).toBeInTheDocument();
	} );

	it( 'hides the button for an up-to-date translation or without permission', async () => {
		serve(
			group( {
				translation: translation( {
					status: 'in_sync',
					changed_fields: [],
				} ),
			} )
		);
		const { unmount } = render( <DriftPanel postId={ 11 } /> );
		expect(
			await screen.findByText( 'FR: Up to date' )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Mark as up to date' } )
		).not.toBeInTheDocument();
		unmount();

		serve( group( { can_mark: false } ) );
		render( <DriftPanel postId={ 11 } /> );
		await screen.findByText( 'FR: Outdated' );
		expect(
			screen.queryByRole( 'button', { name: 'Mark as up to date' } )
		).not.toBeInTheDocument();
	} );

	it( 'shows an error with a retry, and reloads when the refresh key changes', async () => {
		serve( group() );
		mockFetch.mockRejectedValueOnce( { message: 'Forbidden' } );
		const { rerender } = render( <DriftPanel postId={ 11 } /> );

		expect( await screen.findByText( 'Forbidden' ) ).toBeInTheDocument();
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Try again' } )
		);
		expect( await screen.findByText( 'FR: Outdated' ) ).toBeInTheDocument();

		const calls = mockFetch.mock.calls.length;
		rerender( <DriftPanel postId={ 11 } refreshKey={ 1 } /> );
		await waitFor( () =>
			expect( mockFetch.mock.calls.length ).toBeGreaterThan( calls )
		);
	} );
} );
