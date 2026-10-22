const wordpress = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...wordpress,
	{
		// WordPress provides the @wordpress/* packages at runtime: the build marks them external
		// (@wordpress/dependency-extraction-webpack-plugin), so they are not installed for the browser bundle.
		files: [ 'assets/src/**/*.js' ],
		settings: {
			'import/core-modules': [
				'@wordpress/blocks',
				'@wordpress/block-editor',
				'@wordpress/components',
				'@wordpress/i18n',
				'@wordpress/interactivity',
				'@wordpress/server-side-render',
			],
		},
	},
	{
		// The block's state machine is tested on Node's own runner until a DOM test dependency is approved
		// (docs/DECISIONS.md D-060).
		files: [ 'tests/js/**/*.mjs' ],
		rules: { 'vitest/no-import-node-test': 'off' },
	},
	{
		ignores: [
			'vendor/**',
			'node_modules/**',
			'build/**',
			'tmp/**',
			'test-results/**',
			'playwright-report/**',
		],
	},
];
