<?php
/**
 * The ranking other plugins ask for from PHP.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Search\OwnerRules;
use CreativeBranding\Search\Search\SearchLog;
use CreativeBranding\Search\Search\SearchService;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Tests\Support\IndexTestCase;
use WC_Product_Simple;

/**
 * `csearch_ranked_ids` (1.7.0 brief, item 1, D-161) on a generic store, a kitchen shop, and a caller that is neither
 * the shop nor any plugin the agency ships: Cookware (Cast Iron Skillet, Steel Skillet, Skillet Lid) and Tea
 * (Ceramic Teapot, Glass Teapot, Teapot Warmer).
 */
final class RankedIdsFilterTest extends IndexTestCase {

	/**
	 * Product ids by name.
	 *
	 * @var array<string, int>
	 */
	private array $ids = array();

	/**
	 * Category ids by name.
	 *
	 * @var array<string, int>
	 */
	private array $categories = array();

	public function set_up(): void {
		parent::set_up();
		$this->categories = array(
			'Cookware' => $this->catalogue->term( 'product_cat', 'Cookware', 0, 'cookware' ),
			'Tea'      => $this->catalogue->term( 'product_cat', 'Tea', 0, 'tea' ),
		);
		foreach (
			array(
				'Cast Iron Skillet' => array( 'Cookware', 'Seasoned and heavy.' ),
				'Steel Skillet'     => array( 'Cookware', 'Light and quick to heat.' ),
				'Skillet Lid'       => array( 'Cookware', 'Fits a twelve inch pan.' ),
				'Ceramic Teapot'    => array( 'Tea', 'Holds four cups.' ),
				'Glass Teapot'      => array( 'Tea', 'Holds two cups.' ),
				'Teapot Warmer'     => array( 'Tea', 'A candle keeps the pot warm.' ),
			) as $name => [ $category, $description ]
		) {
			$this->ids[ $name ] = $this->simple( $name, $category, $description );
		}
	}

	public function test_the_filter_answers_with_the_ranked_ids(): void {
		$this->reindex();
		$answer = apply_filters( 'csearch_ranked_ids', null, '  skillet ', array( 'lang' => 'en_US' ) );
		self::assertIsArray( $answer );
		self::assertSame( array( 'ids', 'corrected', 'query', 'lang' ), array_keys( $answer ) );
		self::assertSame( 'skillet', $answer['query'], 'the query as cleaned' );
		self::assertSame( 'en', $answer['lang'] );
		self::assertNull( $answer['corrected'] );
		self::assertEqualsCanonicalizing( array( $this->ids['Cast Iron Skillet'], $this->ids['Steel Skillet'], $this->ids['Skillet Lid'] ), $answer['ids'] );
		self::assertSame( $this->service( SearchService::class )->rankedIds( 'skillet', 'en', '' )['ids'], $answer['ids'], 'ranked as the results page ranks' );

		$typo = apply_filters( 'csearch_ranked_ids', null, 'teapott', array() );
		self::assertSame( 'teapot', $typo['corrected'], 'typos are tolerated as on the results page' );
		self::assertContains( $this->ids['Ceramic Teapot'], $typo['ids'] );
		self::assertSame( 'en', $typo['lang'], 'the site’s language by default' );
	}

	public function test_the_limit_and_the_arguments_of_the_wrong_type(): void {
		$this->reindex();
		$all = apply_filters( 'csearch_ranked_ids', null, 'teapot', array() )['ids'];
		self::assertCount( 3, $all );
		self::assertSame( array_slice( $all, 0, 2 ), apply_filters( 'csearch_ranked_ids', null, 'teapot', array( 'limit' => 2 ) )['ids'] );
		self::assertCount( 1, apply_filters( 'csearch_ranked_ids', null, 'teapot', array( 'limit' => 0 ) )['ids'], 'at least 1' );
		self::assertSame(
			$all,
			apply_filters(
				'csearch_ranked_ids',
				null,
				'teapot',
				array(
					'limit'   => 'two',
					'lang'    => array( 'en' ),
					'exact'   => 'yes',
					'source'  => 7,
					'unknown' => true,
				)
			)['ids'],
			'a value of the wrong type is ignored, an unknown key too'
		);
		self::assertSame( $all, apply_filters( 'csearch_ranked_ids', null, 'teapot', 'not an array' )['ids'] );
		self::assertSame( array(), apply_filters( 'csearch_ranked_ids', null, array( 'teapot' ), array() )['ids'], 'a query that is not a string finds nothing' );
		self::assertSame( array(), apply_filters( 'csearch_ranked_ids', null, '   ', array() )['ids'] );

		// "teapot warmerr" without its exact flag drops nothing it can match; with it, the shopper's exact words.
		$exact = apply_filters( 'csearch_ranked_ids', null, 'teapot kettle', array( 'exact' => true ) );
		self::assertSame( array(), $exact['ids'], 'exact: no word dropped' );
		self::assertNotSame( array(), apply_filters( 'csearch_ranked_ids', null, 'teapot kettle', array() )['ids'], 'not exact: the word nothing has is dropped' );
	}

	public function test_null_while_the_index_is_not_ready(): void {
		// The products are saved but never indexed: the live generation holds nothing.
		self::assertNull( apply_filters( 'csearch_ranked_ids', null, 'skillet', array() ) );
		$this->reindex();
		self::assertIsArray( apply_filters( 'csearch_ranked_ids', null, 'skillet', array() ) );
	}

	public function test_another_answer_is_left_alone(): void {
		$this->reindex();
		$theirs = array( 'ids' => array( 1, 2, 3 ) );
		add_filter( 'csearch_ranked_ids', static fn(): array => $theirs, 5, 0 );
		self::assertSame( $theirs, apply_filters( 'csearch_ranked_ids', null, 'skillet', array() ) );
	}

	public function test_the_owners_pins_are_left_out(): void {
		$this->reindex();
		Settings::set(
			OwnerRules::PINS,
			array(
				array(
					'query' => 'skillet',
					'lang'  => '',
					'ids'   => array( $this->ids['Glass Teapot'] ),
				),
			)
		);
		do_action( 'csearch_flush_cache' );
		self::assertSame( $this->ids['Glass Teapot'], $this->service( SearchService::class )->rankedIds( 'skillet', 'en', '' )['ids'][0], 'the results page puts the pin first' );
		self::assertNotContains( $this->ids['Glass Teapot'], apply_filters( 'csearch_ranked_ids', null, 'skillet', array() )['ids'], 'pins are the shop’s own results' );
	}

	public function test_hidden_products_stay_out_and_nothing_is_logged_without_a_source(): void {
		$hidden = $this->simple( 'Hidden Skillet', 'Cookware', 'Not for sale yet.', 'hidden' );
		$this->reindex();
		self::assertNotContains( $hidden, apply_filters( 'csearch_ranked_ids', null, 'skillet', array() )['ids'] );
		self::assertSame( array(), $this->service( SearchLog::class )->pending(), 'no source, no row' );
	}

	public function test_sold_out_products_stay_out_when_the_shop_hides_them(): void {
		$product = wc_get_product( $this->ids['Steel Skillet'] );
		$product->set_stock_status( 'outofstock' );
		$product->save();
		$this->reindex();
		update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );
		self::assertNotContains( $this->ids['Steel Skillet'], apply_filters( 'csearch_ranked_ids', null, 'skillet', array() )['ids'], 'as the results page leaves it out' );
		update_option( 'woocommerce_hide_out_of_stock_items', 'no' );
		self::assertContains( $this->ids['Steel Skillet'], apply_filters( 'csearch_ranked_ids', null, 'skillet', array() )['ids'], 'and lists it when the shop shows sold-out products' );
	}

	/**
	 * A simple product.
	 *
	 * @param string $name        Its name.
	 * @param string $category    Its category's name.
	 * @param string $description Its description.
	 * @param string $visibility  Its catalogue visibility.
	 */
	private function simple( string $name, string $category, string $description, string $visibility = 'visible' ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( '20' );
		$product->set_description( $description );
		$product->set_category_ids( array( $this->categories[ $category ] ) );
		$product->set_catalog_visibility( $visibility );
		$id = $product->save();
		$this->service( \CreativeBranding\Search\Index\IndexQueue::class )->clear();
		return $id;
	}

	/**
	 * Index the store and forget the cached answers.
	 */
	private function reindex(): void {
		$this->service( Reindex::class )->runInline();
		do_action( 'csearch_flush_cache' );
	}
}
