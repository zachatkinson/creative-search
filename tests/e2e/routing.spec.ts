import { test, expect } from './support/test';
import { execSync } from 'node:child_process';
import { axe } from './support/axe';

/**
 * 1.2, Skatehouse handoff items 9 and 10: a query that names a category opens it (D-102), and a size in a
 * query becomes WooCommerce's own Width filter (D-101), with the line that says so and the way back to every
 * product, on a block theme and a classic one.
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

for ( const theme of [ 'twentytwentyfive', 'storefront' ] ) {
	test.describe( `Routing, ${ theme }`, () => {
		test.describe.configure( { mode: 'serial' } );
		test.beforeAll( () => {
			wp( `theme activate ${ theme }` );
		} );

		test( 'a category name opens the category', async ( { page } ) => {
			await page.goto( '/?s=wheels&post_type=product' );
			await expect( page ).toHaveURL(
				/product-category\/skateboards\/wheels\/\?csearch_from=wheels&csearch_sig=[a-z0-9]{12}$/
			);
			const line = page.locator( '.csearch-results-routed' );
			await expect( line ).toHaveText(
				'Showing Wheels. Search all products for “wheels”.'
			);
			await expect( line ).toHaveCount( 1 );
			await axe(
				page,
				`${ theme } category line`,
				'main, #main, #primary'
			);

			await line.getByRole( 'link' ).click();
			await expect( page ).toHaveURL( /csearch_all=1/ );
			await expect(
				page.locator( '.csearch-results-summary' )
			).toHaveText( /\d+ results? for “wheels”/ );
		} );

		test( 'a size becomes the Width filter', async ( { page } ) => {
			await page.goto( '/?s=philly+8.75&post_type=product' );
			await expect( page ).toHaveURL( /[?&]s=philly&/ );
			await expect( page ).toHaveURL( /filter_width=8-75/ );
			await expect(
				page.locator( '.csearch-results-routed' )
			).toContainText( 'Showing products with Width 8.75.' );
			const titles = page.locator(
				'ul.products li.product .woocommerce-loop-product__title, .wp-block-post-title'
			);
			await expect( titles ).toHaveText( [
				'Philly’s Skate Deck, Horror Party',
			] );
			// Removing the filter, as WooCommerce's chip does, widens the results to every Philly's deck.
			const unfiltered = new URL( page.url() );
			unfiltered.searchParams.delete( 'filter_width' );
			await page.goto( unfiltered.toString() );
			await expect.poll( () => titles.count() ).toBeGreaterThan( 1 );
			await axe(
				page,
				`${ theme } filter line`,
				'main, #main, #primary'
			);
		} );

		test( 'a category and a size together', async ( { page } ) => {
			await page.goto( '/?s=8.75+decks&post_type=product' );
			await expect( page ).toHaveURL(
				/product-category\/skateboards\/decks\/\?filter_width=8-75&csearch_from=8.75%20decks&csearch_sig=[a-z0-9]{12}$/
			);
			await expect(
				page.locator( '.csearch-results-routed' )
			).toContainText( 'Showing Decks with Width 8.75.' );
		} );
	} );
}
