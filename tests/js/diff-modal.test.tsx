/**
 * External dependencies
 */
import { act, render, screen, waitFor } from '@testing-library/react';
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
import { DiffModal } from '../../src/shared/diff-modal';
import { diff, type FetchOptions } from './fixtures';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

const mockFetch = apiFetch as unknown as jest.Mock;

describe( 'DiffModal', () => {
	beforeEach( () => {
		mockFetch.mockReset();
		( speak as jest.Mock ).mockReset();
	} );

	it( 'shows each changed field and the links side by side, without accessibility violations', async () => {
		mockFetch.mockResolvedValue( diff );
		render( <DiffModal translationId={ 11 } onClose={ jest.fn() } /> );

		const dialog = await screen.findByRole( 'dialog', {
			name: 'Changes to “Hello world” for Français',
		} );
		expect( mockFetch ).toHaveBeenCalledWith( {
			path: '/stalelingo/v1/diff/11?split=true',
		} );
		expect(
			screen.getByRole( 'heading', { level: 2, name: 'Title' } )
		).toBeInTheDocument();
		expect( screen.getByText( 'Deleted:' ) ).toHaveClass(
			'screen-reader-text'
		);
		expect(
			screen.getByText( /No copy of the earlier version was stored/ )
		).toBeInTheDocument();
		expect(
			screen.getByText( /marked up to date on .* by Admin/ )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'Open source' } )
		).toHaveAttribute( 'href', '/wp-admin/post.php?post=10&action=edit' );
		expect(
			screen.getByRole( 'link', { name: 'Open translation' } )
		).toHaveAttribute( 'href', '/wp-admin/post.php?post=11&action=edit' );

		expect( await axe( dialog ) ).toHaveNoViolations();
	} );

	it( 'is keyboard-operable: focus moves in, Tab stays inside, Escape closes', async () => {
		mockFetch.mockResolvedValue( diff );
		const onClose = jest.fn();
		render(
			<>
				<button type="button">Outside</button>
				<DiffModal translationId={ 11 } onClose={ onClose } />
			</>
		);
		const dialog = await screen.findByRole( 'dialog' );
		await screen.findByRole( 'button', { name: 'Mark as up to date' } );

		expect( dialog ).toContainElement(
			dialog.ownerDocument.activeElement as HTMLElement
		);
		for ( let i = 0; i < 6; i++ ) {
			await userEvent.tab();
			expect( dialog ).toContainElement(
				dialog.ownerDocument.activeElement as HTMLElement
			);
		}

		// The modal closes after its exit animation.
		await userEvent.keyboard( '{Escape}' );
		await waitFor( () => expect( onClose ).toHaveBeenCalled() );
	} );

	it( 'marks the translation as up to date and announces it', async () => {
		mockFetch.mockImplementation( async ( options: FetchOptions ) =>
			options.method === 'POST'
				? { updated: [ 11 ], failed: [], translations: {} }
				: diff
		);
		const onClose = jest.fn();
		const onMarked = jest.fn();
		render(
			<DiffModal
				translationId={ 11 }
				onClose={ onClose }
				onMarked={ onMarked }
			/>
		);

		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Mark as up to date' } )
		);

		await waitFor( () => expect( onClose ).toHaveBeenCalled() );
		expect( mockFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/stalelingo/v1/mark-synced',
				data: { ids: [ 11 ] },
			} )
		);
		expect( onMarked ).toHaveBeenCalledWith(
			expect.objectContaining( { updated: [ 11 ] } )
		);
		expect( speak ).toHaveBeenCalledWith(
			'Translation marked as up to date.'
		);
	} );

	it( 'hides the button when the translation is up to date or the user may not mark it', async () => {
		mockFetch.mockResolvedValue( { ...diff, can_mark: false } );
		render( <DiffModal translationId={ 11 } onClose={ jest.fn() } /> );

		await screen.findByRole( 'link', { name: 'Open source' } );
		expect(
			screen.queryByRole( 'button', { name: 'Mark as up to date' } )
		).not.toBeInTheDocument();
	} );

	it( 'shows a loading state, then an error with a retry', async () => {
		let reject: ( e: unknown ) => void = () => {};
		mockFetch.mockImplementationOnce(
			() => new Promise( ( _resolve, r ) => ( reject = r ) )
		);
		render(
			<DiffModal
				translationId={ 11 }
				title="Hello world"
				onClose={ jest.fn() }
			/>
		);

		expect(
			screen.getByRole( 'dialog', { name: 'Hello world' } )
		).toBeInTheDocument();
		expect( screen.getByText( 'Loading changes…' ) ).toBeInTheDocument();

		await act( async () => reject( { message: 'Not found' } ) );
		expect( screen.getByText( 'Not found' ) ).toBeInTheDocument();

		mockFetch.mockResolvedValueOnce( diff );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Try again' } )
		);
		expect(
			await screen.findByRole( 'heading', { name: 'Title' } )
		).toBeInTheDocument();
	} );
} );
