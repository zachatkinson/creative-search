import { execSync } from 'node:child_process';
import { test, expect, Page } from './support/test';
import { axe } from './support/axe';
import { login } from './support/login';

/**
 * Milestone 7: WooCommerce → Search. Axe on every tab and on the product editor's Search panel, and the
 * owner's main tasks done by keyboard alone (focus reached with Tab, controls used with the keyboard): add
 * and delete a synonym, run a test search, choose an Analytics period, open the product's Search panel.
 */

const SCREEN = '/wp-admin/admin.php?page=csearch';
const REGION = '.csearch-admin';

function wp( command: string ): string {
	return execSync( `npx wp-env run cli -- wp ${ command }`, {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'ignore' ],
	} );
}

/**
 * Press Tab until the focus is on an element, as a keyboard user would. WebKit, like Safari by default, skips
 * links on Tab; Option+Tab is how a Safari keyboard user reaches every control, so that is what it presses.
 *
 * @param page     The page.
 * @param selector Where the focus should land.
 * @param max      Tab presses at most.
 */
async function tabTo( page: Page, selector: string, max = 250 ) {
	const key =
		page.context().browser()?.browserType().name() === 'webkit'
			? 'Alt+Tab'
			: 'Tab';
	for ( let i = 0; i < max; i++ ) {
		await page.keyboard.press( key );
		const there = await page.evaluate(
			( s ) =>
				document.querySelector( s ) ===
				document.querySelector( ':focus' ),
			selector
		);
		if ( there ) {
			return;
		}
	}
	throw new Error( `Tab never reached ${ selector }` );
}

test.describe( 'WooCommerce → Search', () => {
	test.beforeAll( () => {
		wp(
			'eval "global \\$wpdb; \\$wpdb->query( \\"DELETE FROM {\\$wpdb->prefix}csearch_synonyms\\" );"'
		);
	} );

	test.beforeEach( async ( { page } ) => {
		await login( page );
	} );

	for ( const [ tab, heading ] of [
		[ 'analytics', 'Summary' ],
		[ 'synonyms', 'Add a synonym' ],
		[ 'boosts', 'Boost rules' ],
		[ 'rules', 'Redirects' ],
		[ 'tools', 'Index' ],
	] ) {
		test( `the ${ tab } tab is axe-clean`, async ( { page } ) => {
			await page.goto( `${ SCREEN }&tab=${ tab }` );
			await expect(
				page.getByRole( 'heading', { name: heading, exact: true } )
			).toBeVisible();
			await expect(
				page.locator( '.nav-tab[aria-current="page"]' )
			).toHaveCount( 1 );
			await axe( page, `${ tab } tab`, REGION );
		} );
	}

	test( 'add and delete a synonym by keyboard', async ( { page } ) => {
		await page.goto( `${ SCREEN }&tab=synonyms` );
		await tabTo( page, '#csearch-syn-word' );
		await page.keyboard.type( 'board' );
		await page.keyboard.press( 'Tab' );
		await page.keyboard.type( 'deck' );
		await page.keyboard.press( 'Enter' );
		await expect( page.getByRole( 'status' ).first() ).toContainText(
			'Synonym added: “board” means “deck”.'
		);
		await expect( page.locator( 'table.synonyms' ) ).toContainText(
			'board'
		);
		await axe( page, 'synonyms with a row and a notice', REGION );

		await tabTo( page, '#csearch-try-word' );
		await page.keyboard.type( 'board' );
		await page.keyboard.press( 'Enter' );
		await expect( page.locator( '.csearch-try-answer' ) ).toContainText(
			'A search for “board” also finds: deck.'
		);

		// Back at the top of the tab (the test box left the focus below the table).
		await page.goto( `${ SCREEN }&tab=synonyms` );
		await tabTo( page, 'a[aria-label="Delete “board”"]' );
		await page.keyboard.press( 'Enter' );
		await expect( page.getByRole( 'status' ).first() ).toContainText(
			'1 synonym deleted.'
		);
	} );

	test( 'a boost rule and a fallback product, by keyboard', async ( {
		page,
	} ) => {
		// The results spec relies on the shop's own fallback products: keep the settings and put them back.
		const saved = wp( 'option get csearch_settings --format=json' ).trim();
		const id = wp(
			'post list --post_type=product --name=kazumi-skate-deck-dealer --field=ID'
		).trim();
		const sku = wp(
			`eval "echo wc_get_product( ${ id } )->get_sku();"`
		).trim();
		try {
			await page.goto( `${ SCREEN }&tab=boosts` );
			await tabTo( page, '#csearch-new-ref' );
			// Type-ahead picks the first term starting with the letters, on every engine.
			await page.keyboard.type( 'Decks' );
			await tabTo( page, '#csearch-new-boost', 3 );
			await page.keyboard.press( 'ControlOrMeta+A' );
			await page.keyboard.type( '4' );
			await tabTo( page, '#csearch-fallback-add' );
			await page.keyboard.type( sku );
			await page.keyboard.press( 'Enter' );
			await expect( page.getByRole( 'status' ).first() ).toContainText(
				'Boosts and exclusions saved.'
			);
			await expect(
				page.locator( '.csearch-rules tbody' )
			).toContainText( 'Category: Decks' );
			await expect( page.locator( '.csearch-fallback' ) ).toContainText(
				'KAZUMI Skate Deck, Dealer'
			);
			await axe( page, 'boosts with a rule and a fallback', REGION );
		} finally {
			execSync(
				'npx wp-env run cli -- wp option update csearch_settings --format=json',
				{ input: saved, stdio: [ 'pipe', 'ignore', 'ignore' ] }
			);
		}
	} );

	test( 'a test search explains its ranking, by keyboard', async ( {
		page,
	} ) => {
		await page.goto( `${ SCREEN }&tab=tools` );
		await tabTo( page, '#csearch-test-query' );
		await page.keyboard.type( 'kazumi dealer' );
		await page.keyboard.press( 'Enter' );
		const explain = page.locator( 'table.csearch-explain' );
		await expect( explain.locator( 'tbody tr' ).first() ).toContainText(
			'KAZUMI Skate Deck, Dealer'
		);
		await axe( page, 'tools with a test search', REGION );
	} );

	test( 'an Analytics period is chosen by keyboard', async ( { page } ) => {
		await page.goto( SCREEN );
		await tabTo( page, '#csearch-days' );
		// Type-ahead on a closed select picks an option the same way on every engine and platform.
		await page.keyboard.type( 'Last 9' );
		await tabTo( page, '#csearch-show-period', 3 );
		await page.keyboard.press( 'Enter' );
		await expect( page ).toHaveURL( /days=90/ );
		await expect( page.locator( '#csearch-days' ) ).toHaveValue( '90' );
	} );

	test( 'the product editor’s Search panel, by keyboard', async ( {
		page,
	} ) => {
		const id = wp(
			'post list --post_type=product --name=kazumi-skate-deck-dealer --field=ID'
		).trim();
		await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
		await tabTo( page, '.csearch_options a' );
		await page.keyboard.press( 'Enter' );
		await expect( page.locator( '#csearch_boost' ) ).toBeVisible();
		await expect( page.locator( '.csearch-last-indexed' ) ).toContainText(
			/Last indexed .* ago\./
		);
		await axe( page, 'product Search panel', '#csearch_product_data' );
	} );
} );
