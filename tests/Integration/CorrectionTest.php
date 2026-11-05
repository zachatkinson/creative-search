<?php
/**
 * A misspelling finds the products before the descriptions (D-146).
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Index\IndexQueue;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Search\SearchService;
use CreativeBranding\Search\Search\Suggestions;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Tests\Support\IndexTestCase;
use WC_Product_Simple;

/**
 * The 1.6.3 brief's item 2, through the one SQL statement: "hody" read as "hoody" finds what "hoody" finds, a
 * short typo finds nothing through a description alone, and a page asked for by name keeps the products that only
 * mention its words out of the panel.
 *
 * @group sql-mutation
 */
final class CorrectionTest extends IndexTestCase {

	/**
	 * Product ids by name.
	 *
	 * @var array<string, int>
	 */
	private array $ids = array();

	public function set_up(): void {
		parent::set_up();
		$hoodies = $this->catalogue->term( 'product_cat', 'Hoodies and sweaters', 0, 'hoodies-and-sweaters' );
		$decks   = $this->catalogue->term( 'product_cat', 'Decks', 0, 'decks' );
		$this->ids['Blotto Hoody Black'] = $this->simple( 'Blotto Hoody Black', 'outofstock', 40, $hoodies );
		$this->ids['Blotto Hoody Grey']  = $this->simple( 'Blotto Hoody Grey', 'instock', 30, $hoodies );
		$this->ids['Classic Hoodie']     = $this->simple( 'Classic Hoodie', 'instock', 20, $hoodies );
		$this->ids['Crewneck Sweater']   = $this->simple( 'Crewneck Sweater', 'instock', 10, $hoodies );
		// "body" is one letter from "hody", in a description only.
		$this->ids['HZRD Deck'] = $this->simple( 'HZRD Deck', 'instock', 90, $decks, 'A light body with a stiff pop.' );
		// "shipping" in a description only, and a page named for it.
		$this->ids['Brick Deck'] = $this->simple( 'Brick Deck', 'instock', 80, $decks, 'Free shipping on every deck.' );
		self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Shipping &amp; returns',
				'post_content' => 'Free shipping over $100.',
				'post_status'  => 'publish',
			)
		);
		$this->service( IndexQueue::class )->clear();
		Settings::update( array( 'index_pages' => 'yes' ) );
		$this->service( Reindex::class )->runInline();
	}

	public function tear_down(): void {
		Settings::update(
			array(
				'index_pages'    => 'no',
				'in_stock_first' => 'no',
			)
		);
		parent::tear_down();
	}

	/**
	 * A simple product in a category.
	 *
	 * @param string $name     Name.
	 * @param string $status   Stock status.
	 * @param int    $sales    Total sales.
	 * @param int    $category Category id.
	 * @param string $text     Description.
	 */
	private function simple( string $name, string $status, int $sales, int $category, string $text = '' ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_description( $text );
		$product->set_status( 'publish' );
		$product->set_regular_price( '60' );
		$product->set_stock_status( $status );
		$product->set_total_sales( $sales );
		$product->set_category_ids( array( $category ) );
		return $product->save();
	}

	/**
	 * The products' names in an order of ids.
	 *
	 * @param int[] $ids Product ids.
	 * @return string[]
	 */
	private function names( array $ids ): array {
		$names = array_flip( $this->ids );
		return array_values( array_map( static fn( int $id ): string => $names[ $id ] ?? (string) $id, $ids ) );
	}

	/**
	 * The panel's answer.
	 *
	 * @param string $q What is typed.
	 * @return array<string, mixed>
	 */
	private function panel( string $q ): array {
		return $this->service( Suggestions::class )->answer( $q, 'en', 6 );
	}

	/**
	 * The results page's products.
	 *
	 * @param string $q The query.
	 * @return string[]
	 */
	private function page( string $q ): array {
		return $this->names( $this->service( SearchService::class )->rankedIds( $q, 'en', '' )['ids'] );
	}

	public function test_a_typo_finds_what_its_corrected_word_finds(): void {
		$hoody = $this->page( 'hoody' );
		self::assertEqualsCanonicalizing( array( 'Blotto Hoody Black', 'Blotto Hoody Grey', 'Classic Hoodie', 'Crewneck Sweater' ), $hoody, 'typed in full: every hoodie and sweater, through the stem and the category' );
		self::assertEqualsCanonicalizing( $hoody, $this->page( 'hody' ), 'read as "hoody", it finds what "hoody" finds (a typo weighs less, so the order may differ)' );
		$typo = $this->panel( 'hody' );
		self::assertSame( 'hoody', $typo['corrected'] );
		self::assertSame( $this->panel( 'hoody' )['total'], $typo['total'], '"See all" counts the same' );
		self::assertNotContains( 'HZRD Deck', $this->names( array_column( $typo['products'], 'id' ) ) );
	}

	public function test_a_short_typo_reaches_no_product_through_its_description_alone(): void {
		self::assertNotContains( 'HZRD Deck', $this->page( 'hody' ), '"body" is in its description only' );
		self::assertSame( array( 'HZRD Deck' ), $this->page( 'body' ), 'typed, the word finds it' );
		self::assertSame( array(), $this->page( 'bodu' ), 'nor does a four-letter typo read as "body" itself' );
	}

	public function test_a_page_asked_for_keeps_the_products_that_only_mention_it_out_of_the_panel(): void {
		$answer = $this->panel( 'shipping' );
		self::assertSame( 'Shipping & returns', $answer['content'][0]['name'] );
		self::assertSame( array(), $answer['products'], 'the deck only mentions shipping' );
		self::assertSame( 1, $answer['total'], 'the results page still lists it' );
		self::assertSame( array( 'Brick Deck' ), $this->names( array_column( $this->panel( 'brick' )['products'], 'id' ) ), 'no page asked for: as before' );
	}

	public function test_in_stock_first_still_holds_within_the_corrected_word(): void {
		Settings::update( array( 'in_stock_first' => 'yes' ) );
		do_action( 'csearch_flush_cache' );
		$order = $this->page( 'hody' );
		self::assertSame( 'Blotto Hoody Black', end( $order ), 'sold out last' );
		self::assertEqualsCanonicalizing( $this->page( 'hoody' ), $order );
	}
}
