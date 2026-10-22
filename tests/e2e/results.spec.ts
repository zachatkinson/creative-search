import { execSync } from 'node:child_process';
import { test, expect } from '@playwright/test';
import { axe } from './support/axe';

/**
 * Milestone 5: the results page on `?s=` with two themes the spec names. The plugin's order on Relevance,
 * Relevance first in the sort menu, the count line, the corrected line with its "search instead" link, the
 * no-results state with categories and fallback products, a price filter narrowing the ranked set, and axe
 * on the results area.
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
			await axe( page, `${ theme } no results`, '.csearch-no-results' );
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
