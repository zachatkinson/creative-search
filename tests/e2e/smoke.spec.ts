import { test, expect, Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Milestone 1 has no screen of its own, so the smoke test proves the plugin is active, raises no console
 * errors on the shop or in wp-admin, and that its row on the Plugins screen is axe-clean (the rest of that
 * screen is WordPress's and WooCommerce's to fix). The axe helper is the one the block (milestone 6) and the
 * admin tabs (milestone 7) will use on every state.
 */

const USER = process.env.WP_ADMIN_USER || 'admin';
const PASS = process.env.WP_ADMIN_PASS || 'password';

async function login( page: Page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', USER );
	await page.fill( '#user_pass', PASS );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

export async function axe( page: Page, name: string, include = 'body' ) {
	const results = await new AxeBuilder( { page } )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] )
		.include( include )
		.analyze();
	const serious = results.violations.filter(
		( v ) => v.impact === 'serious' || v.impact === 'critical'
	);
	expect(
		serious,
		`${ name }: ${ JSON.stringify(
			serious.map( ( v ) => ( {
				id: v.id,
				nodes: v.nodes.map( ( n ) => n.html ),
			} ) ),
			null,
			2
		) }`
	).toEqual( [] );
}

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
