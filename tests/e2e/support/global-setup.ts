import { execSync } from 'node:child_process';

/**
 * Once before every spec: a launched shop with the hero products indexed (tests/e2e/fixtures/shop.php) and
 * the default theme active.
 */
export default function globalSetup() {
	const run = ( command: string ) =>
		execSync( `npx wp-env run cli -- wp ${ command }`, {
			stdio: [ 'ignore', 'ignore', 'inherit' ],
		} );
	run(
		'eval-file wp-content/plugins/creative-search/tests/e2e/fixtures/shop.php'
	);
	run( 'theme activate twentytwentyfive' );
}
