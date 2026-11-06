<?php
/**
 * A value filters only where the real matches carry it.
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
use CreativeBranding\Search\Search\SearchService;
use CreativeBranding\Search\Search\Suggestions;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Tests\Support\IndexTestCase;
use CreativeBranding\Search\Tests\Support\WooCommerceCatalogue;
use WC_Product_Attribute;
use WC_Product_Simple;

/**
 * D-157 (1.6.6 brief, item 1), on a generic store: apparel in sizes, hard goods in widths. "9 hoodie" on Skatehouse
 * read 9 as a deck width because three decks mention a hoodie in their descriptions; here "9 pullover" and one board
 * whose description mentions a pullover. No word, slug or number of Skatehouse's.
 *
 * Apparel (no width): Fleece Pullover and Cotton Pullover, in Size S, M and L. Boards, in Width: Street Board (8.0
 * and 9.0, its description mentions a pullover and the word "stitched"), Cloud 9 Board (8.0 only), Pro 9 Board (9.0).
 */
final class StrongValueTest extends IndexTestCase {

	/**
	 * The categories, by name.
	 *
	 * @var array<string, int>
	 */
	private array $categories = array();

	public function set_up(): void {
		parent::set_up();
		WooCommerceCatalogue::ensureWidthTaxonomy();
		self::ensureAttribute( 'Size', 'size' );
		foreach ( array( '8.0', '9.0' ) as $width ) {
			$this->catalogue->term( 'pa_width', $width );
		}
		foreach ( array( 'S', 'M', 'L' ) as $size ) {
			$this->catalogue->term( 'pa_size', $size );
		}
		$this->categories = array(
			'Apparel' => $this->catalogue->term( 'product_cat', 'Apparel', 0, 'apparel' ),
			'Boards'  => $this->catalogue->term( 'product_cat', 'Boards', 0, 'boards' ),
		);
		$this->product( 'Fleece Pullover', 'Apparel', 'A warm layer.', array( 'pa_size' => array( 'S', 'M', 'L' ) ) );
		$this->product( 'Cotton Pullover', 'Apparel', 'A light layer.', array( 'pa_size' => array( 'S', 'M', 'L' ) ) );
		$this->product( 'Street Board', 'Boards', 'Seven plies, stitched grip edge. Pairs well with a pullover on cold days.', array( 'pa_width' => array( '8.0', '9.0' ) ) );
		$this->product( 'Cloud 9 Board', 'Boards', 'A mellow shape.', array( 'pa_width' => array( '8.0' ) ) );
		$this->product( 'Pro 9 Board', 'Boards', 'A steep shape.', array( 'pa_width' => array( '9.0' ) ) );
		Settings::set( 'filter_attributes', array( 'pa_width', 'pa_size' ) );
		$this->reindex();
	}

	public function tear_down(): void {
		update_option( 'woocommerce_hide_out_of_stock_items', 'no' );
		parent::tear_down();
	}

	public function test_a_value_carried_only_by_description_matches_stays_a_word(): void {
		// The pullovers have no width; the board that mentions a pullover does. The 9 stays a word.
		$target = $this->target( '9 pullover' );
		self::assertNull( $target, 'the results for the words typed: no width filter, no line' );
		self::assertSame( '', QueryRouter::from() );
		self::assertNotSame( array(), $GLOBALS['wp_query']->posts, 'and they list products' );

		$answer = $this->panel( '9 pullover' );
		self::assertSame( '', $answer['unstocked'], 'no line in the panel' );
		self::assertSame( count( $this->service( SearchService::class )->rankedIds( '9 pullover', 'en', '' )['ids'] ), $answer['total'], 'See all counts the page Enter opens' );
	}

	public function test_a_query_that_finds_only_description_matches_filters_as_before(): void {
		// "stitched" is only in the Street Board's description: it alone decides, as in 1.6.5.
		self::assertStringContainsString( 'filter_width=9-0', (string) $this->target( '9 stitched' ) );
	}

	public function test_strong_matches_that_carry_the_value_still_filter(): void {
		self::assertStringContainsString( 'filter_width=8-0', (string) $this->target( '8 board' ) );
		self::assertStringContainsString( 'filter_width=9-0', (string) $this->target( 'pro 9' ), 'a product named with a value it has keeps the filter' );
		// Cloud 9 Board (no 9.0) is among the boards, but Boards lists boards in 9.0: the filter stays (spec review).
		self::assertStringContainsString( 'filter_width=9-0', (string) $this->target( '9 boards' ), 'a name never drops a filter that lists products' );
	}

	public function test_a_number_in_a_product_name_is_that_name(): void {
		// Cloud 9 Board has no 9.0: "cloud 9" names it, not Width 9.0. Before, the 9 filtered the search for "cloud",
		// which listed nothing, and the page said that nothing in Width 9.0 was in stock.
		self::assertNull( $this->target( 'cloud 9' ), 'the results for the words' );
		$listed = array_map( static fn( $p ): string => get_the_title( $p ), $GLOBALS['wp_query']->posts );
		self::assertContains( 'Cloud 9 Board', $listed );
		self::assertSame( '', $this->panel( 'cloud 9' )['unstocked'] );
	}

	public function test_a_value_only_a_hidden_product_has_does_not_filter(): void {
		// A product hidden from the catalogue in Apparel has a width: the Apparel page can't list it, so "9 apparel" is
		// no width of the page (before, it filtered, listed nothing, and said nothing in 9.0 was in stock).
		$hidden = $this->product( 'Pullover Gift Box', 'Apparel', 'A box.', array( 'pa_width' => array( '9.0' ) ), 'instock', 'search' );
		$this->reindex();
		self::assertNull( $this->target( '9 apparel' ), 'the results for the words: no width filter, no line' );
		wp_delete_post( $hidden, true );
	}

	public function test_a_search_kept_to_a_category_judges_by_its_own_strong_matches(): void {
		// Kept to Boards, "pullover" finds only the Street Board, by its description: it alone decides, so 9 filters.
		$args = self::args( (string) $this->target( '9 pullover', 'boards' ) );
		self::assertSame( '9-0', $args['filter_width'] ?? '' );
		// Kept to Apparel, the pullovers decide: no width, so the 9 stays a word.
		self::assertNull( $this->target( '9 pullover', 'apparel' ) );
	}

	public function test_a_value_only_a_sold_out_product_has_is_the_unstocked_line(): void {
		// A sold-out product the page would list carries it: the shop has the size, nothing in it in stock (D-154).
		update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );
		$this->product( 'Pullover Gift Box', 'Apparel', 'A box.', array( 'pa_width' => array( '9.0' ) ), 'outofstock' );
		$this->reindex();
		$args = self::args( (string) $this->target( '9 apparel' ) );
		self::assertArrayNotHasKey( 'filter_width', $args );
		self::assertSame( 'pa_width:9-0', $args[ QueryRouter::NONE_ARG ] ?? '' );
	}

	/**
	 * Index again and forget the cached answers.
	 */
	private function reindex(): void {
		delete_transient( 'wc_attribute_taxonomies' );
		\WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
		$GLOBALS['wc_product_attributes']['pa_width'] = get_taxonomy( 'pa_width' );
		$GLOBALS['wc_product_attributes']['pa_size']  = get_taxonomy( 'pa_size' );
		$this->service( AttributeTerms::class )->rebuild();
		$this->service( Reindex::class )->runInline();
		do_action( 'csearch_flush_cache' );
	}

	/**
	 * An attribute, made when missing, with its taxonomy registered for this request.
	 *
	 * @param string $name Its name.
	 * @param string $slug Its slug.
	 */
	private static function ensureAttribute( string $name, string $slug ): int {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT attribute_id FROM %i WHERE attribute_name = %s', $wpdb->prefix . 'woocommerce_attribute_taxonomies', $slug ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a test fixture.
		if ( 0 === $id ) {
			unregister_taxonomy( 'pa_' . $slug );
			$made = wc_create_attribute(
				array(
					'name' => $name,
					'slug' => $slug,
				)
			);
			$id   = is_int( $made ) ? $made : 0;
		}
		delete_transient( 'wc_attribute_taxonomies' );
		\WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
		if ( ! taxonomy_exists( 'pa_' . $slug ) ) {
			register_taxonomy( 'pa_' . $slug, array( 'product' ), array( 'hierarchical' => false ) );
		}
		return $id;
	}

	/**
	 * A simple product with attribute values.
	 *
	 * @param string                  $name        Its name.
	 * @param string                  $category    Its category's name.
	 * @param string                  $description Its description.
	 * @param array<string, string[]> $attributes  Taxonomy to term names.
	 * @param string                  $stock       Its stock status.
	 * @param string                  $visibility  Its catalogue visibility.
	 */
	private function product( string $name, string $category, string $description, array $attributes, string $stock = 'instock', string $visibility = 'visible' ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( '50' );
		$product->set_description( $description );
		$product->set_category_ids( array( $this->categories[ $category ] ) );
		$product->set_stock_status( $stock );
		$product->set_catalog_visibility( $visibility );
		$set = array();
		foreach ( $attributes as $taxonomy => $values ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_id( (int) wc_attribute_taxonomy_id_by_name( $taxonomy ) );
			$attribute->set_name( $taxonomy );
			$attribute->set_options( array_map( fn( string $v ): int => $this->catalogue->term( $taxonomy, $v ), $values ) );
			$attribute->set_visible( true );
			$set[] = $attribute;
		}
		$product->set_attributes( $set );
		$id = $product->save();
		$this->runQueue();
		return $id;
	}

	/**
	 * Where a product search is sent, or null for the results for its words (the page is then visited).
	 *
	 * @param string $words The query.
	 * @param string $scope The category the search is kept to, by slug, or none.
	 */
	private function target( string $words, string $scope = '' ): ?string {
		$this->service( ProductSearch::class )->reset();
		$this->service( ResultsHooks::class )->reset();
		( new \ReflectionProperty( \WC_Query::class, 'chosen_attributes' ) )->setValue( null, null );
		$this->go_to(
			add_query_arg(
				array_filter(
					array(
						's'          => rawurlencode( $words ),
						'post_type'  => 'product',
						'product_cat' => $scope,
					)
				),
				home_url( '/' )
			)
		);
		return $this->service( QueryRouter::class )->target();
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
	 * The panel's answer.
	 *
	 * @param string $words The query.
	 * @return array<mixed>
	 */
	private function panel( string $words ): array {
		return $this->service( Suggestions::class )->answer( $words, 'en', 6 );
	}
}
