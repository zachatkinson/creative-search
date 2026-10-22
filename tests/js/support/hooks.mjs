/**
 * Module resolve hook: the Interactivity API is WordPress's at runtime; in tests it is the double next to
 * this file.
 *
 * @param {string}                                    specifier What the module imports.
 * @param {Object}                                    context   Node's resolve context.
 * @param {(s: string, c: Object) => Promise<Object>} next      The next resolver.
 * @return {Promise<Object>} The resolved module.
 */
export async function resolve( specifier, context, next ) {
	if ( specifier === '@wordpress/interactivity' ) {
		return {
			url: new URL( './interactivity.mjs', import.meta.url ).href,
			shortCircuit: true,
		};
	}
	return next( specifier, context );
}
