import { test, expect, Page } from '@playwright/test';
import { axe } from './support/axe';

/**
 * 1.3: the variation a query names (D-104) and what matched (D-105), on the Mumber-Fur grip the global
 * setup adds (tests/e2e/fixtures/shop.php): three designs, Fire and Ice with their own photos, each with
 * its own SKU.
 */

const PAGE = '/search-block/';

async function suggest( page: Page, query: string ) {
	await page.goto( PAGE );
	const input = page.getByRole( 'combobox', { name: 'Search products' } );
	await input.click();
	await input.pressSequentially( query, { delay: 30 } );
	const listbox = page.getByRole( 'listbox', { name: 'Suggestions' } );
	await expect( listbox ).toBeVisible();
	return listbox.getByRole( 'option' ).first();
}

test( 'a design in the query shows its photo and opens with it chosen', async ( {
	page,
} ) => {
	const row = await suggest( page, 'mumber fur fire' );
	await expect( row ).toContainText( 'Mumber-Fur Grip' );
	await expect( row.locator( 'img' ) ).toHaveAttribute( 'src', /grip-fire/ );
	await expect( row.locator( '.csearch-matched' ) ).toHaveText(
		/Matches Design: Fire/
	);
	await expect( row ).toHaveAccessibleName(
		/^Mumber-Fur Grip.*Matches Design: Fire$/
	);
	await axe( page, 'a named variation', '.wp-block-creative-search-search' );
	await row.click();
	await expect( page ).toHaveURL( /attribute_design=Fire/ );
} );

test( 'a variation SKU says so and shows that design', async ( { page } ) => {
	const row = await suggest( page, 'MUM-FUR-ICE' );
	await expect( row.locator( 'img' ) ).toHaveAttribute( 'src', /grip-ice/ );
	await expect( row.locator( '.csearch-matched' ) ).toHaveText(
		/Matches SKU MUM-FUR-ICE/
	);
} );

test( 'a name match says nothing more', async ( { page } ) => {
	const row = await suggest( page, 'mumber fur grip' );
	await expect( row ).toContainText( 'Mumber-Fur Grip' );
	await expect( row.locator( '.csearch-matched' ) ).toBeHidden();
} );

test( 'the results page sends one result to the product with the design chosen', async ( {
	page,
} ) => {
	await page.goto( '/?s=mumber+fur+ice&post_type=product' );
	await expect( page ).toHaveURL(
		/\/product\/mumber-fur-grip\/\?attribute_design=Ice/
	);
} );
