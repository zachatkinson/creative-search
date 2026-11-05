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
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Query;

/**
 * D-154 (1.6.5 brief, item 1): on the demo every deck carries each width of its range, at stock 0 where it isn't
 * made, and the shop hides what is sold out. "9 deck" opened Decks in 9.00″, which listed nothing. Now the size is
 * left out, the page says so with the closest widths in stock, and the search is logged as finding nothing.
 *
 * Decks here: Alpha in 7.75 (sold out), 8.0, 8.25 and 9.0 (sold out), Mellow concave; Bravo in 8.25, 8.5 (sold
 * out), 8.75 and 9.0 (sold out), Steep concave. In stock in Decks: 8.0, 8.25 and 8.75.
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
		$decks = $this->catalogue->term( 'product_cat', 'Decks', 0, 'decks' );
		$this->deck( 'Alpha', $decks, array( '7.75' => false, '8.0' => true, '8.25' => true, '9.0' => false ), 'Mellow' );
		$this->deck( 'Bravo', $decks, array( '8.25' => true, '8.5' => false, '8.75' => true, '9.0' => false ), 'Steep' );
		update_option( 'woocommerce_attribute_lookup_enabled', 'yes' );
		update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );
		$lookup = wc_get_container()->get( LookupDataStore::class );
		foreach ( get_posts( array( 'post_type' => 'product', 'fields' => 'ids', 'numberposts' => -1 ) ) as $product ) {
			$lookup->create_data_for_product( (int) $product );
		}
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
	 */
	private function deck( string $name, int $decks, array $widths, string $concave ): int {
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
		self::assertSame( 2, self::decksListed() );
		self::assertSame( self::decksListed(), $this->panel( '9 deck' )['total'], 'See all counts Decks' );

		// The page landed on.
		$this->visit( $target );
		self::assertSame( '9 deck', QueryRouter::from() );
		$line = $this->service( ResultsView::class )->routedLine();
		self::assertStringContainsString( '<p class="csearch-results-unstocked">Nothing in Width 9.0 is in stock right now.</p>', $line );
		self::assertStringContainsString( '<p class="csearch-results-closest">Closest in stock: <a href="', $line );
		self::assertStringContainsString( '>Width 8.75</a></p>', $line, 'the link names the attribute, so it reads alone' );
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

	public function test_the_smallest_size_has_one_closest_value_and_a_middle_one_two(): void {
		$args = self::args( $this->send( '7.75 deck' )[0] );
		self::assertSame( 'pa_width:8-0', $args[ QueryRouter::NEAR_ARG ], 'nothing below 7.75 in stock' );

		[ $target ] = $this->send( '8.5 deck' );
		self::assertSame( 'pa_width:8-25,pa_width:8-75', self::args( $target )[ QueryRouter::NEAR_ARG ], 'lower first' );
		$this->visit( $target );
		self::assertMatchesRegularExpression( '/Closest in stock: <a href="[^"]+">Width 8.25<\/a> or <a href="[^"]+">Width 8.75<\/a>/', $this->service( ResultsView::class )->routedLine() );
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
}
