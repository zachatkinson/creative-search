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

		test( 'the whole panel stops at its cap: eight rows on a desktop, five on a phone (D-136)', async ( {
			page,
			isMobile,
		} ) => {
			await page.goto( '/search-block/' );
			await typeInto( page, 'deck' );
			const listbox = page.getByRole( 'listbox' );
			const all = listbox.locator( '.csearch-option-all' );
			await expect( all ).toHaveCount( 1 );
			const rows = listbox.locator(
				'[role="option"]:not(.csearch-option-all)'
			);
			const count = await rows.count();
			expect( count ).toBeGreaterThan( 0 );
			expect( count ).toBeLessThanOrEqual( isMobile ? 5 : 8 );
			if ( isMobile ) {
				// On a phone the first three suggestions after a suggested search are products, when there are three.
				const products = listbox.locator( '.csearch-option-product' );
				expect( await products.count() ).toBeGreaterThanOrEqual(
					Math.min( 3, count - 2 )
				);
			}
			await axe(
				page,
				`${ theme } panel at its cap`,
				'.wp-block-creative-search-search, .csearch-form'
			);
		} );

		test( 'a suggested search leads, and a scope follows the products', async ( {
			page,
			isMobile,
		} ) => {
			// The panel keeps to the screen and drops its last rows first (D-092): on a desktop as short as
			// 720 px the field sits low enough on this page that the scope would go. A phone's budget is smaller.
			const size = page.viewportSize();
			if ( ! isMobile && size && size.height < 1000 ) {
				await page.setViewportSize( {
					width: size.width,
					height: 1000,
				} );
			}
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
			// A simple product in stock: a variable one needs a choice first, so it has no way to the cart (D-106).
			const input = await typeInto( page, 'mainline griptape' );
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

			// No-results is told once, for the search sent, never for each prefix typed (1.3 review, blocker 1).
			await page.evaluate( () => {
				( window as any ).csearchHold = ( event: Event ) =>
					event.preventDefault();
				window.addEventListener(
					'submit',
					( window as any ).csearchHold,
					true
				);
			} );
			await input.fill( '' );
			const answered = page.waitForResponse( /suggest\?q=zzzqqq/ );
			await input.pressSequentially( 'zzzqqq', { delay: 30 } );
			await answered;
			const told = () =>
				page.evaluate( () =>
					( window as any ).dataLayer
						.filter(
							( e: any ) => e.event === 'csearch_no_results'
						)
						.map( ( e: any ) => e.search_term )
				);
			expect( await told() ).toEqual( [] );
			await input.press( 'Enter' );
			await expect.poll( told ).toEqual( [ 'zzzqqq' ] );
			await page.evaluate( () =>
				window.removeEventListener(
					'submit',
					( window as any ).csearchHold,
					true
				)
			);
			await input.fill( 'orbit' );
			await input.press( 'Enter' );
			await expect( page ).toHaveURL( /[?&]s=orbit/ );
		} );
	} );
}
