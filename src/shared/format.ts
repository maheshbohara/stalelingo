/**
 * WordPress dependencies
 */
import { dateI18n, getSettings } from '@wordpress/date';

/**
 * A UTC ISO date in the site's date and time format and timezone.
 *
 * @param iso ISO 8601 date, or null.
 * @return Formatted date, or an empty string.
 */
export function formatDateTime( iso: string | null ): string {
	if ( ! iso ) {
		return '';
	}

	return dateI18n( getSettings().formats.datetime, iso, undefined );
}
