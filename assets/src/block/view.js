/**
 * The search block's state machine, as an Interactivity API store. The server renders the whole form
 * (it submits with no script); this turns it into the APG list-autocomplete combobox: suggestions after
 * 150 ms of quiet, one request in flight, stale answers dropped by sequence number, the last 20 answers
 * cached per page, keyboard parity, a polite live region, and the click beacon. Since 1.3: suggested searches
 * above the products (D-112), searches within a category (D-113), add to cart for a simple product (D-115) and
 * the events an analytics tag can listen to (D-116).
 *
 * Every word the shopper reads arrives translated in the context (`SearchForm::context()`), so this file
 * holds no string a translator needs.
 */
import {
	store,
	getContext,
	getElement,
	withScope,
} from '@wordpress/interactivity';
import {
	coverViewport,
	pinnedIn,
	place,
	roomAround,
	scrollToRow,
} from './placement';

const DEBOUNCE = 150;
const SLOW = 400;
const CACHE = 20;
const RECENT = 5;
const RECENT_KEY = 'csearch_recent';
const SPACE_FOR_PANEL = 240;
// Baymard: at most 10 suggestions on a desktop, 4 to 8 on a phone, and no scrollbar where the page can scroll
// (D-092); in a fixed or sticky header, or on a touch screen, the panel scrolls inside itself (D-126). The whole
// panel is capped by `panel_max` and `panel_max_phone` (D-136); these are the caps a form without them uses.
const MOST = 8;
const PHONE_MOST = 5;
const PHONE = '(max-width: 600px), (pointer: coarse)';
// Products a phone's cap keeps before the category row, when there are that many (D-136).
const FIRST_PRODUCTS = 3;
const PHONE_TERMS = 2;
const TERMS = 3;
// Posts and pages, after the products, at most (Skatehouse handoff item 7).
const CONTENT = 2;
// Suggested searches above the products, at most (D-112).
const QUERIES = 3;
const PHONE_QUERIES = 2;
// Searches within a category, at most, in the room the terms have (D-113).
const SCOPES = 2;
// The list an analytics tag sees the suggestions as (D-116).
const LIST_ID = 'csearch_suggestions';
const LIST_NAME = 'Search suggestions';
// Frames fit() waits for the rows to render before it gives up measuring.
const FIT_TRIES = 10;
const REDACTED = '[redacted]';
// Panels fit() re-measures when their size changes (a web font arriving, an image loading).
const watched = new WeakSet();

/**
 * Per-instance bookkeeping the context should not carry (timers, the abort controller, the cache).
 *
 * @type {Map<string, {seq: number, timer: number, slow: number, ctrl: AbortController|null, cache: Map<string, Object>, last: Object|null, adding: boolean, emptyFor: string|null, personal: RegExp[]|null, unsafe: boolean, following: boolean}>}
 */
const privates = new Map();

function priv( ctx ) {
	let p = privates.get( ctx.id );
	if ( ! p ) {
		p = {
			seq: 0,
			timer: 0,
			slow: 0,
			ctrl: null,
			cache: new Map(),
			last: null,
			adding: false,
			emptyFor: null,
			personal: null,
			unsafe: false,
			following: false,
		};
		privates.set( ctx.id, p );
	}
	return p;
}

function fill( template, ...args ) {
	return template.replace( /%(\d)\$s/g, ( _, n ) => args[ n - 1 ] ?? '' );
}

function num( n, lang ) {
	try {
		return Number( n || 0 ).toLocaleString( lang.replace( /_/g, '-' ) );
	} catch {
		return String( Number( n || 0 ) );
	}
}

function textOf( html ) {
	const t = document.createElement( 'template' );
	t.innerHTML = html || '';
	return ( t.content.textContent || '' ).replace( /\s+/g, ' ' ).trim();
}

function pageLang( fallback ) {
	const raw = ( document.documentElement.lang || '' )
		.toLowerCase()
		.replace( /[^a-z0-9_-]/g, '' )
		.slice( 0, 8 );
	return raw.length >= 2 ? raw : fallback;
}

function readRecent() {
	try {
		const list = JSON.parse( window.localStorage.getItem( RECENT_KEY ) );
		return Array.isArray( list )
			? list.filter( ( q ) => typeof q === 'string' ).slice( 0, RECENT )
			: [];
	} catch {
		return [];
	}
}

function remember( ctx, query ) {
	const q = ( query || '' ).trim();
	if ( ! ctx.recent || ! q ) {
		return;
	}
	try {
		const list = [ q, ...readRecent().filter( ( x ) => x !== q ) ].slice(
			0,
			RECENT
		);
		window.localStorage.setItem( RECENT_KEY, JSON.stringify( list ) );
	} catch {
		// Storage unavailable: recent searches are a convenience, not a requirement.
	}
}

function fold( text ) {
	return text
		.normalize( 'NFD' )
		.replace( /[\u0300-\u036f]/g, '' )
		.toLowerCase();
}

/**
 * Split a suggestion's name into the parts the shopper typed and the parts the suggestion adds, so the added
 * parts can be marked (Baymard: highlight the suggested, not the typed, text; D-095). Each word keeps
 * plain the longest typed word it starts with, accents and case aside; a word nothing typed starts (a typo
 * or a synonym match) is marked whole. With nothing typed, nothing is marked.
 *
 * @param {string} name  The suggestion's name.
 * @param {string} query What the shopper typed.
 * @return {{text: string, mark: boolean}[]} The parts, in order.
 */
export function highlight( name, query ) {
	const text = String( name || '' );
	const typed = fold( String( query || '' ) )
		.split( /[^\p{L}\p{N}]+/u )
		.filter( Boolean );
	if ( ! typed.length ) {
		return text ? [ { text, mark: false } ] : [];
	}
	const parts = [];
	const push = ( piece, mark ) => {
		if ( ! piece ) {
			return;
		}
		const last = parts[ parts.length - 1 ];
		if ( last && last.mark === mark ) {
			last.text += piece;
		} else {
			parts.push( { text: piece, mark } );
		}
	};
	for ( const piece of text.split( /([\p{L}\p{N}]+)/u ) ) {
		if ( ! /[\p{L}\p{N}]/u.test( piece ) ) {
			push( piece, false );
			continue;
		}
		const folded = fold( piece );
		const longest = typed.reduce(
			( best, t ) =>
				folded.startsWith( t ) && t.length > best ? t.length : best,
			0
		);
		let cut = 0;
		let covered = 0;
		for ( const ch of piece ) {
			if ( covered >= longest ) {
				break;
			}
			covered += fold( ch ).length;
			cut += ch.length;
		}
		push( piece.slice( 0, cut ), false );
		push( piece.slice( cut ), true );
	}
	return parts;
}

function setActive( ctx, index ) {
	ctx.active = index;
	const row = ctx.rows[ index ];
	ctx.activeId = ctx.open && row ? row.id : null;
	if ( ! row ) {
		return;
	}
	const el = document.getElementById( row.id );
	if ( ! keepInPanel( ctx, el ) ) {
		el?.scrollIntoView( { block: 'nearest' } );
	}
}

/**
 * Bring a row into a capped panel by the panel's own scroll; the page stays where it is (D-126).
 *
 * @param {Object}           ctx The block's context.
 * @param {HTMLElement|null} el  The row.
 * @return {boolean} Whether the panel is capped and took care of it.
 */
function keepInPanel( ctx, el ) {
	const panel = ctx.capped ? el?.closest?.( '.csearch-panel' ) : null;
	if ( ! panel ) {
		return false;
	}
	panel.scrollTop = scrollToRow(
		panel.scrollTop,
		panel.clientHeight,
		el.offsetTop,
		el.offsetHeight
	);
	return true;
}

/**
 * Move the keyboard's choice and write it into the field, as the APG list-autocomplete pattern does: the
 * chosen suggestion's name while a row is chosen, what the shopper typed when the choice leaves the rows
 * or rests on "See all" (Baymard: copy the suggestion into the field; D-091).
 *
 * @param {Object} ctx   The block's context.
 * @param {number} index The row, or -1 for the field itself.
 */
function moveTo( ctx, index ) {
	setActive( ctx, index );
	const row = ctx.rows[ index ];
	// A search within a category writes the words it searches, not "kazumi in Decks".
	ctx.query = row && ! row.all ? ( row.value ?? row.name ) : ctx.typed;
}

/**
 * Tell the page what happened (D-116): a `csearch:<name>` event on the form for any script, and the matching
 * Google Analytics event on the page's `dataLayer` when it has one (Google Tag Manager's). Nothing leaves the
 * page from here; with the setting off, nothing happens at all.
 *
 * @param {Object}      ctx    The block's context.
 * @param {string}      name   `search`, `select`, `no-results` or `add-to-cart`.
 * @param {Object}      detail What the event carries.
 * @param {Object|null} layer  The dataLayer entry, or null for none.
 */
/**
 * The words an analytics tag may hear: `[redacted]` when they look like an e-mail address, a phone or card number
 * or a postal code, by the log's own rules (the spec's privacy rule; the patterns come from `Domain\Scrub`). A
 * browser that cannot compile one of them hears `[redacted]` for every search rather than risk one.
 *
 * @param {Object} ctx   The block's context.
 * @param {string} words What the shopper typed.
 * @return {string} The words, or `[redacted]`.
 */
function told( ctx, words ) {
	const p = priv( ctx );
	if ( ! p.personal ) {
		p.personal = [];
		for ( const source of ctx.personal || [] ) {
			try {
				p.personal.push( new RegExp( source, 'u' ) );
			} catch {
				p.unsafe = true;
			}
		}
	}
	return p.unsafe || p.personal.some( ( re ) => re.test( words ) )
		? REDACTED
		: words;
}

function emit( ctx, name, detail, layer = null ) {
	if ( ! ctx.events ) {
		return;
	}
	const form = formOf( document.getElementById( `${ ctx.id }-input` ) );
	try {
		form?.dispatchEvent?.(
			new window.CustomEvent( `csearch:${ name }`, {
				bubbles: true,
				detail,
			} )
		);
	} catch {
		// No CustomEvent (a very old browser): the dataLayer still hears it.
	}
	const data = window.dataLayer;
	if ( layer && Array.isArray( data ) ) {
		if ( layer.ecommerce ) {
			data.push( { ecommerce: null } ); // Clear the last one, as Google's examples do.
		}
		data.push( layer );
	}
}

/**
 * The analytics item for a product row.
 *
 * @param {Object} row A product row.
 * @return {Object} A Google Analytics item.
 */
function item( row ) {
	const out = { item_id: String( row.pid ), item_name: row.name };
	if ( typeof row.price === 'number' ) {
		out.price = row.price;
	}
	return out;
}

function closePanel( ctx ) {
	ctx.open = false;
	ctx.panel = false;
	ctx.searching = false;
	ctx.empty = false;
	setActive( ctx, -1 );
}

/**
 * Where the panel opens and how tall it may be (D-126): in the dialog always under the field; in a fixed or
 * sticky header, or on a touch screen, capped to the room left in the visible viewport and scrolling inside
 * itself; elsewhere at its natural height.
 *
 * @param {Object}           ctx   The block's context.
 * @param {HTMLElement|null} input The field.
 */
function placePanel( ctx, input ) {
	ctx.capped = false;
	ctx.panelMax = '';
	if ( ctx.dialog ) {
		ctx.above = false; // In the dialog the panel always sits under the field.
		return;
	}
	if ( ! input ) {
		ctx.above = ctx.position === 'above';
		return;
	}
	const block = input.closest?.( '.wp-block-creative-search-search' );
	const styleOf = window.getComputedStyle
		? ( el ) => window.getComputedStyle( el )
		: null;
	const field = input.closest?.( '.csearch-field' ) || input;
	const spot = place( {
		position: ctx.position,
		coarse: Boolean( window.matchMedia?.( '(pointer: coarse)' ).matches ),
		pinned: Boolean( block && styleOf && pinnedIn( block, styleOf ) ),
		room: roomAround(
			field.getBoundingClientRect(),
			window.visualViewport || null,
			window.innerHeight
		),
		needed: SPACE_FOR_PANEL,
	} );
	ctx.above = spot.above;
	ctx.capped = spot.capped;
	ctx.panelMax = spot.max;
}

/**
 * Keep an open panel's placement and the dimming layer right while the visible viewport changes: the keyboard
 * opening or closing, the page scrolling a header away. One listener set per block, at most one update a frame.
 *
 * @param {Object}      ctx   The block's context.
 * @param {HTMLElement} input The field.
 */
function follow( ctx, input ) {
	const p = priv( ctx );
	if ( p.following || ctx.dialog || ! window.addEventListener ) {
		return;
	}
	p.following = true;
	let queued = false;
	const update = withScope( () => {
		queued = false;
		if ( ctx.panel ) {
			placePanel( ctx, input );
			coverPage( ctx, 0 );
		}
	} );
	const later = () => {
		if ( ! queued ) {
			queued = true;
			window.requestAnimationFrame( update );
		}
	};
	window.visualViewport?.addEventListener( 'resize', later );
	window.visualViewport?.addEventListener( 'scroll', later );
	window.addEventListener( 'resize', later );
	window.addEventListener( 'scroll', later, { passive: true } );
}

/**
 * Spread the dimming layer over the whole viewport even when a transformed ancestor (a header that slides away)
 * has become its containing block (D-127). Waits a few frames for the layer to be shown.
 *
 * @param {Object} ctx   The block's context.
 * @param {number} tries Frames left to wait.
 */
function coverPage( ctx, tries = FIT_TRIES ) {
	const layer = document
		.getElementById( `${ ctx.id }-input` )
		?.closest?.( '.wp-block-creative-search-search' )
		?.querySelector( ':scope > .csearch-backdrop' );
	if ( ! layer || ! ctx.panel ) {
		return;
	}
	if ( layer.hidden ) {
		if ( tries > 0 ) {
			window.requestAnimationFrame?.(
				withScope( () => coverPage( ctx, tries - 1 ) )
			);
		}
		return;
	}
	for ( const key of [ 'inset', 'top', 'left', 'width', 'height' ] ) {
		layer.style.removeProperty( key );
	}
	const cover = coverViewport(
		layer.getBoundingClientRect(),
		document.documentElement.clientWidth,
		window.innerHeight
	);
	if ( cover ) {
		layer.style.inset = 'auto';
		Object.assign( layer.style, cover );
	}
}

function openPanel( ctx, input ) {
	placePanel( ctx, input );
	ctx.open = true;
	ctx.panel = ctx.rows.length > 0 || ctx.empty || ctx.searching;
	if ( input && ctx.panel ) {
		follow( ctx, input );
		coverPage( ctx );
	}
}

function productRow( ctx, p, index ) {
	const i18n = ctx.i18n;
	const parts = [ p.name ];
	const price = ctx.prices ? textOf( p.price_html ) : '';
	if ( price ) {
		parts.push( price );
	}
	let stock = '';
	if ( ctx.stock ) {
		stock = p.in_stock ? i18n.inStock : i18n.outOfStock;
	}
	if ( stock ) {
		parts.push( stock );
	}
	return {
		key: `p${ p.id }`,
		id: `${ ctx.id }-opt-${ index }`,
		index,
		product: true,
		pid: p.id,
		url: p.url,
		name: p.name,
		label: parts.join( i18n.separator ),
		src: p.image?.src || null,
		srcset: p.image?.srcset || null,
		width: p.image?.width || null,
		height: p.image?.height || null,
		priceHtml: ctx.prices ? p.price_html || null : null,
		price: typeof p.price === 'number' ? p.price : null,
		cart: Boolean( ctx.cart && p.cart ),
		hint: ctx.cart && p.cart ? `${ ctx.id }-cart-hint` : null,
		stock: stock || null,
		out: ctx.stock && ! p.in_stock,
		meta: Boolean( price || stock ),
		matched: typeof p.matched === 'string' ? p.matched : '',
		classes: Array.isArray( p.classes ) ? p.classes : [],
	};
}

function termRow( ctx, t, index ) {
	const i18n = ctx.i18n;
	const kind = i18n.taxonomies[ t.taxonomy ] || t.taxonomy;
	return {
		key: `t${ t.taxonomy }${ t.name }`,
		id: `${ ctx.id }-opt-${ index }`,
		index,
		term: true,
		taxonomy: t.taxonomy,
		pid: t.id || 0,
		url: t.url,
		name: t.name,
		label: fill( i18n.termLabel, t.name, kind, num( t.count, ctx.lang ) ),
		kind: `${ kind } (${ num( t.count, ctx.lang ) })`,
		meta: true,
	};
}

function queryRow( ctx, q, index ) {
	return {
		key: `q${ q }`,
		id: `${ ctx.id }-opt-${ index }`,
		index,
		query: true,
		name: q,
		value: q,
		label: q,
		type: ctx.i18n.search,
		meta: true,
	};
}

function scopeRow( ctx, s, query, index ) {
	const name = fill( ctx.i18n.scope, query, s.name );
	return {
		key: `s${ s.id }`,
		id: `${ ctx.id }-opt-${ index }`,
		index,
		scope: true,
		cid: s.id,
		url: s.url,
		name,
		value: query,
		label: name,
		meta: false,
	};
}

function contentRow( ctx, c, index, first ) {
	const type = ctx.i18n.types[ c.type ] || c.type;
	return {
		key: `c${ c.id }`,
		id: `${ ctx.id }-opt-${ index }`,
		index,
		content: true,
		ctype: c.type,
		pid: c.id,
		url: c.url,
		name: c.name,
		type,
		heading: first ? ctx.i18n.content : null,
		kind: c.parent || null,
		src: c.image?.src || null,
		srcset: c.image?.srcset || null,
		width: c.image?.width || null,
		height: c.image?.height || null,
		meta: Boolean( c.parent ),
	};
}

/**
 * How many suggested searches, products, categories (scopes, then terms) and posts or pages the panel shows (D-136).
 * Each group has its own limit first: the owner's products, up to 3 suggested searches (2 on a phone, D-112), up to
 * 3 terms (2 on a phone) sharing their room with up to 2 searches within a category (D-113), and up to 2 posts or
 * pages (none on a phone unless the form allows them). The panel's posts and pages all have every word in their
 * title (D-137); the first is the page the shopper asked for (D-143), which gets its row on a phone too, and the
 * products are then split into those found by more than their description (`products`) and those some word
 * reached only there (`described`). The whole panel is filled to its cap in this order: one suggested search,
 * three products, the page asked for, the rest of the three products from the described ones, one category or
 * scope row, the other products, the other described products, the other suggested searches and categories, and
 * the other posts or pages last. "See all" is not counted.
 *
 * @param {Object} ctx   The block's context.
 * @param {Object} found How many of each the answer has: `{queries, products, described, scopes, terms, content}`;
 *                       `described` is 0 when there is no page asked for, every product then in `products`.
 * @return {{queries: number, products: number, described: number, scopes: number, terms: number, page: number, content: number}} The budget.
 */
export function budget( ctx, found ) {
	const phone = Boolean( window.matchMedia?.( PHONE ).matches );
	const room = phone ? PHONE_TERMS : TERMS;
	const scopes = Math.min(
		SCOPES,
		room,
		found.products + ( found.described || 0 ) ? found.scopes : 0
	);
	const products = Math.min( ctx.limit, found.products );
	const page = found.content > 0 ? 1 : 0;
	const has = {
		queries: Math.min( phone ? PHONE_QUERIES : QUERIES, found.queries ),
		products,
		described: Math.min( ctx.limit - products, found.described || 0 ),
		scopes,
		terms: ctx.terms ? Math.min( room - scopes, found.terms ) : 0,
		page,
		content:
			phone && false === ctx.pagesPhone
				? 0
				: Math.min( CONTENT, found.content ) - page,
	};
	const cap =
		Number( phone ? ctx.maxPhone : ctx.max ) ||
		( phone ? PHONE_MOST : MOST );
	const out = {
		queries: 0,
		products: 0,
		described: 0,
		scopes: 0,
		terms: 0,
		page: 0,
		content: 0,
	};
	let left = cap;
	const take = ( group, upTo ) => {
		const n = Math.max(
			0,
			Math.min( left, has[ group ] - out[ group ], upTo - out[ group ] )
		);
		out[ group ] += n;
		left -= n;
	};
	const category = ( upTo ) => {
		take( 'scopes', upTo - out.terms );
		take( 'terms', upTo - out.scopes );
	};
	// The first three products are promised (when there are three), and then the page asked for: a cap of 3 leaves
	// no room for the search, nor for the page; a cap of 4 none for the search when there is a page.
	if (
		cap >
		Math.min( FIRST_PRODUCTS, has.products + has.described ) + has.page
	) {
		take( 'queries', 1 );
	}
	take( 'products', FIRST_PRODUCTS );
	take( 'page', 1 );
	take( 'described', FIRST_PRODUCTS - out.products );
	category( 1 );
	take( 'products', Infinity );
	take( 'described', Infinity );
	take( 'queries', Infinity );
	category( Infinity );
	take( 'content', Infinity );
	return out;
}

function liveWords( ctx, count ) {
	const words =
		ctx.i18n.counts[ Math.min( count, ctx.i18n.counts.length - 1 ) ];
	return ctx.corrected ? `${ words }. ${ ctx.corrected }` : words;
}

/**
 * Drop the last suggestions until the open panel fits the visible viewport, so it never needs a scrollbar
 * (D-092); a capped panel, until its rows fit its cap, scrolling inside itself only when two suggestions and
 * "See all" do not (D-126). Runs after each render; "See all" always stays.
 *
 * @param {Object} ctx   The block's context.
 * @param {number} tries Frames left to wait for the rows to render.
 */
function fit( ctx, tries = FIT_TRIES ) {
	window.requestAnimationFrame?.(
		withScope( () => {
			const list = document.getElementById( `${ ctx.id }-listbox` );
			const panel = list?.closest( '.csearch-panel' );
			const suggestions = ctx.rows.filter( ( r ) => ! r.all );
			if ( ! ctx.panel || ! panel || suggestions.length < 2 ) {
				return;
			}
			// The rows render after the context changes: measure once they are all on the page.
			if (
				panel.hidden ||
				list.querySelectorAll( '[role="option"]' ).length !==
					ctx.rows.length
			) {
				if ( tries > 0 ) {
					fit( ctx, tries - 1 );
				}
				return;
			}
			if ( window.ResizeObserver && ! watched.has( panel ) ) {
				watched.add( panel );
				new window.ResizeObserver(
					withScope( () => fit( ctx ) )
				).observe( panel );
			}
			const box = panel.getBoundingClientRect();
			const top = window.visualViewport?.offsetTop || 0;
			const height = window.visualViewport?.height || window.innerHeight;
			const fits = ctx.capped
				? panel.scrollHeight <= panel.clientHeight + 1
				: box.bottom <= top + height && box.top >= top;
			if ( fits ) {
				// Rows dropped since the choice moved: the chosen one may have slid out of a capped panel.
				const chosen = ctx.rows[ ctx.active ];
				if ( chosen ) {
					keepInPanel( ctx, document.getElementById( chosen.id ) );
				}
				return;
			}
			// The last row goes, but a suggested search before any product (D-112); the products found only by their
			// description, then the suggested search, before the page asked for (D-143).
			const last = ( keep ) => suggestions.findLast( keep );
			const drop =
				last( ( r ) => ! r.product && ! r.query && ! r.asked ) ||
				last( ( r ) => r.described ) ||
				last( ( r ) => r.query ) ||
				last( ( r ) => r.asked ) ||
				suggestions[ suggestions.length - 1 ];
			const chosen = ctx.rows[ ctx.active ];
			ctx.rows = ctx.rows
				.filter( ( r ) => r !== drop )
				.map( ( r, index ) => ( {
					...r,
					index,
					id: `${ ctx.id }-opt-${ index }`,
				} ) );
			// The ids moved: the field must not point at one that is gone.
			if ( chosen === drop ) {
				moveTo( ctx, -1 );
			} else {
				setActive(
					ctx,
					ctx.rows.findIndex( ( r ) => r.key === chosen?.key )
				);
			}
			ctx.live = liveWords( ctx, suggestions.length - 1 );
			fit( ctx, tries );
		} )
	);
}

function showAnswer( ctx, data, input ) {
	const list = ( name ) =>
		Array.isArray( data[ name ] ) ? data[ name ] : [];
	const content = list( 'content' );
	// With a page asked for (D-143), the products found only by their description go after it.
	const all = list( 'products' );
	const split = content.length > 0;
	const found = {
		queries: list( 'queries' ),
		products: split ? all.filter( ( p ) => ! p.described ) : all,
		described: split ? all.filter( ( p ) => p.described ) : [],
		scopes: list( 'scopes' ),
		terms: list( 'terms' ),
		page: content.slice( 0, 1 ),
		content: content.slice( 1 ),
	};
	const most = budget( ctx, {
		queries: found.queries.length,
		products: found.products.length,
		described: found.described.length,
		scopes: found.scopes.length,
		terms: found.terms.length,
		content: content.length,
	} );
	const query = data.query || ctx.query.trim();
	const rows = [];
	found.queries
		.slice( 0, most.queries )
		.forEach( ( q ) => rows.push( queryRow( ctx, q, rows.length ) ) );
	found.products
		.slice( 0, most.products )
		.forEach( ( p ) => rows.push( productRow( ctx, p, rows.length ) ) );
	found.page.slice( 0, most.page ).forEach( ( c ) =>
		rows.push( {
			...contentRow( ctx, c, rows.length, true ),
			asked: true,
		} )
	);
	found.described.slice( 0, most.described ).forEach( ( p ) =>
		rows.push( {
			...productRow( ctx, p, rows.length ),
			described: true,
		} )
	);
	found.scopes
		.slice( 0, most.scopes )
		.forEach( ( sc ) =>
			rows.push( scopeRow( ctx, sc, query, rows.length ) )
		);
	found.terms
		.slice( 0, most.terms )
		.forEach( ( t ) => rows.push( termRow( ctx, t, rows.length ) ) );
	found.content
		.slice( 0, most.content )
		.forEach( ( c, i ) =>
			rows.push(
				contentRow( ctx, c, rows.length, i === 0 && ! most.page )
			)
		);
	const count = rows.length;
	const total = Number( data.total || 0 );
	if ( total > 0 ) {
		const name = fill(
			ctx.i18n.seeAll[ 1 === total ? 0 : 1 ],
			num( total, ctx.lang ),
			data.query || ctx.query.trim()
		);
		rows.push( {
			key: 'all',
			id: `${ ctx.id }-opt-${ rows.length }`,
			index: rows.length,
			all: true,
			name,
			label: name,
			meta: false,
		} );
	}
	ctx.rows = rows;
	ctx.answered = data.query || ctx.query.trim();
	ctx.corrected =
		typeof data.corrected === 'string' && data.corrected
			? fill( ctx.i18n.corrected, data.corrected )
			: '';
	ctx.searching = false;
	ctx.empty = count === 0;
	ctx.live = liveWords( ctx, count );
	setActive( ctx, -1 );
	openPanel( ctx, input );
	fit( ctx );
	// Remembered for the search the shopper sends: an answer while typing is a prefix, not a search, so it says
	// nothing on its own.
	priv( ctx ).emptyFor = count === 0 ? ctx.answered : null;
}

function showRecent( ctx, input ) {
	const rows = readRecent().map( ( q, index ) => ( {
		key: `r${ q }`,
		id: `${ ctx.id }-opt-${ index }`,
		index,
		recent: true,
		name: q,
		label: q,
		meta: false,
	} ) );
	ctx.rows = rows;
	ctx.corrected = '';
	ctx.searching = false;
	ctx.empty = false;
	ctx.live = ctx.i18n.recents[ Math.min( rows.length, RECENT ) ];
	setActive( ctx, -1 );
	if ( rows.length ) {
		openPanel( ctx, input );
	} else {
		closePanel( ctx );
	}
}

function fail( ctx ) {
	ctx.rows = [];
	ctx.corrected = '';
	ctx.live = '';
	closePanel( ctx );
}

function abortPending( p ) {
	window.clearTimeout( p.timer );
	window.clearTimeout( p.slow );
	p.timer = 0;
	p.slow = 0;
	if ( p.ctrl ) {
		p.ctrl.abort();
		p.ctrl = null;
	}
}

/**
 * Whether the dialog's history entry is the current one.
 *
 * @param {Object} ctx The block's context.
 * @return {boolean} True while the open dialog owns the entry.
 */
function pushed( ctx ) {
	return Boolean( ctx.dialog && window.history.state?.csearch === ctx.id );
}

function formOf( input ) {
	return input?.form || input?.closest?.( 'form' );
}

/**
 * Let the mini-cart know (D-115): WooCommerce's classic fragments through jQuery's `added_to_cart`, which its
 * blocks also translate for the Mini-Cart block; the block's own event when the page has no jQuery.
 *
 * @param {Object} data The add-to-cart answer: `fragments` and `cart_hash`.
 */
function refreshCart( data ) {
	const body = document.body;
	if ( window.jQuery && body ) {
		window
			.jQuery( body )
			.trigger( 'added_to_cart', [
				data.fragments,
				data.cart_hash,
				null,
			] );
		return;
	}
	try {
		body?.dispatchEvent?.(
			new window.CustomEvent( 'wc-blocks_added_to_cart', {
				bubbles: true,
				cancelable: true,
			} )
		);
	} catch {
		// No CustomEvent: the cart is right on the next page.
	}
}

/**
 * A page loaded (or reloaded) on a dialog's step has no dialog open: drop that stale key, and only that key,
 * so the step keeps the Interactivity API's own (stamped when its module ran, before this one).
 */
function forgetStaleStep() {
	try {
		const current = window.history.state;
		if ( current && typeof current === 'object' && 'csearch' in current ) {
			const { csearch, ...rest } = current;
			window.history.replaceState( rest, '' );
		}
	} catch {
		// History unavailable (a sandboxed frame): nothing to clean.
	}
}

forgetStaleStep();

const { state, actions, callbacks } = store( 'creative-search/search', {
	state: {
		get isActive() {
			const ctx = getContext();
			return ctx.open && ctx.row.index === ctx.active;
		},
	},
	actions: {
		input( event ) {
			const ctx = getContext();
			const p = priv( ctx );
			ctx.query = event.target.value;
			ctx.typed = ctx.query;
			setActive( ctx, -1 );
			window.clearTimeout( p.timer );
			const q = ctx.query.trim();
			if ( q.length < ctx.min ) {
				abortPending( p );
				if ( q === '' && ctx.recent ) {
					showRecent( ctx, event.target );
				} else {
					ctx.rows = [];
					closePanel( ctx );
				}
				return;
			}
			p.timer = window.setTimeout(
				withScope( () => actions.request() ),
				DEBOUNCE
			);
		},
		*request() {
			const ctx = getContext();
			const q = ctx.query.trim();
			if ( q.length < ctx.min ) {
				return;
			}
			const p = priv( ctx );
			const input = document.getElementById( `${ ctx.id }-input` );
			// On a category page the answer offers that category first (D-130), so each page has its own answers.
			const here = ctx.scope || '';
			const key = here ? `${ q }\n${ here }` : q;
			const cached = p.cache.get( key );
			if ( cached ) {
				p.cache.delete( key );
				p.cache.set( key, cached );
				showAnswer( ctx, cached, input );
				return;
			}
			abortPending( p );
			const seq = ++p.seq;
			const ctrl = new AbortController();
			p.ctrl = ctrl;
			p.slow = window.setTimeout(
				withScope( () => {
					if ( p.seq === seq ) {
						ctx.searching = true;
						openPanel( ctx, input );
					}
				} ),
				SLOW
			);
			const params = new URLSearchParams( {
				q,
				lang: pageLang( ctx.lang ),
				limit: String( ctx.limit ),
			} );
			if ( here ) {
				params.set( 'here', here );
			}
			const url =
				ctx.suggest +
				( ctx.suggest.includes( '?' ) ? '&' : '?' ) +
				params;
			try {
				const response = yield window.fetch( url, {
					signal: ctrl.signal,
					headers: { Accept: 'application/json' },
				} );
				if ( seq !== p.seq ) {
					return;
				}
				if ( ! response.ok ) {
					fail( ctx );
					return;
				}
				const data = yield response.json();
				if ( seq !== p.seq ) {
					return;
				}
				p.cache.set( key, data );
				if ( p.cache.size > CACHE ) {
					p.cache.delete( p.cache.keys().next().value );
				}
				showAnswer( ctx, data, input );
			} catch {
				if ( seq === p.seq ) {
					fail( ctx );
				}
			} finally {
				if ( seq === p.seq ) {
					window.clearTimeout( p.slow );
					p.slow = 0;
					p.ctrl = null;
					ctx.searching = false;
				}
			}
		},
		keydown( event ) {
			const ctx = getContext();
			const input = event.target;
			const n = ctx.rows.length;
			switch ( event.key ) {
				case 'ArrowDown':
					event.preventDefault();
					if ( ! ctx.open ) {
						actions.show( input );
						if ( ! event.altKey && ctx.open && ctx.rows.length ) {
							moveTo( ctx, 0 );
						}
					} else if ( ! event.altKey && n ) {
						// Past the last row, back to what was typed (APG).
						moveTo( ctx, ctx.active + 1 < n ? ctx.active + 1 : -1 );
					}
					break;
				case 'ArrowUp':
					event.preventDefault();
					if ( ! ctx.open ) {
						actions.show( input );
						if ( ctx.open && ctx.rows.length ) {
							moveTo( ctx, ctx.rows.length - 1 );
						}
					} else if ( n ) {
						// With no row chosen, Up goes to the last row; above the first, back to what was
						// typed (APG).
						moveTo( ctx, ctx.active < 0 ? n - 1 : ctx.active - 1 );
					}
					break;
				case 'Enter':
					if (
						ctx.open &&
						ctx.active >= 0 &&
						ctx.rows[ ctx.active ]
					) {
						event.preventDefault();
						// Shift+Enter puts the chosen product in the cart, as its description says (D-115).
						if ( event.shiftKey && ctx.rows[ ctx.active ].cart ) {
							actions.cart( ctx.rows[ ctx.active ] );
						} else {
							actions.pick( ctx.rows[ ctx.active ], input );
						}
					}
					break;
				case 'Escape':
					if ( ctx.open ) {
						event.preventDefault();
						ctx.query = ctx.typed;
						closePanel( ctx );
					} else if ( ! ctx.dialog && ctx.query ) {
						// In the dialog, Escape with the panel closed closes the dialog (its own default).
						event.preventDefault();
						actions.clear();
					}
					break;
				case 'Tab':
					closePanel( ctx );
					break;
				default:
			}
		},
		show( input ) {
			const ctx = getContext();
			const q = ctx.query.trim();
			if ( q === '' ) {
				if ( ctx.recent ) {
					showRecent( ctx, input );
				}
				return;
			}
			if ( q.length < ctx.min ) {
				return;
			}
			if ( ctx.rows.length || ctx.empty ) {
				openPanel( ctx, input );
				return;
			}
			actions.request();
		},
		focus( event ) {
			actions.show( event.target );
		},
		clear() {
			const ctx = getContext();
			const p = priv( ctx );
			abortPending( p );
			p.seq++;
			ctx.query = '';
			ctx.typed = '';
			ctx.rows = [];
			ctx.live = '';
			ctx.corrected = '';
			closePanel( ctx );
			const input = document.getElementById( `${ ctx.id }-input` );
			if ( input ) {
				input.value = '';
				input.focus();
			}
		},
		submit( event ) {
			const ctx = getContext();
			remember( ctx, ctx.query );
			closePanel( ctx );
			const words = ctx.query.trim();
			if ( words ) {
				const term = told( ctx, words );
				emit(
					ctx,
					'search',
					{ query: term },
					{ event: 'search', search_term: term }
				);
				if ( priv( ctx ).emptyFor === words ) {
					emit(
						ctx,
						'no-results',
						{ query: term },
						{ event: 'csearch_no_results', search_term: term }
					);
				}
			}
			const form = event?.target;
			if ( pushed( ctx ) && form?.action ) {
				// Replace the dialog's history entry, so Back from the results returns to the page itself.
				event.preventDefault();
				const url = new URL( form.action, window.location.href );
				new FormData( form ).forEach( ( value, key ) =>
					url.searchParams.append( key, String( value ) )
				);
				window.location.replace( url.toString() );
			}
		},
		hold( event ) {
			event.preventDefault();
		},
		hover() {
			const ctx = getContext();
			if ( ctx.active !== ctx.row.index ) {
				setActive( ctx, ctx.row.index );
			}
		},
		choose() {
			const ctx = getContext();
			actions.pick(
				ctx.row,
				document.getElementById( `${ ctx.id }-input` )
			);
		},
		pick( row, input ) {
			const ctx = getContext();
			const form = formOf( input );
			if ( row.recent || row.query ) {
				ctx.query = row.name;
				ctx.typed = row.name;
				if ( input ) {
					input.value = row.name;
				}
				closePanel( ctx );
				form?.requestSubmit();
				return;
			}
			if ( row.all ) {
				closePanel( ctx );
				form?.requestSubmit();
				return;
			}
			actions.beacon( row );
			actions.selected( row );
			remember( ctx, row.scope ? row.value : ctx.query );
			closePanel( ctx );
			if ( row.url && pushed( ctx ) ) {
				window.location.replace( row.url );
			} else if ( row.url ) {
				window.location.assign( row.url );
			}
		},
		beacon( row ) {
			const ctx = getContext();
			// The log row is written after the suggest response, so the click names the query, not a row id
			// (docs/DECISIONS.md D-052).
			if (
				! ctx.answered ||
				! row.pid ||
				! window.navigator.sendBeacon
			) {
				return;
			}
			window.navigator.sendBeacon(
				ctx.click,
				new URLSearchParams( {
					q: ctx.answered,
					lang: pageLang( ctx.lang ),
					type: row.product ? 'product' : row.ctype || row.taxonomy,
					id: String( row.pid ),
				} )
			);
		},
		selected( row ) {
			const ctx = getContext();
			let type = row.ctype || row.taxonomy;
			if ( row.product ) {
				type = 'product';
			} else if ( row.scope ) {
				type = 'scope';
			}
			const id = row.scope ? row.cid : row.pid;
			emit(
				ctx,
				'select',
				{
					query: told( ctx, ctx.answered ),
					type,
					id,
					name: row.name,
					url: row.url,
					position: row.index,
				},
				row.product
					? {
							event: 'select_item',
							ecommerce: {
								item_list_id: LIST_ID,
								item_list_name: LIST_NAME,
								items: [ { ...item( row ), index: row.index } ],
							},
						}
					: {
							event: 'select_content',
							content_type: type,
							content_id: String( id ),
						}
			);
		},
		cartClick( event ) {
			const ctx = getContext();
			// The row itself goes to the product: the click stops here.
			event.stopPropagation();
			event.preventDefault();
			actions.cart( ctx.row );
		},
		*cart( row ) {
			const ctx = getContext();
			const p = priv( ctx );
			if ( ! ctx.cart || ! row?.cart || p.adding ) {
				return;
			}
			p.adding = true;
			let ok = false;
			try {
				// WooCommerce's own add-to-cart endpoint: a POST, so a page cache never answers it.
				const response = yield window.fetch( ctx.cart, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						Accept: 'application/json',
						'Content-Type':
							'application/x-www-form-urlencoded; charset=UTF-8',
					},
					body: new URLSearchParams( {
						product_id: String( row.pid ),
						quantity: '1',
					} ).toString(),
				} );
				const data = response.ok ? yield response.json() : null;
				ok = Boolean( data && ! data.error );
				if ( ok ) {
					refreshCart( data );
				}
			} catch {
				ok = false;
			} finally {
				p.adding = false;
			}
			ctx.live = fill(
				ok ? ctx.i18n.added : ctx.i18n.notAdded,
				row.name
			);
			if ( ! ok ) {
				return;
			}
			const layer = {
				event: 'add_to_cart',
				ecommerce: { items: [ { ...item( row ), quantity: 1 } ] },
			};
			if ( typeof row.price === 'number' && ctx.currency ) {
				layer.ecommerce.currency = ctx.currency;
				layer.ecommerce.value = row.price;
			}
			emit(
				ctx,
				'add-to-cart',
				{ id: row.pid, name: row.name, quantity: 1 },
				layer
			);
			if ( ctx.cartPage ) {
				// The shop sends shoppers to the cart after adding, as its own buttons do.
				window.location.assign( ctx.cartPage );
			}
		},
		dismiss() {
			closePanel( getContext() );
		},
		openDialog() {
			const ctx = getContext();
			const dialog = document.getElementById( `${ ctx.id }-dialog` );
			if ( ! dialog || dialog.open ) {
				return;
			}
			dialog.showModal();
			try {
				// Back closes the dialog instead of leaving the page. The step is built on the page's own state,
				// so it keeps the Interactivity API's `wpInteractivityId`: without it the runtime reloads the
				// page when Forward lands on this step.
				window.history.pushState(
					{ ...window.history.state, csearch: ctx.id },
					''
				);
			} catch {
				// History unavailable (a sandboxed frame): Escape and the close button still work.
			}
			// Focused in the click itself, so a phone raises its keyboard.
			document.getElementById( `${ ctx.id }-input` )?.focus();
		},
		closeDialog() {
			const ctx = getContext();
			document.getElementById( `${ ctx.id }-dialog` )?.close();
		},
		dialogClosed() {
			const ctx = getContext();
			closePanel( ctx );
			document.getElementById( `${ ctx.id }-open` )?.focus();
			if ( pushed( ctx ) ) {
				window.history.back();
			}
		},
		dialogClick( event ) {
			const dialog = event.currentTarget;
			const box = dialog.getBoundingClientRect();
			const outside =
				event.clientX < box.left ||
				event.clientX > box.right ||
				event.clientY < box.top ||
				event.clientY > box.bottom;
			// A click on the page behind the dialog (its backdrop) closes it; keyboard clicks have no position.
			if ( event.target === dialog && outside && event.detail > 0 ) {
				dialog.close();
			}
		},
		dialogBack() {
			const ctx = getContext();
			const dialog = document.getElementById( `${ ctx.id }-dialog` );
			if ( dialog?.open && ! pushed( ctx ) ) {
				dialog.close();
			}
		},
		outside( event ) {
			const ctx = getContext();
			const { ref } = getElement();
			if ( ctx.open && ref && ! ref.contains( event.target ) ) {
				closePanel( ctx );
			}
		},
	},
	callbacks: {
		classes() {
			const ctx = getContext();
			const { ref } = getElement();
			// Classes a plugin adds to a product's row (Mature Content's blur; handoff item 3), already
			// sanitised by the server. The ones the last row in this element had go first.
			const before = ( ref.dataset.csearchClasses || '' )
				.split( ' ' )
				.filter( Boolean );
			const now = Array.isArray( ctx.row.classes ) ? ctx.row.classes : [];
			ref.classList.remove( ...before );
			ref.classList.add( ...now );
			ref.dataset.csearchClasses = now.join( ' ' );
		},
		name() {
			const ctx = getContext();
			const { ref } = getElement();
			const row = ctx.row;
			const parts =
				row.product || row.term || row.content || row.query || row.scope
					? highlight( row.name, ctx.answered )
					: [ { text: row.name, mark: false } ];
			// Built as nodes, never markup: a product name is the shop's text, not HTML.
			ref.replaceChildren(
				...parts.map( ( part ) => {
					if ( ! part.mark ) {
						return document.createTextNode( part.text );
					}
					const mark = document.createElement( 'mark' );
					mark.textContent = part.text;
					return mark;
				} )
			);
		},
		price() {
			const ctx = getContext();
			const { ref } = getElement();
			// price_html is wc_price() output from the plugin's own same-origin REST route: trusted markup.
			ref.innerHTML = ctx.row.priceHtml || '';
		},
	},
} );

export { state, actions, callbacks };
