import { execSync } from 'node:child_process';
import { test, expect, Page } from '@playwright/test';
import { axe } from './support/axe';

/**
 * 1.3: the owner's rules for single searches (D-108, D-110), made on the Redirects and pins tab by keyboard,
 * then used by a shopper: a search sent straight to a page, and a product pinned first in the panel and on the
 * results page.
 */

const USER = process.env.WP_ADMIN_USER || 'admin';
const PASS = process.env.WP_ADMIN_PASS || 'password';
const TAB = '/wp-admin/admin.php?page=csearch&tab=rules';

function wp( command: string ): string {
	return execSync( `npx wp-env run cli -- wp ${ command }`, {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'ignore' ],
	} );
}

function clearRules() {
	wp(
		`eval "\\CreativeBranding\\Search\\Settings\\Settings::update( array( 'query_redirects' => array(), 'pinned_products' => array() ) ); \\CreativeBranding\\Search\\Search\\Cache::bump();"`
	);
}

async function login( page: Page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', USER );
	await page.fill( '#user_pass', PASS );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

test.describe.configure( { mode: 'serial' } );

test.beforeAll( clearRules );
test.afterAll( clearRules );

test( 'the tab is axe-clean and adds a redirect and a pin', async ( {
	page,
} ) => {
	await login( page );
	await page.goto( TAB );
	await expect(
		page.getByRole( 'heading', { name: 'Redirects', exact: true } )
	).toBeVisible();
	await axe( page, 'rules tab', '.csearch-admin' );
	await page
		.getByLabel( 'Search', { exact: true } )
		.first()
		.fill( 'size chart' );
	await page.locator( '#csearch-redirect-url' ).fill( '/search-block/' );
	await page.locator( '#csearch-pin-query' ).fill( 'kazumi' );
	await page.locator( '#csearch-pin-products' ).fill( 'MNL-PER-00016' );
	await page.locator( '#csearch-pin-products' ).press( 'Enter' );
	await expect( page.getByRole( 'status' ).first() ).toContainText(
		'Redirects and pins saved.'
	);
	await expect( page.locator( 'table.csearch-redirects' ) ).toContainText(
		'size chart'
	);
	await expect( page.locator( 'table.csearch-pins' ) ).toContainText(
		'Mainline Griptape, Perforated'
	);
	await axe( page, 'rules tab with rules', '.csearch-admin' );
} );

test( 'the redirect sends its search to the page', async ( { page } ) => {
	await page.goto( '/?s=Size+Chart&post_type=product' );
	await expect( page ).toHaveURL( /\/search-block\/$/ );
} );

test( 'the pinned product leads the panel and the results', async ( {
	page,
} ) => {
	await page.goto( '/search-block/' );
	const input = page.getByRole( 'combobox', { name: 'Search products' } );
	await input.click();
	await input.pressSequentially( 'kazumi', { delay: 30 } );
	const options = page
		.getByRole( 'listbox', { name: 'Suggestions' } )
		.getByRole( 'option' );
	await expect( options.first() ).toHaveAccessibleName(
		/^Mainline Griptape, Perforated/
	);
	await page.goto( '/?s=kazumi&post_type=product' );
	await expect( page.locator( '.wp-block-post-title' ).first() ).toHaveText(
		'Mainline Griptape, Perforated'
	);
} );

test( 'removing the rules by keyboard', async ( { page } ) => {
	await login( page );
	await page.goto( TAB );
	await page.locator( '#csearch-redirect-remove-0' ).check();
	await page.locator( '#csearch-pin-remove-0' ).check();
	await page.locator( '#csearch-pin-remove-0' ).press( 'Enter' );
	await expect( page.locator( 'table.csearch-redirects' ) ).toContainText(
		'No redirects yet.'
	);
	await expect( page.locator( 'table.csearch-pins' ) ).toContainText(
		'No pinned products yet.'
	);
} );
