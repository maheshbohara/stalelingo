const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		'dashboard/index': path.resolve( __dirname, 'src/dashboard/index.tsx' ),
		'admin/index': path.resolve( __dirname, 'src/admin/index.ts' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
};
