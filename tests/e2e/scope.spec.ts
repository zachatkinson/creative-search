import { execSync } from 'node:child_process';
import { test, expect } from '@playwright/test';
import { axe } from './support/axe';

/**
 * Search within the current category (D-117, D-130) and a sort that keeps the strong matches (D-118). On the Decks
 * page the form in Storefront's sidebar searches the whole shop, and its panel offers "kazumi in Decks" first; that
 * row searches in Decks, and the results page says so and links to every product. On both themes the spec names,
 * the scoped results page and a price sort. Axe on each state.
 */

function wp( command: string ): string {
	return execSync( `npx wp-env run cli -- wp ${ command }`, {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'ignore' ],
	} );
}

const DECKS = '/product-category/skateboards/decks/';
const TITLES = 'ul.products li.product .woocommerce-loop-product__title';

test.afterAll( () => {
	wp( 'theme activate twentytwentyfive' );
} );

for ( const theme of [ 'storefront', 'kadence' ] ) {
	test.describe( `Search in a category, ${ theme }`, () => {
		test.beforeAll( () => {
			wp( `theme activate ${ theme }` );
		} );

		test( 'the results page of a search kept to Decks', async ( {
			page,
		} ) => {
			await page.goto( '/?s=kazumi&post_type=product&product_cat=decks' );
			await expect(
				page.locator( '.csearch-results-summary' )
			).toHaveText( /^\d+ results? for “kazumi” in Decks$/ );
			for ( const title of await page
				.locator( TITLES )
				.allTextContents() ) {
				expect( title ).toContain( 'Deck' );
			}
			await axe(
				page,
				`${ theme } scoped results`,
				'main, #main, #primary'
			);
			await page
				.locator( '.csearch-results-scope' )
				.getByRole( 'link', {
					name: 'Search all products for “kazumi”.',
				} )
				.click();
			await expect( page ).not.toHaveURL( /product_cat=/ );
			await expect(
				page.locator( '.csearch-results-summary' )
			).toHaveText( /^\d+ results for “kazumi”$/ );
			await expect(
				page.locator( TITLES ).filter( { hasText: 'T-Shirt' } ).first()
			).toBeVisible();
		} );

		test( 'a price sort lists the strong matches only', async ( {
			page,
		} ) => {
			await page.goto( '/?s=skate&post_type=product&orderby=price' );
			const titles = await page.locator( TITLES ).allTextContents();
			expect( titles.length ).toBeGreaterThan( 0 );
			for ( const title of titles ) {
				expect( title ).toMatch( /skate/i );
			}
		} );
	} );
}

test.describe( 'The form on a category page', () => {
	let added: string[] = [];

	test.beforeAll( () => {
		wp( 'theme activate storefront' );
		const before = wp( 'widget list sidebar-1 --format=ids' ).trim();
		wp( 'widget add creative_search sidebar-1 1' );
		const after = wp( 'widget list sidebar-1 --format=ids' ).trim();
		added = after
			.split( /\s+/ )
			.filter( ( id ) => id && ! before.split( /\s+/ ).includes( id ) );
	} );

	test.afterAll( () => {
		if ( added.length ) {
			wp( `widget delete ${ added.join( ' ' ) }` );
		}
	} );

	test( 'offers the category first in the panel, and searches in it', async ( {
		page,
	} ) => {
		await page.goto( DECKS );
		const form = page.locator( '.widget .csearch-form' ).first();
		await expect( form.getByRole( 'checkbox' ) ).toHaveCount( 0 );
		const input = form.getByRole( 'combobox' );
		await input.fill( 'kazumi' );
		const options = form.getByRole( 'option' );
		// The panel searches the shop: the T-shirt is there, and Decks is offered as a scope.
		await expect(
			options.filter( { hasText: 'T-Shirt' } ).first()
		).toBeVisible();
		const decks = form.locator( '.csearch-option-scope' ).first();
		await expect( decks ).toHaveAccessibleName( /kazumi in Decks/ );
		await axe(
			page,
			'the panel on Decks with its scope',
			'.widget .csearch-form'
		);

		await decks.click();
		await expect( page ).toHaveURL( /[?&]product_cat=decks/ );
		await expect( page.locator( '.csearch-results-summary' ) ).toHaveText(
			/in Decks$/
		);
	} );

	test( 'Enter searches the shop', async ( { page } ) => {
		await page.goto( DECKS );
		const form = page.locator( '.widget .csearch-form' ).first();
		await form.getByRole( 'combobox' ).fill( 'kazumi' );
		// Enter with no row chosen sends the form (Escape on a closed panel would clear the field).
		await form.getByRole( 'combobox' ).press( 'Enter' );
		await expect( page ).not.toHaveURL( /product_cat=/ );
		await expect( page.locator( '.csearch-results-summary' ) ).toHaveText(
			/^\d+ results for “kazumi”$/
		);
	} );
} );
