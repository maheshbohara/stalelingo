/**
 * WordPress dependencies
 */
import type { Filter, View } from '@wordpress/dataviews/wp';

/**
 * Internal dependencies
 */
import type { StatusQuery } from '../shared/api';
import { isDriftStatus } from '../shared/status';

export const PER_PAGE_SIZES = [ 10, 20, 50, 100 ];

/**
 * Field ID of the language column for a language code.
 *
 * @param code Language code.
 * @return Field ID.
 */
export function langField( code: string ): string {
	return `lang_${ code }`;
}

/**
 * The view the dashboard opens with. A `status` query argument (the admin bar links
 * to `&status=outdated`) becomes a status filter.
 *
 * @param languages Language codes, one column each.
 * @param search    `window.location.search`.
 * @return View.
 */
export function initialView( languages: string[], search: string ): View {
	const filters: Filter[] = [];
	const status = new URLSearchParams( search ).get( 'status' );
	if ( isDriftStatus( status ) ) {
		filters.push( {
			field: 'status',
			operator: 'isAny',
			value: [ status ],
		} );
	}

	return {
		type: 'table',
		page: 1,
		perPage: 20,
		search: '',
		sort: { field: 'modified', direction: 'desc' },
		filters,
		titleField: 'title',
		fields: [ ...languages.map( langField ), 'post_type', 'modified' ],
		layout: { density: 'balanced' },
	};
}

/**
 * Values of a filter as a list of strings.
 *
 * @param filter Filter.
 * @return Values.
 */
function values( filter: Filter ): string[] {
	const raw: unknown[] = Array.isArray( filter.value )
		? filter.value
		: [ filter.value ];

	return raw
		.filter( ( v ) => v !== undefined && v !== null && v !== '' )
		.map( String );
}

/**
 * Converts a DataViews view to `GET tdrift/v1/status` parameters.
 *
 * @param view View.
 * @return Query.
 */
export function viewToQuery( view: View ): StatusQuery {
	const query: StatusQuery = {
		page: view.page ?? 1,
		per_page: view.perPage ?? 20,
		orderby: view.sort?.field === 'title' ? 'title' : 'modified',
		order: view.sort?.direction ?? 'desc',
	};
	if ( view.search ) {
		query.search = view.search;
	}

	for ( const filter of view.filters ?? [] ) {
		const list = values( filter );
		if ( list.length === 0 ) {
			continue;
		}
		switch ( filter.field ) {
			case 'status':
				query.status = list;
				break;
			case 'lang':
				query.lang = list;
				break;
			case 'post_type':
				query.post_type = list;
				break;
			case 'author':
				query.author = Number( list[ 0 ] );
				break;
			case 'translator':
				query.translator = Number( list[ 0 ] );
				break;
			case 'modified':
				// Dates are whole days; the times make "after" and "before" inclusive.
				if ( filter.operator === 'after' ) {
					query.changed_after = `${ list[ 0 ] }T00:00:00`;
				} else if ( filter.operator === 'before' ) {
					query.changed_before = `${ list[ 0 ] }T23:59:59`;
				}
				break;
		}
	}

	return query;
}

/**
 * Languages the view is filtered to, or null for all.
 *
 * @param view View.
 * @return Language codes or null.
 */
export function filteredLanguages( view: View ): string[] | null {
	const filter = view.filters?.find( ( f ) => f.field === 'lang' );
	const list = filter ? values( filter ) : [];

	return list.length ? list : null;
}
