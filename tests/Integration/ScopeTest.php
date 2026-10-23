<?php
/**
 * A search kept to a category, and a sort that keeps the strong matches.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Block\Shortcode;
use CreativeBranding\Search\Hooks\ResultsHooks;
use CreativeBranding\Search\Index\AttributeTerms;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Results\CategoryScope;
use CreativeBranding\Search\Results\ProductSearch;
use CreativeBranding\Search\Results\QueryRouter;
use CreativeBranding\Search\Results\ResultsView;
use CreativeBranding\Search\Search\SearchService;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Tests\Support\IndexTestCase;
use WP_REST_Request;

/**
 * D-117 (Baymard: search within the current category) and D-118 (Baymard: a sort must not bring weak matches up),
 * against WooCommerce: the ranking in the database and in PHP, the results page, the router, the panel's answer
 * and the form on a category page.
 */
final class ScopeTest extends IndexTestCase {

	/**
	 * Product ids by catalogue index.
	 *
	 * @var array<int, int>
	 */
	private array $ids = array();

	public function set_up(): void {
		parent::set_up();
		$indexes   = array( 0, 1, 2, 6, 7, 15, 22 );
		$this->ids = array_combine( $indexes, $this->products( $indexes ) );
		Settings::set( 'filter_attributes', array( 'pa_width' ) );
		$this->service( AttributeTerms::class )->rebuild();
		$this->service( Reindex::class )->runInline();
		CategoryScope::reset();
	}

	public function tear_down(): void {
		CategoryScope::reset();
		parent::tear_down();
	}

	/**
	 * Visit a page.
	 *
	 * @param array<string, string> $args Query arguments.
	 * @param string                $base The page.
	 * @return int[] The ids of the main query's posts, sorted.
	 */
	private function visit( array $args, string $base = '' ): array {
		$this->service( ProductSearch::class )->reset();
		$this->service( ResultsHooks::class )->reset();
		$this->service( QueryRouter::class )->reset();
		( new \ReflectionProperty( \WC_Query::class, 'chosen_attributes' ) )->setValue( null, null );
		delete_transient( 'wc_attribute_taxonomies' );
		\WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
		$GLOBALS['wc_product_attributes']['pa_width'] = get_taxonomy( 'pa_width' );
		$this->go_to( add_query_arg( $args, '' === $base ? home_url( '/' ) : $base ) );
		$ids = array_map( static fn( $p ): int => $p instanceof \WP_Post ? $p->ID : (int) $p, $GLOBALS['wp_query']->posts );
		sort( $ids );
		return $ids;
	}

	/**
	 * Some products' ids, sorted.
	 *
	 * @param int[] $indexes Catalogue indexes.
	 * @return int[]
	 */
	private function idsOf( array $indexes ): array {
		$ids = array_map( fn( int $i ): int => $this->ids[ $i ], $indexes );
		sort( $ids );
		return $ids;
	}

	/**
	 * A category's term-taxonomy ids with its subcategories'.
	 *
	 * @param string $slug The category.
	 * @return int[]
	 */
	private static function scope( string $slug ): array {
		return CategoryScope::ttIds( CategoryScope::bySlug( $slug ) );
	}

	public function test_the_ranking_keeps_to_the_category_and_its_subcategories(): void {
		$search = $this->service( SearchService::class );
		self::assertSame( $this->idsOf( array( 0, 22 ) ), $this->sorted( $search->rankedIds( 'kazumi', 'en', '' )['ids'] ), 'every category' );
		self::assertSame( array( $this->ids[0] ), $search->rankedIds( 'kazumi', 'en', '', false, self::scope( 'decks' ) )['ids'], 'Decks' );
		self::assertSame( array( $this->ids[0] ), $search->rankedIds( 'kazumi', 'en', '', false, self::scope( 'skateboards' ) )['ids'], 'Skateboards, through Decks' );
		self::assertSame( array( $this->ids[22] ), $search->rankedIds( 'kazumi', 'en', '', false, self::scope( 'apparel' ) )['ids'] );
		self::assertSame( array(), $search->rankedIds( 'kazumi', 'en', '', false, self::scope( 'wheels' ) )['ids'] );
		self::assertSame( array( $this->ids[0] ), $search->run( 'kazumi', 'en', 500, 0, true, true, self::scope( 'decks' ) )->productIds(), 'the ranking in PHP keeps to it too' );
		self::assertCount( 4, self::scope( 'skateboards' ), 'Skateboards, Decks, Wheels and Griptape' );
	}

	/**
	 * Ids, sorted.
	 *
	 * @param int[] $ids Ids.
	 * @return int[]
	 */
	private function sorted( array $ids ): array {
		sort( $ids );
		return $ids;
	}

	public function test_the_results_page_of_a_search_kept_to_a_category(): void {
		$ids = $this->visit(
			array(
				's'                => 'kazumi',
				'post_type'        => 'product',
				CategoryScope::ARG => 'decks',
			)
		);
		self::assertSame( array( $this->ids[0] ), $ids );
		self::assertNull( $this->service( QueryRouter::class )->target() );
		$summary = $this->service( ResultsView::class )->summary();
		self::assertStringContainsString( '1 result for “kazumi” in Decks', $summary );
		self::assertStringContainsString( 'class="csearch-results-scope"', $summary );
		self::assertStringContainsString( 'Search all products for “kazumi”.', $summary );
		self::assertStringContainsString( QueryRouter::ALL_ARG . '=1', $summary, 'every product, not sent on to a category' );
		self::assertStringNotContainsString( CategoryScope::ARG . '=decks', $summary );
		$all = $this->visit(
			array(
				's'                => 'kazumi',
				'post_type'        => 'product',
				QueryRouter::ALL_ARG => '1',
			)
		);
		self::assertSame( $this->idsOf( array( 0, 22 ) ), $all );
		self::assertStringNotContainsString( 'csearch-results-scope', $this->service( ResultsView::class )->summary() );
	}

	public function test_a_category_word_in_a_search_kept_to_a_category_stays_a_word(): void {
		$ids = $this->visit(
			array(
				's'                => 'wheels',
				'post_type'        => 'product',
				CategoryScope::ARG => 'skateboards',
			)
		);
		self::assertNull( $this->service( QueryRouter::class )->target(), 'Wheels is not opened: the shopper chose Skateboards' );
		self::assertSame( $this->idsOf( array( 6, 7 ) ), $ids );
		self::assertSame( array(), $this->service( QueryRouter::class )->categories() );
	}

	public function test_a_size_in_a_search_kept_to_a_category_is_the_filter_in_it(): void {
		$this->visit(
			array(
				's'                => 'philly 8.75',
				'post_type'        => 'product',
				CategoryScope::ARG => 'decks',
			)
		);
		self::assertSame(
			add_query_arg(
				array(
					's'                => 'philly',
					'post_type'        => 'product',
					CategoryScope::ARG => 'decks',
					'filter_width'     => '8-75',
					...QueryRouter::fromArgs( 'philly 8.75' ),
				),
				home_url( '/' )
			),
			$this->service( QueryRouter::class )->target()
		);
		$this->visit(
			array(
				's'                => '8.75',
				'post_type'        => 'product',
				CategoryScope::ARG => 'decks',
			)
		);
		$link = get_term_link( 'decks', 'product_cat' );
		self::assertSame( add_query_arg( array( 'filter_width' => '8-75' ) + QueryRouter::fromArgs( '8.75' ), is_string( $link ) ? $link : '' ), $this->service( QueryRouter::class )->target(), 'a size alone: the category, filtered' );
	}

	public function test_a_sort_keeps_the_strong_matches(): void {
		$relevance = $this->visit(
			array(
				's'         => 'skate',
				'post_type' => 'product',
			)
		);
		self::assertContains( $this->ids[6], $relevance, 'on Relevance, "skate" completes to Skateboards, the wheels\' category' );
		$price = $this->visit(
			array(
				's'         => 'skate',
				'post_type' => 'product',
				'orderby'   => 'price',
			)
		);
		self::assertSame( $this->idsOf( array( 0, 1, 2 ) ), $price, 'sorted by price, only the products that say "skate" (D-118)' );
		self::assertStringContainsString( '3 results for “skate”', $this->service( ResultsView::class )->summary(), 'the count is the narrowed set' );
		$typo = $this->visit(
			array(
				's'         => 'kazuni',
				'post_type' => 'product',
				'orderby'   => 'price-desc',
			)
		);
		self::assertSame( $this->idsOf( array( 0, 22 ) ), $typo, 'nothing strong: every match rather than nothing' );
		self::assertSame( $relevance, $this->visit( array( 's' => 'skate', 'post_type' => 'product', 'orderby' => 'relevance' ) ), 'Relevance chosen is Relevance' );
	}

	public function test_the_panel_suggests_from_the_category(): void {
		do_action( 'rest_api_init' );
		$request = new WP_REST_Request( 'GET', '/csearch/v1/suggest' );
		$request->set_param( 'q', 'kazumi' );
		$request->set_param( 'cat', 'decks' );
		$data = rest_do_request( $request )->get_data();
		self::assertIsArray( $data );
		self::assertSame( array( $this->ids[0] ), array_column( (array) $data['products'], 'id' ) );
		$request->set_param( 'cat', 'no-such-category' );
		$every = rest_do_request( $request )->get_data();
		self::assertIsArray( $every );
		self::assertCount( 2, (array) $every['products'], 'an unknown category keeps nothing out' );
		$request->set_param( 'cat', 'bad/slug' );
		self::assertSame( 400, rest_do_request( $request )->get_status() );
	}

	public function test_on_a_category_page_the_panel_offers_the_category_first(): void {
		// 1.3's "Only in Decks" checkbox is gone (D-130): the form carries the category, the panel offers it.
		$link = get_term_link( 'decks', 'product_cat' );
		$this->go_to( is_string( $link ) ? $link : '' );
		$form = do_shortcode( '[' . Shortcode::TAG . ']' );
		self::assertStringNotContainsString( 'type="checkbox"', $form );
		self::assertStringNotContainsString( 'name="product_cat"', $form, 'the form itself searches every product' );
		self::assertStringContainsString( '"scope":"decks"', $form );
		$this->go_to( home_url( '/' ) );
		self::assertStringContainsString( '"scope":""', do_shortcode( '[' . Shortcode::TAG . ']' ) );

		do_action( 'rest_api_init' );
		$scopes = static function ( string $here ): array {
			$request = new WP_REST_Request( 'GET', '/csearch/v1/suggest' );
			$request->set_param( 'q', 'kazumi' );
			if ( '' !== $here ) {
				$request->set_param( 'here', $here );
			}
			$data = rest_do_request( $request )->get_data();
			self::assertIsArray( $data );
			self::assertCount( 2, (array) $data['products'], 'the panel still searches every product' );
			return (array) $data['scopes'];
		};
		self::assertSame( array(), $scopes( '' ), 'two products are too few for a scope of their own' );
		$decks = $scopes( 'decks' );
		self::assertSame( array( 'Decks' ), array_column( $decks, 'name' ), 'the category the shopper is in, first' );
		self::assertStringContainsString( 'product_cat=decks', (string) $decks[0]['url'] );
		self::assertStringContainsString( 's=kazumi', (string) $decks[0]['url'] );
		self::assertSame( array( 'Apparel' ), array_column( $scopes( 'apparel' ), 'name' ) );
		self::assertSame( array(), $scopes( 'uncategorized' ), 'not a category with nothing for the query' );
		Settings::set( 'suggest_scopes', 'no' );
		self::assertSame( array(), $scopes( 'decks' ), 'scopes switched off' );

		$request = new WP_REST_Request( 'GET', '/csearch/v1/suggest' );
		$request->set_param( 'q', 'kazumi' );
		$request->set_param( 'here', 'bad/slug' );
		self::assertSame( 400, rest_do_request( $request )->get_status() );
	}
}
