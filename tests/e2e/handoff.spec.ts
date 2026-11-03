import { test, expect, Page } from '@playwright/test';
import { axe } from './support/axe';

/**
 * 1.1, the Skatehouse handoff and the Baymard fixes: arrowing writes the field (D-091), the panel fits the
 * screen with no scrollbar (D-092), the suggested part marked (D-095), the backdrop (D-097), pages and posts
 * in the panel and on the results page (D-093, D-096), and button mode's dialog (D-099), on every engine and
 * both widths.
 */

async function field( page: Page, name = 'Search products' ) {
	const input = page.getByRole( 'combobox', { name } );
	await expect( input ).toBeVisible();
	return input;
}

async function panelFits( page: Page ) {
	// The panel drops a row a frame until it fits: wait for it to settle.
	await expect
		.poll(
			() =>
				page.evaluate( () => {
					const panel = document.querySelector(
						'.csearch-panel:not([hidden])'
					);
					if ( ! panel ) {
						return false;
					}
					const box = panel.getBoundingClientRect();
					const height =
						window.visualViewport?.height || window.innerHeight;
					return (
						box.top >= 0 &&
						box.bottom <= height + 1 &&
						panel.scrollHeight <= panel.clientHeight + 1
					);
				} ),
			{ message: 'the open panel fits the screen with no scrollbar' }
		)
		.toBe( true );
}

test( 'arrowing writes the field, the suggested part is marked, and the backdrop dims the page', async ( {
	page,
} ) => {
	await page.goto( '/search-block/' );
	const input = await field( page );
	await input.click();
	await input.pressSequentially( 'kaz', { delay: 30 } );
	const options = page.getByRole( 'listbox' ).getByRole( 'option' );
	await expect( options.first() ).toBeVisible();
	await expect( page.locator( '.csearch-backdrop' ) ).toBeVisible();
	// What was typed stays plain; the rest of the suggestion is marked (D-095).
	await expect(
		options.first().locator( '.csearch-name mark' ).first()
	).toHaveText( /^umi/i );
	await input.press( 'ArrowDown' );
	await expect( input ).toHaveValue( 'KAZUMI Skate Deck, Dealer' );
	await input.press( 'ArrowUp' );
	await expect( input ).toHaveValue( 'kaz' );
	await panelFits( page );
	await axe(
		page,
		'open, with the backdrop',
		'.wp-block-creative-search-search'
	);
	await input.press( 'Escape' );
	await expect( page.locator( '.csearch-backdrop' ) ).toBeHidden();
} );

for ( const size of [
	{ width: 1280, height: 720 },
	{ width: 390, height: 664 },
] ) {
	test( `no scrollbar at ${ size.width }×${ size.height }`, async ( {
		page,
	} ) => {
		await page.setViewportSize( size );
		await page.goto( '/search-block/' );
		const input = await field( page );
		await input.click();
		await input.pressSequentially( 'deck', { delay: 30 } );
		await expect(
			page.getByRole( 'listbox' ).getByRole( 'option' ).last()
		).toHaveAccessibleName( /^See all/ );
		await panelFits( page );
	} );
}

test( 'pages show in their own group after the products, and stay off a phone (D-137)', async ( {
	page,
	isMobile,
} ) => {
	await page.goto( '/search-block/' );
	const input = await field( page );
	await input.click();
	const answered = page.waitForResponse(
		( r ) =>
			r.url().includes( 'csearch/v1/suggest' ) &&
			/[?&]q=size(\+|%20)guide(&|$)/.test( r.url() )
	);
	await input.pressSequentially( 'size guide', { delay: 30 } );
	const listbox = page.getByRole( 'listbox' );
	const option = listbox.getByRole( 'option', { name: /Size guide/ } );
	if ( isMobile ) {
		// `suggest_pages_phone` is off by default: a phone's rows go to products, and no product matches.
		const body = await ( await answered ).json();
		expect( body.content.length ).toBeGreaterThan( 0 );
		await expect( option ).toHaveCount( 0 );
		return;
	}
	await expect( option ).toBeVisible();
	await expect( option.locator( '.csearch-group' ) ).toHaveText(
		'Pages and posts'
	);
	await axe(
		page,
		'a page in the panel',
		'.wp-block-creative-search-search'
	);
	await input.press( 'ArrowDown' );
	await input.press( 'Enter' );
	await expect( page ).toHaveURL( /\/size-guide\/$/ );
} );

test( 'the results page leads with the page when no product matches, and links the help page (D-096)', async ( {
	page,
} ) => {
	await page.goto( '/?s=size+guide&post_type=product' );
	const empty = page.locator( '.csearch-no-results' );
	await expect( empty ).toContainText(
		'No products match “size guide”, but these do:'
	);
	await expect(
		empty.getByRole( 'link', { name: 'Size guide' } )
	).toBeVisible();
	await page.goto( '/?s=zzzzqqq&post_type=product' );
	await expect(
		page.locator( '.csearch-no-results' ).getByRole( 'link', {
			name: 'Can’t find what you need? Contact us',
		} )
	).toBeVisible();
	await axe( page, 'no results with links', '.csearch-no-results' );
} );

test.describe( 'button mode', () => {
	test( 'click opens the dialog; typing needs no tap; Escape closes it and focus returns', async ( {
		page,
	} ) => {
		await page.goto( '/search-button/' );
		const open = page.locator( '.csearch-open' );
		await expect( open ).toHaveAccessibleName( 'Search' );
		await expect( page.locator( '.csearch-dialog' ) ).toBeHidden();
		await open.click();
		const dialog = page.getByRole( 'dialog', { name: 'Search' } );
		await expect( dialog ).toBeVisible();
		const input = await field( page );
		await expect( input ).toBeFocused();
		await page.keyboard.type( 'kazumi', { delay: 30 } );
		await expect(
			dialog.getByRole( 'listbox' ).getByRole( 'option' ).first()
		).toBeVisible();
		await axe( page, 'the dialog, open', '.csearch-dialog' );
		// The page behind is inert (showModal): a pointer on its first link lands on the dialog's backdrop.
		const behind = await page.evaluate( () => {
			const link = document.querySelector( 'a[href]:not(dialog a)' );
			const box = link?.getBoundingClientRect();
			if ( ! link || ! box ) {
				return 'no link';
			}
			const hit = document.elementFromPoint(
				box.left + box.width / 2,
				box.top + box.height / 2
			);
			return link.contains( hit ) ? 'reached' : 'blocked';
		} );
		expect( behind ).toBe( 'blocked' );
		await page.keyboard.press( 'Escape' );
		await expect( input ).toHaveAttribute( 'aria-expanded', 'false' );
		await expect( dialog ).toBeVisible();
		await page.keyboard.press( 'Escape' );
		await expect( dialog ).toBeHidden();
		await expect( open ).toBeFocused();
		await expect( page ).toHaveURL( /\/search-button\/$/ );
	} );

	test( 'the keyboard opens it; Back closes it without leaving the page', async ( {
		page,
	} ) => {
		await page.goto( '/search-button/' );
		const open = page.locator( '.csearch-open' );
		await open.focus();
		await page.keyboard.press( 'Enter' );
		const dialog = page.getByRole( 'dialog', { name: 'Search' } );
		await expect( dialog ).toBeVisible();
		await page.goBack();
		await expect( dialog ).toBeHidden();
		await expect( page ).toHaveURL( /\/search-button\/$/ );
		await expect( open ).toBeFocused();
	} );

	test( 'Back then Forward stays on the page: the dialog’s step keeps the Interactivity API’s key', async ( {
		page,
	} ) => {
		await page.goto( '/search-button/' );
		await page.evaluate( () => {
			( window as unknown as Record< string, unknown > ).csearchMarker =
				'still here';
		} );
		await page.locator( '.csearch-open' ).click();
		const dialog = page.getByRole( 'dialog', { name: 'Search' } );
		await expect( dialog ).toBeVisible();
		expect(
			await page.evaluate( () => window.history.state?.wpInteractivityId )
		).toBeTruthy();
		await page.goBack();
		await expect( dialog ).toBeHidden();
		await expect( page ).toHaveURL( /\/search-button\/$/ );
		await page.goForward();
		await expect( page ).toHaveURL( /\/search-button\/$/ );
		// A reload would have cleared the marker: the runtime reloads on a step without its session's key.
		await page.waitForTimeout( 500 );
		expect(
			await page.evaluate(
				() =>
					( window as unknown as Record< string, unknown > )
						.csearchMarker
			)
		).toBe( 'still here' );
	} );

	test( 'the close button closes it, and a search from it lands on the results', async ( {
		page,
	} ) => {
		await page.goto( '/search-button/' );
		await page.locator( '.csearch-open' ).click();
		const dialog = page.getByRole( 'dialog', { name: 'Search' } );
		await dialog.getByRole( 'button', { name: 'Close search' } ).click();
		await expect( dialog ).toBeHidden();
		await page.locator( '.csearch-open' ).click();
		const input = await field( page );
		await input.pressSequentially( 'kazumi', { delay: 30 } );
		await expect( dialog.getByRole( 'option' ).first() ).toBeVisible();
		await input.press( 'Enter' );
		await expect( page ).toHaveURL( /[?&]s=kazumi/ );
		// The dialog's history entry was replaced, not kept: Back goes to the page before.
		await page.goBack();
		await expect( page ).toHaveURL( /\/search-button\/$/ );
		await expect( page.locator( '.csearch-dialog' ) ).toBeHidden();
	} );
} );

test.describe( 'button mode, scripts blocked', () => {
	test.use( { javaScriptEnabled: false } );
	test( 'the form shows in place and searches', async ( { page } ) => {
		await page.goto( '/search-button/' );
		await expect( page.locator( '.csearch-open' ) ).toBeHidden();
		const input = page.locator( 'input[name="s"]' ).first();
		await expect( input ).toBeVisible();
		await input.fill( 'kazumi' );
		await input.press( 'Enter' );
		await expect( page ).toHaveURL( /[?&]s=kazumi/ );
	} );
} );
