/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Drift status of a translation, as stored in the `tdrift_sync` table.
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
			return __( 'Up to date', 'translation-drift' );
		case 'outdated':
			return __( 'Outdated', 'translation-drift' );
		case 'missing':
			return __( 'Missing', 'translation-drift' );
		case 'untracked':
			return __( 'Not tracked yet', 'translation-drift' );
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
