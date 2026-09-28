/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getStatusLabel } from '../shared/status';
import type { SourceItem } from '../shared/types';

/**
 * Quotes a CSV cell. Cells that a spreadsheet would run as a formula get a leading
 * apostrophe, so exported titles can't inject formulas.
 *
 * @param value Cell value.
 * @return Escaped cell.
 */
export function csvCell( value: string | number | null ): string {
	let text = value === null ? '' : String( value );
	if ( /^[=+\-@\t\r]/.test( text ) ) {
		text = `'${ text }`;
	}

	return /[",\r\n]/.test( text ) ? `"${ text.replace( /"/g, '""' ) }"` : text;
}

/**
 * One CSV row per translation.
 *
 * @param items Sources with their translations.
 * @param langs Only these languages; null for all.
 * @return CSV text, CRLF line endings.
 */
export function toCsv( items: SourceItem[], langs: string[] | null ): string {
	const rows: ( string | number | null )[][] = [
		[
			__( 'Source ID', 'translation-drift' ),
			__( 'Title', 'translation-drift' ),
			__( 'Post type', 'translation-drift' ),
			__( 'Source language', 'translation-drift' ),
			__( 'Language', 'translation-drift' ),
			__( 'Status', 'translation-drift' ),
			__( 'Changed fields', 'translation-drift' ),
			__( 'Translation ID', 'translation-drift' ),
			__( 'Last marked up to date (UTC)', 'translation-drift' ),
			__( 'Source last changed (UTC)', 'translation-drift' ),
		],
	];

	for ( const item of items ) {
		for ( const translation of Object.values( item.translations ) ) {
			if ( langs && ! langs.includes( translation.lang ) ) {
				continue;
			}
			rows.push( [
				item.id,
				item.title,
				item.post_type_label,
				item.source_lang,
				translation.lang,
				getStatusLabel( translation.status ),
				translation.changed_fields.map( ( f ) => f.label ).join( '; ' ),
				translation.translation_id || null,
				translation.synced_at,
				item.modified,
			] );
		}
	}

	return rows.map( ( row ) => row.map( csvCell ).join( ',' ) ).join( '\r\n' );
}

/**
 * Makes the browser save text as a file.
 *
 * @param text     File contents.
 * @param filename File name.
 */
export function downloadCsv( text: string, filename: string ): void {
	// The byte-order mark makes spreadsheet apps read the file as UTF-8.
	const blob = new Blob( [ '﻿', text ], {
		type: 'text/csv;charset=utf-8',
	} );
	const url = URL.createObjectURL( blob );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = filename;
	document.body.appendChild( link );
	link.click();
	link.remove();
	URL.revokeObjectURL( url );
}
