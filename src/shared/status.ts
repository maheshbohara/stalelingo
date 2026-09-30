/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Drift status of a translation, as stored in the `stalelingo_sync` table.
 */
export type DriftStatus = 'in_sync' | 'outdated' | 'missing' | 'untracked';

export const DRIFT_STATUSES: readonly DriftStatus[] = [
	'in_sync',
	'outdated',
	'missing',
	'untracked',
];

/**
 * Returns the human-readable label for a status.
 *
 * Status badges always show this text, so status is never conveyed by color alone.
 *
 * @param status Drift status.
 * @return Translated label.
 */
export function getStatusLabel( status: DriftStatus ): string {
	switch ( status ) {
		case 'in_sync':
			return __( 'Up to date', 'stalelingo' );
		case 'outdated':
			return __( 'Outdated', 'stalelingo' );
		case 'missing':
			return __( 'Missing', 'stalelingo' );
		case 'untracked':
			return __( 'Not tracked yet', 'stalelingo' );
	}
}

/**
 * Returns a symbol that tells statuses apart without color (matches the PHP badges).
 *
 * @param status Drift status.
 * @return Symbol.
 */
export function getStatusSymbol( status: DriftStatus ): string {
	switch ( status ) {
		case 'in_sync':
			return '✓';
		case 'outdated':
			return '!';
		case 'missing':
			return '–';
		case 'untracked':
			return '?';
	}
}

/**
 * Type guard for values coming from the REST API.
 *
 * @param value Unknown value.
 * @return Whether the value is a known status.
 */
export function isDriftStatus( value: unknown ): value is DriftStatus {
	return (
		typeof value === 'string' &&
		( DRIFT_STATUSES as readonly string[] ).includes( value )
	);
}
