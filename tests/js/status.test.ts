/**
 * Internal dependencies
 */
import {
	DRIFT_STATUSES,
	getStatusLabel,
	isDriftStatus,
} from '../../src/shared/status';

describe( 'status helpers', () => {
	it( 'gives every status a non-empty label', () => {
		for ( const status of DRIFT_STATUSES ) {
			expect( getStatusLabel( status ) ).not.toBe( '' );
		}
	} );

	it( 'recognises only known statuses', () => {
		expect( isDriftStatus( 'outdated' ) ).toBe( true );
		expect( isDriftStatus( 'stale' ) ).toBe( false );
		expect( isDriftStatus( 1 ) ).toBe( false );
	} );
} );
