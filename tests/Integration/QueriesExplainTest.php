<?php
/**
 * The search statements' plans.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Index\AnalyzerFactory;
use CreativeBranding\Search\Index\IndexQueue;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Tests\Fixtures\Catalogue;
use CreativeBranding\Search\Tests\Support\IndexTestCase;
use CreativeBranding\Search\Tests\Support\WooCommerceCatalogue;
use WP_REST_Request;

/**
 * docs/QUERIES.md as a test (spec: Performance → How): the statements a suggestion and a results page run,
 * caught as they run on the 300-product bilingual catalogue, each `EXPLAIN`ed. Every read of a plugin table
 * must have a key it can use. The plans are written to `tmp/explain.md` for the doc.
 *
 * @group benchmark
 */
final class QueriesExplainTest extends IndexTestCase {

	public function test_every_search_statement_has_a_key(): void {
		global $wpdb;
		add_filter( 'csearch_languages', array( WooCommerceCatalogue::class, 'bilingual' ) );
		$this->service( AnalyzerFactory::class )->flush();
		$this->catalogue->load( ( new Catalogue() )->generate( 300 ) );
		$this->service( IndexQueue::class )->clear();
		self::assertTrue( $this->service( Reindex::class )->runInline() );
		do_action( 'rest_api_init' );

		$caught = array();
		$spy    = static function ( $sql ) use ( &$caught ) {
			if ( is_string( $sql ) && str_starts_with( ltrim( $sql ), 'SELECT' ) && str_contains( $sql, 'csearch_' ) ) {
				// One plan per statement shape: values and lists of any length are the same statement.
				$shape            = (string) preg_replace( '/\(\?(,\s*\?)*\)/', '(…)', (string) preg_replace( '/\d+|\'[^\']*\'/', '?', $sql ) );
				$caught[ $shape ] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $spy );
		// An exact word, a prefix, a typo, a SKU with a number, French, and a results page.
		foreach ( array( array( 'kazumi', 'en' ), array( 'kaz', 'en' ), array( 'kazmui', 'en' ), array( 'deck 8.25', 'en' ), array( 'planches', 'fr' ) ) as [ $q, $lang ] ) {
			wp_cache_flush();
			$request = new WP_REST_Request( 'GET', '/csearch/v1/suggest' );
			$request->set_param( 'q', $q );
			$request->set_param( 'lang', $lang );
			self::assertSame( 200, rest_do_request( $request )->get_status() );
		}
		wp_cache_flush();
		$request = new WP_REST_Request( 'GET', '/csearch/v1/search' );
		$request->set_param( 'q', 'kazumi deck' );
		self::assertSame( 200, rest_do_request( $request )->get_status() );
		remove_filter( 'query', $spy );

		self::assertNotEmpty( $caught );
		$out   = array( '# EXPLAIN, ' . gmdate( 'Y-m-d' ) . ', 300 products in two languages', '' );
		$scans = array();
		foreach ( $caught as $sql ) {
			$plan  = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- a statement the plugin prepared, explained.
			$out[] = '```sql';
			$out[] = $sql;
			$out[] = '```';
			$out[] = '';
			$out[] = '| table | type | possible keys | key | rows | Extra |';
			$out[] = '| --- | --- | --- | --- | --- | --- |';
			foreach ( is_array( $plan ) ? $plan : array() as $row ) {
				$out[] = sprintf( '| %s | %s | %s | %s | %s | %s |', $row['table'] ?? '', $row['type'] ?? '', $row['possible_keys'] ?? '', $row['key'] ?? '', $row['rows'] ?? '', $row['Extra'] ?? '' );
				$table = (string) ( $row['table'] ?? '' );
				// A key must exist for every read; the optimiser may still read a small table whole when the
				// list covers much of it, which is cheaper than the key.
				if ( 'ALL' === ( $row['type'] ?? '' ) && null === ( $row['possible_keys'] ?? null ) && ( str_contains( $table, 'csearch_' ) || in_array( $table, array( 'p', 't', 'd', 'g' ), true ) ) ) {
					$scans[] = $table . ': ' . $sql;
				}
			}
			$out[] = '';
		}
		if ( is_dir( CSEARCH_PATH . 'tmp' ) || wp_mkdir_p( CSEARCH_PATH . 'tmp' ) ) {
			file_put_contents( CSEARCH_PATH . 'tmp/explain.md', implode( "\n", $out ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test report.
		}
		self::assertSame( array(), $scans, 'a plugin table read with no key to use' );
	}
}
