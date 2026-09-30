/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { Button, Notice, Spinner } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { errorMessage, fetchDiff, fetchGroup, markSynced } from '../shared/api';
import { DiffModal } from '../shared/diff-modal';
import { DiffView } from '../shared/diff-view';
import { formatDateTime } from '../shared/format';
import { getStatusLabel } from '../shared/status';
import { StatusBadge } from '../shared/status-badge';
import type { Diff, Group, Translation } from '../shared/types';

interface Props {
	postId: number;
	/** Changes after each save, so the panel reloads. */
	refreshKey?: number;
}

/**
 * Stalelingo panel body.
 *
 * On a translation: its status, what changed in the source (inline diff), and
 * "Mark as up to date". On a source: the translations its changes affect.
 *
 * @param props            Props.
 * @param props.postId     Post being edited.
 * @param props.refreshKey Reload trigger.
 * @return Panel body.
 */
export function DriftPanel( { postId, refreshKey = 0 }: Props ) {
	const [ group, setGroup ] = useState< Group | null >( null );
	const [ diff, setDiff ] = useState< Diff | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ isMarking, setIsMarking ] = useState( false );
	const [ isModalOpen, setIsModalOpen ] = useState( false );
	const [ reloads, setReloads ] = useState( 0 );

	const reload = useCallback( () => setReloads( ( n ) => n + 1 ), [] );

	useEffect( () => {
		let cancelled = false;
		setError( null );
		setDiff( null );
		fetchGroup( postId )
			.then( async ( next ) => {
				if ( cancelled ) {
					return;
				}
				setGroup( next );
				if ( next.translation?.status === 'outdated' ) {
					const nextDiff = await fetchDiff( postId, false );
					if ( ! cancelled ) {
						setDiff( nextDiff );
					}
				}
			} )
			.catch( ( e: unknown ) => {
				if ( ! cancelled ) {
					setError(
						errorMessage(
							e,
							__(
								'Translation status could not be loaded.',
								'stalelingo'
							)
						)
					);
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ postId, refreshKey, reloads ] );

	const mark = async () => {
		setIsMarking( true );
		try {
			await markSynced( [ postId ] );
			speak( __( 'Translation marked as up to date.', 'stalelingo' ) );
			reload();
		} catch ( e ) {
			const message = errorMessage(
				e,
				__( 'The translation could not be updated.', 'stalelingo' )
			);
			setError( message );
			speak( message, 'assertive' );
		}
		setIsMarking( false );
	};

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				<p>{ error }</p>
				<Button variant="secondary" size="compact" onClick={ reload }>
					{ __( 'Try again', 'stalelingo' ) }
				</Button>
			</Notice>
		);
	}

	if ( ! group ) {
		return (
			<div className="stalelingo-loading">
				<Spinner />
				<span className="screen-reader-text">
					{ __( 'Loading translation status…', 'stalelingo' ) }
				</span>
			</div>
		);
	}

	if ( group.role === 'source' ) {
		return <SourceStatus group={ group } />;
	}

	if ( group.role === 'none' ) {
		return (
			<p>
				{ group.tracked
					? __(
							'This post has no source to compare with yet. Link it to its translations first.',
							'stalelingo'
						)
					: __( 'This post type is not tracked.', 'stalelingo' ) }
			</p>
		);
	}

	const translation = group.translation;
	const canMark =
		group.can_mark && ( ! translation || translation.status !== 'in_sync' );

	return (
		<div className="stalelingo-editor-translation">
			{ translation ? (
				<p className="stalelingo-editor-status">
					<StatusBadge
						lang={ translation.lang }
						status={ translation.status }
					/>{ ' ' }
					{ getStatusLabel( translation.status ) }
				</p>
			) : (
				<p>
					{ __(
						'Not tracked yet. It will be once the baseline reaches it, or when you mark it as up to date.',
						'stalelingo'
					) }
				</p>
			) }
			{ group.source && (
				<p>
					{ sprintf(
						/* translators: %s: language name. */
						__( 'Source (%s):', 'stalelingo' ),
						group.source.language
					) }{ ' ' }
					{ group.source.edit_url ? (
						<a href={ group.source.edit_url }>
							{ group.source.title ||
								__( '(no title)', 'stalelingo' ) }
						</a>
					) : (
						group.source.title
					) }
				</p>
			) }
			{ translation?.status === 'outdated' && (
				<>
					<p>
						{ __(
							'Changed in the source since this translation was last marked up to date:',
							'stalelingo'
						) }
					</p>
					{ diff ? (
						<DiffView fields={ diff.fields } headingLevel={ 3 } />
					) : (
						<ul className="stalelingo-editor-fields">
							{ translation.changed_fields.map( ( field ) => (
								<li key={ field.key }>{ field.label }</li>
							) ) }
						</ul>
					) }
					{ diff && diff.fields.length > 0 && (
						<Button
							variant="link"
							onClick={ () => setIsModalOpen( true ) }
						>
							{ __( 'Compare side by side', 'stalelingo' ) }
						</Button>
					) }
				</>
			) }
			{ translation?.synced_at && (
				<p className="description">
					{ sprintf(
						/* translators: %s: date and time. */
						__( 'Last marked up to date: %s', 'stalelingo' ),
						formatDateTime( translation.synced_at )
					) }
				</p>
			) }
			{ canMark && (
				<Button
					variant="secondary"
					onClick={ mark }
					isBusy={ isMarking }
					disabled={ isMarking }
					accessibleWhenDisabled
				>
					{ __( 'Mark as up to date', 'stalelingo' ) }
				</Button>
			) }
			{ isModalOpen && (
				<DiffModal
					translationId={ postId }
					onClose={ () => setIsModalOpen( false ) }
					onMarked={ reload }
				/>
			) }
		</div>
	);
}

/**
 * The translations of a source and their status.
 *
 * @param props       Props.
 * @param props.group Group of the source.
 * @return Source status.
 */
function SourceStatus( { group }: { group: Group } ) {
	const translations: Translation[] = Object.values( group.translations );

	return (
		<div className="stalelingo-editor-source">
			<p>
				{ __(
					'This is the source. Changing its tracked fields marks these translations as outdated:',
					'stalelingo'
				) }
			</p>
			{ translations.length === 0 ? (
				<p>
					{ __(
						'Translation status will appear once the baseline reaches this post.',
						'stalelingo'
					) }
				</p>
			) : (
				<ul className="stalelingo-editor-translations">
					{ translations.map( ( t ) => (
						<li key={ t.lang }>
							<StatusBadge
								lang={ t.lang }
								status={ t.status }
								href={ t.edit_url ?? t.create_url }
							/>{ ' ' }
							{ t.language }
						</li>
					) ) }
				</ul>
			) }
			<p className="description">
				{ __(
					'Translations are checked again in the background shortly after you save.',
					'stalelingo'
				) }
			</p>
		</div>
	);
}
