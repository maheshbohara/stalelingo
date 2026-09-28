/**
 * External dependencies
 */
import { render, within } from '@testing-library/react';
import { axe } from 'jest-axe';

/**
 * Internal dependencies
 */
import { Dashboard } from '../../src/dashboard/dashboard';

describe( 'Dashboard shell', () => {
	it( 'renders the baseline notice without accessibility violations', async () => {
		const { container } = render( <Dashboard /> );

		expect(
			within( container ).getByText( /baseline has been built/i )
		).toBeInTheDocument();
		expect( await axe( container ) ).toHaveNoViolations();
	} );
} );
