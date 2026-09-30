/**
 * Internal dependencies
 */
import {
	filteredLanguages,
	initialView,
	langField,
	viewToQuery,
} from '../../src/dashboard/query';

describe( 'dashboard view', () => {
	it( 'starts sorted by last change with one column per language', () => {
		const view = initialView( [ 'fr', 'es' ], '' );

		expect( view.fields ).toEqual( [
			langField( 'fr' ),
			langField( 'es' ),
			'post_type',
			'modified',
		] );
		expect( view.filters ).toEqual( [] );
		expect( viewToQuery( view ) ).toEqual( {
			page: 1,
			per_page: 20,
			orderby: 'modified',
			order: 'desc',
		} );
	} );

	it( 'turns a status query argument into a filter and ignores unknown ones', () => {
		expect(
			initialView( [], '?page=stalelingo&status=outdated' ).filters
		).toEqual( [
			{ field: 'status', operator: 'isAny', value: [ 'outdated' ] },
		] );
		expect( initialView( [], '?status=stale' ).filters ).toEqual( [] );
	} );

	it( 'maps every filter, search, sort and page to REST parameters', () => {
		const query = viewToQuery( {
			type: 'table',
			page: 3,
			perPage: 50,
			search: 'hello',
			sort: { field: 'title', direction: 'asc' },
			filters: [
				{
					field: 'status',
					operator: 'isAny',
					value: [ 'outdated', 'missing' ],
				},
				{ field: 'lang', operator: 'isAny', value: [ 'fr' ] },
				{ field: 'post_type', operator: 'isAny', value: [ 'page' ] },
				{ field: 'author', operator: 'is', value: '4' },
				{ field: 'translator', operator: 'is', value: 7 },
				{ field: 'modified', operator: 'after', value: '2026-09-01' },
				{ field: 'modified', operator: 'before', value: '2026-09-30' },
				{ field: 'status', operator: 'isAny', value: [] },
			],
		} );

		expect( query ).toEqual( {
			page: 3,
			per_page: 50,
			search: 'hello',
			orderby: 'title',
			order: 'asc',
			status: [ 'outdated', 'missing' ],
			lang: [ 'fr' ],
			post_type: [ 'page' ],
			author: 4,
			translator: 7,
			changed_after: '2026-09-01T00:00:00',
			changed_before: '2026-09-30T23:59:59',
		} );
	} );

	it( 'reports the languages a view is filtered to', () => {
		expect( filteredLanguages( { type: 'table' } ) ).toBeNull();
		expect(
			filteredLanguages( {
				type: 'table',
				filters: [
					{ field: 'lang', operator: 'isAny', value: [ 'es' ] },
				],
			} )
		).toEqual( [ 'es' ] );
	} );
} );
