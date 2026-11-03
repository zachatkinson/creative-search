import { test, expect, Page } from './support/test';
import { axe } from './support/axe';
import { login } from './support/login';

/**
 * Milestone 1 has no screen of its own, so the smoke test proves the plugin is active, raises no console
 * errors on the shop or in wp-admin, and that its row on the Plugins screen is axe-clean (the rest of that
 * screen is WordPress's and WooCommerce's to fix). The axe helper is the one the block (milestone 6) and the
 * admin tabs (milestone 7) will use on every state.
 */

// WooCommerce's own dashboard widgets load over admin-ajax after login; WebKit reports those requests as
// console errors ("due to access control checks"). They are not the plugin's and are left out of the count.
const NOT_OURS = /admin-ajax\.php\?action=woocommerce_/;

function collectConsoleErrors( page: Page ): string[] {
	const errors: string[] = [];
	page.on( 'console', ( message ) => {
		if ( message.type() === 'error' && ! NOT_OURS.test( message.text() ) ) {
			errors.push( message.text() );
		}
	} );
	page.on( 'pageerror', ( error ) => errors.push( error.message ) );
	return errors;
}

test.describe( 'Creative Search, milestone 1', () => {
	test( 'the plugin is active and the Plugins screen is axe-clean', async ( {
		page,
	} ) => {
		await login( page );
		await page.goto( '/wp-admin/plugins.php' );
		const errors = collectConsoleErrors( page );
		const row = page.locator( 'tr[data-slug="creative-search"]' );
		await expect( row ).toBeVisible();
		await expect( row ).toHaveClass( /active/ );
		await expect(
			row.getByRole( 'link', { name: 'Deactivate Creative Search' } )
		).toBeVisible();
		await axe( page, 'plugins-row', 'tr[data-slug="creative-search"]' );
		expect( errors, errors.join( '\n' ) ).toEqual( [] );
	} );

	test( 'the shop front end loads with no console errors', async ( {
		page,
	} ) => {
		const errors = collectConsoleErrors( page );
		const response = await page.goto( '/?s=deck&post_type=product' );
		expect( response?.status() ).toBe( 200 );
		await expect( page.locator( 'body' ) ).toBeVisible();
		expect( errors, errors.join( '\n' ) ).toEqual( [] );
	} );
} );
