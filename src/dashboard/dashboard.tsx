/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { Button, Notice } from '@wordpress/components';
import { DataViews, type Action, type View } from '@wordpress/dataviews/wp';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	errorMessage,
	fetchAllStatus,
	fetchStatus,
	fetchSummary,
	markSynced,
	startBaseline,
} from '../shared/api';
import { DiffModal } from '../shared/diff-modal';
import type { DriftStatus } from '../shared/status';
import type {
	SourceItem,
	Summary as SummaryData,
	Translation,
} from '../shared/types';
import { getConfig, type DashboardConfig } from './config';
import { downloadCsv, toCsv } from './csv';
import { buildFields } from './fields';
import {
	filteredLanguages,
	initialView,
	PER_PAGE_SIZES,
	viewToQuery,
} from './query';
import { Summary } from './summary';

/**
 * How often the summary refreshes while a baseline build runs.
 */
const BASELINE_POLL_MS = 5000;

/**
 * Translations of an item that the "Mark as up to date" action applies to.
 *
 * @param item  Source.
 * @param langs Languages the view is filtered to, or null.
 * @return Translations the user may mark and that aren't up to date.
 */
export function markableTranslations(
	item: SourceItem,
	langs: string[] | null
): Translation[] {
	return Object.values( item.translations ).filter(
		( t ) =>
			t.can_mark &&
			t.translation_id > 0 &&
			( t.status === 'outdated' || t.status === 'untracked' ) &&
			( ! langs || langs.includes( t.lang ) )
	);
}

interface OpenDiff {
	translationId: number;
	title: string;
}

interface Props {
	/** Settings; defaults to `window.tdriftDashboard`. */
	config?: DashboardConfig;
}

/**
 * Tools → Translation Drift: summary counts and a DataViews matrix of sources × languages.
 *
 * @param props        Props.
 * @param props.config Dashboard settings.
 * @return Dashboard.
 */
export function Dashboard( { config: configProp }: Props ) {
	const config = useMemo( () => configProp ?? getConfig(), [ configProp ] );
	const [ view, setView ] = useState< View >( () =>
		initialView(
			config.languages
				.map( ( l ) => l.code )
				.filter( ( code ) => code !== config.sourceLanguage ),
			window.location.search
		)
	);
	const [ items, setItems ] = useState< SourceItem[] >( [] );
	const [ pagination, setPagination ] = useState( {
		totalItems: 0,
		totalPages: 0,
	} );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ summary, setSummary ] = useState< SummaryData | null >( null );
	const [ notice, setNotice ] = useState< {
		status: 'success' | 'error' | 'info';
		message: string;
	} | null >( null );
	const [ openDiff, setOpenDiff ] = useState< OpenDiff | null >( null );
	const [ isExporting, setIsExporting ] = useState( false );
	const [ reloadKey, setReloadKey ] = useState( 0 );
	const request = useRef( 0 );

	const query = useMemo( () => viewToQuery( view ), [ view ] );

	// Load the current page whenever the view changes; ignore responses to outdated requests.
	useEffect( () => {
		if ( ! config.ready ) {
			return;
		}
		const id = ++request.current;
		setIsLoading( true );
		setError( null );
		fetchStatus( query )
			.then( ( result ) => {
				if ( id !== request.current ) {
					return;
				}
				setItems( result.items );
				setPagination( {
					totalItems: result.total,
					totalPages: result.totalPages,
				} );
			} )
			.catch( ( e: unknown ) => {
				if ( id !== request.current ) {
					return;
				}
				const message = errorMessage(
					e,
					__(
						'Translation status could not be loaded.',
						'translation-drift'
					)
				);
				setError( message );
				speak( message, 'assertive' );
			} )
			.finally( () => {
				if ( id === request.current ) {
					setIsLoading( false );
				}
			} );
	}, [ config.ready, query, reloadKey ] );

	const loadSummary = useCallback( () => {
		if ( ! config.ready ) {
			return;
		}
		fetchSummary()
			.then( setSummary )
			.catch( () => setSummary( null ) );
	}, [ config.ready ] );

	useEffect( loadSummary, [ loadSummary, reloadKey ] );

	// Poll while a baseline build is in progress, then reload the list once it's done.
	const baselineStatus = summary?.baseline.status;
	useEffect( () => {
		if ( baselineStatus !== 'queued' && baselineStatus !== 'running' ) {
			return;
		}
		const timer = window.setInterval( () => {
			fetchSummary()
				.then( ( next ) => {
					setSummary( next );
					if ( next.baseline.status === 'done' ) {
						setReloadKey( ( k ) => k + 1 );
					}
				} )
				.catch( () => {} );
		}, BASELINE_POLL_MS );

		return () => window.clearInterval( timer );
	}, [ baselineStatus ] );

	const reload = useCallback( () => setReloadKey( ( k ) => k + 1 ), [] );

	const announce = useCallback(
		( status: 'success' | 'error' | 'info', message: string ) => {
			setNotice( { status, message } );
			speak( message, status === 'error' ? 'assertive' : 'polite' );
		},
		[]
	);

	const fields = useMemo(
		() =>
			buildFields( config, ( item, translation ) =>
				setOpenDiff( {
					translationId: translation.translation_id,
					title: item.title,
				} )
			),
		[ config ]
	);

	const langs = filteredLanguages( view );

	const actions: Action< SourceItem >[] = useMemo(
		() => [
			{
				id: 'mark-synced',
				label: __( 'Mark as up to date', 'translation-drift' ),
				isPrimary: true,
				supportsBulk: true,
				isEligible: ( item ) =>
					markableTranslations( item, langs ).length > 0,
				callback: async ( selected, { onActionPerformed } ) => {
					const ids = selected.flatMap( ( item ) =>
						markableTranslations( item, langs ).map(
							( t ) => t.translation_id
						)
					);
					try {
						const result = await markSynced( ids );
						const count = result.updated.length;
						announce(
							result.failed.length ? 'error' : 'success',
							result.failed.length
								? sprintf(
										/* translators: 1: number marked. 2: number that failed. */
										__(
											'%1$d translations marked as up to date; %2$d could not be marked.',
											'translation-drift'
										),
										count,
										result.failed.length
									)
								: sprintf(
										/* translators: %d: number of translations. */
										_n(
											'%d translation marked as up to date.',
											'%d translations marked as up to date.',
											count,
											'translation-drift'
										),
										count
									)
						);
						onActionPerformed?.( selected );
					} catch ( e ) {
						announce(
							'error',
							errorMessage(
								e,
								__(
									'The translations could not be updated.',
									'translation-drift'
								)
							)
						);
					}
					reload();
				},
			},
		],
		// eslint-disable-next-line react-hooks/exhaustive-deps -- langs is derived from view.
		[ announce, reload, langs?.join( ',' ) ]
	);

	const selectStatus = ( status: DriftStatus ) =>
		setView( {
			...view,
			page: 1,
			filters: [
				...( view.filters ?? [] ).filter(
					( f ) => f.field !== 'status'
				),
				{ field: 'status', operator: 'isAny', value: [ status ] },
			],
		} );

	const exportCsv = async () => {
		setIsExporting( true );
		try {
			const all = await fetchAllStatus( query );
			const date = new Date().toISOString().slice( 0, 10 );
			downloadCsv(
				toCsv( all, langs ),
				`translation-drift-${ date }.csv`
			);
			speak(
				sprintf(
					/* translators: %d: number of posts. */
					_n(
						'Exported %d post.',
						'Exported %d posts.',
						all.length,
						'translation-drift'
					),
					all.length
				)
			);
		} catch ( e ) {
			announce(
				'error',
				errorMessage(
					e,
					__( 'The export failed.', 'translation-drift' )
				)
			);
		}
		setIsExporting( false );
	};

	const buildBaseline = async () => {
		try {
			const result = await startBaseline();
			setSummary( ( current ) =>
				current ? { ...current, baseline: result.state } : current
			);
			announce(
				'info',
				__(
					'The baseline build is queued. It runs in the background.',
					'translation-drift'
				)
			);
		} catch ( e ) {
			announce(
				'error',
				errorMessage(
					e,
					__(
						'The baseline build could not be started.',
						'translation-drift'
					)
				)
			);
		}
	};

	if ( ! config.ready ) {
		return (
			<div className="tdrift-dashboard-body">
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Translation Drift needs Polylang or WPML with at least one language.',
						'translation-drift'
					) }
				</Notice>
			</div>
		);
	}

	const hasFilters = ( view.filters?.length ?? 0 ) > 0 || !! view.search;
	const baselineRunning =
		baselineStatus === 'queued' || baselineStatus === 'running';
	const nothingTracked =
		summary !== null &&
		Object.values( summary.totals ).every( ( n ) => ! n );

	return (
		<div className="tdrift-dashboard-body">
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			{ baselineRunning && summary && (
				<Notice status="info" isDismissible={ false }>
					{ sprintf(
						/* translators: %d: number of posts processed so far. */
						__(
							'Building the baseline in the background: %d posts checked so far.',
							'translation-drift'
						),
						summary.baseline.processed
					) }
				</Notice>
			) }
			{ nothingTracked && ! baselineRunning && (
				<Notice status="info" isDismissible={ false }>
					<p>
						{ __(
							'No translations are tracked yet. Build the baseline to start: every existing translation is marked as up to date with its source.',
							'translation-drift'
						) }
					</p>
					<Button variant="primary" onClick={ buildBaseline }>
						{ __( 'Build baseline', 'translation-drift' ) }
					</Button>
				</Notice>
			) }
			{ summary && ! nothingTracked && (
				<Summary summary={ summary } onSelectStatus={ selectStatus } />
			) }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					<p>{ error }</p>
					<Button variant="secondary" onClick={ reload }>
						{ __( 'Try again', 'translation-drift' ) }
					</Button>
				</Notice>
			) }
			<DataViews< SourceItem >
				data={ error ? [] : items }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				actions={ actions }
				getItemId={ ( item ) => String( item.id ) }
				isLoading={ isLoading }
				paginationInfo={ pagination }
				defaultLayouts={ { table: {} } }
				config={ { perPageSizes: PER_PAGE_SIZES } }
				searchLabel={ __( 'Search titles', 'translation-drift' ) }
				header={
					<Button
						variant="secondary"
						size="compact"
						onClick={ exportCsv }
						isBusy={ isExporting }
						disabled={ isExporting || pagination.totalItems === 0 }
						accessibleWhenDisabled
					>
						{ __( 'Export CSV', 'translation-drift' ) }
					</Button>
				}
				empty={
					<p>
						{ hasFilters
							? __(
									'No posts match these filters.',
									'translation-drift'
								)
							: __(
									'No tracked translations yet.',
									'translation-drift'
								) }
					</p>
				}
			/>
			{ openDiff && (
				<DiffModal
					translationId={ openDiff.translationId }
					title={ openDiff.title }
					onClose={ () => setOpenDiff( null ) }
					onMarked={ reload }
				/>
			) }
		</div>
	);
}
