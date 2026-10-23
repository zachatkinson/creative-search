import { test, expect, Page } from '@playwright/test';
import { execSync } from 'node:child_process';
import { axe } from './support/axe';

/**
 * 1.3, the panel: suggested searches from the log (D-112), "kazumi in Decks" (D-113), the typo's
 * correction (D-114), add to cart without a focusable child in an option (D-115), and the analytics events
 * (D-116), on a block theme and a classic one.
 */

function wp( command: string ): string {
	return execSync( `npx wp-env run cli -- wp ${ command }`, {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'ignore' ],
	} );
}

async function typeInto( page: Page, words: string ) {
	const input = page.getByRole( 'combobox', { name: 'Search products' } );
	await expect( input ).toBeVisible();
	await input.click();
	await input.pressSequentially( words, { delay: 30 } );
	return input;
}

test.beforeAll( () => {
	wp(
		'eval-file wp-content/plugins/creative-search/tests/e2e/fixtures/panel.php'
	);
} );

test.afterAll( () => {
	wp( 'theme activate twentytwentyfive' );
	wp( 'option patch update csearch_settings suggest_cart no' );
	wp( 'option patch update csearch_settings suggest_queries no' );
	wp( 'csearch log purge --days=0' );
	wp( 'eval "do_action( \'csearch_flush_cache\' );"' );
} );

for ( const theme of [ 'twentytwentyfive', 'storefront' ] ) {
	test.describe( `Panel, ${ theme }`, () => {
		test.describe.configure( { mode: 'serial' } );
		test.beforeAll( () => {
			wp( `theme activate ${ theme }` );
		} );

		test( 'a suggested search leads, and a scope follows the products', async ( {
			page,
		} ) => {
			await page.goto( '/search-block/' );
			const input = await typeInto( page, 'kazumi' );
			const listbox = page.getByRole( 'listbox' );
			const query = listbox.locator( '.csearch-option-query' );
			await expect( query.first() ).toHaveAccessibleName(
				/kazumi deck/i
			);
			const scope = listbox.locator( '.csearch-option-scope' );
			await expect( scope.first() ).toHaveAccessibleName(
				/kazumi in Decks/
			);
			await axe(
				page,
				`${ theme } panel with a suggested search and a scope`,
				'.wp-block-creative-search-search, .csearch-form'
			);

			// Arrowing onto the suggested search writes it into the field, as a product's name does.
			await input.press( 'ArrowDown' );
			await expect( input ).toHaveValue( 'kazumi deck' );
			await input.press( 'Escape' );

			await input.fill( '' );
			await input.pressSequentially( 'kazumi', { delay: 30 } );
			await scope.first().click();
			await expect( page ).toHaveURL( /[?&]product_cat=decks/ );
			await expect( page ).toHaveURL( /[?&]s=kazumi/ );
		} );

		test( 'a typo says what the results are for, with no way back', async ( {
			page,
		} ) => {
			await page.goto( '/?s=kazuni&post_type=product' );
			const line = page.locator( '.csearch-results-corrected' );
			await expect( line ).toHaveText( 'Showing results for kazumi' );
			await expect( line.getByRole( 'link' ) ).toHaveCount( 0 );
			await axe( page, `${ theme } typo line`, 'main, #main, #primary' );
		} );

		test( 'Shift+Enter adds a product to the cart and the page hears of it', async ( {
			page,
		} ) => {
			await page.goto( '/search-block/' );
			await page.evaluate( () => {
				( window as any ).dataLayer = [];
				( window as any ).csearchEvents = [];
				for ( const name of [
					'search',
					'select',
					'no-results',
					'add-to-cart',
				] ) {
					document.addEventListener( `csearch:${ name }`, () =>
						( window as any ).csearchEvents.push( name )
					);
				}
			} );
			const input = await typeInto( page, 'orbit classic' );
			const product = page
				.getByRole( 'listbox' )
				.locator( '.csearch-option-product' )
				.first();
			await expect( product ).toBeVisible();
			// The affordance is for the pointer only: no focusable child in an option (APG; axe nested-interactive).
			const add = product.locator( '.csearch-cart' );
			await expect( add ).toHaveAttribute( 'aria-hidden', 'true' );
			await expect( add ).not.toHaveAttribute( 'tabindex', /.*/ );
			await input.press( 'ArrowDown' );
			await expect( product ).toHaveAttribute(
				'aria-describedby',
				/-cart-hint$/
			);
			const added = page.waitForResponse( /wc-ajax=add_to_cart/ );
			await input.press( 'Shift+Enter' );
			expect( ( await added ).ok() ).toBe( true );
			await expect( page.locator( '.csearch-live' ) ).toContainText(
				'added to your cart'
			);
			const pushed = await page.evaluate( () =>
				( window as any ).dataLayer.map( ( e: any ) => e.event )
			);
			expect( pushed ).toContain( 'add_to_cart' );
			expect(
				await page.evaluate( () => ( window as any ).csearchEvents )
			).toContain( 'add-to-cart' );
			await axe(
				page,
				`${ theme } panel after adding to the cart`,
				'.wp-block-creative-search-search, .csearch-form'
			);

			await input.fill( '' );
			await input.pressSequentially( 'zzzqqq', { delay: 30 } );
			await expect
				.poll( () =>
					page.evaluate( () =>
						( window as any ).dataLayer.map( ( e: any ) => e.event )
					)
				)
				.toContain( 'csearch_no_results' );
			await input.fill( 'orbit' );
			await input.press( 'Enter' );
			await expect( page ).toHaveURL( /[?&]s=orbit/ );
		} );
	} );
}
