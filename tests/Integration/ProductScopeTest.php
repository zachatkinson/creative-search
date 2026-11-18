<?php
/**
 * A ranking kept to some products by id.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Index\IndexQueue;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Search\SearchService;
use CreativeBranding\Search\Tests\Support\IndexTestCase;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * `product_ids` (1.7.0 brief, item 2, D-162) on a generic store, a kitchen shop: Cookware (Cast Iron Skillet, Steel
 * Skillet, Skillet Lid) and Tea (Ceramic Teapot, Glass Teapot, Teapot Warmer, and Loose Leaf Tea in two weights, a
 * variable product).
 */
final class ProductScopeTest extends IndexTestCase {

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

	/**
	 * The variations of Loose Leaf Tea.
	 *
	 * @var int[]
	 */
	private array $variations = array();

	public function set_up(): void {
		parent::set_up();
		$this->categories = array(
			'Cookware' => $this->catalogue->term( 'product_cat', 'Cookware', 0, 'cookware' ),
			'Tea'      => $this->catalogue->term( 'product_cat', 'Tea', 0, 'tea' ),
		);
		foreach (
			array(
				'Cast Iron Skillet' => 'Cookware',
				'Steel Skillet'     => 'Cookware',
				'Skillet Lid'       => 'Cookware',
				'Ceramic Teapot'    => 'Tea',
				'Glass Teapot'      => 'Tea',
				'Teapot Warmer'     => 'Tea',
			) as $name => $category
		) {
			$product = new WC_Product_Simple();
			$product->set_name( $name );
			$product->set_regular_price( '20' );
			$product->set_category_ids( array( $this->categories[ $category ] ) );
			$this->ids[ $name ] = $product->save();
		}
		$this->ids['Loose Leaf Tea'] = $this->variable( 'Loose Leaf Tea', array( '100 g', '250 g' ) );
		$this->service( IndexQueue::class )->clear();
		$this->service( Reindex::class )->runInline();
		do_action( 'csearch_flush_cache' );
	}

	public function test_the_ranking_is_kept_to_the_ids_given(): void {
		$answer = $this->ask( 'skillet', array( $this->ids['Steel Skillet'], $this->ids['Skillet Lid'], $this->ids['Glass Teapot'] ) );
		self::assertEqualsCanonicalizing( array( $this->ids['Steel Skillet'], $this->ids['Skillet Lid'] ), $answer['ids'] );
		self::assertNotContains( $this->ids['Cast Iron Skillet'], $answer['ids'], 'a product not in the list is left out' );
	}

	public function test_a_variation_counts_as_its_parent(): void {
		$answer = $this->ask( 'tea', array( $this->variations[1], $this->ids['Glass Teapot'] ) );
		self::assertSame( array( $this->ids['Loose Leaf Tea'] ), array_values( array_intersect( $answer['ids'], array( $this->ids['Loose Leaf Tea'] ) ) ), 'the parent’s id' );
		self::assertNotContains( $this->variations[1], $answer['ids'] );
		self::assertNotContains( $this->ids['Ceramic Teapot'], $answer['ids'] );
	}

	public function test_an_empty_list_finds_nothing(): void {
		$answer = $this->ask( 'skillet', array() );
		self::assertIsArray( $answer, 'an answer, not null' );
		self::assertSame( array(), $answer['ids'], 'no products, not the whole catalogue' );
		self::assertSame( array(), $this->ask( 'skillet', array( 'none', -4, 0 ) )['ids'], 'a list with no id in it is empty too' );
		self::assertCount( 3, $this->ask( 'skillet', 'not a list' )['ids'], 'a value of the wrong type is ignored' );
	}

	public function test_the_cache_is_kept_apart_per_list(): void {
		$first  = $this->ask( 'skillet', array( $this->ids['Cast Iron Skillet'] ) )['ids'];
		$second = $this->ask( 'skillet', array( $this->ids['Steel Skillet'] ) )['ids'];
		self::assertSame( array( $this->ids['Cast Iron Skillet'] ), $first );
		self::assertSame( array( $this->ids['Steel Skillet'] ), $second, 'another list, another answer' );
		self::assertSame( $first, $this->ask( 'skillet', array( $this->ids['Cast Iron Skillet'], $this->ids['Cast Iron Skillet'] ) )['ids'] );
	}

	public function test_a_category_scope_and_the_ids_narrow_each_other(): void {
		$search   = $this->service( SearchService::class );
		$tea      = (int) get_term( $this->categories['Tea'], 'product_cat' )->term_taxonomy_id;
		$cookware = (int) get_term( $this->categories['Cookware'], 'product_cat' )->term_taxonomy_id;
		$products = array( $this->ids['Teapot Warmer'], $this->ids['Steel Skillet'] );
		foreach ( array( false, true ) as $explain ) {
			$how = $explain ? 'ranked in PHP' : 'ranked in the database';
			self::assertCount( 3, $search->run( 'teapot', 'en', SearchService::MAX_IDS, 0, true, $explain, array( $tea ) )->productIds(), $how );
			self::assertSame( array( $this->ids['Teapot Warmer'] ), $search->run( 'teapot', 'en', SearchService::MAX_IDS, 0, true, $explain, array( $tea ), $products )->productIds(), 'the ids narrow the category, ' . $how );
			self::assertSame( array(), $search->run( 'teapot', 'en', SearchService::MAX_IDS, 0, true, $explain, array( $cookware ), $products )->productIds(), 'the category narrows the ids, ' . $how );
		}
	}

	public function test_the_cap_counts_only_the_products_in_the_list(): void {
		$search = $this->service( SearchService::class );
		$all    = $search->run( 'teapot', 'en', 2 )->productIds();
		$rest   = array_values( array_diff( array( $this->ids['Ceramic Teapot'], $this->ids['Glass Teapot'], $this->ids['Teapot Warmer'] ), $all ) );
		self::assertCount( 1, $rest );
		$kept = array_merge( $rest, array( $all[0] ) );
		foreach ( array( false, true ) as $explain ) {
			$hits = $search->run( 'teapot', 'en', 2, 0, true, $explain, array(), $kept )->productIds();
			self::assertEqualsCanonicalizing( $kept, $hits, 'the limit is filled from the list, not cut before it' );
		}
	}

	/**
	 * Ask the filter.
	 *
	 * @param string $words    The query.
	 * @param mixed  $products The `product_ids` argument.
	 * @return array{ids: int[], corrected: string|null, query: string, lang: string}
	 */
	private function ask( string $words, mixed $products ): array {
		$answer = apply_filters( 'csearch_ranked_ids', null, $words, array( 'product_ids' => $products ) );
		self::assertIsArray( $answer );
		return $answer;
	}

	/**
	 * A variable product in some weights.
	 *
	 * @param string   $name    Its name.
	 * @param string[] $weights Its weights.
	 */
	private function variable( string $name, array $weights ): int {
		$product   = new WC_Product_Variable();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Weight' );
		$attribute->set_options( $weights );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_name( $name );
		$product->set_category_ids( array( $this->categories['Tea'] ) );
		$product->set_attributes( array( $attribute ) );
		$id = $product->save();
		foreach ( $weights as $weight ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $id );
			$variation->set_regular_price( '12' );
			$variation->set_attributes( array( 'weight' => $weight ) );
			$this->variations[] = $variation->save();
		}
		WC_Product_Variable::sync( $id );
		return $id;
	}
}
