/**
 * A double of `@wordpress/interactivity` with the runtime's one behaviour the store relies on: an action
 * written as a generator runs as an async flow, each yielded promise resumed with its value or its error.
 */
export const scope = { ctx: null, ref: null };

function run( fn, args ) {
	return new Promise( ( resolve, reject ) => {
		const it = fn( ...args );
		const step = ( method, value ) => {
			let result;
			try {
				result = it[ method ]( value );
			} catch ( e ) {
				reject( e );
				return;
			}
			if ( result.done ) {
				resolve( result.value );
				return;
			}
			Promise.resolve( result.value ).then(
				( v ) => step( 'next', v ),
				( e ) => step( 'throw', e )
			);
		};
		step( 'next' );
	} );
}

export function store( namespace, definition ) {
	for ( const [ name, fn ] of Object.entries( definition.actions ) ) {
		if ( fn.constructor.name === 'GeneratorFunction' ) {
			definition.actions[ name ] = ( ...args ) => run( fn, args );
		}
	}
	return definition;
}

export const getContext = () => scope.ctx;
export const getElement = () => ( { ref: scope.ref } );
export const withScope = ( fn ) => fn;
