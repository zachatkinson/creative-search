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

function closePanel( ctx ) {
	ctx.open = false;
	ctx.panel = false;
	ctx.searching = false;
	ctx.empty = false;
	setActive( ctx, -1 );
}

function placePanel( ctx, input ) {
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

function showAnswer( ctx, data, input ) {
	const products = Array.isArray( data.products ) ? data.products : [];
	const terms = ctx.terms && Array.isArray( data.terms ) ? data.terms : [];
	const rows = [];
	products.forEach( ( p ) => rows.push( productRow( ctx, p, rows.length ) ) );
	terms.forEach( ( t ) => rows.push( termRow( ctx, t, rows.length ) ) );
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
	ctx.searching = false;
	ctx.empty = count === 0;
	ctx.live = ctx.i18n.counts[ Math.min( count, ctx.i18n.counts.length - 1 ) ];
	setActive( ctx, -1 );
	openPanel( ctx, input );
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
						if ( ! event.altKey && ctx.open && n ) {
							setActive( ctx, 0 );
						}
					} else if ( ! event.altKey && n ) {
						setActive( ctx, ( ctx.active + 1 ) % n );
					}
					break;
				case 'ArrowUp':
					event.preventDefault();
					if ( ! ctx.open ) {
						actions.show( input );
						if ( ctx.open && n ) {
							setActive( ctx, n - 1 );
						}
					} else if ( n ) {
						// With no row chosen, Up goes to the last row (APG).
						setActive(
							ctx,
							ctx.active < 0 ? n - 1 : ( ctx.active - 1 + n ) % n
						);
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
					event.preventDefault();
					if ( ctx.open ) {
						closePanel( ctx );
					} else if ( ctx.query ) {
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
			ctx.rows = [];
			ctx.live = '';
			closePanel( ctx );
			const input = document.getElementById( `${ ctx.id }-input` );
			if ( input ) {
				input.value = '';
				input.focus();
			}
		},
		submit() {
			const ctx = getContext();
			remember( ctx, ctx.query );
			closePanel( ctx );
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
			if ( row.url ) {
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
					type: row.product ? 'product' : row.taxonomy,
					id: String( row.pid ),
				} )
			);
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
		price() {
			const ctx = getContext();
			const { ref } = getElement();
			// price_html is wc_price() output from the plugin's own same-origin REST route: trusted markup.
			ref.innerHTML = ctx.row.priceHtml || '';
		},
	},
} );

export { state, actions, callbacks };
