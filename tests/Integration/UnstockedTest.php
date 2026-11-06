<?php
/**
 * A value the query names that has nothing in stock where it lands.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use Automattic\WooCommerce\Internal\ProductAttributesLookup\LookupDataStore;
use CreativeBranding\Search\Hooks\ResultsHooks;
use CreativeBranding\Search\Index\AttributeTerms;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Results\ProductSearch;
use CreativeBranding\Search\Results\QueryRouter;
use CreativeBranding\Search\Results\ResultsView;
use CreativeBranding\Search\Search\SearchLog;
use CreativeBranding\Search\Search\Suggestions;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Tests\Support\IndexTestCase;
use CreativeBranding\Search\Tests\Support\WooCommerceCatalogue;
use CreativeBranding\Search\Search\SearchService;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Query;

/**
 * D-154 (1.6.5 brief, item 1): on the demo every deck carries each width of its range, at stock 0 where it isn't
 * made, and the shop hides what is sold out. "9 deck" opened Decks in 9.00″, which listed nothing. Now the size is
 * left out, the page says so with the closest widths in stock, and the search is logged as finding nothing.
 *
 * Decks here: Alpha in 7.75 (sold out), 8.0, 8.25 and 9.0 (sold out), Mellow concave; Bravo in 8.25, 8.5 (sold
 * out), 8.75 and 9.0 (sold out), Steep concave; Charlie in 8.25 (sold out) and 8.75, Flat concave. In stock in
 * Decks: 8.0, 8.25 and 8.75.
 */
final class UnstockedTest extends IndexTestCase {

	private const WIDTHS = array( '7.75', '8.0', '8.25', '8.5', '8.75', '9.0' );

	public function set_up(): void {
		parent::set_up();
		WooCommerceCatalogue::ensureWidthTaxonomy();
		self::ensureConcave();
		foreach ( self::WIDTHS as $width ) {
			$this->catalogue->term( 'pa_width', $width );
		}
		// The demo's configuration before the decks are made: with sold-out products hidden WooCommerce leaves sold-out
		// variations out of a product's visible children, so the index reads the product's own values for them.
		update_option( 'woocommerce_attribute_lookup_enabled', 'yes' );
		update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );
		$decks = $this->catalogue->term( 'product_cat', 'Decks', 0, 'decks' );
		$this->deck( 'Alpha', $decks, array( '7.75' => false, '8.0' => true, '8.25' => true, '9.0' => false ), 'Mellow' );
		$this->deck( 'Bravo', $decks, array( '8.25' => true, '8.5' => false, '8.75' => true, '9.0' => false ), 'Steep' );
		$this->deck( 'Charlie', $decks, array( '8.25' => false, '8.75' => true ), 'Flat' );
		$lookup = wc_get_container()->get( LookupDataStore::class );
		foreach ( get_posts( array( 'post_type' => 'product', 'fields' => 'ids', 'numberposts' => -1 ) ) as $product ) {
			$lookup->create_data_for_product( (int) $product );
		}
		// WooCommerce reads its attribute labels once a process: the rows are made again in each test.
		delete_transient( 'wc_attribute_taxonomies' );
		\WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
		$GLOBALS['wc_product_attributes']['pa_width']   = get_taxonomy( 'pa_width' );
		$GLOBALS['wc_product_attributes']['pa_concave'] = get_taxonomy( 'pa_concave' );
		Settings::set( 'filter_attributes', array( 'pa_width', 'pa_concave' ) );
		$this->service( AttributeTerms::class )->rebuild();
		$this->service( Reindex::class )->runInline();
	}

	public function tear_down(): void {
		update_option( 'woocommerce_hide_out_of_stock_items', 'no' );
		update_option( 'woocommerce_attribute_lookup_enabled', 'no' );
		parent::tear_down();
	}

	/**
	 * The Concave attribute, made when missing, with its taxonomy registered for this request.
	 */
	private static function ensureConcave(): int {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT attribute_id FROM %i WHERE attribute_name = %s', $wpdb->prefix . 'woocommerce_attribute_taxonomies', 'concave' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a test fixture.
		if ( 0 === $id ) {
			unregister_taxonomy( 'pa_concave' );
			$made = wc_create_attribute(
				array(
					'name' => 'Concave',
					'slug' => 'concave',
				)
			);
			$id   = is_int( $made ) ? $made : 0;
		}
		delete_transient( 'wc_attribute_taxonomies' );
		\WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
		if ( ! taxonomy_exists( 'pa_concave' ) ) {
			register_taxonomy( 'pa_concave', array( 'product' ), array( 'hierarchical' => false ) );
		}
		return $id;
	}

	/**
	 * A variable deck, one variation per width, each in stock or not.
	 *
	 * @param string            $name    Its name.
	 * @param int               $decks   The Decks category.
	 * @param array<string, bool> $widths Width to in stock.
	 * @param string            $concave Its concave.
	 * @param string            $status  Its status.
	 */
	private function deck( string $name, int $decks, array $widths, string $concave, string $status = 'publish' ): int {
		$width = new WC_Product_Attribute();
		$width->set_id( WooCommerceCatalogue::ensureWidthTaxonomy() );
		$width->set_name( 'pa_width' );
		$width->set_options( array_map( fn( string $w ): int => $this->catalogue->term( 'pa_width', $w ), array_map( 'strval', array_keys( $widths ) ) ) );
		$width->set_visible( true );
		$width->set_variation( true );
		$shape = new WC_Product_Attribute();
		$shape->set_id( self::ensureConcave() );
		$shape->set_name( 'pa_concave' );
		$shape->set_options( array( $this->catalogue->term( 'pa_concave', $concave ) ) );
		$shape->set_visible( true );
		$shape->set_variation( false );
		$deck = new WC_Product_Variable();
		$deck->set_name( $name . ' Deck' );
		$deck->set_category_ids( array( $decks ) );
		$deck->set_attributes( array( $width, $shape ) );
		$deck->set_status( $status );
		$id = $deck->save();
		foreach ( $widths as $w => $stocked ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $id );
			$variation->set_regular_price( '60' );
			$variation->set_attributes( array( 'pa_width' => sanitize_title( (string) $w ) ) );
			$variation->set_stock_status( $stocked ? 'instock' : 'outofstock' );
			$variation->save();
		}
		WC_Product_Variable::sync( $id );
		$this->runQueue();
		return $id;
	}

	/**
	 * Visit a page.
	 *
	 * @param string $url The page.
	 */
	private function visit( string $url ): void {
		$this->service( ProductSearch::class )->reset();
		$this->service( ResultsHooks::class )->reset();
		( new \ReflectionProperty( \WC_Query::class, 'chosen_attributes' ) )->setValue( null, null );
		delete_transient( 'wc_attribute_taxonomies' );
		\WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
		$GLOBALS['wc_product_attributes']['pa_width']   = get_taxonomy( 'pa_width' );
		$GLOBALS['wc_product_attributes']['pa_concave'] = get_taxonomy( 'pa_concave' );
		$this->go_to( $url );
	}

	/**
	 * Where a product search is sent, and the count it was logged with.
	 *
	 * @param string $words The query.
	 * @return array{0: string, 1: int}
	 */
	private function send( string $words ): array {
		$log = $this->service( SearchLog::class );
		$log->clear();
		$this->visit(
			add_query_arg(
				array(
					's'         => rawurlencode( $words ),
					'post_type' => 'product',
				),
				home_url( '/' )
			)
		);
		$target = $this->service( QueryRouter::class )->target();
		self::assertNotNull( $target, $words . ' is sent on' );
		return array( $target, (int) array_column( $log->pending(), 'results' )[0] );
	}

	/**
	 * A target's query arguments.
	 *
	 * @param string $url The target.
	 * @return array<string, string>
	 */
	private static function args( string $url ): array {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
		return array_map( 'strval', $args );
	}

	/**
	 * What WooCommerce lists on Decks (in stock), optionally in one width.
	 */
	private static function decksListed(): int {
		return ( new WP_Query(
			array(
				'post_type'      => 'product',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- a test.
					array( 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => array( 'decks' ) ),
					array( 'taxonomy' => 'product_visibility', 'field' => 'slug', 'terms' => array( 'outofstock', 'exclude-from-catalog' ), 'operator' => 'NOT IN' ),
				),
			)
		) )->post_count;
	}

	/**
	 * What the page a query lands on lists, as WooCommerce's own query on that page counts it.
	 *
	 * @param string $target The landing.
	 */
	private function listedAt( string $target ): int {
		$this->visit( str_starts_with( $target, '/' ) ? untrailingslashit( home_url() ) . $target : $target );
		return (int) $GLOBALS['wp_query']->found_posts;
	}

	/**
	 * The panel's answer.
	 *
	 * @param string $words The query.
	 * @return array<mixed>
	 */
	private function panel( string $words ): array {
		return $this->service( Suggestions::class )->answer( $words, 'en', 6 );
	}

	public function test_a_size_with_nothing_in_stock_is_left_out(): void {
		[ $target, $logged ] = $this->send( '9 deck' );
		$args                = self::args( $target );
		self::assertStringStartsWith( (string) get_term_link( 'decks', 'product_cat' ), $target, 'Decks' );
		self::assertArrayNotHasKey( 'filter_width', $args, 'without the size' );
		self::assertSame( 'pa_width:9-0', $args[ QueryRouter::NONE_ARG ] );
		self::assertSame( 'pa_width:8-75', $args[ QueryRouter::NEAR_ARG ], 'stock on one side only: one closest value' );
		self::assertSame( 0, $logged, 'Analytics counts it among the searches that found nothing' );
		self::assertSame( 3, self::decksListed() );
		self::assertSame( self::decksListed(), $this->panel( '9 deck' )['total'], 'See all counts Decks' );

		// The page landed on.
		$this->visit( $target );
		self::assertSame( '9 deck', QueryRouter::from() );
		$line = $this->service( ResultsView::class )->routedLine();
		self::assertStringContainsString( '<p class="csearch-results-unstocked">Nothing in Width 9.0 is in stock right now.</p>', $line );
		self::assertStringContainsString( '<p class="csearch-results-closest">Closest in stock:</p><ul class="csearch-results-closest-list"><li><a href="', $line );
		self::assertStringContainsString( '>Width 8.75</a></li></ul>', $line, 'the link names the attribute, so it reads alone' );
		self::assertStringContainsString( 'Showing Decks.', $line, 'the D-102 line follows' );
		self::assertLessThan( strpos( $line, 'Showing Decks.' ), strpos( $line, 'Nothing in' ) );

		// The closest value's link: Decks in 8.75, signed, with the D-102 line and no "nothing in stock".
		preg_match( '/<a href="([^"]+)">Width 8.75<\/a>/', $line, $m );
		$link = html_entity_decode( $m[1] ?? '' );
		self::assertSame( '8-75', self::args( $link )['filter_width'] ?? null );
		self::assertArrayNotHasKey( QueryRouter::NONE_ARG, self::args( $link ) );
		$this->visit( str_starts_with( $link, '/' ) ? untrailingslashit( home_url() ) . $link : $link );
		$closest = $this->service( ResultsView::class )->routedLine();
		self::assertStringNotContainsString( 'Nothing in', $closest );
		self::assertStringContainsString( 'Showing Decks with Width 8.75.', $closest );
	}

	public function test_see_all_counts_the_page_enter_opens(): void {
		foreach ( array( '9 deck', '7.75 deck', '8.5 deck', '8.25 deck', 'steep 9 deck', 'mellow 8.75 deck', '9 inch deck' ) as $query ) {
			[ $target ] = $this->send( $query );
			self::assertSame( $this->listedAt( $target ), $this->panel( $query )['total'], $query );
		}
	}

	public function test_a_value_only_a_hidden_product_has_is_no_way_on(): void {
		// A draft deck in stock in 7.5: the shop lists it nowhere, so 7.5 isn't offered for "7.75".
		$this->catalogue->term( 'pa_width', '7.5' );
		$draft = $this->deck( 'Draft', $this->catalogue->term( 'product_cat', 'Decks', 0, 'decks' ), array( '7.5' => true ), 'Flat', 'draft' );
		wc_get_container()->get( LookupDataStore::class )->create_data_for_product( $draft );
		$args = self::args( $this->send( '7.75' )[0] );
		self::assertSame( 'pa_width:7-75', $args[ QueryRouter::NONE_ARG ], 'the shop, without the size' );
		self::assertSame( 'pa_width:8-0', $args[ QueryRouter::NEAR_ARG ] );
	}

	public function test_every_value_left_out_keeps_the_way_back_to_the_search(): void {
		// "9 hoodie"-like searches that do carry a width: the landing is the search for the other words, unfiltered.
		Settings::set( 'category_redirect', false );
		[ $target ] = $this->send( '9 alpha' );
		self::assertArrayNotHasKey( 'filter_width', self::args( $target ) );
		$this->visit( $target );
		$line = $this->service( ResultsView::class )->routedLine();
		self::assertStringContainsString( 'Nothing in Width 9.0 is in stock right now.', $line );
		self::assertStringContainsString( '<p class="csearch-results-routed"><a href="', $line, 'the way back, with no "Showing" lead' );
		self::assertStringContainsString( 'Search all products for “9 alpha”.', $line );
	}

	public function test_the_smallest_size_has_one_closest_value_and_a_middle_one_two(): void {
		$args = self::args( $this->send( '7.75 deck' )[0] );
		self::assertSame( 'pa_width:8-0', $args[ QueryRouter::NEAR_ARG ], 'nothing below 7.75 in stock' );

		[ $target ] = $this->send( '8.5 deck' );
		self::assertSame( 'pa_width:8-25,pa_width:8-75', self::args( $target )[ QueryRouter::NEAR_ARG ], 'lower first' );
		$this->visit( $target );
		self::assertMatchesRegularExpression( '/Closest in stock:<\/p><ul class="csearch-results-closest-list"><li><a href="[^"]+">Width 8.25<\/a><\/li><li><a href="[^"]+">Width 8.75<\/a><\/li><\/ul>/', $this->service( ResultsView::class )->routedLine() );
	}

	public function test_a_size_in_stock_still_filters(): void {
		[ $target, $logged ] = $this->send( '8.25 deck' );
		$args                = self::args( $target );
		self::assertSame( '8-25', $args['filter_width'] );
		self::assertArrayNotHasKey( QueryRouter::NONE_ARG, $args );
		self::assertSame( 2, $logged );
		self::assertSame( 2, $this->panel( '8.25 deck' )['total'] );
	}

	public function test_with_two_values_only_the_one_that_empties_the_page_goes(): void {
		[ $target, $logged ] = $this->send( 'steep 9 deck' );
		$args                = self::args( $target );
		self::assertSame( 'steep', $args['filter_concave'] ?? null, 'the other value stays applied' );
		self::assertArrayNotHasKey( 'filter_width', $args );
		self::assertSame( 'pa_width:9-0', $args[ QueryRouter::NONE_ARG ] );
		self::assertSame( 'pa_width:8-75', $args[ QueryRouter::NEAR_ARG ], 'the closest Steep deck in stock' );
		self::assertSame( 0, $logged );
		self::assertSame( 1, $this->panel( 'steep 9 deck' )['total'], 'See all: Bravo, in Steep' );

		// Each lists something alone, nothing together: both go, and no closest values are offered.
		$args = self::args( $this->send( 'mellow 8.75 deck' )[0] );
		self::assertArrayNotHasKey( 'filter_width', $args );
		self::assertArrayNotHasKey( 'filter_concave', $args );
		self::assertSame( 'pa_concave:mellow,pa_width:8-75', $args[ QueryRouter::NONE_ARG ], 'as typed' );
		self::assertArrayNotHasKey( QueryRouter::NEAR_ARG, $args );
		self::assertSame( self::decksListed(), $this->panel( 'mellow 8.75 deck' )['total'] );
	}

	public function test_the_line_is_signed(): void {
		[ $target ] = $this->send( '9 deck' );
		$args       = self::args( $target );
		$forged     = add_query_arg( QueryRouter::NONE_ARG, rawurlencode( 'pa_width:8-25' ), remove_query_arg( QueryRouter::NONE_ARG, $target ) );
		$this->visit( $forged );
		self::assertSame( '', $this->service( ResultsView::class )->routedLine(), 'a changed value breaks the signature' );
		$plain = add_query_arg( QueryRouter::fromArgs( '9 deck' ) + array( QueryRouter::NONE_ARG => rawurlencode( $args[ QueryRouter::NONE_ARG ] ) ), (string) get_term_link( 'decks', 'product_cat' ) );
		$this->visit( $plain );
		self::assertSame( array(), QueryRouter::unstocked(), 'the query\'s own signature does not sign the values' );
	}

	public function test_a_sold_out_size_applies_when_the_shop_shows_what_is_sold_out(): void {
		update_option( 'woocommerce_hide_out_of_stock_items', 'no' );
		do_action( 'csearch_flush_cache' );
		$args = self::args( $this->send( '9 deck' )[0] );
		self::assertSame( '9-0', $args['filter_width'], 'shown when sold out: 9.0 lists the two decks, so it applies' );
		self::assertArrayNotHasKey( QueryRouter::NONE_ARG, $args );
	}

	public function test_the_panel_says_what_the_page_says(): void {
		$answer = $this->panel( '9 deck' );
		self::assertSame( 'Nothing in Width 9.0 is in stock right now.', $answer['unstocked'], 'the page\'s line (D-155)' );
		self::assertSame( 3, $answer['total'], 'See all is back, counting Decks' );
		self::assertNotEmpty( $answer['products'] );
		foreach ( $answer['products'] as $product ) {
			self::assertStringNotContainsString( '9.0', (string) $product['matched'], $product['name'] . ': its 9.0 is sold out' );
		}
		self::assertSame( '', $this->panel( '8.25 deck' )['unstocked'] );
		self::assertSame( '', $this->panel( 'deck' )['unstocked'], 'no value named' );
	}

	public function test_the_panel_suggests_what_the_page_lists(): void {
		$answer = $this->panel( '8.25 deck' );
		$names  = array_column( $answer['products'], 'name' );
		sort( $names );
		self::assertSame( array( 'Alpha Deck', 'Bravo Deck' ), $names, 'Charlie\'s 8.25 is sold out: Decks in 8.25 doesn\'t list it' );
		self::assertSame( count( $names ), $answer['total'] );
		foreach ( $answer['products'] as $product ) {
			self::assertSame( 'Matches Width: 8.25', $product['matched'], $product['name'] . ' has 8.25 in stock' );
		}
		// Without the lookup table WooCommerce filters by the terms alone: Charlie is listed, and its line stays.
		update_option( 'woocommerce_attribute_lookup_enabled', 'no' );
		do_action( 'csearch_flush_cache' );
		self::assertContains( 'Charlie Deck', array_column( $this->panel( '8.25 deck' )['products'], 'name' ) );
	}

	public function test_a_value_filters_only_products_that_have_the_attribute(): void {
		// D-156: hoodies have no width, so the 9 in "9 hoodie" stays a word.
		$hoodie = new WC_Product_Simple();
		$hoodie->set_name( 'Grey Hoodie' );
		$hoodie->set_regular_price( '50' );
		$hoodie->set_description( 'A heavy 9 oz fleece.' );
		$hoodie->set_category_ids( array( $this->catalogue->term( 'product_cat', 'Hoodies', 0, 'hoodies' ) ) );
		$hoodie->save();
		$this->runQueue();
		do_action( 'csearch_flush_cache' );

		$this->visit(
			add_query_arg(
				array(
					's'         => '9+hoodie',
					'post_type' => 'product',
				),
				home_url( '/' )
			)
		);
		$target = $this->service( QueryRouter::class )->target();
		self::assertStringNotContainsString( 'filter_width', (string) $target, 'no width filter' );
		self::assertNull( $target, 'the results for the words typed' );
		$listed = array_map( static fn( $p ): int => $p instanceof \WP_Post ? $p->ID : (int) $p, $GLOBALS['wp_query']->posts );
		self::assertContains( $hoodie->get_id(), $listed, 'Enter lands on results that list products' );
		self::assertSame( '', QueryRouter::from() );

		$answer = $this->panel( '9 hoodie' );
		self::assertSame( '', $answer['unstocked'], 'the shop never had a 9 hoodie to sell' );
		self::assertSame( count( $this->service( SearchService::class )->rankedIds( '9 hoodie', 'en', '' )['ids'] ), $answer['total'], 'See all counts the search for the words' );

		// The decks do carry widths: "9 deck" is still read as a width (and left out, D-154).
		self::assertSame( 'pa_width:9-0', self::args( $this->send( '9 deck' )[0] )[ QueryRouter::NONE_ARG ] );
	}
}
