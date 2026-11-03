import { test, expect, Page } from './support/test';
import { execSync } from 'node:child_process';
import { axe } from './support/axe';

/**
 * Field mode in Kadence's own sticky header (1.4): the fixture in sticky.spec.ts copies what Kadence does; this
 * runs the theme itself, its sticky main row on desktop and phone with the form in the header's HTML element,
 * and Kadence's own input styles (D-125 to D-127). Kadence prints the desktop and the mobile header both, one
 * hidden, so every locator keeps to the visible form.
 */

function wp( command: string ): string {
	return execSync( `npx wp-env run cli -- wp ${ command }`, {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'ignore' ],
	} );
}

const FIXTURE =
	'eval-file wp-content/plugins/creative-search/tests/e2e/fixtures/kadence-sticky.php';

test.describe.configure( { mode: 'serial' } );

test.beforeAll( () => {
	wp( FIXTURE );
} );

test.afterAll( () => {
	wp( `${ FIXTURE } restore` );
	wp( 'theme activate twentytwentyfive' );
} );

const form = ( page: Page ) =>
	page.locator( '.wp-block-creative-search-search:visible' ).first();

async function openPanel( page: Page ) {
	const input = form( page ).getByRole( 'combobox', {
		name: 'Search products',
	} );
	await expect( input ).toBeVisible();
	await input.click();
	await input.pressSequentially( 'kazumi', { delay: 30 } );
	await expect(
		form( page ).getByRole( 'listbox' ).getByRole( 'option' ).first()
	).toBeVisible();
	return input;
}

test( 'the form sits in the sticky header and keeps its own look', async ( {
	page,
} ) => {
	await page.goto( '/kadence-sticky/' );
	await page.evaluate( () => window.scrollTo( 0, 1500 ) );
	// Kadence pins its main row once the page scrolls: the form is still on screen at the top.
	await expect
		.poll( async () => ( await form( page ).boundingBox() )?.y ?? -1 )
		.toBeLessThan( 200 );
	const box = ( await form( page ).boundingBox() )!;
	expect( box.y ).toBeGreaterThanOrEqual( 0 );
	const input = form( page ).getByRole( 'combobox' );
	await input.focus();
	const own = await input.evaluate( ( el ) => {
		const c = getComputedStyle( el );
		return {
			background: c.backgroundColor,
			border: c.borderTopWidth,
			shadow: c.boxShadow,
			outline: c.outlineStyle,
		};
	} );
	expect( own ).toMatchObject( {
		background: 'rgba(0, 0, 0, 0)',
		border: '0px',
		shadow: 'none',
		outline: 'none',
	} );
	expect(
		await form( page )
			.locator( '.csearch-field' )
			.evaluate( ( el ) => getComputedStyle( el ).outlineStyle )
	).toBe( 'solid' );
} );

test( 'scrolled under the sticky header, the panel stays on screen and the page dims around it', async ( {
	page,
	isMobile,
} ) => {
	await page.goto( '/kadence-sticky/' );
	await page.evaluate( () => window.scrollTo( 0, 1500 ) );
	await expect
		.poll( async () => ( await form( page ).boundingBox() )?.y ?? -1 )
		.toBeLessThan( 200 );
	const input = await openPanel( page );
	const panel = form( page ).locator( '.csearch-panel' );
	const size = page.viewportSize()!;
	const placed = await panel.evaluate( ( el ) => {
		const r = el.getBoundingClientRect();
		return {
			top: r.top,
			bottom: r.bottom,
			capped: el.classList.contains( 'is-capped' ),
		};
	} );
	expect( placed.top ).toBeGreaterThanOrEqual( 0 );
	expect( placed.bottom ).toBeLessThanOrEqual( size.height + 1 );
	if ( isMobile ) {
		expect( placed.capped ).toBe( true );
	}

	const layer = form( page ).locator( '.csearch-backdrop' );
	await expect( layer ).toBeVisible();
	await expect
		.poll( async () => ( await layer.boundingBox() )?.height )
		.toBeGreaterThan( size.height - 2 );
	await axe(
		page,
		'the panel in Kadence’s sticky header',
		'.wp-block-creative-search-search'
	);
	// A click on the page behind closes the panel and stays on the page.
	const scrolled = await page.evaluate( () => window.scrollY );
	await page.mouse.click( size.width / 2, size.height - 20 );
	await expect( input ).toHaveAttribute( 'aria-expanded', 'false' );
	await expect( layer ).toBeHidden();
	expect( page.url() ).toMatch( /\/kadence-sticky\/$/ );
	expect(
		Math.abs( ( await page.evaluate( () => window.scrollY ) ) - scrolled )
	).toBeLessThan( 2 );
} );
