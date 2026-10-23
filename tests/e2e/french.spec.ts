import { test, expect } from '@playwright/test';
import { axe } from './support/axe';

/**
 * Milestone 8: the shop in Canadian French. The global setup turned on the bilingual fixture
 * (tests/e2e/fixtures/french.php), and the `csearch_lang=fr` cookie gives this visitor the site in fr_CA
 * with prices as `84,99 $`. The block, the results page and the admin screen speak French, accents fold,
 * French stems match, and a word only the English documents hold falls back to them. Axe on each state.
 */

const USER = process.env.WP_ADMIN_USER || 'admin';
const PASS = process.env.WP_ADMIN_PASS || 'password';

// French typography puts a no-break space inside guillemets and before a colon.
const S = '[\\s\\u00a0\\u202f]';

test.beforeEach( async ( { context, baseURL } ) => {
	await context.addCookies( [
		{
			name: 'csearch_lang',
			value: 'fr',
			url: baseURL ?? 'http://localhost:8890',
		},
	] );
} );

test( 'the block suggests French products in French words', async ( {
	page,
} ) => {
	await page.goto( '/search-block/' );
	await expect( page.locator( 'html' ) ).toHaveAttribute( 'lang', 'fr-CA' );
	const input = page.getByRole( 'combobox', {
		name: 'Rechercher des produits',
	} );
	await input.click();
	await input.pressSequentially( 'planc', { delay: 30 } );
	const listbox = page.getByRole( 'listbox', { name: 'Suggestions' } );
	await expect( listbox ).toBeVisible();
	const options = listbox.getByRole( 'option' );
	await expect( options.first() ).toHaveAccessibleName(
		new RegExp(
			`^Planche KAZUMI, Dealer${ S }*,${ S }*84,99${ S }*\\$${ S }*,${ S }*En stock$`
		)
	);
	await expect( options.last() ).toHaveAccessibleName(
		/^Voir (les \d+ résultats|le 1 résultat) pour planc$/
	);
	// The count's words come from the French plural ("9 suggestions"); "Aucune suggestion" below is French alone.
	await expect( page.locator( '.csearch-live' ) ).toHaveText(
		/^\d+ suggestions?$/
	);
	// The panel fits the screen with no scrollbar (D-092, superseding D-079's exemption).
	await input.press( 'ArrowUp' );
	await expect( options.last() ).toBeInViewport();
	await axe( page, 'French panel', '.wp-block-creative-search-search' );
} );

test( 'accents fold: vetements finds Vêtements', async ( { page } ) => {
	await page.goto( '/search-block/' );
	const input = page.getByRole( 'combobox', {
		name: 'Rechercher des produits',
	} );
	await input.click();
	await input.pressSequentially( 'vetements', { delay: 30 } );
	await expect(
		page
			.getByRole( 'listbox', { name: 'Suggestions' } )
			.getByRole( 'option', {
				name: new RegExp(
					`^Vêtements${ S }*,${ S }*Catégorie \\(1\\)$`
				),
			} )
	).toBeVisible();
} );

test( 'the results page in French, with French stems and prices', async ( {
	page,
} ) => {
	// csearch_all: the results page, not the Decks category the word names (D-102; routing.spec.ts).
	await page.goto( '/?s=planches&post_type=product&csearch_all=1' );
	await expect( page.locator( '.csearch-results-summary' ) ).toHaveText(
		new RegExp( `^\\d+ résultats pour «${ S }planches${ S }»$` )
	);
	// The French name lives in the index; the page shows the product's own title, as the theme does.
	await expect( page.locator( '.wp-block-post-title' ).first() ).toHaveText(
		'KAZUMI Skate Deck, Dealer'
	);
	await expect( page.locator( 'main' ) ).toContainText( /84,99/ );
	await axe( page, 'French results', 'main' );
} );

test( 'a word only the English documents hold falls back to them', async ( {
	page,
} ) => {
	await page.goto( '/?s=deck&post_type=product&csearch_all=1' );
	await expect( page.locator( '.csearch-results-summary' ) ).toHaveText(
		new RegExp( `^\\d+ résultats pour «${ S }deck${ S }»$` )
	);
	await expect( page.locator( '.wp-block-post-title' ).first() ).toHaveText(
		'KAZUMI Skate Deck, Dealer'
	);
} );

test( 'no results in French', async ( { page } ) => {
	await page.goto( '/?s=zzzzqqq&post_type=product' );
	const empty = page.locator( '.csearch-no-results' );
	await expect( empty ).toContainText(
		new RegExp( `Essayez une de ces catégories${ S }:` )
	);
	await axe( page, 'French no results', '.csearch-no-results' );
} );

test( 'the keyboard script in French: Down, Escape, Alt+Down, Enter', async ( {
	page,
} ) => {
	await page.goto( '/search-block/' );
	const input = page.getByRole( 'combobox', {
		name: 'Rechercher des produits',
	} );
	await input.click();
	await input.pressSequentially( 'kazumi', { delay: 30 } );
	const options = page
		.getByRole( 'listbox', { name: 'Suggestions' } )
		.getByRole( 'option' );
	await expect( options.first() ).toBeVisible();
	await input.press( 'ArrowDown' );
	const first = await options.first().getAttribute( 'id' );
	await expect( input ).toHaveAttribute(
		'aria-activedescendant',
		first ?? ''
	);
	await expect( options.first() ).toHaveAttribute( 'aria-selected', 'true' );
	await input.press( 'Escape' );
	await expect( input ).toHaveAttribute( 'aria-expanded', 'false' );
	await expect( input ).toHaveValue( 'kazumi' );
	await input.press( 'Alt+ArrowDown' );
	await expect( input ).toHaveAttribute( 'aria-expanded', 'true' );
	await input.press( 'ArrowDown' );
	await input.press( 'Enter' );
	await expect( page ).toHaveURL( /\/product\/kazumi-skate-deck-dealer\/$/ );
} );

test( 'no suggestions and a slow answer, in French', async ( { page } ) => {
	await page.goto( '/search-block/' );
	const input = page.getByRole( 'combobox', {
		name: 'Rechercher des produits',
	} );
	await input.click();
	await input.pressSequentially( 'zzzzqqq', { delay: 30 } );
	await expect( page.locator( '.csearch-live' ) ).toHaveText(
		'Aucune suggestion'
	);
	await axe(
		page,
		'French no suggestions',
		'.wp-block-creative-search-search'
	);

	await page.route( '**/csearch/v1/suggest**', async ( route ) => {
		await new Promise( ( r ) => setTimeout( r, 2000 ) );
		await route.continue();
	} );
	await input.fill( '' );
	await input.pressSequentially( 'orbit', { delay: 30 } );
	await expect( page.getByText( 'Recherche en cours…' ) ).toBeVisible();
	await expect( page.getByRole( 'option' ).first() ).toBeVisible( {
		timeout: 5000,
	} );
} );

test( 'the corrected line in French', async ( { page } ) => {
	await page.goto( '/?s=kazumi+wheels&post_type=product' );
	await expect( page.locator( '.csearch-results-corrected' ) ).toContainText(
		'Résultats pour kazumi; chercher plutôt kazumi wheels'
	);
} );

test.describe( 'scripts blocked, in French', () => {
	test.use( { javaScriptEnabled: false } );
	test( 'the form still searches', async ( { page } ) => {
		await page.goto( '/search-block/' );
		await page.locator( 'input[name="s"]' ).first().fill( 'planche' );
		await page
			.getByRole( 'button', { name: 'Rechercher', exact: true } )
			.first()
			.click();
		await expect( page.locator( '.csearch-results-summary' ) ).toHaveText(
			new RegExp( `^\\d+ résultats pour «${ S }planche${ S }»$` )
		);
	} );
} );

test.describe( 'the admin in French', () => {
	test.beforeEach( async ( { page } ) => {
		await page.goto( '/wp-login.php' );
		await page.fill( '#user_login', USER );
		await page.fill( '#user_pass', PASS );
		await page.click( '#wp-submit' );
		await page.waitForURL( /wp-admin/ );
	} );

	for ( const [ tab, heading ] of [
		[ 'analytics', 'Résumé' ],
		[ 'synonyms', 'Ajouter un synonyme' ],
		[ 'boosts', 'Règles de promotion' ],
		[ 'tools', 'Index' ],
	] ) {
		test( `the ${ tab } tab in French`, async ( { page } ) => {
			await page.goto( `/wp-admin/admin.php?page=csearch&tab=${ tab }` );
			await expect(
				page.getByRole( 'heading', { name: heading, exact: true } )
			).toBeVisible();
			await expect(
				page.getByRole( 'link', { name: 'Statistiques' } ).first()
			).toBeVisible();
			await axe( page, `French ${ tab } tab`, '.csearch-admin' );
		} );
	}

	test( 'the product editor’s Search panel in French', async ( { page } ) => {
		await page.goto(
			'/wp-admin/edit.php?post_type=product&s=kazumi+skate+deck+dealer'
		);
		await page.locator( '.row-title' ).first().click();
		await page.locator( '.csearch_options a' ).click();
		await expect(
			page.getByLabel( 'Exclure de la recherche' )
		).toBeVisible();
		// "il y a" is the plugin's; the duration is WordPress's own, French with WordPress's fr_CA pack.
		await expect( page.locator( '.csearch-last-indexed' ) ).toContainText(
			/Indexé il y a .+\./
		);
		await axe( page, 'French product panel', '#csearch_product_data' );
	} );
} );
