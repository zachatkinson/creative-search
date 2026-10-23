import { execSync } from 'node:child_process';
import { test, expect } from '@playwright/test';
import { axe } from './support/axe';

/**
 * 1.3: search within the current category (D-117) and a sort that keeps the strong matches (D-118). The form in
 * Storefront's sidebar on the Decks page offers "Only in Decks", ticked; the suggestions and the results keep to
 * Decks; the results page says so and links to every product; unticked, the search covers the shop. On both
 * themes the spec names, the scoped results page and a price sort. Axe on each state.
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

	test( 'offers the category, suggests from it, and searches in it', async ( {
		page,
	} ) => {
		await page.goto( DECKS );
		const form = page.locator( '.widget .csearch-form' ).first();
		const scope = form.getByRole( 'checkbox', { name: 'Only in Decks' } );
		await expect( scope ).toBeChecked();
		await axe( page, 'the form on Decks', '.widget .csearch-form' );

		const input = form.getByRole( 'combobox' );
		await input.fill( 'kazumi' );
		const options = form.getByRole( 'option' );
		await expect( options.first() ).toBeVisible();
		await expect( options.filter( { hasText: 'T-Shirt' } ) ).toHaveCount(
			0
		);
		await axe( page, 'the panel kept to Decks', '.widget .csearch-form' );

		await scope.uncheck();
		await expect(
			options.filter( { hasText: 'T-Shirt' } ).first()
		).toBeVisible();
		await scope.check();
		await input.press( 'Enter' );
		await expect( page ).toHaveURL( /[?&]product_cat=decks/ );
		await expect( page.locator( '.csearch-results-summary' ) ).toHaveText(
			/in Decks$/
		);
		await expect(
			page
				.locator( '.widget .csearch-form' )
				.first()
				.getByRole( 'checkbox', {
					name: 'Only in Decks',
				} )
		).toBeChecked();
	} );

	test( 'unticked, the search covers the shop', async ( { page } ) => {
		await page.goto( DECKS );
		const form = page.locator( '.widget .csearch-form' ).first();
		await form.getByRole( 'checkbox', { name: 'Only in Decks' } ).uncheck();
		await form.getByRole( 'combobox' ).fill( 'kazumi' );
		// Enter with no row chosen sends the form (Escape on a closed panel would clear the field).
		await form.getByRole( 'combobox' ).press( 'Enter' );
		await expect( page ).not.toHaveURL( /product_cat=/ );
		await expect( page.locator( '.csearch-results-summary' ) ).toHaveText(
			/^\d+ results for “kazumi”$/
		);
	} );
} );
