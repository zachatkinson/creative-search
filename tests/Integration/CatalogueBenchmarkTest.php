<?php
/**
 * The index and API budgets, on one catalogue.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Domain\Synonym;
use CreativeBranding\Search\Index\AnalyzerFactory;
use CreativeBranding\Search\Index\IndexQueue;
use CreativeBranding\Search\Index\IndexState;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Repository\LogRepository;
use CreativeBranding\Search\Repository\SynonymRepository;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Clock;
use CreativeBranding\Search\Support\Tables;
use CreativeBranding\Search\Tests\Fixtures\Catalogue;
use CreativeBranding\Search\Tests\Fixtures\GoldenQueries;
use CreativeBranding\Search\Tests\Support\IndexTestCase;
use CreativeBranding\Search\Tests\Support\WooCommerceCatalogue;
use WP_REST_Request;

/**
 * The spec's budgets, on one catalogue loaded once (`CSEARCH_BENCH_PRODUCTS`, default 300, the shop's size):
 * loading 10,000 products through WooCommerce's CRUD takes far longer than anything it measures, so the two
 * measurements share it, and it is loaded quietly ({@see WooCommerceCatalogue::load()}): term counts deferred to
 * the end and the plugin's index hooks detached, which keeps the cost a product flat as the catalogue grows.
 *
 * 1. A full reindex in English within 5 minutes for 10,000 products, scaled linearly (30 ms a product, so 9 s
 *    for 300). The one-product budget (50 ms) is an assertion in the regular suite.
 * 2. Milestone 4's golden set through the API, with the catalogue in two languages the way skatehouse-core
 *    answers `csearch_languages` and the golden set's synonyms (spec: Security, performance → Performance):
 *    `/suggest` p95 within 60 ms warm and 100 ms cold, `/search` within 100 ms, 4 MB above the baseline. Times
 *    are measured around `rest_do_request()`: the route's own work, without WordPress's bootstrap. The search
 *    log holds each golden query from a results page 3 times and 10,000 other rows, so every answer pays for
 *    its suggested searches (D-112) and its category scopes (D-113), and the owner has 200 redirects and 200
 *    pinned searches, the most of each (D-108, D-110).
 *
 * Each phase writes its time to STDERR as it ends, so a slow run shows where the time went.
 *
 * @group benchmark
 */
final class CatalogueBenchmarkTest extends IndexTestCase {

	/**
	 * Timings of each cold request; the fastest is kept (D-129).
	 */
	private const TIMINGS = 3;

	/**
	 * When the test began, for the phase lines.
	 *
	 * @var int
	 */
	private int $began = 0;

	public function test_the_index_and_api_budgets_on_one_catalogue(): void {
		$count       = max( 24, (int) ( getenv( 'CSEARCH_BENCH_PRODUCTS' ) ?: 300 ) );
		$this->began = hrtime( true );

		$start = hrtime( true );
		$specs = ( new Catalogue() )->generate( $count );
		$ids   = $this->catalogue->load( $specs, true, true );
		$this->service( IndexQueue::class )->clear();
		$this->phase( sprintf( 'loaded %d products through WooCommerce', $count ), $start );

		// 1. The full reindex, in English.
		$start = hrtime( true );
		self::assertTrue( $this->service( Reindex::class )->runInline() );
		$seconds = ( hrtime( true ) - $start ) / 1e9;
		$budget  = 300.0 * $count / 10000;
		$state   = $this->service( IndexState::class )->all();
		$this->phase(
			sprintf(
				'reindex %.2f s (budget %.1f s), %.1f ms a product; %d documents, %d postings, %d terms; %d errors',
				$seconds,
				$budget,
				1000 * $seconds / $count,
				$this->rowCount( Tables::documents() ),
				$this->rowCount( Tables::postings() ),
				$this->rowCount( Tables::terms() ),
				count( (array) $state[ IndexState::ERRORS ] )
			),
			$start
		);
		self::assertSame( array(), $state[ IndexState::ERRORS ] );
		self::assertSame( $count, $state[ IndexState::DONE ] );
		self::assertLessThan( $budget, $seconds );

		// 2. The golden set through the API, in two languages.
		$start = hrtime( true );
		add_filter( 'csearch_languages', array( WooCommerceCatalogue::class, 'bilingual' ) );
		$this->service( AnalyzerFactory::class )->flush();
		$skus = array();
		foreach ( $specs as $spec ) {
			$skus[ $ids[ (int) $spec['id'] ] ] = (string) $spec['sku'];
		}
		foreach ( GoldenQueries::load()['synonyms'] as $row ) {
			$this->service( SynonymRepository::class )->add( new Synonym( $row['lang'], $row['word'], $row['means'], $row['both_ways'] ) );
		}
		// Pages and posts on, as Skatehouse runs: each answer then makes a second pass over them (D-093).
		$this->content( max( 10, intdiv( $count, 100 ) ) );
		Settings::update(
			array(
				'index_pages' => 'yes',
				'index_posts' => 'yes',
			)
		);
		self::assertTrue( $this->service( Reindex::class )->runInline() );
		$this->analyzeAside( self::$analyzed );
		do_action( 'rest_api_init' );
		$this->phase( 'reindexed in English and French, with the synonyms, pages and posts', $start );
		$start = hrtime( true );
		$this->seedLog();
		// The most owner rules of each kind (D-108, D-110), none of them a golden query: every answer looks its
		// words up among them (1.3 review M7).
		$redirects = array();
		$pins      = array();
		$products  = array_values( $ids );
		for ( $i = 0; $i < Settings::QUERY_RULES_MAX; $i++ ) {
			$redirects[] = array(
				'query' => 'owner page ' . $i,
				'lang'  => 0 === $i % 2 ? '' : 'en',
				'url'   => home_url( '/page-' . $i . '/' ),
			);
			$pins[]      = array(
				'query' => 'owner pick ' . $i,
				'lang'  => 0 === $i % 2 ? '' : 'fr',
				'ids'   => array( $products[ $i % count( $products ) ] ),
			);
		}
		Settings::update(
			array(
				'query_redirects' => $redirects,
				'pinned_products' => $pins,
			)
		);
		self::assertCount( Settings::QUERY_RULES_MAX, Settings::get( 'pinned_products' ) );
		$this->phase( 'search log seeded, 200 redirects and 200 pins', $start );

		$queried  = hrtime( true );
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
			$times    = array( ( hrtime( true ) - $start ) / 1e6 );
			$memory[] = ( memory_get_peak_usage() - $baseline ) / 1048576;
			$start    = hrtime( true );
			$this->get( '/suggest', array( 'q' => $q['q'], 'lang' => $q['lang'] ) );
			$warm[] = ( hrtime( true ) - $start ) / 1e6;
			// A shared runner only ever adds time: each cold request is timed three times, the cache emptied
			// before each, and the fastest kept (D-129). The p95 is still taken over the queries.
			for ( $i = 1; $i < self::TIMINGS; $i++ ) {
				wp_cache_flush();
				$start   = hrtime( true );
				$this->get( '/suggest', array( 'q' => $q['q'], 'lang' => $q['lang'] ) );
				$times[] = ( hrtime( true ) - $start ) / 1e6;
			}
			$cold[] = min( $times );
			$times  = array();
			for ( $i = 0; $i < self::TIMINGS; $i++ ) {
				wp_cache_flush();
				$start   = hrtime( true );
				$ranked  = $this->get( '/search', array( 'q' => $q['q'], 'lang' => $q['lang'], 'per_page' => 100 ) );
				$times[] = ( hrtime( true ) - $start ) / 1e6;
			}
			$search[] = min( $times );

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
		Settings::update(
			array(
				'index_pages' => 'no',
				'index_posts' => 'no',
			)
		);
		$this->phase(
			sprintf(
				'%d queries, the fastest of %d cold timings each: /suggest p95 cold %.1f ms (budget 100), warm %.1f ms (budget 60); /search p95 %.1f ms (budget 100); peak memory p95 %.2f MB (budget 4)',
				count( $cold ),
				self::TIMINGS,
				self::p95( $cold ),
				self::p95( $warm ),
				self::p95( $search ),
				self::p95( $memory )
			),
			$queried
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
	 * Pages under a few parents and as many posts, worded like a skate shop's, so the content pass has terms
	 * that overlap the catalogue's (deck, wheels, size).
	 *
	 * @param int $count Pages, and posts.
	 */
	private function content( int $count ): void {
		$words   = array( 'deck', 'wheels', 'size', 'guide', 'shipping', 'returns', 'griptape', 'bearings', 'trucks', 'kazumi' );
		$parents = array();
		foreach ( array( 'Size guides', 'Help', 'About' ) as $title ) {
			$parents[] = self::factory()->post->create(
				array(
					'post_type'   => 'page',
					'post_title'  => $title,
					'post_status' => 'publish',
				)
			);
		}
		for ( $i = 0; $i < $count; $i++ ) {
			$text = $words[ $i % 10 ] . ' ' . $words[ ( $i * 3 + 1 ) % 10 ] . ' ' . $words[ ( $i * 7 + 2 ) % 10 ];
			foreach ( array( 'page', 'post' ) as $type ) {
				self::factory()->post->create(
					array(
						'post_type'    => $type,
						'post_title'   => ucfirst( $text ) . ' ' . $i,
						'post_content' => str_repeat( $text . '. ', 40 ),
						'post_status'  => 'publish',
						'post_parent'  => 'page' === $type ? $parents[ $i % 3 ] : 0,
					)
				);
			}
		}
		$this->service( IndexQueue::class )->clear();
	}

	/**
	 * Write one phase's line: what it did, how long it took, and the time since the test began.
	 *
	 * @param string $what  What the phase did.
	 * @param int    $start When it began (hrtime).
	 */
	private function phase( string $what, int $start ): void {
		$now = hrtime( true );
		fwrite( STDERR, sprintf( "\n[benchmark] %s (%.1f s; %.1f s in all)\n", $what, ( $now - $start ) / 1e9, ( $now - $this->began ) / 1e9 ) );
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

	/**
	 * The search log a shop has after a while: each golden query searched three times from a results page (so
	 * each is a suggested search for its own prefix) and 10,000 other searches, within the retention window.
	 */
	private function seedLog(): void {
		$log  = $this->service( LogRepository::class );
		$when = Clock::format( Clock::timestamp() - DAY_IN_SECONDS );
		$rows = array();
		foreach ( GoldenQueries::queries() as $q ) {
			$normalised = mb_strtolower( trim( (string) $q['q'] ), 'UTF-8' );
			for ( $i = 0; $i < 3; $i++ ) {
				$rows[] = array(
					'query'      => (string) $q['q'],
					'normalised' => $normalised,
					'lang'       => (string) $q['lang'],
					'results'    => 1,
					'source'     => 'page',
					'created_at' => $when,
				);
			}
		}
		for ( $i = 0; $i < 10000; $i++ ) {
			$rows[] = array(
				'query'      => 'noise ' . $i,
				'normalised' => 'noise ' . $i,
				'lang'       => 0 === $i % 2 ? 'en' : 'fr',
				'results'    => $i % 3,
				'source'     => 0 === $i % 4 ? 'page' : 'suggest',
				'created_at' => $when,
			);
		}
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$log->insert( $chunk );
		}
	}
}
