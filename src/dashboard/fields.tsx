/**
 * WordPress dependencies
 */
import type { Field } from '@wordpress/dataviews/wp';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatDateTime } from '../shared/format';
import { StatusBadge } from '../shared/status-badge';
import { DRIFT_STATUSES, getStatusLabel } from '../shared/status';
import type { SourceItem, Translation } from '../shared/types';
import type { DashboardConfig } from './config';
import { langField } from './query';

/**
 * DataViews fields: the title, one column per language, post type, author and last change,
 * plus filter-only fields for status, language and translator.
 *
 * Filtering, sorting and paging happen on the server (`stalelingo/v1/status`).
 *
 * @param config     Dashboard settings.
 * @param onOpenDiff Opens the diff of a translation.
 * @return Fields.
 */
export function buildFields(
	config: DashboardConfig,
	onOpenDiff: ( item: SourceItem, translation: Translation ) => void
): Field< SourceItem >[] {
	const languageFields: Field< SourceItem >[] = config.languages.map(
		( language ) => ( {
			id: langField( language.code ),
			label: language.name,
			enableSorting: false,
			filterBy: false,
			getValue: ( { item } ) =>
				item.translations[ language.code ]?.status ?? '',
			render: ( { item } ) => {
				if ( item.source_lang === language.code ) {
					return (
						<span className="stalelingo-source-cell">
							{ __( 'Source', 'stalelingo' ) }
						</span>
					);
				}
				const translation = item.translations[ language.code ];
				if ( ! translation ) {
					return <span aria-hidden="true">—</span>;
				}
				if ( translation.status === 'outdated' ) {
					return (
						<StatusBadge
							lang={ language.code }
							status="outdated"
							action={ __( 'View changes', 'stalelingo' ) }
							onClick={ () => onOpenDiff( item, translation ) }
						/>
					);
				}

				return (
					<StatusBadge
						lang={ language.code }
						status={ translation.status }
						href={ translation.edit_url ?? translation.create_url }
					/>
				);
			},
		} )
	);

	return [
		{
			id: 'title',
			label: __( 'Title', 'stalelingo' ),
			enableHiding: false,
			enableGlobalSearch: true,
			getValue: ( { item } ) => item.title,
			render: ( { item } ) => {
				const title = item.title || __( '(no title)', 'stalelingo' );

				return item.edit_url ? (
					<a href={ item.edit_url }>{ title }</a>
				) : (
					<span>{ title }</span>
				);
			},
		},
		...languageFields,
		{
			id: 'post_type',
			label: __( 'Post type', 'stalelingo' ),
			enableSorting: false,
			elements: config.postTypes.map( ( type ) => ( {
				value: type.slug,
				label: type.label,
			} ) ),
			filterBy: { operators: [ 'isAny' ] },
			getValue: ( { item } ) => item.post_type,
			render: ( { item } ) => <>{ item.post_type_label }</>,
		},
		{
			id: 'author',
			label: __( 'Author', 'stalelingo' ),
			enableSorting: false,
			elements: config.authors.map( ( author ) => ( {
				value: String( author.id ),
				label: author.name,
			} ) ),
			filterBy: { operators: [ 'is' ] },
			getValue: ( { item } ) => String( item.author.id ),
			render: ( { item } ) => <>{ item.author.name }</>,
		},
		{
			id: 'modified',
			type: 'date',
			label: __( 'Source last changed', 'stalelingo' ),
			filterBy: { operators: [ 'after', 'before' ] },
			getValue: ( { item } ) => item.modified ?? '',
			render: ( { item } ) => (
				<time dateTime={ item.modified ?? undefined }>
					{ formatDateTime( item.modified ) }
				</time>
			),
		},
		{
			id: 'status',
			label: __( 'Status', 'stalelingo' ),
			enableHiding: false,
			enableSorting: false,
			elements: DRIFT_STATUSES.map( ( status ) => ( {
				value: status,
				label: getStatusLabel( status ),
			} ) ),
			filterBy: { operators: [ 'isAny' ], isPrimary: true },
			getValue: ( { item } ) =>
				Object.values( item.translations )
					.map( ( t ) => t.status )
					.join( ' ' ),
			render: () => null,
		},
		{
			id: 'lang',
			label: __( 'Language', 'stalelingo' ),
			enableHiding: false,
			enableSorting: false,
			elements: config.languages.map( ( language ) => ( {
				value: language.code,
				label: language.name,
			} ) ),
			filterBy: { operators: [ 'isAny' ], isPrimary: true },
			getValue: ( { item } ) =>
				Object.keys( item.translations ).join( ' ' ),
			render: () => null,
		},
		{
			id: 'translator',
			label: __( 'Translator', 'stalelingo' ),
			enableHiding: false,
			enableSorting: false,
			elements: config.translators.map( ( translator ) => ( {
				value: String( translator.id ),
				label: translator.name,
			} ) ),
			filterBy:
				config.translators.length > 0 ? { operators: [ 'is' ] } : false,
			getValue: () => '',
			render: () => null,
		},
	];
}
