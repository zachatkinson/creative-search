/**
 * The search block's state machine, as an Interactivity API store. The server renders the whole form
 * (it submits with no script); this turns it into the APG list-autocomplete combobox: suggestions after
 * 150 ms of quiet, one request in flight, stale answers dropped by sequence number, the last 20 answers
 * cached per page, keyboard parity, a polite live region, and the click beacon.
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

const DEBOUNCE = 150;
const SLOW = 400;
const CACHE = 20;
const RECENT = 5;
const RECENT_KEY = 'csearch_recent';
const SPACE_FOR_PANEL = 240;
// Baymard: at most 10 suggestions on a desktop, 4 to 8 on a phone, and never a scrollbar (D-092).
const MOST = 10;
const PHONE = '(max-width: 600px), (pointer: coarse)';
const PHONE_PRODUCTS = 4;
const PHONE_TERMS = 2;
const TERMS = 3;
// Posts and pages, after the products, at most (Skatehouse handoff item 7).
const CONTENT = 2;
// Frames fit() waits for the rows to render before it gives up measuring.
const FIT_TRIES = 10;
// Panels fit() re-measures when their size changes (a web font arriving, an image loading).
const watched = new WeakSet();

/**
 * Per-instance bookkeeping the context should not carry (timers, the abort controller, the cache).
 *
 * @type {Map<string, {seq: number, timer: number, slow: number, ctrl: AbortController|null, cache: Map<string, Object>, last: Object|null}>}
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
	if ( row ) {
		document
			.getElementById( row.id )
			?.scrollIntoView( { block: 'nearest' } );
	}
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
	ctx.query = row && ! row.all ? row.name : ctx.typed;
}

function closePanel( ctx ) {
	ctx.open = false;
	ctx.panel = false;
	ctx.searching = false;
	ctx.empty = false;
	setActive( ctx, -1 );
}

function placePanel( ctx, input ) {
	if ( ctx.dialog ) {
		ctx.above = false; // In the dialog the panel always sits under the field.
		return;
	}
	if ( ctx.position !== 'auto' ) {
		ctx.above = ctx.position === 'above';
		return;
	}
	const coarse =
		window.matchMedia && window.matchMedia( '(pointer: coarse)' ).matches;
	if ( ! coarse || ! input ) {
		ctx.above = false;
		return;
	}
	const viewport = window.visualViewport
		? window.visualViewport.height
		: window.innerHeight;
	ctx.above =
		viewport - input.getBoundingClientRect().bottom < SPACE_FOR_PANEL;
}

function openPanel( ctx, input ) {
	placePanel( ctx, input );
	ctx.open = true;
	ctx.panel = ctx.rows.length > 0 || ctx.empty || ctx.searching;
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
		stock: stock || null,
		out: ctx.stock && ! p.in_stock,
		meta: Boolean( price || stock ),
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
 * How many products, terms and posts or pages the panel shows: the owner's limit, at most 10 rows in all on a
 * desktop with terms giving way first, 4 products and 2 terms on a phone (D-092), and up to 2 posts or pages
 * after them (Skatehouse handoff item 7).
 *
 * @param {Object} ctx     The block's context.
 * @param {number} content Posts and pages in the answer.
 * @return {{products: number, terms: number, content: number}} The budget.
 */
function budget( ctx, content = 0 ) {
	const phone = Boolean( window.matchMedia?.( PHONE ).matches );
	const pages = Math.min( CONTENT, content );
	const products = Math.min(
		ctx.limit,
		phone ? PHONE_PRODUCTS : MOST - pages
	);
	const terms = phone
		? PHONE_TERMS
		: Math.min( TERMS, MOST - products - pages );
	return {
		products,
		terms: ctx.terms ? Math.max( 0, terms ) : 0,
		content: pages,
	};
}

function liveWords( ctx, count ) {
	const words =
		ctx.i18n.counts[ Math.min( count, ctx.i18n.counts.length - 1 ) ];
	return ctx.corrected ? `${ words }. ${ ctx.corrected }` : words;
}

/**
 * Drop the last suggestions until the open panel fits the visible viewport, so it never needs a scrollbar
 * (D-092). Runs after each render; "See all" always stays.
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
			if ( box.bottom <= top + height && box.top >= top ) {
				return;
			}
			const drop = suggestions[ suggestions.length - 1 ];
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
	const found = Array.isArray( data.content ) ? data.content : [];
	const most = budget( ctx, found.length );
	const products = (
		Array.isArray( data.products ) ? data.products : []
	).slice( 0, most.products );
	const terms = ( Array.isArray( data.terms ) ? data.terms : [] ).slice(
		0,
		most.terms
	);
	const rows = [];
	products.forEach( ( p ) => rows.push( productRow( ctx, p, rows.length ) ) );
	terms.forEach( ( t ) => rows.push( termRow( ctx, t, rows.length ) ) );
	found
		.slice( 0, most.content )
		.forEach( ( c, i ) =>
			rows.push( contentRow( ctx, c, rows.length, i === 0 ) )
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
			const cached = p.cache.get( q );
			if ( cached ) {
				p.cache.delete( q );
				p.cache.set( q, cached );
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
				p.cache.set( q, data );
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
						actions.pick( ctx.rows[ ctx.active ], input );
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
			if ( row.recent ) {
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
			remember( ctx, ctx.query );
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
				// Back closes the dialog instead of leaving the page.
				window.history.pushState( { csearch: ctx.id }, '' );
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
				row.product || row.term || row.content
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
