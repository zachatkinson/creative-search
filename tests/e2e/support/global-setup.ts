import { execSync } from 'node:child_process';

/**
 * Once before every spec: a launched shop with the hero products indexed (tests/e2e/fixtures/shop.php), the
 * default theme active, an empty search log and suggested searches off. The specs' own searches fill the log
 * as they run, so suggested searches (D-112) would lead the panel in specs written before them; panel.spec.ts
 * turns them on with a log of its own.
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
	run( 'csearch log purge --days=0' );
	run( 'option patch update csearch_settings suggest_queries no' );
	run( 'eval "do_action( \'csearch_flush_cache\' );"' );
}
