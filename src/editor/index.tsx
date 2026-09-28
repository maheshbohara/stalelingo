/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import { EditorPlugin } from './plugin';
import '../shared/shared.scss';
import './editor.scss';

registerPlugin( 'translation-drift', { render: EditorPlugin } );
