/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	DRIFT_STATUSES,
	getStatusLabel,
	type DriftStatus,
} from '../shared/status';
import type { StatusCounts, Summary as SummaryData } from '../shared/types';

interface Props {
	summary: SummaryData;
	/** Filters the list to one status. */
	onSelectStatus: ( status: DriftStatus ) => void;
}

/**
 * Totals per status, and counts per language and post type, as accessible tables.
 *
 * @param props                Props.
 * @param props.summary        Summary counts.
 * @param props.onSelectStatus Status filter handler.
 * @return Summary.
 */
export function Summary( { summary, onSelectStatus }: Props ) {
	const count = ( counts: StatusCounts, status: DriftStatus ) =>
		counts[ status ] ?? 0;

	return (
		<section
			className="tdrift-summary"
			aria-label={ __( 'Summary', 'translation-drift' ) }
		>
			<ul className="tdrift-summary-totals">
				{ DRIFT_STATUSES.map( ( status ) => (
					<li key={ status }>
						<button
							type="button"
							className={ `tdrift-total tdrift-total-${ status.replace( '_', '-' ) }` }
							onClick={ () => onSelectStatus( status ) }
							aria-label={ sprintf(
								/* translators: 1: number of translations. 2: status, e.g. Outdated. */
								__(
									'%1$d %2$s – show only these',
									'translation-drift'
								),
								count( summary.totals, status ),
								getStatusLabel( status )
							) }
						>
							<span className="tdrift-total-count">
								{ count( summary.totals, status ) }
							</span>
							<span className="tdrift-total-label">
								{ getStatusLabel( status ) }
							</span>
						</button>
					</li>
				) ) }
			</ul>
			<div className="tdrift-summary-tables">
				<CountsTable
					caption={ __( 'By language', 'translation-drift' ) }
					header={ __( 'Language', 'translation-drift' ) }
					rows={ summary.languages
						.filter( ( l ) => Object.keys( l.counts ).length > 0 )
						.map( ( l ) => ( {
							key: l.code,
							label: l.name,
							counts: l.counts,
						} ) ) }
				/>
				<CountsTable
					caption={ __( 'By post type', 'translation-drift' ) }
					header={ __( 'Post type', 'translation-drift' ) }
					rows={ summary.post_types.map( ( t ) => ( {
						key: t.slug,
						label: t.label,
						counts: t.counts,
					} ) ) }
				/>
			</div>
		</section>
	);
}

interface TableProps {
	caption: string;
	header: string;
	rows: { key: string; label: string; counts: StatusCounts }[];
}

function CountsTable( { caption, header, rows }: TableProps ) {
	if ( rows.length === 0 ) {
		return null;
	}

	return (
		<table className="widefat striped tdrift-counts">
			<caption>{ caption }</caption>
			<thead>
				<tr>
					<th scope="col">{ header }</th>
					{ DRIFT_STATUSES.map( ( status ) => (
						<th scope="col" key={ status }>
							{ getStatusLabel( status ) }
						</th>
					) ) }
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( row ) => (
					<tr key={ row.key }>
						<th scope="row">{ row.label }</th>
						{ DRIFT_STATUSES.map( ( status ) => (
							<td key={ status }>
								{ row.counts[ status ] ?? 0 }
							</td>
						) ) }
					</tr>
				) ) }
			</tbody>
		</table>
	);
}
