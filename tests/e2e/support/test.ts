import { test as base } from '@playwright/test';

export { expect } from '@playwright/test';
export type { Page } from '@playwright/test';

/**
 * Playwright's test, with every request to the shop under test marked `X-Creative-Test: 1`, so a run against a
 * real shop (WP_BASE_URL) stays out of its search log and clicks (D-139, D-141). Only the shop's own origin gets
 * the header: on a cross-origin request (a theme's web fonts) it would force a CORS preflight the font host
 * refuses, the page would fall back to another font, and the panel's height budget (D-092) would change.
 */
export const test = base.extend( {
	context: async ( { context, baseURL }, provide ) => {
		const origin = new URL( baseURL || 'http://localhost:8890' ).origin;
		await context.route(
			( url ) => url.origin === origin,
			( route ) =>
				route.continue( {
					headers: {
						...route.request().headers(),
						'x-creative-test': '1',
					},
				} )
		);
		await provide( context );
	},
} );
