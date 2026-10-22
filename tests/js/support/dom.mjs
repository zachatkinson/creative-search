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
		fetch: null,
	};
	class FakeElement {
		constructor( id = '' ) {
			this.id = id;
			this.value = '';
			this.form = { requestSubmit: () => ( dom.submitted = true ) };
		}
		contains( other ) {
			return other === this || other?.parent === this;
		}
		focus() {}
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
		location: { assign: ( url ) => dom.assigned.push( url ) },
	};
	return dom;
}
