import '@testing-library/jest-dom';
import { toHaveNoViolations } from 'jest-axe';

expect.extend( toHaveNoViolations );

// jsdom can't parse some modern CSS that DataViews injects when it loads; that says nothing about the code under test.
// eslint-disable-next-line no-console -- Wraps console.error to drop one known jsdom message.
const originalError = console.error;
// eslint-disable-next-line no-console -- See above.
console.error = ( ...args: unknown[] ) => {
	// The error comes from jsdom's realm, so `instanceof Error` is false.
	const message = String(
		( args[ 0 ] as { message?: unknown } )?.message ?? ''
	);
	if ( message.includes( 'Could not parse CSS stylesheet' ) ) {
		return;
	}
	originalError( ...args );
};
