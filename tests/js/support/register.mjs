/**
 * Loaded with `node --import`: routes `@wordpress/interactivity` to the test double, so the block's view
 * module can be imported as it is shipped (docs/DECISIONS.md D-060).
 */
import { register } from 'node:module';

register( './hooks.mjs', import.meta.url );
