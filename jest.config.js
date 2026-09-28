const preset = require( '@wordpress/jest-preset-default/jest-preset' );

module.exports = {
	preset: '@wordpress/jest-preset-default',
	rootDir: __dirname,
	testMatch: [ '<rootDir>/tests/js/**/*.test.[jt]s?(x)' ],
	setupFilesAfterEnv: [ '<rootDir>/tests/js/setup.ts' ],
	// WordPress packages and some of their dependencies ship ES modules only (.mjs), so Babel compiles them for Jest.
	transform: {
		'\\.m?[jt]sx?$': preset.transform[ '\\.[jt]sx?$' ],
	},
	transformIgnorePatterns: [
		'/node_modules/(?!(@wordpress|@ariakit|uuid|clsx|colord|framer-motion|motion-dom|motion-utils|date-fns|memize|is-plain-object)/)',
	],
	moduleFileExtensions: [ 'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx', 'json' ],
	collectCoverageFrom: [ 'src/**/*.{ts,tsx}' ],
};
