/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import {
	Button,
	Flex,
	Modal,
	Notice,
	Spinner,
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { errorMessage, fetchDiff, markSynced } from './api';
import { DiffView } from './diff-view';
import { formatDateTime } from './format';
import type { Diff, MarkSyncedResult } from './types';

interface Props {
	translationId: number;
	/** Title shown while the diff loads, e.g. the source title. */
	title?: string;
	onClose: () => void;
	/** Called after the translation was marked up to date from the modal. */
	onMarked?: ( result: MarkSyncedResult ) => void;
}

/**
 * Field-by-field diff of a translation's source, in a keyboard-operable modal
 * (focus moves into it, Tab stays inside, Escape closes it).
 *
 * @param props               Props.
 * @param props.translationId Translation post ID.
 * @param props.title         Title while loading.
 * @param props.onClose       Close handler.
 * @param props.onMarked      Called after "Mark as up to date".
 * @return Modal.
 */
export function DiffModal( {
	translationId,
	title,
	onClose,
	onMarked,
}: Props ) {
	const [ diff, setDiff ] = useState< Diff | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ isMarking, setIsMarking ] = useState( false );

	const load = useCallback( () => {
		setError( null );
		fetchDiff( translationId )
			.then( setDiff )
			.catch( ( e: unknown ) =>
				setError(
					errorMessage(
						e,
						__(
							'The changes could not be loaded.',
							'translation-drift'
						)
					)
				)
			);
	}, [ translationId ] );

	useEffect( load, [ load ] );

	const mark = async () => {
		setIsMarking( true );
		try {
			const result = await markSynced( [ translationId ] );
			speak(
				__( 'Translation marked as up to date.', 'translation-drift' )
			);
			onMarked?.( result );
			onClose();
		} catch ( e ) {
			const message = errorMessage(
				e,
				__(
					'The translation could not be updated.',
					'translation-drift'
				)
			);
			setError( message );
			speak( message, 'assertive' );
			setIsMarking( false );
		}
	};

	const heading = diff
		? sprintf(
				/* translators: 1: source post title. 2: language name. */
				__( 'Changes to “%1$s” for %2$s', 'translation-drift' ),
				diff.source.title || __( '(no title)', 'translation-drift' ),
				diff.language
			)
		: ( title ?? __( 'Changes in the source', 'translation-drift' ) );

	return (
		<Modal
			title={ heading }
			onRequestClose={ onClose }
			size="large"
			className="tdrift-diff-modal"
		>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					<p>{ error }</p>
					{ ! diff && (
						<Button variant="secondary" onClick={ load }>
							{ __( 'Try again', 'translation-drift' ) }
						</Button>
					) }
				</Notice>
			) }
			{ ! diff && ! error && (
				<Flex justify="center" className="tdrift-loading">
					<Spinner />
					<span>
						{ __( 'Loading changes…', 'translation-drift' ) }
					</span>
				</Flex>
			) }
			{ diff && (
				<>
					{ diff.synced_at && (
						<p className="tdrift-diff-meta">
							{ diff.synced_by
								? sprintf(
										/* translators: 1: date and time. 2: user name. */
										__(
											'Compared with the source as it was when this translation was marked up to date on %1$s by %2$s.',
											'translation-drift'
										),
										formatDateTime( diff.synced_at ),
										diff.synced_by
									)
								: sprintf(
										/* translators: %s: date and time. */
										__(
											'Compared with the source as it was when this translation was marked up to date on %s.',
											'translation-drift'
										),
										formatDateTime( diff.synced_at )
									) }
						</p>
					) }
					<DiffView fields={ diff.fields } headingLevel={ 2 } />
					<HStack
						className="tdrift-diff-actions"
						justify="flex-start"
						wrap
					>
						{ ( diff.source.edit_url || diff.source.view_url ) && (
							<Button
								variant="secondary"
								href={
									( diff.source.edit_url ??
										diff.source.view_url ) as string
								}
							>
								{ __( 'Open source', 'translation-drift' ) }
							</Button>
						) }
						{ ( diff.translation.edit_url ||
							diff.translation.view_url ) && (
							<Button
								variant="secondary"
								href={
									( diff.translation.edit_url ??
										diff.translation.view_url ) as string
								}
							>
								{ __(
									'Open translation',
									'translation-drift'
								) }
							</Button>
						) }
						{ diff.can_mark && diff.status !== 'in_sync' && (
							<Button
								variant="primary"
								onClick={ mark }
								isBusy={ isMarking }
								disabled={ isMarking }
								accessibleWhenDisabled
							>
								{ __(
									'Mark as up to date',
									'translation-drift'
								) }
							</Button>
						) }
					</HStack>
				</>
			) }
		</Modal>
	);
}
