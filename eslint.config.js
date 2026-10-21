const wordpress = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...wordpress,
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
