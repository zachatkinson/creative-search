/**
 * Vitest for the block's state machine (tests/js). The Interactivity API is WordPress's at runtime; in tests
 * it is the double in tests/js/support.
 */
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig( {
	resolve: {
		alias: {
			'@wordpress/interactivity': fileURLToPath(
				new URL( './tests/js/support/interactivity.mjs', import.meta.url )
			),
		},
	},
	test: {
		include: [ 'tests/js/**/*.test.mjs' ],
		environment: 'node',
	},
} );
