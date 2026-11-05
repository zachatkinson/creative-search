<?php
/**
 * A results-page query that names a category or a filter.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Hooks\ResultsHooks;
use CreativeBranding\Search\Index\AttributeTerms;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Results\ProductSearch;
use CreativeBranding\Search\Results\QueryRouter;
use CreativeBranding\Search\Results\ResultsView;
use CreativeBranding\Search\Search\SearchLog;
use CreativeBranding\Search\Search\SearchService;
use CreativeBranding\Search\Search\Suggestions;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Options;
use CreativeBranding\Search\Tests\Support\IndexTestCase;
use Automattic\WooCommerce\Internal\ProductAttributesLookup\Filterer;
use Automattic\WooCommerce\Internal\ProductAttributesLookup\LookupDataStore;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Query;

/**
 * D-101 and D-102 (Skatehouse handoff items 9 and 10), against WooCommerce: the golden queries, the cases that
 * stay on the results page, the line on the page the shopper lands on, the log, the hooks and the settings.
 */
final class RoutingTest extends IndexTestCase {

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
	}

	/**
	 * Visit a page.
	 *
	 * @param array<string, string> $args Query arguments.
	 * @param string                $base The page.
	 * @return int[] The ids of the main query's posts, in order.
	 */
	private function visit( array $args, string $base = '' ): array {
		$this->service( ProductSearch::class )->reset();
		$this->service( ResultsHooks::class )->reset();
		// WooCommerce reads the chosen filters once a request, and its attribute lists once a process: the Width
		// attribute's row is made again in each test.
		( new \ReflectionProperty( \WC_Query::class, 'chosen_attributes' ) )->setValue( null, null );
		delete_transient( 'wc_attribute_taxonomies' );
		\WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
		$GLOBALS['wc_product_attributes']['pa_width'] = get_taxonomy( 'pa_width' );
		$this->go_to( add_query_arg( $args, '' === $base ? home_url( '/' ) : $base ) );
		return array_map( static fn( $p ): int => $p instanceof \WP_Post ? $p->ID : (int) $p, $GLOBALS['wp_query']->posts );
	}

	/**
	 * Where a product search is sent, or null.
	 *
	 * @param string $words The query.
	 */
	private function target( string $words ): ?string {
		$this->visit(
			array(
				's'         => $words,
				'post_type' => 'product',
			)
		);
		return $this->service( QueryRouter::class )->target();
	}

	/**
	 * A category's archive link.
	 *
	 * @param string $slug The category.
	 */
	private static function categoryLink( string $slug ): string {
		$link = get_term_link( $slug, 'product_cat' );
		return is_string( $link ) ? $link : '';
	}

	public function test_a_query_naming_a_category_opens_it(): void {
		self::assertSame( add_query_arg( QueryRouter::fromArgs( 'wheels' ), self::categoryLink( 'wheels' ) ), $this->target( 'wheels' ) );
		self::assertNull( $this->service( ProductSearch::class )->answer(), 'the page that is left is not ranked' );
		self::assertSame( add_query_arg( QueryRouter::fromArgs( 'wheel' ), self::categoryLink( 'wheels' ) ), $this->target( 'wheel' ), 'by stem' );
		self::assertStringStartsWith( self::categoryLink( 'decks' ), (string) $this->target( 'Decks' ) );
		self::assertStringStartsWith( self::categoryLink( 'decks' ), (string) $this->target( 'deck' ) );
		self::assertStringStartsWith( self::categoryLink( 't-shirts' ), (string) $this->target( 't-shirts' ) );
		self::assertNull( $this->target( 'skate wheels' ), 'a word the name does not have' );
		self::assertNull( $this->target( 'orbit' ), 'a brand' );
	}

	public function test_a_size_becomes_the_shops_filter(): void {
		self::assertSame(
			add_query_arg(
				array(
					'filter_width'        => '8-75',
					...QueryRouter::fromArgs( '8.75 deck' ),
				),
				self::categoryLink( 'decks' )
			),
			$this->target( '8.75 deck' ),
			'a category and a size'
		);
		self::assertSame(
			add_query_arg(
				array(
					's'                   => 'philly',
					'post_type'           => 'product',
					'filter_width'        => '8-75',
					...QueryRouter::fromArgs( 'philly 8.75"' ),
				),
				home_url( '/' )
			),
			$this->target( 'philly 8.75"' ),
			'the other words searched, the inch mark read'
		);
		self::assertStringContainsString( 'filter_width=8-0', (string) $this->target( 'kazumi 8 inch' ), '8.0 is 8' );
		self::assertStringContainsString( 'filter_width=8-25,8-5&query_type_width=or', (string) $this->target( 'kazumi 8.25 8.5' ), 'several values' );
		$shop = (string) $this->target( '8.25' );
		self::assertStringStartsWith( wc_get_page_permalink( 'shop' ), $shop, 'nothing else: the shop' );
		self::assertStringContainsString( 'filter_width=8-25', $shop );
		self::assertNull( $this->target( 'kazumi 7.25' ), 'a size no term has stays a word' );
		self::assertNull( $this->target( 'kazumi -8.25' ), 'a minus word stays a word' );
	}

	public function test_the_page_landed_on(): void {
		$ids = $this->visit(
			array(
				's'                   => 'philly',
				'post_type'           => 'product',
				'filter_width'        => '8-75',
				...QueryRouter::fromArgs( 'philly 8.75' ),
			)
		);
		// WooCommerce applies the filter on a shop archive, which this site has no shop page for: the browser tests
		// check the narrowing (tests/e2e/routing.spec.ts).
		self::assertEqualsCanonicalizing( array( $this->ids[1], $this->ids[2] ), $ids, 'the search for the other words' );
		self::assertNull( $this->service( QueryRouter::class )->target(), 'the page landed on is not sent on' );
		self::assertFalse( $this->service( ResultsHooks::class )->singleRedirect( true ), 'one product left: the shopper still sees the filter' );
		$view = $this->service( ResultsView::class );
		self::assertStringContainsString( 'Showing products with Width 8.75.', $view->routedLine() );
		self::assertStringContainsString( QueryRouter::ALL_ARG . '=1', $view->routedLine() );
		self::assertStringContainsString( 'Search all products for “philly 8.75”.', $view->routedLine() );
	}

	public function test_the_category_page_says_where_the_shopper_is(): void {
		$this->visit( QueryRouter::fromArgs( 'wheels' ), self::categoryLink( 'wheels' ) );
		$line = $this->service( ResultsView::class )->routedLine();
		self::assertStringContainsString( '<p class="csearch-results-routed">Showing Wheels. <a href="', $line );
		self::assertStringContainsString( 's=wheels&#038;post_type=product&#038;' . QueryRouter::ALL_ARG . '=1', $line );
		ob_start();
		$this->service( ResultsHooks::class )->printSummary();
		self::assertStringContainsString( 'Showing Wheels.', (string) ob_get_clean(), 'printed above the loop' );

		$this->visit(
			array(
				'filter_width'        => '8-75',
				...QueryRouter::fromArgs( '8.75 deck' ),
			),
			self::categoryLink( 'decks' )
		);
		self::assertStringContainsString( 'Showing Decks with Width 8.75.', $this->service( ResultsView::class )->routedLine() );

		$this->visit( array(), self::categoryLink( 'wheels' ) );
		self::assertSame( '', $this->service( ResultsView::class )->routedLine(), 'only after a redirect' );
	}

	public function test_every_product_when_asked(): void {
		$ids = $this->visit(
			array(
				's'                 => 'wheels',
				'post_type'         => 'product',
				QueryRouter::ALL_ARG => '1',
			)
		);
		self::assertNull( $this->service( QueryRouter::class )->target() );
		self::assertEqualsCanonicalizing( array( $this->ids[6], $this->ids[7] ), $ids, 'the ranked search' );
		foreach ( array( 'orderby' => 'price', 'paged' => '2', 'filter_width' => '8-25', ProductSearch::EXACT_ARG => '1' ) as $arg => $value ) {
			$this->visit(
				array(
					's'         => 'wheels',
					'post_type' => 'product',
					$arg        => $value,
				)
			);
			self::assertNull( $this->service( QueryRouter::class )->target(), $arg . ' refines the search' );
		}
	}

	public function test_what_stays_on_the_results_page(): void {
		$empty = $this->catalogue->term( 'product_cat', 'Ramps' );
		self::assertGreaterThan( 0, $empty );
		self::assertNull( $this->target( 'ramps' ), 'a category with no products' );

		$product = new WC_Product_Simple();
		$product->set_name( 'Bearings' );
		$product->set_regular_price( '20' );
		$product->set_category_ids( array( $this->catalogue->term( 'product_cat', 'Bearings', 0, 'bearings' ) ) );
		$product->save();
		$this->runQueue();
		self::assertNull( $this->target( 'bearings' ), 'a product named exactly as the query reads' );

		$fingerboards = $this->catalogue->term( 'product_cat', 'Fingerboards' );
		$small        = (int) wp_insert_term(
			'Wheels',
			'product_cat',
			array(
				'parent' => $fingerboards,
				'slug'   => 'fingerboard-wheels',
			)
		)['term_id'];
		$tiny         = new WC_Product_Simple();
		$tiny->set_name( 'Tiny Spinner' );
		$tiny->set_regular_price( '5' );
		$tiny->set_category_ids( array( $small ) );
		$tiny->save();
		$this->runQueue();
		wp_cache_set_terms_last_changed();
		self::assertNull( $this->target( 'wheels' ), 'two categories named Wheels' );
		self::assertCount( 2, $this->service( QueryRouter::class )->categories() );
		$line = $this->service( ResultsView::class )->summary();
		self::assertStringContainsString( '<p class="csearch-results-categories">Categories: <a href="', $line );
		self::assertStringContainsString( '>Wheels (Skateboards)</a>', $line, 'each link says where it goes' );
		self::assertStringContainsString( '>Wheels (Fingerboards)</a>', $line );
		self::assertNull( $this->target( 'wheels 8.25' ), 'with a size too: still the shopper\'s choice' );
		self::assertCount( 2, $this->service( QueryRouter::class )->categories() );
	}

	public function test_the_settings_and_the_hooks(): void {
		Settings::set( 'category_redirect', 'no' );
		self::assertNull( $this->target( 'wheels' ) );
		self::assertStringContainsString( 'filter_width', (string) $this->target( 'deck 8.25' ), 'the filter still applies' );
		Settings::set( 'category_redirect', 'yes' );

		Settings::set( 'filter_attributes', array() );
		$this->service( AttributeTerms::class )->rebuild();
		self::assertSame( array(), get_option( Options::ATTRIBUTE_TERMS ) );
		self::assertNull( $this->target( 'kazumi 8.25' ) );
		Settings::set( 'filter_attributes', array( 'pa_width' ) );
		$this->service( AttributeTerms::class )->rebuild();

		$none = static fn(): array => array();
		add_filter( 'csearch_query_filters', $none );
		self::assertNull( $this->target( 'kazumi 8.25' ), 'csearch_query_filters' );
		self::assertStringNotContainsString( 'filter_width', (string) $this->target( 'deck 8.25' ), 'the size searched as a word' );
		remove_filter( 'csearch_query_filters', $none );

		add_filter( 'csearch_category_redirect', '__return_null' );
		self::assertNull( $this->target( 'wheels' ), 'csearch_category_redirect' );
		remove_filter( 'csearch_category_redirect', '__return_null' );
	}

	public function test_the_query_is_logged_once_as_typed(): void {
		$log = $this->service( SearchLog::class );
		$log->clear();
		$this->target( 'wheels' );
		$this->target( 'philly 8.75' );
		$this->target( '8.25' );
		$this->target( '8.75 decks' );
		$rows = array_slice( $log->pending(), 2 );
		self::assertSame( array( 3, 1 ), array_column( $rows, 'results' ), 'the shop in 8.25; Decks in 8.75' );
		$rows = array_slice( $log->pending(), 0, 2 );
		self::assertSame( array( 'wheels', 'philly 8.75' ), array_column( $rows, 'query' ) );
		self::assertSame( array( 2, 1 ), array_column( $rows, 'results' ), 'the category\'s products; the other words\' products in the size' );
		$this->visit(
			array(
				's'                   => 'philly',
				'post_type'           => 'product',
				'filter_width'        => '8-75',
				...QueryRouter::fromArgs( 'philly 8.75' ),
			)
		);
		self::assertCount( 4, $log->pending(), 'the page landed on is not logged again' );
	}

	public function test_see_all_counts_what_the_page_it_opens_lists(): void {
		$log    = $this->service( SearchLog::class );
		$panel  = fn( string $q ): int => (int) $this->service( Suggestions::class )->answer( $q, 'en', 6 )['total'];
		$landed = function ( string $q ) use ( $log ): int {
			$log->clear();
			self::assertNotNull( $this->target( $q ), $q . ' is sent on' );
			return (int) array_column( $log->pending(), 'results' )[0];
		};
		// Skatehouse's decks name every width they come in, in the description: the search for every word finds a deck
		// the filter leaves out.
		$wide = new WC_Product_Simple();
		$wide->set_name( 'Wide Deck' );
		$wide->set_regular_price( '60' );
		$wide->set_description( 'Also comes in 8.75 and 9.' );
		$wide->set_category_ids( array( $this->catalogue->term( 'product_cat', 'Decks', 0, 'decks' ) ) );
		$wide->save();
		$this->runQueue();
		$ranked = fn( string $q ): int => count( $this->service( SearchService::class )->rankedIds( $q, 'en', '' )['ids'] );
		self::assertGreaterThan( 1, $ranked( '8.75 deck' ), 'the search for every word finds more decks than 8.75 holds' );
		self::assertSame( 1, $panel( '8.75 deck' ), 'Decks in 8.75, not every deck (D-145)' );
		self::assertSame( $landed( '8.75 deck' ), $panel( '8.75 deck' ), 'a category and a size' );
		self::assertSame( 1, $panel( 'philly 8.75' ) );
		self::assertSame( $landed( 'philly 8.75' ), $panel( 'philly 8.75' ), 'a brand and a size' );
		self::assertSame( $landed( '8.25' ), $panel( '8.25' ), 'a size alone: the shop in that size' );

		self::assertSame( $ranked( 'kazumi' ), $panel( 'kazumi' ), 'no size: what the search found, as in 1.6.2' );
		self::assertSame( $ranked( 'wheels' ), $panel( 'wheels' ), 'a category without a size: as in 1.6.2' );
		self::assertSame( $ranked( 'kazumi 7.25' ), $panel( 'kazumi 7.25' ), 'a size no term has stays a word' );
		// The shop hides sold-out products: its listing in 8.75 is empty, and so is the count (the panel then shows no
		// "See all", and the search is logged as finding nothing, as the results page logs it, D-101).
		$sized = get_posts(
			array(
				'post_type'   => 'product',
				'fields'      => 'ids',
				'tax_query'   => array( array( 'taxonomy' => 'pa_width', 'field' => 'slug', 'terms' => array( '8-75' ) ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- a test fixture.
			)
		);
		self::assertNotEmpty( $sized, 'the deck in 8.75' );
		foreach ( $sized as $id ) {
			// What WooCommerce marks a sold-out product with (a variable product takes it from its variations).
			wp_set_object_terms( $id, 'outofstock', 'product_visibility', true );
		}
		update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );
		do_action( 'csearch_flush_cache' );
		self::assertSame( 0, $panel( '8.75 deck' ), 'sold out and hidden: the page lists none' );
		self::assertSame( $landed( '8.75 deck' ), $panel( '8.75 deck' ) );
		update_option( 'woocommerce_hide_out_of_stock_items', 'no' );
		do_action( 'csearch_flush_cache' );
		self::assertSame( 1, $panel( '8.75 deck' ), 'shown: counted' );

		Settings::set( 'filter_attributes', array() );
		$this->service( AttributeTerms::class )->rebuild();
		self::assertSame( $ranked( '8.75 deck' ), $panel( '8.75 deck' ), 'no attribute filtered by: what the search found' );
	}

	public function test_see_all_leaves_out_a_product_whose_size_is_sold_out(): void {
		// The demo's decks are variable, one variation per width: WooCommerce's filtered listing, when it hides what is
		// sold out, leaves out a deck whose 8.25 is sold out though the deck itself is in stock (D-151).
		$decks = $this->catalogue->term( 'product_cat', 'Decks', 0, 'decks' );
		$sizes = array();
		foreach ( array( '8-25', '8-5' ) as $slug ) {
			$term = get_term_by( 'slug', $slug, 'pa_width' );
			self::assertInstanceOf( \WP_Term::class, $term, $slug );
			$sizes[ $slug ] = $term->term_id;
		}
		$width = new WC_Product_Attribute();
		$width->set_id( wc_attribute_taxonomy_id_by_name( 'pa_width' ) );
		$width->set_name( 'pa_width' );
		$width->set_options( array_values( $sizes ) );
		$width->set_visible( true );
		$width->set_variation( true );
		$deck = new WC_Product_Variable();
		$deck->set_name( 'Split Deck' );
		$deck->set_category_ids( array( $decks ) );
		$deck->set_attributes( array( $width ) );
		$id = $deck->save();
		foreach ( array( '8-25' => 'outofstock', '8-5' => 'instock' ) as $slug => $status ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $id );
			$variation->set_regular_price( '60' );
			$variation->set_attributes( array( 'pa_width' => $slug ) );
			$variation->set_stock_status( $status );
			$variation->save();
		}
		WC_Product_Variable::sync( $id );
		$this->runQueue();
		update_option( 'woocommerce_attribute_lookup_enabled', 'yes' );
		$lookup = wc_get_container()->get( LookupDataStore::class );
		foreach ( get_posts( array( 'post_type' => 'product', 'fields' => 'ids', 'numberposts' => -1 ) ) as $product ) {
			$lookup->create_data_for_product( (int) $product );
		}

		$panel  = fn( string $q ): int => (int) $this->service( Suggestions::class )->answer( $q, 'en', 6 )['total'];
		$log    = $this->service( SearchLog::class );
		$landed = function ( string $q ) use ( $log ): int {
			$log->clear();
			self::assertNotNull( $this->target( $q ), $q . ' is sent on' );
			return (int) array_column( $log->pending(), 'results' )[0];
		};
		// What WooCommerce's own filter lists on Decks in a width: its lookup-table clause on a catalogue query.
		$woo = static function ( string $slug ) use ( $decks ): int {
			$filterer = wc_get_container()->get( Filterer::class );
			$on       = static fn(): bool => true;
			$clauses  = static fn( array $args, WP_Query $query ): array => $filterer->filter_by_attribute_post_clauses( $args, $query, array( 'pa_width' => array( 'terms' => array( $slug ), 'query_type' => 'and' ) ) );
			add_filter( 'woocommerce_enable_post_clause_filtering', $on );
			add_filter( 'posts_clauses', $clauses, 10, 2 );
			$visibility = wc_get_product_visibility_term_ids();
			$query      = new WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- a test.
						array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => array( $decks ) ),
						array( 'taxonomy' => 'product_visibility', 'field' => 'term_taxonomy_id', 'terms' => array( $visibility['exclude-from-catalog'], $visibility['outofstock'] ), 'operator' => 'NOT IN' ),
					),
				)
			);
			remove_filter( 'woocommerce_enable_post_clause_filtering', $on );
			remove_filter( 'posts_clauses', $clauses, 10 );
			return count( $query->posts );
		};

		$shown = $panel( '8.25 deck' );
		update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );
		do_action( 'csearch_flush_cache' );
		self::assertSame( $shown - 2, $panel( '8.25 deck' ), 'hidden: the Split Deck and the catalogue\'s deck sold out in 8.25 are not counted' );
		self::assertSame( $woo( '8-25' ), $panel( '8.25 deck' ), 'as WooCommerce lists it' );
		self::assertSame( $landed( '8.25 deck' ), $panel( '8.25 deck' ), 'as the router logs it' );
		self::assertSame( $woo( '8-5' ), $panel( '8.5 deck' ), 'in stock in 8.5: counted' );
		update_option( 'woocommerce_attribute_lookup_enabled', 'no' );
		do_action( 'csearch_flush_cache' );
		self::assertSame( $shown, $panel( '8.25 deck' ), 'without the lookup table WooCommerce filters by the terms alone, and so does the count' );
		update_option( 'woocommerce_hide_out_of_stock_items', 'no' );
	}

	public function test_the_attribute_values_follow_the_terms(): void {
		$values = $this->service( AttributeTerms::class )->values();
		self::assertSame( array( 'pa_width' => '8-25' ), $values['8.25'] );
		self::assertSame( array( 'pa_width' => '8-0' ), $values['8'] );
		$this->catalogue->term( 'pa_width', '9.5"' );
		self::assertSame( array( 'pa_width' => '9-5' ), $this->service( AttributeTerms::class )->values()['9.5'] ?? null, 'rebuilt when a term is added' );
		$this->catalogue->term( 'pa_width', 'Extra wide' );
		self::assertArrayNotHasKey( 'extra wide', $this->service( AttributeTerms::class )->values(), 'a name of two words names nothing' );
		delete_option( Options::ATTRIBUTE_TERMS );
		self::assertArrayHasKey( '8.25', $this->service( AttributeTerms::class )->values(), 'rebuilt when missing' );
	}

	public function test_the_field_keeps_what_the_shopper_typed(): void {
		$this->visit( QueryRouter::fromArgs( '8.75 deck' ), self::categoryLink( 'decks' ) );
		self::assertStringContainsString( 'value="8.75 deck"', do_shortcode( '[creative_search]' ) );
	}

	public function test_the_settings_are_sanitised(): void {
		$clean = Settings::sanitize(
			array(
				'filter_attributes' => array( 'pa_width', 'PA_Width', 'product_cat', '', array( 'x' ), 'pa_' . str_repeat( 'a', 40 ) ),
				'category_redirect' => 'off',
			)
		);
		self::assertSame( array( 'pa_width' ), $clean['filter_attributes'] );
		self::assertSame( 'no', $clean['category_redirect'] );
		self::assertSame( 'yes', Settings::defaults()['category_redirect'] );
		self::assertSame( array(), Settings::defaults()['filter_attributes'] );
	}

	public function test_a_routed_page_fetches_nothing(): void {
		$this->target( 'wheels' );
		self::assertSame( array( 0 ), $GLOBALS['wp_query']->get( 'post__in' ) );
		self::assertStringNotContainsString( 'LIKE', strstr( $GLOBALS['wp_query']->request, 'ORDER BY', true ) ?: '', 'no search of the words before the redirect' );
	}

	public function test_a_search_page_reads_nothing_more_once_warm(): void {
		$this->target( 'kazumi' );
		self::assertNull( $this->service( QueryRouter::class )->target() );
		$queries = array();
		$spy     = static function ( string $sql ) use ( &$queries ): string {
			$queries[] = $sql;
			return $sql;
		};
		add_filter( 'query', $spy );
		$this->service( QueryRouter::class )->route( $GLOBALS['wp_the_query'] );
		remove_filter( 'query', $spy );
		self::assertSame( array(), $queries, 'the attribute values and the category names are autoloaded' );
	}

	public function test_an_unsigned_from_is_ignored(): void {
		$this->visit( array( QueryRouter::FROM_ARG => 'Call 555-0100 for refunds' ), self::categoryLink( 'wheels' ) );
		self::assertSame( '', $this->service( ResultsView::class )->routedLine(), 'nobody else puts words on the shop\'s pages' );
		self::assertSame( '', QueryRouter::from() );
	}

	public function test_a_quoted_phrase_or_a_minus_word_stays_a_search(): void {
		$tools   = $this->catalogue->term( 'product_cat', 'Skate Tools' );
		$product = wc_get_product( $this->ids[15] );
		$product->set_category_ids( array( $tools ) );
		$product->save();
		$this->runQueue();
		self::assertStringStartsWith( self::categoryLink( 'skate-tools' ), (string) $this->target( 'skate tools' ) );
		self::assertNull( $this->target( '"skate tools"' ), 'a quoted phrase' );
		self::assertNull( $this->target( 'wheels -orbit' ), 'a minus word' );
	}

	public function test_the_maps_are_forgotten_when_their_terms_change(): void {
		$attributes = $this->service( AttributeTerms::class );
		$attributes->values();
		$width = get_term_by( 'slug', '8-75', 'pa_width' );
		self::assertInstanceOf( \WP_Term::class, $width );
		wp_update_term( $width->term_id, 'pa_width', array( 'name' => '8.75″' ) );
		self::assertFalse( get_option( Options::ATTRIBUTE_TERMS ), 'a value edited' );
		$attributes->values();
		wp_delete_term( $width->term_id, 'pa_width' );
		self::assertFalse( get_option( Options::ATTRIBUTE_TERMS ), 'a value deleted' );
		$attributes->values();
		Settings::set( 'filter_attributes', array() );
		self::assertFalse( get_option( Options::ATTRIBUTE_TERMS ), 'the choice changed, by any path' );

		$this->target( 'wheels' );
		self::assertIsArray( get_option( Options::CATEGORY_STEMS ) );
		wp_update_term( (int) get_term_by( 'slug', 'wheels', 'product_cat' )->term_id, 'product_cat', array( 'name' => 'Skate wheels' ) );
		self::assertFalse( get_option( Options::CATEGORY_STEMS ), 'a category renamed' );
		self::assertStringStartsWith( self::categoryLink( 'wheels' ), (string) $this->target( 'skate wheels' ), 'read again' );
		do_action( 'csearch_index_switched', 2, 1 );
		self::assertFalse( get_option( Options::CATEGORY_STEMS ), 'a rebuild went live' );
	}
}
