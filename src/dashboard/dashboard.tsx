/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Dashboard shell. The DataViews matrix replaces this body in a later release phase.
 *
 * @return Dashboard element.
 */
export function Dashboard() {
	return (
		<div className="tdrift-dashboard-body">
			<Notice status="info" isDismissible={ false }>
				{ __(
					'Translation status will appear here once the baseline has been built.',
					'translation-drift'
				) }
			</Notice>
		</div>
	);
}
