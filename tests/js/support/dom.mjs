/**
 * The few browser objects the view module touches, as plain JavaScript: an element registry for
 * `getElementById`, a `template` whose text is its markup without tags, storage, a beacon, timers that
 * follow node:test's mock clock, and a location that records where it was sent.
 */
export function installDom() {
	const elements = new Map();
	const storage = new Map();
	const dom = {
		elements,
		beacons: [],
		assigned: [],
		replaced: [],
		focused: null,
		backs: 0,
		fetch: null,
		events: [],
		bodyEvents: [],
	};
	class FakeElement {
		constructor( id = '' ) {
			this.id = id;
			this.value = '';
			this.form = {
				requestSubmit: () => ( dom.submitted = true ),
				dispatchEvent: ( event ) => dom.events.push( event ) > 0,
			};
			this.open = false;
			this.dataset = {};
			const classes = new Set();
			this.classList = {
				add: ( ...names ) => names.forEach( ( n ) => classes.add( n ) ),
				remove: ( ...names ) =>
					names.forEach( ( n ) => classes.delete( n ) ),
				contains: ( name ) => classes.has( name ),
				get size() {
					return classes.size;
				},
			};
		}
		showModal() {
			this.open = true;
		}
		close() {
			this.open = false;
		}
		contains( other ) {
			return other === this || other?.parent === this;
		}
		focus() {
			dom.focused = this.id;
		}
		scrollIntoView() {}
		getBoundingClientRect() {
			return { bottom: 100 };
		}
	}
	dom.element = ( id ) => {
		const el = new FakeElement( id );
		if ( id ) {
			elements.set( id, el );
		}
		return el;
	};
	globalThis.document = {
		documentElement: { lang: 'en-CA' },
		body: { dispatchEvent: ( event ) => dom.bodyEvents.push( event ) > 0 },
		getElementById: ( id ) => elements.get( id ) || null,
		createElement: () => {
			const t = { content: { textContent: '' } };
			Object.defineProperty( t, 'innerHTML', {
				set( html ) {
					t.content.textContent = String( html ).replace(
						/<[^>]*>/g,
						''
					);
				},
			} );
			return t;
		},
	};
	globalThis.window = {
		setTimeout: ( ...a ) => globalThis.setTimeout( ...a ),
		clearTimeout: ( ...a ) => globalThis.clearTimeout( ...a ),
		fetch: ( ...a ) => dom.fetch( ...a ),
		CustomEvent: class {
			constructor( type, init = {} ) {
				this.type = type;
				this.detail = init.detail;
				this.bubbles = Boolean( init.bubbles );
			}
		},
		innerHeight: 800,
		matchMedia: () => ( { matches: false } ),
		localStorage: {
			getItem: ( k ) => ( storage.has( k ) ? storage.get( k ) : null ),
			setItem: ( k, v ) => storage.set( k, String( v ) ),
			clear: () => storage.clear(),
		},
		navigator: {
			sendBeacon: ( url, body ) => dom.beacons.push( { url, body } ) > 0,
		},
		location: {
			href: 'http://shop.test/',
			assign: ( url ) => dom.assigned.push( url ),
			replace: ( url ) => dom.replaced.push( url ),
		},
		history: {
			state: null,
			pushState( state ) {
				this.state = state;
			},
			replaceState( state ) {
				this.state = state;
			},
			back() {
				this.state = null;
				dom.backs++;
			},
		},
	};
	return dom;
}
