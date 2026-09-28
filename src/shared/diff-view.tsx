/**
 * WordPress dependencies
 */
import { RawHTML } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { FieldDiff } from './types';

interface Props {
	fields: FieldDiff[];
	/** Heading level of each field name. */
	headingLevel?: 2 | 3 | 4;
}

/**
 * Field-by-field diffs. The tables come from wp_text_diff(), sanitized on the server,
 * and mark added and deleted lines with screen-reader text as well as color.
 *
 * @param props              Props.
 * @param props.fields       Changed fields.
 * @param props.headingLevel Heading level of the field names.
 * @return Diffs.
 */
export function DiffView( { fields, headingLevel = 3 }: Props ) {
	const Heading = `h${ headingLevel }` as const;

	if ( fields.length === 0 ) {
		return (
			<p className="tdrift-diff-empty">
				{ __(
					'Nothing has changed in the source since this translation was last marked up to date.',
					'translation-drift'
				) }
			</p>
		);
	}

	return (
		<div className="tdrift-diff">
			{ fields.map( ( field ) => (
				<section key={ field.key } className="tdrift-diff-field">
					<Heading className="tdrift-diff-label">
						{ field.label }
					</Heading>
					{ ! field.available && (
						<p>
							{ __(
								'No copy of the earlier version was stored, so only the fact that it changed is known.',
								'translation-drift'
							) }
						</p>
					) }
					{ field.available && field.diff === '' && (
						<p>
							{ __(
								'Only formatting changed (strict mode).',
								'translation-drift'
							) }
						</p>
					) }
					{ field.available && field.diff !== '' && (
						<RawHTML className="tdrift-diff-table">
							{ field.diff }
						</RawHTML>
					) }
					{ field.truncated && (
						<p className="description">
							{ __(
								'The stored earlier version was long and has been cut short.',
								'translation-drift'
							) }
						</p>
					) }
				</section>
			) ) }
		</div>
	);
}
