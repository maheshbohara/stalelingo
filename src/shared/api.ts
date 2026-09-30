/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import type {
	BaselineState,
	Diff,
	Group,
	MarkSyncedResult,
	SourceItem,
	Summary,
} from './types';

export const NAMESPACE = 'stalelingo/v1';

/**
 * Query parameters of `GET stalelingo/v1/status`.
 */
export interface StatusQuery {
	page?: number;
	per_page?: number;
	search?: string;
	lang?: string[];
	status?: string[];
	post_type?: string[];
	author?: number;
	translator?: number;
	changed_after?: string;
	changed_before?: string;
	orderby?: 'modified' | 'title';
	order?: 'asc' | 'desc';
}

export interface StatusPage {
	items: SourceItem[];
	total: number;
	totalPages: number;
}

/**
 * Drops empty values so they are not sent as query arguments.
 *
 * @param query Query.
 * @return Query without empty strings, empty lists or undefined values.
 */
function compact( query: StatusQuery ): Record< string, unknown > {
	return Object.fromEntries(
		Object.entries( query ).filter(
			( [ , value ] ) =>
				value !== undefined &&
				value !== '' &&
				! ( Array.isArray( value ) && value.length === 0 )
		)
	);
}

/**
 * One page of sources with their translation status.
 *
 * @param query Filters, sorting and paging.
 * @return Items plus the totals from the pagination headers.
 */
export async function fetchStatus( query: StatusQuery ): Promise< StatusPage > {
	const response = await apiFetch< Response, false >( {
		path: addQueryArgs( `/${ NAMESPACE }/status`, compact( query ) ),
		parse: false,
	} );
	const items = ( await response.json() ) as SourceItem[];

	return {
		items,
		total: Number( response.headers.get( 'X-WP-Total' ) ?? items.length ),
		totalPages: Number( response.headers.get( 'X-WP-TotalPages' ) ?? 1 ),
	};
}

/**
 * Every source matching the filters, a hundred at a time (for CSV export).
 *
 * @param query Filters and sorting; paging is ignored.
 * @return All items.
 */
export async function fetchAllStatus(
	query: StatusQuery
): Promise< SourceItem[] > {
	const items: SourceItem[] = [];
	let page = 1;
	let totalPages = 1;
	do {
		const result = await fetchStatus( { ...query, page, per_page: 100 } );
		items.push( ...result.items );
		totalPages = result.totalPages;
		page++;
	} while ( page <= totalPages );

	return items;
}

export function fetchSummary(): Promise< Summary > {
	return apiFetch< Summary >( { path: `/${ NAMESPACE }/status/summary` } );
}

export function fetchGroup( postId: number ): Promise< Group > {
	return apiFetch< Group >( { path: `/${ NAMESPACE }/group/${ postId }` } );
}

export function fetchDiff(
	translationId: number,
	split = true
): Promise< Diff > {
	return apiFetch< Diff >( {
		path: addQueryArgs( `/${ NAMESPACE }/diff/${ translationId }`, {
			split,
		} ),
	} );
}

/**
 * Marks translations as up to date, a hundred per request.
 *
 * @param ids Translation post IDs.
 * @return Combined result of every request.
 */
export async function markSynced( ids: number[] ): Promise< MarkSyncedResult > {
	const result: MarkSyncedResult = {
		updated: [],
		failed: [],
		translations: {},
	};
	for ( let i = 0; i < ids.length; i += 100 ) {
		const chunk = await apiFetch< MarkSyncedResult >( {
			path: `/${ NAMESPACE }/mark-synced`,
			method: 'POST',
			data: { ids: ids.slice( i, i + 100 ) },
		} );
		result.updated.push( ...chunk.updated );
		result.failed.push( ...chunk.failed );
		Object.assign( result.translations, chunk.translations );
	}

	return result;
}

export function startBaseline(
	force = false
): Promise< { queued: boolean; state: BaselineState } > {
	return apiFetch( {
		path: `/${ NAMESPACE }/baseline`,
		method: 'POST',
		data: { force },
	} );
}

/**
 * Message of an error thrown by apiFetch or anything else.
 *
 * @param error    Caught value.
 * @param fallback Message when the error has none.
 * @return Message.
 */
export function errorMessage( error: unknown, fallback: string ): string {
	if (
		error &&
		typeof error === 'object' &&
		'message' in error &&
		typeof error.message === 'string' &&
		error.message !== ''
	) {
		return error.message;
	}

	return fallback;
}
