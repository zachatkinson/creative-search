import { execSync } from 'node:child_process';
import { test, expect } from './support/test';
import { axe } from './support/axe';

/**
 * Milestone 5: the results page on `?s=` with two themes the spec names. The plugin's order on Relevance,
 * Relevance first in the sort menu, the count line, the corrected line with its "search instead" link, the
 * no-results state with categories and fallback products, a price filter narrowing the ranked set, and axe
 * on the results area. Since 1.3: one result message when nothing is found (D-122), and the result count says
 * "Sorted by relevance" on a search in Relevance order (D-123).
 */

function wp( command: string ): string {
	return execSync( `npx wp-env run cli -- wp ${ command }`, {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'ignore' ],
	} );
}

test.afterAll( () => {
	wp( 'theme activate twentytwentyfive' );
} );

for ( const theme of [ 'storefront', 'kadence' ] ) {
	test.describe( `Results page, ${ theme }`, () => {
		test.beforeAll( () => {
			wp( `theme activate ${ theme }` );
		} );

		test( 'relevance order, sort menu, count line, axe', async ( {
			page,
		} ) => {
			await page.goto( '/?s=kazumi&post_type=product' );
			await expect(
				page.locator( '.csearch-results-summary' )
			).toHaveText( /\d+ results for “kazumi”/ );
			const titles = page.locator(
				'ul.products li.product .woocommerce-loop-product__title'
			);
			await expect( titles.first() ).toHaveText(
				'KAZUMI Skate Deck, Dealer'
			);
			const sort = page.locator( 'select.orderby' ).first();
			await expect( sort.locator( 'option' ).first() ).toHaveText(
				'Relevance'
			);
			await expect( sort ).toHaveValue( 'relevance' );
			// The result count reads the order the products are in (D-123).
			await expect(
				page.locator( '.woocommerce-result-count' ).first()
			).toContainText( 'Sorted by relevance' );
			await axe( page, `${ theme } results`, 'main, #main, #primary' );
		} );

		test( 'another sort keeps the set; a price filter narrows it', async ( {
			page,
		} ) => {
			await page.goto( '/?s=kazumi&post_type=product&orderby=price' );
			await expect(
				page
					.locator(
						'ul.products li.product .woocommerce-loop-product__title'
					)
					.first()
			).toHaveText( 'KAZUMI T-Shirt, Shop Logo' );
			await expect(
				page.locator( '.woocommerce-result-count' ).first()
			).toContainText( 'Sorted by price: low to high' );
			// One product left: WooCommerce sends the shopper straight to it.
			await page.goto( '/?s=orbit&post_type=product&max_price=45' );
			await expect( page ).toHaveURL(
				/\/product\/orbit-wheels-classic\/$/
			);
		} );

		test( 'the corrected line and its exact search', async ( { page } ) => {
			await page.goto( '/?s=kazumi+wheels&post_type=product' );
			const corrected = page.locator( '.csearch-results-corrected' );
			await expect( corrected ).toContainText(
				'Showing results for kazumi; search instead for kazumi wheels'
			);
			await corrected
				.getByRole( 'link', { name: 'kazumi wheels' } )
				.click();
			await expect( page.locator( '.csearch-no-results' ) ).toBeVisible();
		} );

		test( 'no results: categories and fallback products', async ( {
			page,
		} ) => {
			await page.goto( '/?s=zzzzqqq&post_type=product' );
			const empty = page.locator( '.csearch-no-results' );
			await expect( empty ).toContainText( '0 results for “zzzzqqq”' );
			await expect( empty ).toContainText(
				'Try one of these categories:'
			);
			await expect(
				empty.locator( '.csearch-no-results-categories-list a' )
			).not.toHaveCount( 0 );
			await expect( empty ).toContainText(
				'Mainline Griptape, Perforated'
			);
			// One result message: WooCommerce's notice is left out (D-122).
			await expect(
				page.getByText(
					'No products were found matching your selection.'
				)
			).toHaveCount( 0 );
			await expect( page.locator( '.woocommerce-info' ) ).toHaveCount(
				0
			);
			await expect( empty.locator( '[role="status"]' ) ).toHaveCount( 1 );
			await axe( page, `${ theme } no results`, '.csearch-no-results' );
		} );

		test( "a category page keeps WooCommerce's sort line and notice", async ( {
			page,
		} ) => {
			wp( 'option update woocommerce_default_catalog_orderby date' );
			try {
				await page.goto( '/product-category/decks/' );
				const count = page
					.locator( '.woocommerce-result-count' )
					.first();
				await expect( count ).toContainText( 'Sorted by latest' );
				await expect( count ).not.toContainText( 'relevance' );
				await page.goto( '/?post_type=product&min_price=99999' );
				await expect(
					page.getByText(
						'No products were found matching your selection.'
					)
				).toHaveCount( 1 );
			} finally {
				wp(
					'option update woocommerce_default_catalog_orderby menu_order'
				);
			}
		} );
	} );
}

test.describe( 'Results page, twentytwentyfive (a block theme)', () => {
	test.beforeAll( () => {
		wp( 'theme activate twentytwentyfive' );
	} );

	test( 'the count line once, the plugin order, and the no-results state', async ( {
		page,
	} ) => {
		await page.goto( '/?s=kazumi&post_type=product' );
		await expect( page.locator( '.csearch-results-summary' ) ).toHaveCount(
			1
		);
		await expect( page.locator( '.csearch-results-summary' ) ).toHaveText(
			/\d+ results for “kazumi”/
		);
		await expect(
			page.locator( '.wp-block-post-title' ).first()
		).toHaveText( 'KAZUMI Skate Deck, Dealer' );
		await page.goto( '/?s=zzzzqqq&post_type=product' );
		await expect( page.locator( '.csearch-results-summary' ) ).toHaveCount(
			1
		);
		await expect( page.locator( '.csearch-no-results' ) ).toContainText(
			'Try one of these categories:'
		);
		await axe( page, 'twentytwentyfive no results', '.csearch-no-results' );
	} );
} );
