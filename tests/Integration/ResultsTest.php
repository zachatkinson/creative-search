<?php
/**
 * Results page tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Results\CategoryScope;
use CreativeBranding\Search\Results\MatchDecorations;
use CreativeBranding\Search\Results\ProductSearch;
use CreativeBranding\Search\Results\ResultsView;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Tests\Support\IndexTestCase;

/**
 * The archive query on `?s=`: the plugin's order on Relevance, the match set kept under other sorts and under
 * WooCommerce's filters, WordPress's search clause gone, other searches untouched; the count, the corrected
 * line, the no-results state; one extra query per page.
 */
final class ResultsTest extends IndexTestCase {

	/**
	 * Product ids by catalogue index.
	 *
	 * @var array<int, int>
	 */
	private array $ids = array();

	public function set_up(): void {
		parent::set_up();
		$indexes   = array( 0, 1, 2, 6, 7, 15, 20, 21, 22 );
		$this->ids = array_combine( $indexes, $this->products( $indexes ) );
		$this->service( Reindex::class )->runInline();
	}

	/**
	 * Visit a search page.
	 *
	 * @param array<string, string> $args Query arguments.
	 * @return int[] The ids of the main query's posts, in order.
	 */
	private function visit( array $args ): array {
		$this->service( ProductSearch::class )->reset();
		$this->service( \CreativeBranding\Search\Hooks\ResultsHooks::class )->reset();
		$this->go_to( add_query_arg( $args, home_url( '/' ) ) );
		return array_map( static fn( $p ): int => $p instanceof \WP_Post ? $p->ID : (int) $p, $GLOBALS['wp_query']->posts );
	}

	public function test_relevance_is_the_plugins_order(): void {
		$ids = $this->visit( array( 's' => 'kazumi', 'post_type' => 'product' ) );
		$ranked = $this->service( ProductSearch::class )->answer();
		self::assertNotNull( $ranked );
		self::assertSame( array_slice( $ranked['ids'], 0, count( $ids ) ), $ids );
		self::assertSame( $this->ids[0], $ids[0], 'the bestselling in-stock deck first' );
		self::assertContains( $this->ids[22], $ids, 'the KAZUMI tee' );
		self::assertNotContains( $this->ids[20], $ids, 'the hidden blank is not found by name' );
		self::assertTrue( $GLOBALS['wp_query']->get( 'csearch_ranked' ) );
		self::assertStringNotContainsString( 'post_title LIKE', $GLOBALS['wp_query']->request, 'WordPress\'s search clause is gone' );
		self::assertStringContainsString( 'FIELD(', $GLOBALS['wp_query']->request, 'ordered by post__in' );
	}

	public function test_another_sort_keeps_the_match_set(): void {
		$relevance = $this->visit( array( 's' => 'kazumi', 'post_type' => 'product' ) );
		$byPrice   = $this->visit( array( 's' => 'kazumi', 'post_type' => 'product', 'orderby' => 'price' ) );
		$sorted    = $relevance;
		sort( $sorted );
		$check = $byPrice;
		sort( $check );
		self::assertSame( $sorted, $check, 'the same products' );
		self::assertSame( $this->ids[22], $byPrice[0], 'the tee is the cheapest' );
		self::assertStringNotContainsString( 'FIELD(', $GLOBALS['wp_query']->request );
	}

	public function test_woocommerce_filters_narrow_the_ranked_set(): void {
		$ids = $this->visit( array( 's' => 'kazumi', 'post_type' => 'product', 'product_cat' => 't-shirts' ) );
		self::assertSame( array( $this->ids[22] ), $ids, 'a category filter composes with the ranking' );
		$cheap = $this->visit( array( 's' => 'orbit', 'post_type' => 'product', 'max_price' => '45' ) );
		self::assertSame( array( $this->ids[6] ), $cheap, 'the price filter keeps the 44.99 wheels' );
	}

	public function test_other_searches_are_left_to_wordpress(): void {
		self::factory()->post->create( array( 'post_title' => 'Kazumi team news' ) );
		$this->visit( array( 's' => 'kazumi' ) );
		self::assertNotTrue( $GLOBALS['wp_query']->get( 'csearch_ranked' ) );
		self::assertNull( $this->service( ProductSearch::class )->answer() );
		self::assertStringContainsString( 'post_title LIKE', $GLOBALS['wp_query']->request );
	}

	public function test_the_count_and_the_corrected_line(): void {
		$this->visit( array( 's' => 'kazumi', 'post_type' => 'product' ) );
		$summary = $this->service( ResultsView::class )->summary();
		self::assertStringContainsString( '3 results for “kazumi”', $summary );
		self::assertStringContainsString( 'role="status"', $summary );
		self::assertStringNotContainsString( 'search instead', $summary );

		$ids     = $this->visit( array( 's' => 'kazumi wheels', 'post_type' => 'product' ) );
		$summary = $this->service( ResultsView::class )->summary();
		self::assertNotSame( array(), $ids );
		self::assertStringContainsString( 'Showing results for <em>kazumi</em>; search instead for <a href="', $summary );
		self::assertStringContainsString( ProductSearch::EXACT_ARG . '=1', $summary );

		self::assertSame( array(), $this->visit( array( 's' => 'kazumi wheels', 'post_type' => 'product', ProductSearch::EXACT_ARG => '1' ) ), 'the shopper\'s exact words' );
		self::assertNull( $this->service( ProductSearch::class )->answer()['corrected'] ?? null );

		self::assertNotSame( array(), $this->visit( array( 's' => 'kazuni', 'post_type' => 'product' ) ) );
		$summary = $this->service( ResultsView::class )->summary();
		self::assertStringContainsString( 'Showing results for <em>kazumi</em>', $summary, 'a typo says what the results are for (D-114)' );
		self::assertStringNotContainsString( 'search instead', $summary, 'the exact words would find the same' );
	}

	public function test_no_results_offers_categories_and_fallback_products(): void {
		Settings::set( 'fallback_products', array( $this->ids[15], $this->ids[6] ) );
		self::assertSame( array(), $this->visit( array( 's' => 'decks snowboard binding', 'post_type' => 'product', ProductSearch::EXACT_ARG => '1' ) ) );
		$html = $this->service( ResultsView::class )->noResults();
		self::assertStringContainsString( '0 results for “decks snowboard binding”', $html );
		self::assertStringContainsString( 'Try one of these categories:', $html );
		self::assertStringContainsString( '>Decks</a>', $html, 'the category the query points to' );
		self::assertStringContainsString( 'Mainline Griptape, Perforated', $html, 'a fallback product' );
		self::assertStringContainsString( 'Orbit Wheels, Classic', $html );

		$this->visit( array( 's' => 'zzzzqqq', 'post_type' => 'product' ) );
		$html = $this->service( ResultsView::class )->noResults();
		self::assertMatchesRegularExpression( '/<li><a href="[^"]+">[^<]+<\/a><\/li>/', $html, 'the largest categories when the query points nowhere' );

		$this->visit( array( 's' => 'kazumi', 'post_type' => 'product' ) );
		self::assertSame( '', $this->service( ResultsView::class )->noResults(), 'only when nothing matched' );
	}

	/**
	 * What `woocommerce_no_products_found` prints on the page just visited.
	 */
	private function noProductsFound(): string {
		ob_start();
		do_action( 'woocommerce_no_products_found' );
		return (string) ob_get_clean();
	}

	public function test_an_empty_search_has_one_result_message(): void {
		$other = static function (): void {
			echo '<p class="another-plugin">kept</p>';
		};
		add_action( 'woocommerce_no_products_found', $other, 30 );
		$this->visit( array( 's' => 'zzzzqqq', 'post_type' => 'product' ) );
		$html = $this->noProductsFound();
		self::assertStringNotContainsString( 'No products were found matching your selection.', $html, 'WooCommerce\'s notice goes (D-122)' );
		self::assertSame( 1, substr_count( $html, 'role="status"' ), 'one status message' );
		self::assertStringContainsString( '0 results for “zzzzqqq”', $html );
		self::assertStringContainsString( 'another-plugin', $html, 'other callbacks still run' );

		$this->visit( array( 'post_type' => 'product', 'product_cat' => 'no-such-category-' . wp_generate_password( 6, false ) ) );
		self::assertStringContainsString( 'No products were found matching your selection.', $this->noProductsFound(), 'a listing that is not a search keeps it' );
		$this->visit( array( 's' => 'zzzzqqq', 'post_type' => 'post' ) );
		self::assertNotFalse( has_action( 'woocommerce_no_products_found', 'wc_no_products_found' ), 'nor a search the plugin does not answer' );
		remove_action( 'woocommerce_no_products_found', $other, 30 );
	}

	/**
	 * WooCommerce's result count and sort menu on the page just visited, with its ordering arguments.
	 *
	 * @return array{count: string, menu: string, args: array<string, mixed>}
	 */
	private function sortLines(): array {
		wc_setup_loop(
			array(
				'is_paginated' => true,
				'is_search'    => is_search(),
				'total'        => max( 1, (int) $GLOBALS['wp_query']->found_posts ),
				'per_page'     => 16,
				'current_page' => 1,
			)
		);
		ob_start();
		woocommerce_result_count();
		$count = (string) ob_get_clean();
		ob_start();
		woocommerce_catalog_ordering();
		$menu = (string) ob_get_clean();
		$args = WC()->query->get_catalog_ordering_args();
		wc_reset_loop();
		return array(
			'count' => $count,
			'menu'  => $menu,
			'args'  => $args,
		);
	}

	public function test_the_result_count_says_the_order_the_results_are_in(): void {
		update_option( 'woocommerce_default_catalog_orderby', 'date' );
		$this->visit( array( 's' => 'kazumi', 'post_type' => 'product' ) );
		$ours = $this->sortLines();
		self::assertStringContainsString( 'Sorted by relevance', $ours['count'], 'not the shop\'s "Sorted by latest" (D-123)' );
		self::assertStringNotContainsString( 'Sorted by latest', $ours['count'] );
		$view = $this->service( ResultsView::class );
		remove_filter( 'woocommerce_catalog_orderedby', array( $view, 'orderedBy' ) );
		remove_filter( 'woocommerce_default_catalog_orderby', array( $view, 'defaultOrderby' ) );
		$theirs = $this->sortLines();
		add_filter( 'woocommerce_catalog_orderedby', array( $view, 'orderedBy' ) );
		add_filter( 'woocommerce_default_catalog_orderby', array( $view, 'defaultOrderby' ) );
		self::assertStringContainsString( 'Sorted by latest', $theirs['count'], 'what WooCommerce says alone' );
		self::assertSame( $theirs['menu'], $ours['menu'], 'the sort menu does not change' );
		self::assertStringContainsString( "value=\"relevance\"  selected='selected'", $ours['menu'] );
		self::assertSame( $theirs['args'], $ours['args'], 'nor WooCommerce\'s ordering arguments' );

		$this->visit( array( 's' => 'kazumi', 'post_type' => 'product', 'orderby' => 'relevance' ) );
		self::assertStringContainsString( 'Sorted by relevance', $this->sortLines()['count'] );
		$this->visit( array( 's' => 'kazumi', 'post_type' => 'product', 'orderby' => 'price' ) );
		self::assertStringContainsString( 'Sorted by price: low to high', $this->sortLines()['count'] );

		$this->visit( array( 'post_type' => 'product', 'product_cat' => 'decks' ) );
		self::assertStringContainsString( 'Sorted by latest', $this->sortLines()['count'], 'a category page says what it said' );
		update_option( 'woocommerce_default_catalog_orderby', 'menu_order' );
		$this->visit( array( 'post_type' => 'product', 'product_cat' => 'decks' ) );
		self::assertStringNotContainsString( 'Sorted by', $this->sortLines()['count'] );
	}

	/**
	 * A results page, its sorted page and a search kept to a category (D-117, D-118), each with its cards' match
	 * lines and photos (MatchDecorations, D-107).
	 *
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function warm_pages(): array {
		return array(
			'relevance' => array( array( 's' => 'kazumi', 'post_type' => 'product' ) ),
			'sorted'    => array( array( 's' => 'kazumi', 'post_type' => 'product', 'orderby' => 'price' ) ),
			'scoped'    => array( array( 's' => 'kazumi', 'post_type' => 'product', 'product_cat' => 'decks' ) ),
		);
	}

	/**
	 * @dataProvider warm_pages
	 *
	 * @param array<string, string> $args The page's query arguments.
	 */
	public function test_a_results_page_costs_one_extra_query_when_warm( array $args ): void {
		$ids   = $this->visit( $args );
		$cards = $this->service( MatchDecorations::class );
		self::assertNotSame( array(), $ids );
		$render = static function () use ( $cards, $ids ): void {
			foreach ( $ids as $id ) {
				$cards->line( $id );
				$cards->imageId( 0, wc_get_product( $id ) );
			}
		};
		$render(); // The cold page fills the caches.
		$ours = array();
		$spy  = static function ( string $sql ) use ( &$ours ): string {
			$ours[] = $sql; // Every query the plugin's part of the page runs, whatever the table.
			return $sql;
		};
		// The suite's go_to() flushes the object cache; run the plugin's part of the page again on the same main
		// query instead, which is all a warm page adds.
		$this->service( ProductSearch::class )->reset();
		$cards->reset();
		CategoryScope::reset();
		add_filter( 'query', $spy );
		$this->service( ProductSearch::class )->apply( $GLOBALS['wp_the_query'] );
		$render();
		remove_filter( 'query', $spy );
		self::assertNotNull( $this->service( ProductSearch::class )->answer() );
		self::assertCount( 1, $ours, "one extra query: the live generation\n" . implode( "\n", $ours ) );
	}

	public function test_block_templates_get_the_same_lines(): void {
		$this->visit( array( 's' => 'kazumi', 'post_type' => 'product' ) );
		$count = apply_filters( 'render_block_woocommerce/product-results-count', '<p>Showing all 3 results</p>', array() );
		self::assertStringStartsWith( '<p class="csearch-results-summary"', $count );
		self::assertStringEndsWith( '<p>Showing all 3 results</p>', $count );
		self::assertSame( '<p>Showing all 3 results</p>', apply_filters( 'render_block_woocommerce/product-results-count', '<p>Showing all 3 results</p>', array() ), 'printed once per page' );
	}

	public function test_slashed_words_are_searched_as_typed(): void {
		$_GET['s'] = wp_slash( "philly's rascals" ); // As WordPress slashes $_GET on every request.
		$this->service( ProductSearch::class )->reset();
		$query = new \WP_Query();
		$query->parse_query( array( 's' => $_GET['s'], 'post_type' => 'product' ) );
		$GLOBALS['wp_the_query'] = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		self::assertSame( "philly's rascals", ProductSearch::words( $query ) );
		$this->service( ProductSearch::class )->apply( $query );
		self::assertSame( "philly's rascals", $this->service( ProductSearch::class )->answer()['query'] ?? '' );
		self::assertSame( $this->ids[1], $this->service( ProductSearch::class )->answer()['ids'][0] ?? 0 );
		unset( $_GET['s'] );
	}

	public function test_empty_searches_and_feeds_are_left_to_woocommerce(): void {
		$this->visit( array( 's' => '  ', 'post_type' => 'product' ) );
		self::assertNull( $this->service( ProductSearch::class )->answer(), 'an empty search is WordPress\'s' );
		self::assertNotTrue( $GLOBALS['wp_query']->get( 'csearch_ranked' ) );
		$this->visit( array( 's' => 'kazumi', 'post_type' => 'product', 'feed' => 'rss2' ) );
		self::assertNull( $this->service( ProductSearch::class )->answer() );
	}

	public function test_the_count_is_what_the_page_shows_after_filters(): void {
		$this->visit( array( 's' => 'kazumi', 'post_type' => 'product', 'product_cat' => 't-shirts' ) );
		self::assertStringContainsString( '1 result for “kazumi”', $this->service( ResultsView::class )->summary() );
	}

	public function test_hidden_by_sku_reaches_the_results_page(): void {
		self::assertSame( array(), $this->visit( array( 's' => 'KAZ-DBA-00021', 'post_type' => 'product' ) ) );
		Settings::set( 'hidden_by_sku', 'yes' );
		self::assertSame( array( $this->ids[20] ), $this->visit( array( 's' => 'KAZ-DBA-00021', 'post_type' => 'product' ) ) );
		self::assertSame( array(), array_intersect( array( $this->ids[20] ), $this->visit( array( 's' => 'kazumi', 'post_type' => 'product' ) ) ), 'not by name' );
	}

	public function test_block_templates_show_the_no_results_line_once(): void {
		$this->visit( array( 's' => 'zzzzqqq', 'post_type' => 'product' ) );
		$count = apply_filters( 'render_block_woocommerce/product-results-count', '<div></div>', array() );
		$empty = apply_filters( 'render_block_woocommerce/product-collection-no-results', '<p>No products</p>', array() );
		self::assertSame( '<div></div>', $count, 'no count line above an empty collection' );
		self::assertSame( 1, substr_count( $count . $empty, 'csearch-results-summary' ) );
		self::assertStringContainsString( 'Try one of these categories:', $empty );
	}

	public function test_classic_filter_widgets_count_the_ranked_set(): void {
		$this->visit( array( 's' => 'kazumi', 'post_type' => 'product' ) );
		$query = $this->service( ProductSearch::class )->filterCounts( array( 'where' => 'WHERE 1=1' ) );
		self::assertMatchesRegularExpression( '/AND \w+\.ID IN \(\d+(,\d+)*\)$/', $query['where'] );
		$range = $this->service( ProductSearch::class )->priceRange( 'SELECT x FROM t WHERE product_id IN ( SELECT ID FROM p WHERE 1=1 )' );
		self::assertMatchesRegularExpression( '/WHERE 1=1\s+AND \w+\.ID IN \([\d,]+\) \)$/', $range );
		self::assertSame( array(), $GLOBALS['wp_query']->get( 'search_terms' ), 'no LIKE on the raw words anywhere' );
	}

	public function test_a_second_page_or_a_sort_is_not_logged_again(): void {
		$log = $this->service( \CreativeBranding\Search\Search\SearchLog::class );
		$this->visit( array( 's' => 'kazumi', 'post_type' => 'product' ) );
		self::assertCount( 1, $log->pending() );
		$this->visit( array( 's' => 'kazumi', 'post_type' => 'product', 'paged' => '2' ) );
		$this->visit( array( 's' => 'kazumi', 'post_type' => 'product', 'orderby' => 'price' ) );
		self::assertCount( 1, $log->pending() );
	}
}
