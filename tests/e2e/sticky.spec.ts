import { test, expect, Page } from '@playwright/test';
import { execSync } from 'node:child_process';
import { axe } from './support/axe';

/**
 * Field mode in a sticky site header (Skatehouse): theme input rules do not reach the field (D-125), the panel
 * keeps to the visible viewport and scrolls inside itself (D-126), the page dims over the whole viewport even
 * inside a transformed header and a click on it closes the suggestions (D-127), and the colour hooks (D-128).
 * The header is the fixture's: fixed, with a transform and a z-index of its own, as Kadence's is.
 */

function wp( command: string ): string {
	return execSync( `npx wp-env run cli -- wp ${ command }`, {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'ignore' ],
	} );
}

async function openPanel( page: Page, words = 'kazumi' ) {
	const input = page.getByRole( 'combobox', { name: 'Search products' } );
	await expect( input ).toBeVisible();
	await input.click();
	await input.pressSequentially( words, { delay: 30 } );
	await expect(
		page.getByRole( 'listbox' ).getByRole( 'option' ).first()
	).toBeVisible();
	return input;
}

test.beforeAll( () => {
	wp(
		'eval-file wp-content/plugins/creative-search/tests/e2e/fixtures/sticky.php'
	);
} );

test( 'theme input rules leave one box and one focus ring', async ( {
	page,
	isMobile,
} ) => {
	await page.goto( '/sticky-header/' );
	const input = page.getByRole( 'combobox', { name: 'Search products' } );
	await input.focus();
	const own = await input.evaluate( ( el ) => {
		const c = getComputedStyle( el );
		return {
			background: c.backgroundColor,
			border: c.borderTopWidth,
			radius: c.borderTopLeftRadius,
			shadow: c.boxShadow,
			outline: c.outlineStyle,
			padding: c.paddingTop,
			maxWidth: c.maxWidth,
			fontSize: parseFloat( c.fontSize ),
		};
	} );
	expect( own ).toMatchObject( {
		background: 'rgba(0, 0, 0, 0)',
		border: '0px',
		radius: '0px',
		shadow: 'none',
		outline: 'none',
		padding: '0px',
		maxWidth: 'none',
	} );
	// The field shows focus, once.
	expect(
		await page
			.locator( '.csearch-field' )
			.first()
			.evaluate( ( el ) => getComputedStyle( el ).outlineStyle )
	).toBe( 'solid' );
	if ( isMobile ) {
		// iOS zooms into a field under 16px.
		expect( own.fontSize ).toBeGreaterThanOrEqual( 16 );
	}
} );

test( 'on a phone the panel keeps to the visible viewport and scrolls inside itself', async ( {
	page,
	isMobile,
} ) => {
	test.skip( ! isMobile, 'the touch-screen layout' );
	await page.goto( '/sticky-header/' );
	await page.evaluate( () => window.scrollTo( 0, 400 ) );
	const input = await openPanel( page );
	const panel = page.locator( '.csearch-panel' ).first();
	await expect( panel ).toHaveClass( /is-capped/ );
	const measure = () =>
		panel.evaluate( ( el ) => {
			const c = getComputedStyle( el );
			const vv = window.visualViewport!;
			return {
				max: parseFloat( c.maxHeight ),
				overflow: c.overflowY,
				overscroll: c.overscrollBehaviorY,
				bottom: el.getBoundingClientRect().bottom,
				visible: vv.offsetTop + vv.height,
			};
		} );
	const before = await measure();
	expect( before.overflow ).toBe( 'auto' );
	expect( before.overscroll ).toBe( 'contain' );
	expect( before.bottom ).toBeLessThanOrEqual( before.visible + 1 );
	// With the room it has, it drops rows rather than scroll (D-092 within the cap).
	await expect
		.poll( () =>
			panel.evaluate( ( el ) => el.scrollHeight <= el.clientHeight + 1 )
		)
		.toBe( true );

	// The keyboard opens and leaves less room than two suggestions and "See all" need: the panel shrinks to
	// its cap and scrolls inside itself.
	const size = page.viewportSize()!;
	const fieldBottom = await page
		.locator( '.csearch-field' )
		.first()
		.evaluate( ( el ) => el.getBoundingClientRect().bottom );
	await page.setViewportSize( {
		width: size.width,
		height: Math.round( fieldBottom + 110 ),
	} );
	await expect
		.poll( async () => ( await measure() ).max )
		.toBeLessThan( before.max );
	const after = await measure();
	expect( after.bottom ).toBeLessThanOrEqual( after.visible + 1 );
	expect(
		await panel.evaluate( ( el ) => el.scrollHeight > el.clientHeight )
	).toBe( true );

	// Arrowing to the last row ("See all") scrolls the panel, never the page.
	const scrolled = await page.evaluate( () => window.scrollY );
	// Up from the field wraps to the last row, however many rows the panel kept.
	await input.press( 'ArrowUp' );
	await expect( panel.locator( '.csearch-option-all' ) ).toHaveClass(
		/is-active/
	);
	expect( await page.evaluate( () => window.scrollY ) ).toBe( scrolled );
	expect( await panel.evaluate( ( el ) => el.scrollTop ) ).toBeGreaterThan(
		0
	);
	const active = page.locator( '.csearch-option.is-active' );
	// The panel settles a frame after the room changes (rows dropped to the cap, D-126): then the row shows whole.
	await expect
		.poll( () =>
			active.evaluate( ( el ) => {
				const p = el
					.closest( '.csearch-panel' )!
					.getBoundingClientRect();
				const r = el.getBoundingClientRect();
				return r.top >= p.top - 1 && r.bottom <= p.bottom + 1;
			} )
		)
		.toBe( true );
	await page.setViewportSize( size );
} );

test( 'on a desktop the list keeps its natural height when it fits', async ( {
	page,
	isMobile,
} ) => {
	test.skip( isMobile, 'the desktop layout' );
	await page.goto( '/sticky-header/' );
	await openPanel( page );
	const panel = page.locator( '.csearch-panel' ).first();
	expect(
		await panel.evaluate( ( el ) => el.scrollHeight <= el.clientHeight )
	).toBe( true );
	// A page that scrolls keeps D-092's panel: not capped at all.
	await page.goto( '/search-block/' );
	await openPanel( page );
	await expect( page.locator( '.csearch-panel' ).first() ).not.toHaveClass(
		/is-capped/
	);
} );

test( 'the page dims over the whole viewport inside the header, and a click on it closes the suggestions', async ( {
	page,
} ) => {
	await page.goto( '/sticky-header/' );
	const input = await openPanel( page );
	const layer = page.locator( '.csearch-backdrop' ).first();
	await expect( layer ).toBeVisible();
	const size = page.viewportSize()!;
	await expect
		.poll( async () => ( await layer.boundingBox() )?.height )
		.toBeGreaterThan( size.height - 2 );
	const box = ( await layer.boundingBox() )!;
	expect( Math.abs( box.x ) ).toBeLessThan( 1 );
	expect( Math.abs( box.y ) ).toBeLessThan( 1 );
	expect( box.width ).toBeGreaterThan( size.width - 20 );
	// The page below the header is behind the layer; the form is above it.
	expect(
		await page.evaluate( () =>
			document
				.elementFromPoint(
					window.innerWidth / 2,
					window.innerHeight - 30
				)
				?.classList.contains( 'csearch-backdrop' )
		)
	).toBe( true );
	await axe(
		page,
		'the panel in a sticky header',
		'.wp-block-creative-search-search'
	);
	await page.mouse.click( size.width / 2, size.height - 30 );
	await expect( input ).toHaveAttribute( 'aria-expanded', 'false' );
	await expect( layer ).toBeHidden();
	expect( page.url() ).toMatch( /\/sticky-header\/$/ );
} );

test( 'a block with the dimming switched off leaves the page as it is', async ( {
	page,
} ) => {
	await page.goto( '/sticky-header-nodim/' );
	await openPanel( page );
	await expect( page.locator( '.csearch-backdrop' ) ).toHaveCount( 0 );
} );

test( 'the colour hooks colour the field, the panel, the button and the focus ring', async ( {
	page,
} ) => {
	await page.goto( '/search-block/' );
	const read = () =>
		page.evaluate( () => {
			const of = ( selector: string ) =>
				getComputedStyle( document.querySelector( selector )! );
			return {
				fieldBg: of( '.csearch-field' ).backgroundColor,
				fieldFg: of( '.csearch-field' ).color,
				panelBg: of( '.csearch-panel' ).backgroundColor,
				panelFg: of( '.csearch-panel' ).color,
				submitBg: of( '.csearch-submit' ).backgroundColor,
				submitFg: of( '.csearch-submit' ).color,
				ring: of( '.csearch-field' ).outlineColor,
			};
		} );
	const input = page.getByRole( 'combobox', { name: 'Search products' } );
	await input.focus();
	const before = await read();
	// Today's look: the field and the panel on the block's background, the button clear.
	expect( before.panelBg ).toBe( before.fieldBg );
	expect( before.submitBg ).toBe( 'rgba(0, 0, 0, 0)' );
	await page.addStyleTag( {
		content: `:root {
			--csearch-field-bg: rgb(1, 2, 3);
			--csearch-field-fg: rgb(240, 241, 242);
			--csearch-panel-bg: rgb(4, 5, 6);
			--csearch-panel-fg: rgb(243, 244, 245);
			--csearch-submit-bg: rgb(7, 8, 9);
			--csearch-submit-fg: rgb(246, 247, 248);
			--csearch-focus-ring: rgb(200, 0, 0);
		}`,
	} );
	expect( await read() ).toEqual( {
		fieldBg: 'rgb(1, 2, 3)',
		fieldFg: 'rgb(240, 241, 242)',
		panelBg: 'rgb(4, 5, 6)',
		panelFg: 'rgb(243, 244, 245)',
		submitBg: 'rgb(7, 8, 9)',
		submitFg: 'rgb(246, 247, 248)',
		ring: 'rgb(200, 0, 0)',
	} );
} );
