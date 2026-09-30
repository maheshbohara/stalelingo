/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getStatusLabel, getStatusSymbol, type DriftStatus } from './status';

interface Props {
	/** Language code. */
	lang: string;
	status: DriftStatus;
	/** Link to the translation (edit or create). */
	href?: string | null;
	/** Makes the badge a button, e.g. to open the diff. Takes precedence over href. */
	onClick?: () => void;
	/** Extra words for screen readers and the tooltip, e.g. "View changes". */
	action?: string;
}

/**
 * A language badge: code, symbol and full status as screen-reader text, so status never relies on color.
 *
 * @param props         Props.
 * @param props.lang    Language code.
 * @param props.status  Status.
 * @param props.href    Link.
 * @param props.onClick Click handler.
 * @param props.action  Extra accessible text.
 * @return Badge.
 */
export function StatusBadge( { lang, status, href, onClick, action }: Props ) {
	const code = lang.toUpperCase();
	let full: string = sprintf(
		/* translators: 1: language code, e.g. FR. 2: translation status, e.g. Outdated. */
		__( '%1$s: %2$s', 'stalelingo' ),
		code,
		getStatusLabel( status )
	);
	if ( action ) {
		full = sprintf(
			/* translators: 1: language and status, e.g. "FR: Outdated". 2: action, e.g. "View changes". */
			__( '%1$s – %2$s', 'stalelingo' ),
			full,
			action
		);
	}

	const className = `stalelingo-badge stalelingo-badge-${ status.replace( '_', '-' ) }`;
	const inner = (
		<>
			<span aria-hidden="true">
				{ code }{ ' ' }
				<span className="stalelingo-badge-symbol">
					{ getStatusSymbol( status ) }
				</span>
			</span>
			<span className="screen-reader-text">{ full }</span>
		</>
	);

	if ( onClick ) {
		return (
			<button
				type="button"
				className={ className }
				title={ full }
				onClick={ onClick }
			>
				{ inner }
			</button>
		);
	}
	if ( href ) {
		return (
			<a className={ className } href={ href } title={ full }>
				{ inner }
			</a>
		);
	}

	return (
		<span className={ className } title={ full }>
			{ inner }
		</span>
	);
}
