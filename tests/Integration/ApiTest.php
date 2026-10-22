<?php
/**
 * REST API tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Index\Indexer;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Repository\LogRepository;
use CreativeBranding\Search\Search\SearchLog;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Tables;
use CreativeBranding\Search\Tests\Support\IndexTestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Route schemas, caps, 429s, cache headers, the deferred log write and redaction, clicks, status.
 */
final class ApiTest extends IndexTestCase {

	/**
	 * Product ids by catalogue index.
	 *
	 * @var array<int, int>
	 */
	private array $ids = array();

	public function set_up(): void {
		parent::set_up();
		$indexes   = array( 0, 1, 2, 6, 7, 15, 20, 21 );
		$this->ids = array_combine( $indexes, $this->products( $indexes ) );
		$this->service( Reindex::class )->runInline();
		do_action( 'rest_api_init' );
	}

	/**
	 * Dispatch a request.
	 *
	 * @param string               $method  Method.
	 * @param string               $route   Route under csearch/v1.
	 * @param array<string, mixed> $params  Params.
	 * @param array<string, string> $headers Headers.
	 */
	private function call( string $method, string $route, array $params = array(), array $headers = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/csearch/v1' . $route );
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}
		foreach ( $headers as $k => $v ) {
			$request->set_header( $k, $v );
		}
		return rest_do_request( $request );
	}

	public function test_suggest_answers_from_the_index(): void {
		$response = $this->call( 'GET', '/suggest', array( 'q' => 'kazumi dea' ) );
		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertSame( $this->ids[0], $data['products'][0]['id'] );
		self::assertSame( 'KAZUMI Skate Deck, Dealer', $data['products'][0]['name'] );
		self::assertSame( get_permalink( $this->ids[0] ), $data['products'][0]['url'] );
		self::assertStringContainsString( '84.99', $data['products'][0]['price_html'] );
		self::assertTrue( $data['products'][0]['in_stock'] );
		self::assertSame( 'product', $data['products'][0]['type'] );
		self::assertNull( $data['products'][0]['image'], 'no image in the fixture' );
		self::assertSame( 'kazumi dea', $data['query'] );
		self::assertSame( 'en', $data['lang'] );
		self::assertNull( $data['corrected'] );
		self::assertSame( 1, $data['total'] );

		$terms = $this->call( 'GET', '/suggest', array( 'q' => 'decks' ) )->get_data()['terms'];
		self::assertSame( 'Decks', $terms[0]['name'] );
		self::assertSame( 'category', $terms[0]['taxonomy'] );
		self::assertSame( get_term_link( (int) $terms[0]['id'], 'product_cat' ), $terms[0]['url'] );

		$range = $this->call( 'GET', '/suggest', array( 'q' => 'orbit' ) )->get_data();
		self::assertSame( 2, $range['total'] );
	}

	public function test_empty_long_and_unknown_language_queries(): void {
		$empty = $this->call( 'GET', '/suggest', array( 'q' => '   ' ) );
		self::assertSame( 200, $empty->get_status() );
		self::assertSame( array(), $empty->get_data()['products'] );
		self::assertSame( 0, $empty->get_data()['total'] );

		$long = $this->call( 'GET', '/suggest', array( 'q' => str_repeat( 'deck ', 80 ) ) )->get_data();
		self::assertLessThanOrEqual( 200, mb_strlen( $long['query'] ) );

		self::assertSame( 'en', $this->call( 'GET', '/suggest', array( 'q' => 'deck', 'lang' => 'de' ) )->get_data()['lang'], 'an unindexed language falls back' );
		self::assertSame( 'en', $this->call( 'GET', '/suggest', array( 'q' => 'deck', 'lang' => 'en_CA' ) )->get_data()['lang'] );
	}

	public function test_schemas_reject_bad_arguments(): void {
		self::assertSame( 400, $this->call( 'GET', '/suggest', array( 'q' => 'deck', 'limit' => 11 ) )->get_status() );
		self::assertSame( 400, $this->call( 'GET', '/suggest', array( 'q' => 'deck', 'limit' => 0 ) )->get_status() );
		self::assertSame( 400, $this->call( 'GET', '/suggest', array( 'q' => 'deck', 'lang' => '<script>' ) )->get_status() );
		self::assertSame( 400, $this->call( 'GET', '/search', array( 'q' => 'deck', 'per_page' => 101 ) )->get_status() );
		self::assertSame( 400, $this->call( 'GET', '/search', array( 'q' => 'deck', 'orderby' => 'price' ) )->get_status() );
		self::assertSame( 400, $this->call( 'POST', '/click', array( 'q' => 'deck', 'type' => 'order', 'id' => 1 ) )->get_status() );
		self::assertSame( 400, $this->call( 'POST', '/click', array( 'q' => 'deck', 'type' => 'product' ) )->get_status(), 'id is required' );

		$options = $this->call( 'OPTIONS', '/suggest' )->get_data();
		self::assertSame( 'csearch-suggest', $options['schema']['title'] );
		self::assertArrayHasKey( 'q', $options['endpoints'][0]['args'] );
		self::assertSame( 'csearch-search', $this->call( 'OPTIONS', '/search' )->get_data()['schema']['title'] );
	}

	public function test_search_pages_the_ranked_ids(): void {
		$data = $this->call( 'GET', '/search', array( 'q' => 'deck', 'per_page' => 2 ) )->get_data();
		self::assertCount( 2, $data['ids'] );
		self::assertSame( 1, $data['page'] );
		self::assertGreaterThanOrEqual( 4, $data['total'] );
		self::assertSame( (int) ceil( $data['total'] / 2 ), $data['pages'] );
		$page2 = $this->call( 'GET', '/search', array( 'q' => 'deck', 'per_page' => 2, 'page' => 2 ) )->get_data();
		self::assertSame( array(), array_intersect( $data['ids'], $page2['ids'] ) );
	}

	public function test_cache_headers_and_conditional_get(): void {
		$first = $this->call( 'GET', '/suggest', array( 'q' => 'kazumi' ) );
		self::assertSame( 'public, max-age=60', $first->get_headers()['Cache-Control'] );
		$etag = $first->get_headers()['ETag'];
		self::assertMatchesRegularExpression( '/^"2-[0-9a-f]{32}"$/', $etag, 'the generation and the query' );
		self::assertSame( $etag, $this->call( 'GET', '/suggest', array( 'q' => 'kazumi' ) )->get_headers()['ETag'], 'the same answer, the same ETag' );
		$again = $this->call( 'GET', '/suggest', array( 'q' => 'kazumi' ), array( 'If-None-Match' => $etag ) );
		self::assertSame( 304, $again->get_status() );
		self::assertNotSame( $etag, $this->call( 'GET', '/suggest', array( 'q' => 'kazumi', 'limit' => 1 ) )->get_headers()['ETag'], 'a different answer' );

		$this->service( Reindex::class )->runInline();
		self::assertNotSame( $etag, $this->call( 'GET', '/suggest', array( 'q' => 'kazumi' ) )->get_headers()['ETag'], 'a new generation' );

		// A single product's change, once the answer's 60 seconds are up, is a new ETag: revalidation cannot keep
		// the old body.
		$etag    = $this->call( 'GET', '/suggest', array( 'q' => 'kazumi' ) )->get_headers()['ETag'];
		$product = wc_get_product( $this->ids[0] );
		self::assertInstanceOf( \WC_Product::class, $product );
		$product->set_name( 'KAZUMI Skate Deck, Dealer Pro' );
		$product->save();
		$this->service( Indexer::class )->indexProduct( $this->ids[0] );
		wp_cache_flush();
		$fresh = $this->call( 'GET', '/suggest', array( 'q' => 'kazumi' ), array( 'If-None-Match' => $etag ) );
		self::assertSame( 200, $fresh->get_status() );
		self::assertNotSame( $etag, $fresh->get_headers()['ETag'] );
	}

	public function test_answers_are_cached_by_generation(): void {
		$queries = 0;
		$count   = static function ( string $sql ) use ( &$queries ): string {
			if ( str_contains( $sql, Tables::postings() ) ) {
				++$queries;
			}
			return $sql;
		};
		$this->call( 'GET', '/suggest', array( 'q' => 'philly' ) );
		add_filter( 'query', $count );
		$this->call( 'GET', '/suggest', array( 'q' => 'philly' ) );
		self::assertSame( 0, $queries, 'the second answer comes from the cache' );
		remove_filter( 'query', $count );

		$this->service( Indexer::class )->indexProduct( $this->ids[1] );
		$this->service( Reindex::class )->runInline();
		add_filter( 'query', $count );
		$this->call( 'GET', '/suggest', array( 'q' => 'philly' ) );
		remove_filter( 'query', $count );
		self::assertGreaterThan( 0, $queries, 'a reindex makes the old answer unreachable' );
	}

	public function test_rate_limit_answers_429_with_retry_after(): void {
		remove_filter( 'csearch_rate_limit', '__return_zero' );
		$limit = static fn( int $limit, string $route ): int => 'suggest' === $route ? 3 : $limit;
		add_filter( 'csearch_rate_limit', $limit, 10, 2 );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		for ( $i = 0; $i < 3; $i++ ) {
			self::assertSame( 200, $this->call( 'GET', '/suggest', array( 'q' => 'deck' . $i ) )->get_status() );
		}
		$limited = $this->call( 'GET', '/suggest', array( 'q' => 'deck' ) );
		self::assertSame( 429, $limited->get_status() );
		self::assertSame( 'csearch_rate_limited', $limited->get_data()['code'] );
		self::assertMatchesRegularExpression( '/^\d+$/', $limited->get_headers()['Retry-After'] );
		self::assertGreaterThanOrEqual( 1, (int) $limited->get_headers()['Retry-After'] );
		self::assertSame( 200, $this->call( 'GET', '/search', array( 'q' => 'deck' ) )->get_status(), 'each route has its own limit' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		self::assertSame( 200, $this->call( 'GET', '/suggest', array( 'q' => 'deck' ) )->get_status(), 'another client' );

		// A forged session cookie, new on every request, does not buy a new bucket.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.11';
		$statuses               = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$_COOKIE = array( 'wp_woocommerce_session_abc' => 'customer' . $i . '||' . ( time() + 3600 ) . '||1||forged' );
			$statuses[] = $this->call( 'GET', '/suggest', array( 'q' => 'deck' ) )->get_status();
		}
		$_COOKIE = array();
		self::assertSame( array( 200, 200, 200, 429, 429 ), $statuses );

		// A real one is its own client.
		$expiry = time() + 3600;
		$data   = 'shopper|' . $expiry;
		$_COOKIE = array( 'wp_woocommerce_session_abc' => 'shopper||' . $expiry . '||1||' . hash_hmac( 'md5', $data, wp_hash( $data ) ) );
		self::assertSame( 200, $this->call( 'GET', '/suggest', array( 'q' => 'deck' ) )->get_status(), 'a signed session is a client of its own' );
		$_COOKIE = array();
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	public function test_the_log_is_written_after_the_response_and_redacted(): void {
		$log = $this->service( SearchLog::class );
		$this->call( 'GET', '/suggest', array( 'q' => 'Kazumi  Deck' ) );
		$this->call( 'GET', '/suggest', array( 'q' => 'john@example.com deck' ) );
		$this->call( 'GET', '/search', array( 'q' => 'nothing-matches-this' ) );
		$this->call( 'GET', '/suggest', array( 'q' => '' ) );
		self::assertSame( 0, $this->rowCount( Tables::log() ), 'nothing written during the request' );
		self::assertCount( 3, $log->pending(), 'an empty query is not logged; the whole query is redacted when any part looks personal' );
		self::assertNotFalse( has_action( 'shutdown', array( $log, 'flush' ) ) );

		$log->flush();
		$rows = $this->service( LogRepository::class )->latest( 10 );
		self::assertCount( 3, $rows );
		self::assertSame( array( 'nothing-matches-this', '[redacted]', 'Kazumi  Deck' ), array_column( $rows, 'query' ) );
		self::assertSame( 'kazumi deck', $rows[2]['normalised'] );
		self::assertSame( array( 'page', 'suggest', 'suggest' ), array_column( $rows, 'source' ) );
		self::assertSame( '0', $rows[0]['results'] );
		self::assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\d \d\d:\d\d:00$/', (string) $rows[0]['created_at'], 'to the minute' );
		self::assertSame( array( 'id', 'query', 'normalised', 'lang', 'results', 'clicked_type', 'clicked_id', 'source', 'created_at' ), array_keys( $rows[0] ), 'nothing about the shopper' );
	}

	public function test_a_click_marks_the_latest_row_of_its_query(): void {
		$this->call( 'GET', '/suggest', array( 'q' => 'kazumi dea' ) );
		$this->service( SearchLog::class )->flush();
		$response = $this->call( 'POST', '/click', array( 'q' => 'Kazumi Dea', 'type' => 'product', 'id' => $this->ids[0] ) );
		self::assertSame( 202, $response->get_status() );
		self::assertTrue( $response->get_data()['recorded'] );
		$row = $this->service( LogRepository::class )->latest( 1 )[0];
		self::assertSame( 'product', $row['clicked_type'] );
		self::assertSame( (string) $this->ids[0], $row['clicked_id'] );
		self::assertFalse( $this->call( 'POST', '/click', array( 'q' => 'kazumi dea', 'type' => 'product', 'id' => 5 ) )->get_data()['recorded'], 'one click per search row' );
		self::assertFalse( $this->call( 'POST', '/click', array( 'q' => 'never searched', 'type' => 'product', 'id' => 5 ) )->get_data()['recorded'] );
	}

	public function test_status_needs_manage_woocommerce(): void {
		wp_set_current_user( 0 );
		self::assertSame( 401, $this->call( 'GET', '/status' )->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		self::assertSame( 403, $this->call( 'GET', '/status' )->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$response = $this->call( 'GET', '/status' );
		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertSame( 2, $data['generation'] );
		self::assertSame( 8, $data['products'] );
		self::assertNull( $data['building'] );
		self::assertIsString( $data['last_reindex'] );
		self::assertSame( 0, $data['backlog'] );
		self::assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
	}

	public function test_hidden_products_and_boost_rules_follow_the_settings(): void {
		self::assertNotContains( $this->ids[20], array_column( $this->call( 'GET', '/suggest', array( 'q' => 'KAZ-DBA-00021' ) )->get_data()['products'], 'id' ), 'hidden_by_sku is off by default' );
		Settings::set( 'hidden_by_sku', 'yes' );
		self::assertSame( $this->ids[20], $this->call( 'GET', '/suggest', array( 'q' => 'KAZ-DBA-00021' ) )->get_data()['products'][0]['id'] );

		$before = array_column( $this->call( 'GET', '/suggest', array( 'q' => 'skate deck' ) )->get_data()['products'], 'id' );
		$tag    = get_term_by( 'name', 'popsicle', 'product_tag' );
		self::assertInstanceOf( \WP_Term::class, $tag );
		$brand = get_term_by( 'name', "Philly's", 'product_brand' );
		self::assertInstanceOf( \WP_Term::class, $brand );
		Settings::set(
			'boost_rules',
			array(
				array(
					'taxonomy' => 'product_brand',
					'term_id'  => $brand->term_id,
					'boost'    => 10,
				),
			)
		);
		$after = array_column( $this->call( 'GET', '/suggest', array( 'q' => 'skate deck' ) )->get_data()['products'], 'id' );
		self::assertNotSame( $before, $after );
		self::assertContains( $after[0], array( $this->ids[1], $this->ids[2] ), 'a Philly\'s deck rises to the top' );
	}
}
