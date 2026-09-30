/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { DriftPanel } from './panel';

/**
 * Registers the panel in the editor's document settings. Uses `@wordpress/editor`,
 * not the deprecated `@wordpress/edit-post` slot.
 *
 * @return Panel.
 */
export function EditorPlugin() {
	const { postId, isSaving } = useSelect( ( select ) => {
		const editor = select( editorStore );

		return {
			postId: Number( editor.getCurrentPostId() ),
			isSaving: editor.isSavingPost() && ! editor.isAutosavingPost(),
		};
	}, [] );

	// Reload after each save finishes: marking a translation or the auto-clear setting may change its status.
	const [ saves, setSaves ] = useState( 0 );
	const wasSaving = useRef( false );
	useEffect( () => {
		if ( wasSaving.current && ! isSaving ) {
			setSaves( ( n ) => n + 1 );
		}
		wasSaving.current = isSaving;
	}, [ isSaving ] );

	if ( ! postId ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="stalelingo-panel"
			title={ __( 'Stalelingo', 'stalelingo' ) }
			className="stalelingo-editor-panel"
		>
			<DriftPanel postId={ postId } refreshKey={ saves } />
		</PluginDocumentSettingPanel>
	);
}
