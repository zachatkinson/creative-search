/**
 * The search block's state machine (assets/src/block/view.js): typing, debounce, stale answers, the cache,
 * keyboard moves, the live region's words, failures, the click beacon and recent searches.
 * Run with `npm run test:js` (Vitest).
 */
import { test, beforeEach, afterEach, vi } from 'vitest';
import assert from 'node:assert/strict';
import { installDom } from './support/dom.mjs';
import { scope } from './support/interactivity.mjs';

const dom = installDom();
const { actions, state, callbacks, highlight } =
	await import( '../../assets/src/block/view.js' );

let form = 0;

function context( overrides = {} ) {
	return {
		id: `csearch-${ form }`,
		suggest: '/wp-json/csearch/v1/suggest',
		click: '/wp-json/csearch/v1/click',
		lang: 'en',
		min: 2,
		limit: 6,
		prices: true,
		stock: true,
		terms: true,
		recent: false,
		position: 'below',
		i18n: {
			counts: [
				'No suggestions',
				'1 suggestion',
				'2 suggestions',
				'3 suggestions',
				'4 suggestions',
			],
			recents: [
				'No recent searches',
				'1 recent search',
				'2 recent searches',
			],
			seeAll: [
				'See %1$s result for %2$s',
				'See all %1$s results for %2$s',
			],
			inStock: 'In stock',
			outOfStock: 'Out of stock',
			separator: ', ',
			termLabel: '%1$s, %2$s (%3$s)',
			taxonomies: { category: 'Category', tag: 'Tag', brand: 'Brand' },
			corrected: 'Showing results for %1$s',
			content: 'Pages',
			types: { post: 'Post', page: 'Page' },
		},
		query: '',
		typed: '',
		corrected: '',
		open: false,
		panel: false,
		activeId: null,
		active: -1,
		rows: [],
		live: '',
		searching: false,
		empty: false,
		above: false,
		answered: '',
		...overrides,
	};
}

const ANSWER = {
	products: [
		{
			id: 11,
			name: 'KAZUMI Skate Deck, Dealer',
			url: '/p/11',
			image: null,
			price_html: '<span>$84.99</span>',
			in_stock: true,
		},
		{
			id: 12,
			name: 'KAZUMI Skate Deck, Ghost Tiger',
			url: '/p/12',
			image: null,
			price_html: '<span>$84.99</span>',
			in_stock: false,
		},
	],
	terms: [
		{
			id: 5,
			taxonomy: 'brand',
			name: 'KAZUMI',
			count: 3,
			url: '/brand/kazumi',
		},
	],
	total: 2,
	query: 'kazumi',
	corrected: null,
	lang: 'en',
};

const respond = ( body, ok = true ) =>
	Promise.resolve( { ok, json: () => Promise.resolve( body ) } );
let calls;
let input;

beforeEach( () => {
	vi.useFakeTimers( { toFake: [ 'setTimeout', 'clearTimeout' ] } );
	dom.elements.clear();
	dom.beacons.length = 0;
	dom.assigned.length = 0;
	dom.replaced.length = 0;
	dom.backs = 0;
	dom.focused = null;
	window.history.state = null;
	window.localStorage.clear();
	form++;
	input = dom.element( `csearch-${ form }-input` );
	scope.ctx = context();
	scope.ref = dom.element();
	calls = [];
	dom.fetch = ( url ) => {
		calls.push( url );
		return respond( ANSWER );
	};
} );

afterEach( () => vi.useRealTimers() );

function type( text ) {
	input.value = text;
	actions.input( { target: input } );
}

async function settle( ms = 150 ) {
	vi.advanceTimersByTime( ms );
	for ( let i = 0; i < 12; i++ ) {
		await Promise.resolve();
	}
}

const key = ( name, extra = {} ) => {
	const event = { key: name, target: input, prevented: false, ...extra };
	event.preventDefault = () => ( event.prevented = true );
	return event;
};

test( 'nothing is asked below the minimum, and after 150 ms of quiet above it', async () => {
	type( 'k' );
	await settle( 500 );
	assert.equal( calls.length, 0 );
	type( 'ka' );
	vi.advanceTimersByTime( 100 );
	type( 'kaz' );
	vi.advanceTimersByTime( 100 );
	assert.equal( calls.length, 0, 'still typing' );
	await settle( 50 );
	assert.equal( calls.length, 1 );
	const url = new URL( calls[ 0 ], 'http://x' );
	assert.equal( url.searchParams.get( 'q' ), 'kaz' );
	assert.equal( url.searchParams.get( 'limit' ), '6' );
	assert.equal(
		url.searchParams.get( 'lang' ),
		'en-ca',
		'the page language'
	);
} );

test( 'an answer becomes rows, a See all row and words in the live region', async () => {
	type( 'kazumi' );
	await settle();
	const ctx = scope.ctx;
	assert.equal( ctx.open, true );
	assert.equal( ctx.panel, true );
	assert.deepEqual(
		ctx.rows.map( ( r ) => r.key ),
		[ 'p11', 'p12', 'tbrandKAZUMI', 'all' ]
	);
	assert.equal(
		ctx.rows[ 0 ].label,
		'KAZUMI Skate Deck, Dealer, $84.99, In stock'
	);
	assert.equal(
		ctx.rows[ 1 ].label,
		'KAZUMI Skate Deck, Ghost Tiger, $84.99, Out of stock'
	);
	assert.equal( ctx.rows[ 2 ].label, 'KAZUMI, Brand (3)' );
	assert.equal( ctx.rows[ 3 ].name, 'See all 2 results for kazumi' );
	assert.equal( ctx.live, '3 suggestions' );
	assert.equal( ctx.activeId, null );
	assert.equal( ctx.answered, 'kazumi' );
} );

test( 'no answer says so', async () => {
	dom.fetch = () =>
		respond( {
			products: [],
			terms: [],
			total: 0,
			query: 'zz',
			corrected: null,
		} );
	type( 'zzzz' );
	await settle();
	assert.equal( scope.ctx.empty, true );
	assert.equal( scope.ctx.live, 'No suggestions' );
	assert.deepEqual( scope.ctx.rows, [] );
} );

test( 'a stale answer is dropped', async () => {
	let first;
	let n = 0;
	dom.fetch = () => {
		n++;
		return 1 === n
			? new Promise( ( resolve ) => ( first = resolve ) )
			: respond( {
					...ANSWER,
					query: 'kazumi d',
					total: 1,
					products: [ ANSWER.products[ 0 ] ],
					terms: [],
				} );
	};
	type( 'kazumi' );
	await settle();
	type( 'kazumi d' );
	await settle();
	first( { ok: true, json: () => Promise.resolve( ANSWER ) } );
	await settle( 0 );
	assert.deepEqual(
		scope.ctx.rows.map( ( r ) => r.key ),
		[ 'p11', 'all' ]
	);
	assert.equal( scope.ctx.rows[ 1 ].name, 'See 1 result for kazumi d' );
} );

test( 'answers are cached for the page', async () => {
	type( 'kazumi' );
	await settle();
	type( 'kazum' );
	await settle();
	type( 'kazumi' );
	await settle();
	assert.equal( calls.length, 2 );
	assert.equal( scope.ctx.rows.length, 4 );
} );

test( 'a form kept to a category asks for it, and unticked asks for the whole shop (D-117)', async () => {
	scope.ctx = context( { scope: 'decks', scoped: true } );
	type( 'kazumi' );
	await settle();
	assert.equal( calls.length, 1 );
	assert.equal(
		new URL( calls[ 0 ], 'http://x' ).searchParams.get( 'cat' ),
		'decks'
	);
	actions.scope( { target: { checked: false } } );
	await settle( 0 );
	assert.equal( scope.ctx.scoped, false );
	assert.equal( calls.length, 2, 'the shop has its own answer' );
	assert.equal(
		new URL( calls[ 1 ], 'http://x' ).searchParams.has( 'cat' ),
		false
	);
	actions.scope( { target: { checked: true } } );
	await settle( 0 );
	assert.equal( calls.length, 2, "the category's answer was kept" );
	type( 'k' );
	actions.scope( { target: { checked: false } } );
	await settle( 0 );
	assert.equal( calls.length, 2, 'nothing asked below the minimum' );
} );

test( 'a form on no category page never sends one', async () => {
	scope.ctx = context( { scope: '', scoped: false } );
	type( 'kazumi' );
	await settle();
	assert.equal(
		new URL( calls[ 0 ], 'http://x' ).searchParams.has( 'cat' ),
		false
	);
} );

test( 'a failed or rate-limited answer fails silent', async () => {
	dom.fetch = () => respond( { code: 'csearch_rate_limited' }, false );
	type( 'kazumi' );
	await settle();
	assert.equal( scope.ctx.open, false );
	assert.deepEqual( scope.ctx.rows, [] );
	dom.fetch = () => Promise.reject( new Error( 'offline' ) );
	type( 'kazumis' );
	await settle();
	assert.equal( scope.ctx.open, false );
} );

test( 'a slow answer says Searching after 400 ms', async () => {
	dom.fetch = () => new Promise( () => {} );
	type( 'kazumi' );
	await settle();
	assert.equal( scope.ctx.searching, false );
	vi.advanceTimersByTime( 400 );
	assert.equal( scope.ctx.searching, true );
	assert.equal( scope.ctx.panel, true );
} );

test( 'Down and Up move through the rows, write each into the field and wrap through what was typed', async () => {
	type( 'kazumi' );
	await settle();
	const ctx = scope.ctx;
	actions.keydown( key( 'ArrowDown' ) );
	assert.equal( ctx.active, 0 );
	assert.equal( ctx.activeId, `${ ctx.id }-opt-0` );
	assert.equal(
		ctx.query,
		'KAZUMI Skate Deck, Dealer',
		'the field shows the row'
	);
	scope.ctx = { ...ctx, row: ctx.rows[ 0 ] };
	assert.equal( state.isActive, true );
	scope.ctx = ctx;
	actions.keydown( key( 'ArrowUp' ) );
	assert.equal( ctx.active, -1, 'above the first row: the field' );
	assert.equal( ctx.query, 'kazumi', 'with what was typed' );
	actions.keydown( key( 'ArrowUp' ) );
	assert.equal( ctx.active, 3, 'then the last row' );
	assert.equal( ctx.query, 'kazumi', '"See all" keeps what was typed' );
	actions.keydown( key( 'ArrowUp' ) );
	assert.equal( ctx.query, 'KAZUMI', 'the brand row' );
	actions.keydown( key( 'ArrowDown' ) );
	actions.keydown( key( 'ArrowDown' ) );
	assert.equal( ctx.active, -1, 'past the last row: the field' );
	assert.equal( ctx.query, 'kazumi' );
	actions.keydown( key( 'ArrowDown' ) );
	assert.equal( ctx.active, 0, 'wrapped' );
	actions.keydown( key( 'Escape' ) );
	assert.equal( ctx.query, 'kazumi', 'Escape puts back what was typed' );
	actions.keydown( key( 'ArrowDown', { altKey: true } ) );
	assert.equal( ctx.active, -1 );
	actions.keydown( key( 'ArrowUp' ) );
	assert.equal(
		ctx.active,
		ctx.rows.length - 1,
		'Up with no row chosen goes to the last'
	);
} );

test( 'Escape closes, then clears; Tab closes; Alt+Down opens without moving', async () => {
	type( 'kazumi' );
	await settle();
	const ctx = scope.ctx;
	actions.keydown( key( 'Escape' ) );
	assert.equal( ctx.open, false );
	assert.equal( ctx.query, 'kazumi' );
	actions.keydown( key( 'ArrowDown', { altKey: true } ) );
	assert.equal( ctx.open, true );
	assert.equal( ctx.active, -1 );
	actions.keydown( key( 'Tab' ) );
	assert.equal( ctx.open, false );
	actions.keydown( key( 'Escape' ) );
	assert.equal( ctx.query, '' );
	assert.equal( input.value, '' );
} );

test( 'Enter on a product goes to it and sends the click beacon', async () => {
	type( 'kazumi' );
	await settle();
	actions.keydown( key( 'ArrowDown' ) );
	const enter = key( 'Enter' );
	actions.keydown( enter );
	assert.equal( enter.prevented, true );
	assert.deepEqual( dom.assigned, [ '/p/11' ] );
	assert.equal( dom.beacons[ 0 ].url, '/wp-json/csearch/v1/click' );
	assert.deepEqual( Object.fromEntries( dom.beacons[ 0 ].body ), {
		q: 'kazumi',
		lang: 'en-ca',
		type: 'product',
		id: '11',
	} );
} );

test( 'Enter with no row highlighted submits the typed query', async () => {
	type( 'kazumi' );
	await settle();
	const enter = key( 'Enter' );
	actions.keydown( enter );
	assert.equal( enter.prevented, false );
} );

test( 'recent searches are off by default, and kept in the browser when on', () => {
	actions.submit();
	assert.equal( window.localStorage.getItem( 'csearch_recent' ), null );
	scope.ctx = context( { recent: true, query: 'kazumi deck' } );
	actions.submit();
	scope.ctx.query = '';
	actions.focus( { target: input } );
	assert.deepEqual(
		scope.ctx.rows.map( ( r ) => r.name ),
		[ 'kazumi deck' ]
	);
	assert.equal( scope.ctx.live, '1 recent search' );
} );

test( 'a click outside closes the panel', async () => {
	type( 'kazumi' );
	await settle();
	actions.outside( { target: dom.element() } );
	assert.equal( scope.ctx.open, false );
} );

test( 'highlight marks what a suggestion adds, not what was typed', () => {
	const marked = ( name, q ) =>
		highlight( name, q )
			.map( ( p ) => ( p.mark ? `[${ p.text }]` : p.text ) )
			.join( '' );
	assert.equal(
		marked( 'KAZUMI Skate Deck', 'kaz sk' ),
		'KAZ[UMI] Sk[ate] [Deck]'
	);
	assert.equal(
		marked( 'Planche Été', 'ete' ),
		'[Planche] Été',
		'accents and case aside'
	);
	assert.equal(
		marked( 'Dekc', 'deck' ),
		'[Dekc]',
		'a typo match is marked whole'
	);
	assert.equal(
		marked( '<b>Deck</b>', 'deck' ),
		'<[b]>Deck</[b]>',
		'markup in a name stays text'
	);
	assert.equal(
		marked( 'Deck', '' ),
		'Deck',
		'nothing typed, nothing marked'
	);
	assert.deepEqual( highlight( '', 'deck' ), [] );
} );

test( 'the name callback builds text and mark nodes, never markup', async () => {
	type( 'kazumi' );
	await settle();
	const ctx = scope.ctx;
	const made = [];
	globalThis.document.createTextNode = ( text ) => ( { text } );
	const createElement = globalThis.document.createElement;
	globalThis.document.createElement = ( tag ) => {
		const el = { tag, textContent: '' };
		made.push( el );
		return el;
	};
	const ref = { replaceChildren: ( ...nodes ) => ( ref.nodes = nodes ) };
	scope.ref = ref;
	scope.ctx = { ...ctx, row: ctx.rows[ 0 ] };
	callbacks.name();
	globalThis.document.createElement = createElement;
	assert.deepEqual(
		made.map( ( m ) => m.tag ),
		[ 'mark', 'mark', 'mark' ]
	);
	assert.equal( ref.nodes[ 0 ].text, 'KAZUMI ' );
	assert.equal( ref.nodes[ 1 ].textContent, 'Skate' );
	assert.equal( ref.nodes[ 5 ].textContent, 'Dealer' );
	scope.ctx = { ...ctx, row: ctx.rows[ 3 ] };
	callbacks.name();
	assert.equal( ref.nodes.length, 1, '"See all" is not highlighted' );
	scope.ctx = ctx;
} );

test( 'a corrected answer says so in the panel and the live region', async () => {
	dom.fetch = () => respond( { ...ANSWER, corrected: 'kazumi' } );
	type( 'kazumi zzz' );
	await settle();
	const ctx = scope.ctx;
	assert.equal( ctx.corrected, 'Showing results for kazumi' );
	assert.equal( ctx.live, '3 suggestions. Showing results for kazumi' );
	actions.clear();
	assert.equal( ctx.corrected, '' );
} );

test( 'a phone gets four products and two terms at most; a desktop ten rows', async () => {
	const many = {
		...ANSWER,
		products: Array.from( { length: 10 }, ( _, i ) => ( {
			...ANSWER.products[ 0 ],
			id: 100 + i,
		} ) ),
		terms: Array.from( { length: 3 }, ( _, i ) => ( {
			...ANSWER.terms[ 0 ],
			name: `T${ i }`,
		} ) ),
		total: 40,
	};
	dom.fetch = () => respond( many );
	scope.ctx = context( { limit: 10 } );
	type( 'kazumi' );
	await settle();
	let ctx = scope.ctx;
	assert.equal( ctx.rows.filter( ( r ) => r.product ).length, 10 );
	assert.equal(
		ctx.rows.filter( ( r ) => r.term ).length,
		0,
		'terms give way first'
	);

	const matchMedia = window.matchMedia;
	window.matchMedia = () => ( { matches: true } );
	form++;
	input = dom.element( `csearch-${ form }-input` );
	scope.ctx = context( { limit: 10 } );
	type( 'kazumi' );
	await settle();
	window.matchMedia = matchMedia;
	ctx = scope.ctx;
	assert.equal( ctx.rows.filter( ( r ) => r.product ).length, 4 );
	assert.equal( ctx.rows.filter( ( r ) => r.term ).length, 2 );
	assert.equal(
		ctx.rows[ ctx.rows.length - 1 ].all,
		true,
		'"See all" stays'
	);
} );

test( 'the backdrop closes the panel', async () => {
	type( 'kazumi' );
	await settle();
	const ctx = scope.ctx;
	assert.equal( ctx.panel, true );
	actions.dismiss();
	assert.equal( ctx.open, false );
	assert.equal( ctx.panel, false );
} );

const PAGES = [
	{
		id: 70,
		type: 'page',
		name: 'Wheel guide',
		parent: 'Size guides',
		url: '/guides/wheels/',
		image: null,
	},
	{
		id: 71,
		type: 'page',
		name: 'Size guides',
		parent: '',
		url: '/guides/',
		image: null,
	},
	{
		id: 72,
		type: 'post',
		name: 'Wheel care',
		parent: '',
		url: '/wheel-care/',
		image: null,
	},
];

test( 'posts and pages follow the terms, two at most, the first under the group heading', async () => {
	dom.fetch = () => respond( { ...ANSWER, content: PAGES } );
	type( 'kazumi' );
	await settle();
	const ctx = scope.ctx;
	const pages = ctx.rows.filter( ( r ) => r.content );
	assert.equal( pages.length, 2 );
	assert.equal(
		ctx.rows.indexOf( pages[ 0 ] ),
		ctx.rows.findLastIndex( ( r ) => r.term ) + 1,
		'after the terms'
	);
	assert.equal( pages[ 0 ].heading, 'Pages' );
	assert.equal( pages[ 1 ].heading, null );
	assert.equal( pages[ 0 ].kind, 'Size guides', 'the parent page shows' );
	assert.equal( pages[ 0 ].type, 'Page', 'each row names its type' );
	assert.equal( pages[ 1 ].meta, false, 'no parent, no meta line' );
	assert.equal( ctx.rows[ ctx.rows.length - 1 ].all, true );
	assert.equal( ctx.live, '4 suggestions' );

	actions.keydown( key( 'ArrowDown' ) );
	while ( ! ctx.rows[ ctx.active ].content ) {
		actions.keydown( key( 'ArrowDown' ) );
	}
	assert.equal(
		ctx.query,
		'Wheel guide',
		'arrowing writes a page title too'
	);
	actions.keydown( key( 'Enter' ) );
	assert.deepEqual( dom.assigned, [ '/guides/wheels/' ] );
	const sent = new URLSearchParams( dom.beacons[ 0 ].body );
	assert.equal( sent.get( 'type' ), 'page' );
	assert.equal( sent.get( 'id' ), '70' );
} );

test( 'pages take their rows from the products on a desktop, never from the ten', async () => {
	dom.fetch = () =>
		respond( {
			...ANSWER,
			products: Array.from( { length: 10 }, ( _, i ) => ( {
				...ANSWER.products[ 0 ],
				id: 100 + i,
			} ) ),
			content: PAGES,
			total: 40,
		} );
	scope.ctx = context( { limit: 10 } );
	type( 'kazumi' );
	await settle();
	const rows = scope.ctx.rows.filter( ( r ) => ! r.all );
	assert.equal( rows.length, 10 );
	assert.equal( rows.filter( ( r ) => r.product ).length, 8 );
	assert.equal( rows.filter( ( r ) => r.content ).length, 2 );
} );

test( 'a row carries the classes a plugin gave its product, and drops them for the next', () => {
	const ref = dom.element( 'row' );
	scope.ref = ref;
	scope.ctx = { ...context(), row: { classes: [ 'mcw-mature', 'x' ] } };
	callbacks.classes();
	assert.equal( ref.classList.contains( 'mcw-mature' ), true );
	scope.ctx.row = { classes: [] };
	callbacks.classes();
	assert.equal( ref.classList.size, 0 );
	scope.ctx.row = {};
	callbacks.classes();
	assert.equal( ref.classList.size, 0, 'a row without classes adds none' );
} );

test( 'arrowing never fetches, and Enter after editing the copied text searches the edit', async () => {
	type( 'kazumi' );
	await settle();
	const ctx = scope.ctx;
	actions.keydown( key( 'ArrowDown' ) );
	actions.keydown( key( 'ArrowDown' ) );
	await settle( 500 );
	assert.equal( calls.length, 1, 'the copy is not a new query' );
	type( `${ ctx.query } 8.5` );
	assert.equal( ctx.active, -1 );
	const enter = key( 'Enter' );
	actions.keydown( enter );
	assert.equal( enter.prevented, false, 'the form submits the edited text' );
} );

test( 'hovering highlights a row but never writes the field', async () => {
	type( 'kazumi' );
	await settle();
	const ctx = scope.ctx;
	ctx.row = ctx.rows[ 1 ];
	actions.hover();
	assert.equal( ctx.active, 1 );
	assert.equal( ctx.query, 'kazumi' );
} );

test( 'the dialog opens with the field focused and a history entry, and Back closes it', () => {
	const ctx = context( { dialog: true } );
	scope.ctx = ctx;
	const dialog = dom.element( `${ ctx.id }-dialog` );
	dom.element( `${ ctx.id }-open` );
	input = dom.element( `${ ctx.id }-input` );
	actions.openDialog();
	assert.equal( dialog.open, true );
	assert.equal( dom.focused, `${ ctx.id }-input` );
	assert.deepEqual( window.history.state, { csearch: ctx.id } );
	actions.openDialog();
	assert.equal( dom.backs, 0, 'opening twice pushes once' );

	window.history.state = null; // Back.
	actions.dialogBack();
	assert.equal( dialog.open, false );
	actions.dialogClosed();
	assert.equal( dom.backs, 0, 'Back already left the entry' );
	assert.equal(
		dom.focused,
		`${ ctx.id }-open`,
		'focus returns to the button'
	);
} );

test( 'closing the dialog any other way leaves its history entry', () => {
	const ctx = context( { dialog: true, query: 'deck', typed: 'deck' } );
	scope.ctx = ctx;
	const dialog = dom.element( `${ ctx.id }-dialog` );
	input = dom.element( `${ ctx.id }-input` );
	actions.openDialog();
	const escape = key( 'Escape' );
	actions.keydown( escape );
	assert.equal(
		escape.prevented,
		false,
		'with the panel closed, Escape is the dialog’s own'
	);
	assert.equal( ctx.query, 'deck', 'and does not clear the field' );
	actions.closeDialog();
	assert.equal( dialog.open, false );
	actions.dialogClosed();
	assert.equal( dom.backs, 1 );
	actions.dialogBack();
	assert.equal( dom.backs, 1, 'the popstate that follows changes nothing' );
} );

test( 'a click on the backdrop closes the dialog; one inside it does not', () => {
	const dialog = dom.element( 'd' );
	dialog.open = true;
	dialog.getBoundingClientRect = () => ( {
		left: 10,
		right: 110,
		top: 10,
		bottom: 110,
	} );
	const click = ( x, y, target = dialog, detail = 1 ) =>
		actions.dialogClick( {
			currentTarget: dialog,
			target,
			clientX: x,
			clientY: y,
			detail,
		} );
	click( 50, 50 );
	assert.equal( dialog.open, true );
	click( 50, 50, input );
	assert.equal( dialog.open, true );
	click( 5, 50, dialog, 0 );
	assert.equal( dialog.open, true, 'a keyboard click has no position' );
	click( 5, 50 );
	assert.equal( dialog.open, false );
} );

test( 'from the dialog, a suggestion and a search replace its history entry', async () => {
	const ctx = context( { dialog: true } );
	scope.ctx = ctx;
	dom.element( `${ ctx.id }-dialog` );
	input = dom.element( `${ ctx.id }-input` );
	actions.openDialog();
	type( 'kazumi' );
	await settle();
	actions.keydown( key( 'ArrowDown' ) );
	actions.keydown( key( 'Enter' ) );
	assert.deepEqual( dom.replaced, [ '/p/11' ] );
	assert.deepEqual( dom.assigned, [] );

	const formEl = {
		action: 'http://shop.test/',
		elements: [],
	};
	globalThis.FormData = class {
		forEach( fn ) {
			fn( 'kazumi', 's' );
			fn( 'product', 'post_type' );
		}
	};
	const submit = { target: formEl, prevented: false };
	submit.preventDefault = () => ( submit.prevented = true );
	actions.submit( submit );
	assert.equal( submit.prevented, true );
	assert.equal(
		dom.replaced[ 1 ],
		'http://shop.test/?s=kazumi&post_type=product'
	);

	window.history.state = null;
	const plain = { target: formEl, prevented: false };
	plain.preventDefault = () => ( plain.prevented = true );
	actions.submit( plain );
	assert.equal(
		plain.prevented,
		false,
		'outside the dialog the form submits'
	);
} );

/**
 * A panel that is 60 px a row below a 100 px top, rendered only when `shown` is set, with the frames and
 * resize callbacks queued for the test to run.
 *
 * @param {Object} ctx The block's context.
 * @return {Object} The panel and the queues.
 */
function measuredPanel( ctx ) {
	const frames = [];
	const resized = [];
	const panel = {
		hidden: true,
		shown: false,
		getBoundingClientRect: () => ( {
			top: 100,
			bottom: 100 + 60 * ctx.rows.length,
		} ),
	};
	const list = dom.element( `${ ctx.id }-listbox` );
	list.closest = () => panel;
	list.querySelectorAll = () => ( {
		length: panel.shown ? ctx.rows.length : 0,
	} );
	window.requestAnimationFrame = ( cb ) => frames.push( cb );
	window.ResizeObserver = class {
		constructor( cb ) {
			resized.push( cb );
		}
		observe() {}
	};
	const run = () => {
		for ( let i = 0; i < 20 && frames.length; i++ ) {
			frames.shift()();
		}
	};
	return { panel, frames, resized, run };
}

test( 'the panel is measured once its rows render, and drops the last suggestions until it fits', async () => {
	window.innerHeight = 290;
	type( 'kazumi' );
	const ctx = scope.ctx;
	const { panel, resized, run } = measuredPanel( ctx );
	await settle();
	assert.equal( ctx.rows.length, 4 );
	run();
	assert.equal( ctx.rows.length, 4, 'not measured before it renders' );
	panel.hidden = false;
	panel.shown = true;
	type( 'kazumi ' );
	await settle();
	run();
	assert.deepEqual(
		ctx.rows.map( ( r ) => r.key ),
		[ 'p11', 'p12', 'all' ],
		'the term dropped: 3 rows end at 280 px'
	);
	assert.deepEqual(
		ctx.rows.map( ( r ) => r.id ),
		[ 0, 1, 2 ].map( ( i ) => `${ ctx.id }-opt-${ i }` )
	);
	assert.equal( ctx.live, '2 suggestions' );
	assert.equal( resized.length, 1, 'watched once' );
	window.innerHeight = 230;
	resized[ 0 ]();
	run();
	assert.deepEqual(
		ctx.rows.map( ( r ) => r.key ),
		[ 'p11', 'all' ],
		'a font arriving re-measures it'
	);
	window.innerHeight = 800;
	delete window.requestAnimationFrame;
	delete window.ResizeObserver;
} );

test( 'a dropped row never leaves the field pointing at an id that is gone', async () => {
	window.innerHeight = 290;
	type( 'kazumi' );
	const ctx = scope.ctx;
	const { panel, run } = measuredPanel( ctx );
	panel.hidden = false;
	panel.shown = true;
	await settle();
	actions.keydown( key( 'ArrowUp' ) );
	assert.equal( ctx.activeId, `${ ctx.id }-opt-3`, 'See all chosen' );
	run();
	assert.equal( ctx.active, 2, 'still See all' );
	assert.equal( ctx.activeId, `${ ctx.id }-opt-2` );
	type( 'kazumi ' );
	await settle();
	actions.keydown( key( 'ArrowUp' ) );
	actions.keydown( key( 'ArrowUp' ) );
	assert.equal( ctx.query, 'KAZUMI', 'the brand row, about to be dropped' );
	run();
	assert.equal( ctx.active, -1, 'the choice goes back to the field' );
	assert.equal( ctx.activeId, null );
	assert.equal( ctx.query, 'kazumi ', 'with what was typed' );
	window.innerHeight = 800;
	delete window.requestAnimationFrame;
	delete window.ResizeObserver;
} );
