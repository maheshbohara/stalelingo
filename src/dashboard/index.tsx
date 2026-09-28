/**
 * WordPress dependencies
 */
import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Dashboard } from './dashboard';
import '../shared/shared.scss';
import './index.scss';

const root = document.getElementById( 'tdrift-dashboard-root' );

if ( root ) {
	createRoot( root ).render( <Dashboard /> );
}
