/**
 * The search block's state machine (assets/src/block/view.js): typing, debounce, stale answers, the cache,
 * keyboard moves, the live region's words, failures, the click beacon and recent searches.
 * Run with `npm run test:js`.
 */
import { test, beforeEach, afterEach, mock } from 'node:test';
import assert from 'node:assert/strict';
import { installDom } from './support/dom.mjs';
import { scope } from './support/interactivity.mjs';

const dom = installDom();
const { actions, state } = await import( '../../assets/src/block/view.js' );

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
			seeAll: 'See all %1$s results for %2$s',
			inStock: 'In stock',
			outOfStock: 'Out of stock',
			separator: ', ',
			termLabel: '%1$s, %2$s (%3$s)',
			taxonomies: { category: 'Category', tag: 'Tag', brand: 'Brand' },
		},
		query: '',
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
	mock.timers.enable( { apis: [ 'setTimeout' ] } );
	dom.elements.clear();
	dom.beacons.length = 0;
	dom.assigned.length = 0;
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

afterEach( () => mock.timers.reset() );

function type( text ) {
	input.value = text;
	actions.input( { target: input } );
}

async function settle( ms = 150 ) {
	mock.timers.tick( ms );
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
	mock.timers.tick( 100 );
	type( 'kaz' );
	mock.timers.tick( 100 );
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
	mock.timers.tick( 400 );
	assert.equal( scope.ctx.searching, true );
	assert.equal( scope.ctx.panel, true );
} );

test( 'Down and Up move through the rows and wrap; activedescendant follows', async () => {
	type( 'kazumi' );
	await settle();
	const ctx = scope.ctx;
	actions.keydown( key( 'ArrowDown' ) );
	assert.equal( ctx.active, 0 );
	assert.equal( ctx.activeId, `${ ctx.id }-opt-0` );
	scope.ctx = { ...ctx, row: ctx.rows[ 0 ] };
	assert.equal( state.isActive, true );
	scope.ctx = ctx;
	actions.keydown( key( 'ArrowUp' ) );
	actions.keydown( key( 'ArrowUp' ) );
	assert.equal( ctx.active, 2 );
	actions.keydown( key( 'ArrowDown' ) );
	actions.keydown( key( 'ArrowDown' ) );
	assert.equal( ctx.active, 0, 'wrapped' );
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
