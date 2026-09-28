const wpPlugin = require( '@wordpress/eslint-plugin' );
const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaultConfig,
	{
		ignores: [ 'languages/**', 'dist/**', 'coverage/**', 'test-results/**', 'playwright-report/**' ],
	},
	{
		settings: {
			'import/resolver': {
				node: { extensions: [ '.js', '.jsx', '.ts', '.tsx' ] },
			},
		},
		rules: {
			'@wordpress/i18n-text-domain': [
				'error',
				{ allowedTextDomain: 'translation-drift' },
			],
		},
	},
	...wpPlugin.configs[ 'test-playwright' ].map( ( c ) => ( {
		...c,
		files: [ 'tests/e2e/**/*.ts' ],
	} ) ),
	{
		files: [ 'tests/e2e/**/*.ts', '*.config.{js,cjs,ts}' ],
		rules: {
			'import/no-extraneous-dependencies': 'off',
			'import/no-unresolved': 'off',
		},
	},
];
