/**
 * Internal dependencies
 */
import { csvCell, toCsv } from '../../src/dashboard/csv';
import { sourceItem } from './fixtures';

describe( 'CSV export', () => {
	it( 'quotes cells with commas, quotes and line breaks', () => {
		expect( csvCell( 'plain' ) ).toBe( 'plain' );
		expect( csvCell( 'a, b' ) ).toBe( '"a, b"' );
		expect( csvCell( 'say "hi"' ) ).toBe( '"say ""hi"""' );
		expect( csvCell( 'two\nlines' ) ).toBe( '"two\nlines"' );
		expect( csvCell( null ) ).toBe( '' );
		expect( csvCell( 12 ) ).toBe( '12' );
	} );

	it( 'neutralizes spreadsheet formulas', () => {
		expect( csvCell( '=HYPERLINK("x")' ) ).toBe( '"\'=HYPERLINK(""x"")"' );
		expect( csvCell( '+1' ) ).toBe( "'+1" );
		expect( csvCell( '@cmd' ) ).toBe( "'@cmd" );
	} );

	it( 'writes one row per translation, optionally for some languages only', () => {
		const item = sourceItem( { title: 'Hello, world' } );

		const lines = toCsv( [ item ], null ).split( '\r\n' );
		expect( lines ).toHaveLength( 3 );
		expect( lines[ 0 ] ).toContain( 'Source ID,Title,Post type' );
		expect( lines[ 1 ] ).toBe(
			'10,"Hello, world",Post,en,fr,Outdated,Title,11,2026-09-01T10:00:00Z,2026-09-20T08:00:00Z'
		);
		expect( lines[ 2 ] ).toBe(
			'10,"Hello, world",Post,en,es,Missing,,,,2026-09-20T08:00:00Z'
		);

		expect( toCsv( [ item ], [ 'es' ] ).split( '\r\n' ) ).toHaveLength( 2 );
	} );
} );
