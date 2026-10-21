<?php
/**
 * Fails when coverage is under the spec's floors: 100 % line coverage of src/Domain, 90 % overall
 * (Quality bar → Unit tests). Reads PHPUnit's Clover XML.
 *
 * Run: php tools/coverage-floor.php tmp/coverage.xml
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

$csearch_file = $argv[1] ?? 'tmp/coverage.xml';
if ( ! is_readable( $csearch_file ) ) {
	fwrite( STDERR, "No coverage file at {$csearch_file}.\n" );
	exit( 1 );
}
$csearch_xml = simplexml_load_file( $csearch_file );
if ( false === $csearch_xml ) {
	fwrite( STDERR, "Unreadable coverage file.\n" );
	exit( 1 );
}

$csearch_total  = array( 'statements' => 0, 'covered' => 0 );
$csearch_domain = array( 'statements' => 0, 'covered' => 0 );
foreach ( $csearch_xml->xpath( '//file' ) ?: array() as $csearch_node ) {
	$csearch_metrics = $csearch_node->metrics;
	$csearch_s       = (int) $csearch_metrics['statements'];
	$csearch_c       = (int) $csearch_metrics['coveredstatements'];
	$csearch_total['statements'] += $csearch_s;
	$csearch_total['covered']    += $csearch_c;
	if ( str_contains( (string) $csearch_node['name'], '/src/Domain/' ) ) {
		$csearch_domain['statements'] += $csearch_s;
		$csearch_domain['covered']    += $csearch_c;
	}
}

$csearch_pct = static fn( array $m ): float => $m['statements'] > 0 ? 100.0 * $m['covered'] / $m['statements'] : 100.0;
printf( "Overall: %.2f%% (%d/%d). src/Domain: %.2f%% (%d/%d).\n", $csearch_pct( $csearch_total ), $csearch_total['covered'], $csearch_total['statements'], $csearch_pct( $csearch_domain ), $csearch_domain['covered'], $csearch_domain['statements'] );

$csearch_status = 0;
if ( $csearch_pct( $csearch_total ) < 90.0 ) {
	fwrite( STDERR, "Overall coverage is under 90%.\n" );
	$csearch_status = 1;
}
if ( $csearch_pct( $csearch_domain ) < 100.0 ) {
	fwrite( STDERR, "src/Domain coverage is under 100%.\n" );
	$csearch_status = 1;
}
exit( $csearch_status );
