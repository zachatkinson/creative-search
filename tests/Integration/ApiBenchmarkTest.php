<?php
/**
 * The golden set through the API, and the API's budgets.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Domain\Synonym;
use CreativeBranding\Search\Index\AnalyzerFactory;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Repository\SynonymRepository;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Tests\Fixtures\Catalogue;
use CreativeBranding\Search\Tests\Fixtures\GoldenQueries;
use CreativeBranding\Search\Tests\Support\IndexTestCase;
use CreativeBranding\Search\Tests\Support\WooCommerceCatalogue;
use WP_REST_Request;

/**
 * Milestone 4's "the golden set through the API" and its budgets (spec: Security, performance → Performance):
 * `/suggest` p95 within 60 ms warm and 100 ms cold, `/search` within 100 ms, 4 MB above the baseline. The
 * catalogue (300 products by default, `CSEARCH_BENCH_PRODUCTS` to change) is written into WooCommerce in two
 * languages, the way skatehouse-core will answer `csearch_languages`, with the golden set's synonyms. Times are
 * measured around `rest_do_request()`: the route's own work, without WordPress's bootstrap.
 *
 * @group benchmark
 */
final class ApiBenchmarkTest extends IndexTestCase {

	public function test_the_golden_set_through_the_api_within_budget(): void {
		$count = max( 24, (int) ( getenv( 'CSEARCH_BENCH_PRODUCTS' ) ?: 300 ) );
		add_filter( 'csearch_languages', array( WooCommerceCatalogue::class, 'bilingual' ) );
		$this->service( AnalyzerFactory::class )->flush();
		$specs = ( new Catalogue() )->generate( $count );
		$ids   = $this->catalogue->load( $specs );
		$skus  = array();
		foreach ( $specs as $spec ) {
			$skus[ $ids[ (int) $spec['id'] ] ] = (string) $spec['sku'];
		}
		$this->service( \CreativeBranding\Search\Index\IndexQueue::class )->clear();
		foreach ( GoldenQueries::load()['synonyms'] as $row ) {
			$this->service( SynonymRepository::class )->add( new Synonym( $row['lang'], $row['word'], $row['means'], $row['both_ways'] ) );
		}
		self::assertTrue( $this->service( Reindex::class )->runInline() );
		$this->analyzeAside( self::$analyzed );
		do_action( 'rest_api_init' );

		$failures = array();
		$cold     = array();
		$warm     = array();
		$search   = array();
		$memory   = array();
		foreach ( GoldenQueries::queries() as $q ) {
			$e = $q['expect'];
			Settings::set( 'hidden_by_sku', $e['hidden_only'] ? 'yes' : 'no' );
			wp_cache_flush();
			$baseline = memory_get_usage();
			memory_reset_peak_usage();
			$start    = hrtime( true );
			$data     = $this->get( '/suggest', array( 'q' => $q['q'], 'lang' => $q['lang'] ) );
			$cold[]   = ( hrtime( true ) - $start ) / 1e6;
			$memory[] = ( memory_get_peak_usage() - $baseline ) / 1048576;
			$start    = hrtime( true );
			$this->get( '/suggest', array( 'q' => $q['q'], 'lang' => $q['lang'] ) );
			$warm[]   = ( hrtime( true ) - $start ) / 1e6;
			wp_cache_flush();
			$start    = hrtime( true );
			$ranked   = $this->get( '/search', array( 'q' => $q['q'], 'lang' => $q['lang'], 'per_page' => 100 ) );
			$search[] = ( hrtime( true ) - $start ) / 1e6;

			$shown = array_map( static fn( array $p ): string => $skus[ $p['id'] ] ?? '?', $data['products'] );
			$all   = array_map( static fn( int $id ): string => $skus[ $id ] ?? '?', $ranked['ids'] );
			$terms = array_column( $data['terms'], 'name' );
			$wrong = array();
			if ( $e['zero'] && ( array() !== $shown || array() !== $terms ) ) {
				$wrong[] = 'expected nothing';
			}
			if ( null !== $e['top'] && ( $all[0] ?? '' ) !== $e['top'] ) {
				$wrong[] = "top should be {$e['top']}";
			}
			foreach ( $e['products'] as $sku ) {
				if ( ! in_array( $sku, $shown, true ) ) {
					$wrong[] = "{$sku} missing from the suggestions";
				}
			}
			foreach ( $e['absent'] as $sku ) {
				if ( in_array( $sku, $all, true ) ) {
					$wrong[] = "{$sku} must be absent";
				}
			}
			foreach ( $e['terms'] as $term ) {
				if ( ! in_array( $term, $terms, true ) ) {
					$wrong[] = "term {$term} missing";
				}
			}
			if ( null !== $e['corrected'] && $data['corrected'] !== $e['corrected'] ) {
				$wrong[] = "corrected should be \"{$e['corrected']}\"";
			}
			if ( array() !== $wrong ) {
				$failures[] = sprintf( "%s [%s, %s]: %s\n    got %s | terms %s | corrected %s", $q['q'], $q['kind'], $q['lang'], implode( '; ', $wrong ), implode( ', ', $shown ), implode( ', ', $terms ), var_export( $data['corrected'], true ) );
			}
		}
		remove_filter( 'csearch_languages', array( WooCommerceCatalogue::class, 'bilingual' ) );

		fwrite(
			STDERR,
			sprintf(
				"\n[benchmark] %d products, %d queries: /suggest p95 cold %.1f ms (budget 100), warm %.1f ms (budget 60); /search p95 %.1f ms (budget 100); peak memory p95 %.2f MB (budget 4)\n",
				$count,
				count( $cold ),
				self::p95( $cold ),
				self::p95( $warm ),
				self::p95( $search ),
				self::p95( $memory )
			)
		);
		if ( $count <= 300 ) {
			// The golden set is scored at the shop's size; at 10,000 the stock penalty moves six heroes (D-032).
			self::assertSame( array(), $failures, "Golden queries failing through the API:\n" . implode( "\n", $failures ) );
		}
		self::assertLessThan( 100.0, self::p95( $cold ) );
		self::assertLessThan( 60.0, self::p95( $warm ) );
		self::assertLessThan( 100.0, self::p95( $search ) );
		self::assertLessThan( 4.0, self::p95( $memory ) );
	}

	/**
	 * GET a route and return its data.
	 *
	 * @param string               $route  Route under csearch/v1.
	 * @param array<string, mixed> $params Params.
	 * @return array<string, mixed>
	 */
	private function get( string $route, array $params ): array {
		$request = new WP_REST_Request( 'GET', '/csearch/v1' . $route );
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}
		$response = rest_do_request( $request );
		self::assertSame( 200, $response->get_status(), $route . ' ' . wp_json_encode( $params ) . ' ' . wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		self::assertIsArray( $data );
		return $data;
	}

	/**
	 * The 95th percentile.
	 *
	 * @param float[] $values Values.
	 */
	private static function p95( array $values ): float {
		sort( $values );
		return $values[ (int) max( 0, ceil( 0.95 * count( $values ) ) - 1 ) ];
	}
}
