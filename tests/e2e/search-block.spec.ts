import { test, expect, Page } from '@playwright/test';
import { axe } from './support/axe';

/**
 * Milestone 6: the search block on a page, against the real /suggest route and the hero products the
 * global setup indexed. The APG list-autocomplete script step by step with the ARIA state after each step,
 * the live region's words, a 2 s network delay, an error, scripts blocked, and axe on every state.
 */

const PAGE = '/search-block/';

async function field( page: Page ) {
	const input = page.getByRole( 'combobox', { name: 'Search products' } );
	await expect( input ).toBeVisible();
	return input;
}

test( 'closed: the form, its label and axe', async ( { page } ) => {
	await page.goto( PAGE );
	const input = await field( page );
	await expect( input ).toHaveAttribute( 'aria-expanded', 'false' );
	await expect( input ).toHaveAttribute( 'aria-autocomplete', 'list' );
	await axe( page, 'closed', '.wp-block-creative-search-search' );
} );

test( 'the APG keyboard script', async ( { page } ) => {
	await page.goto( PAGE );
	const input = await field( page );
	await input.click();
	await input.pressSequentially( 'kazumi', { delay: 30 } );
	const listbox = page.getByRole( 'listbox', { name: 'Suggestions' } );
	await expect( listbox ).toBeVisible();
	await expect( input ).toHaveAttribute( 'aria-expanded', 'true' );
	const options = listbox.getByRole( 'option' );
	await expect( options.first() ).toHaveAccessibleName(
		/^KAZUMI Skate Deck, Dealer\s*,\s*\S*84\.99\s*,\s*In stock$/
	);
	await expect( options.last() ).toHaveAccessibleName(
		/^See all \d+ results for kazumi$/
	);
	await expect( page.locator( '.csearch-live' ) ).toHaveText(
		/^\d+ suggestions?$/
	);
	await expect( input ).not.toHaveAttribute( 'aria-activedescendant', /.+/ );
	await axe( page, 'open', '.wp-block-creative-search-search' );

	await input.press( 'ArrowDown' );
	const first = await options.first().getAttribute( 'id' );
	await expect( input ).toHaveAttribute(
		'aria-activedescendant',
		first ?? ''
	);
	await expect( options.first() ).toHaveAttribute( 'aria-selected', 'true' );
	await expect( input ).toBeFocused();

	// The chosen suggestion is written into the field (D-091).
	await expect( input ).toHaveValue( 'KAZUMI Skate Deck, Dealer' );

	// Above the first row, back to what was typed; Up again, the last row (APG).
	await input.press( 'ArrowUp' );
	await expect( input ).not.toHaveAttribute( 'aria-activedescendant', /.+/ );
	await expect( input ).toHaveValue( 'kazumi' );
	await input.press( 'ArrowUp' );
	const last = await options.last().getAttribute( 'id' );
	await expect( input ).toHaveAttribute(
		'aria-activedescendant',
		last ?? ''
	);
	await expect( input ).toHaveValue( 'kazumi' );

	// Past the last row, back to what was typed; Down again, the first.
	await input.press( 'ArrowDown' );
	await expect( input ).not.toHaveAttribute( 'aria-activedescendant', /.+/ );
	await input.press( 'ArrowDown' );
	await expect( input ).toHaveAttribute(
		'aria-activedescendant',
		first ?? ''
	);

	await input.press( 'Escape' );
	await expect( input ).toHaveAttribute( 'aria-expanded', 'false' );
	await expect( input ).toHaveValue( 'kazumi' );

	await input.press( 'Alt+ArrowDown' );
	await expect( input ).toHaveAttribute( 'aria-expanded', 'true' );

	await input.press( 'ArrowDown' );
	await input.press( 'Enter' );
	await expect( page ).toHaveURL( /\/product\/kazumi-skate-deck-dealer\/$/ );
} );

test( 'Enter with nothing highlighted searches; Tab closes; Escape twice clears', async ( {
	page,
} ) => {
	await page.goto( PAGE );
	const input = await field( page );
	await input.click();
	await input.pressSequentially( 'orbit', { delay: 30 } );
	await expect( page.getByRole( 'listbox' ) ).toBeVisible();
	await input.press( 'Tab' );
	await expect( input ).toHaveAttribute( 'aria-expanded', 'false' );
	await input.focus();
	await input.press( 'Escape' );
	await input.press( 'Escape' );
	await expect( input ).toHaveValue( '' );
	await input.pressSequentially( 'orbit', { delay: 30 } );
	await expect( page.getByRole( 'listbox' ) ).toBeVisible();
	await input.press( 'Enter' );
	await expect( page ).toHaveURL( /[?&]s=orbit/ );
	await expect( page ).toHaveURL( /post_type=product/ );
} );

test( 'no suggestions says so, and axe', async ( { page } ) => {
	await page.goto( PAGE );
	const input = await field( page );
	await input.click();
	await input.pressSequentially( 'zzzzqqq', { delay: 30 } );
	await expect( page.locator( '.csearch-live' ) ).toHaveText(
		'No suggestions'
	);
	await expect(
		page.getByText( 'No suggestions', { exact: true } ).first()
	).toBeVisible();
	await axe( page, 'no results', '.wp-block-creative-search-search' );
} );

test( 'a 2 s network delay shows Searching, then the answer', async ( {
	page,
} ) => {
	await page.route( '**/csearch/v1/suggest**', async ( route ) => {
		await new Promise( ( r ) => setTimeout( r, 2000 ) );
		await route.continue();
	} );
	await page.goto( PAGE );
	const input = await field( page );
	await input.click();
	await input.pressSequentially( 'kazumi', { delay: 30 } );
	await expect( page.getByText( 'Searching…' ) ).toBeVisible();
	await expect( page.getByRole( 'option' ).first() ).toBeVisible( {
		timeout: 5000,
	} );
	await expect( page.getByText( 'Searching…' ) ).toBeHidden();
} );

test( 'an error fails silent and the field still submits; axe', async ( {
	page,
} ) => {
	await page.route( '**/csearch/v1/suggest**', ( route ) =>
		route.fulfill( {
			status: 429,
			body: '{"code":"csearch_rate_limited"}',
		} )
	);
	await page.goto( PAGE );
	const input = await field( page );
	await input.click();
	await input.pressSequentially( 'kazumi', { delay: 30 } );
	await page.waitForTimeout( 500 );
	await expect( input ).toHaveAttribute( 'aria-expanded', 'false' );
	await axe( page, 'error', '.wp-block-creative-search-search' );
	await input.press( 'Enter' );
	await expect( page ).toHaveURL( /[?&]s=kazumi/ );
} );

test.describe( 'scripts blocked', () => {
	test.use( { javaScriptEnabled: false } );
	test( 'the form still searches', async ( { page } ) => {
		await page.goto( PAGE );
		const input = page.locator( 'input[name="s"]' ).first();
		await input.fill( 'kazumi' );
		await page
			.getByRole( 'button', { name: 'Search', exact: true } )
			.first()
			.click();
		await expect( page ).toHaveURL( /[?&]s=kazumi/ );
		await expect(
			page.locator( '.csearch-results-summary' )
		).toBeVisible();
	} );
} );
