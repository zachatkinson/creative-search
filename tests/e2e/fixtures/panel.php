<?php
/**
 * Browser-test fixture for the panel's 1.3 rows: "kazumi deck" searched three times from a results page, so it
 * is a suggested search (D-112), with every other search the log held removed, and every panel setting on. Run
 * with `wp eval-file` after shop.php.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

use CreativeBranding\Search\Plugin;
use CreativeBranding\Search\Repository\LogRepository;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Clock;

$csearch_log   = Plugin::container()->get( LogRepository::class );
$csearch_since = Clock::format( Clock::timestamp() - Settings::retentionDays() * DAY_IN_SECONDS );
// Only this fixture's searches: the other specs' searches fill the log as they run.
while ( $csearch_log->purge( Clock::format( Clock::timestamp() + DAY_IN_SECONDS ), 1000 ) > 0 ) {
	continue;
}
if ( array() === $csearch_log->completions( 'kazumi deck', 'en', $csearch_since, 3, 1 ) ) {
	$csearch_log->insert(
		array_fill(
			0,
			3,
			array(
				'query'      => 'kazumi deck',
				'normalised' => 'kazumi deck',
				'lang'       => 'en',
				'results'    => 3,
				'source'     => 'page',
				'created_at' => Clock::now(),
			)
		)
	);
}
Settings::update(
	array(
		'suggest_queries'  => 'yes',
		'suggest_scopes'   => 'yes',
		'suggest_cart'     => 'yes',
		'analytics_events' => 'yes',
	)
);
do_action( 'csearch_flush_cache' );
