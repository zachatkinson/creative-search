<?php
/**
 * Scopes tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Domain;

use CreativeBranding\Search\Domain\Scopes;
use PHPUnit\Framework\TestCase;

/**
 * The categories the panel offers to search within (D-113).
 */
final class ScopesTest extends TestCase {

	// Skateboards (1) holds Decks (2) and Completes (3); Clothing (4) holds Hoodies (5).
	private const PARENTS = array(
		1 => 0,
		2 => 1,
		3 => 1,
		4 => 0,
		5 => 4,
	);

	public function test_the_categories_holding_most_products_deepest_first(): void {
		$categories = array(
			11 => array( 1, 2 ),
			12 => array( 1, 2 ),
			13 => array( 1, 2, 2 ),
			14 => array( 1, 3 ),
			15 => array( 4, 5 ),
			16 => array( 4, 5 ),
		);
		self::assertSame( array( 1, 2 ), Scopes::pick( $categories, self::PARENTS, 6, array() ), 'Skateboards holds four, Decks three (a product counted once), Hoodies two' );
		self::assertSame( array( 1 ), Scopes::pick( $categories, self::PARENTS, 6, array(), 1 ) );
		self::assertSame( array(), Scopes::pick( $categories, self::PARENTS, 6, array(), 0 ) );
		self::assertSame( array(), Scopes::pick( $categories, self::PARENTS, 6, array(), -1 ) );
	}

	public function test_a_parent_holding_no_more_than_its_child_gives_way(): void {
		$categories = array(
			11 => array( 1, 2 ),
			12 => array( 1, 2 ),
			13 => array( 4, 5 ),
			14 => array( 4, 5 ),
			15 => array( 4 ),
		);
		self::assertSame( array( 4, 2 ), Scopes::pick( $categories, self::PARENTS, 9, array() ), 'Decks, not Skateboards; Clothing holds one more than Hoodies, so it stays first' );
		self::assertSame( array( 4, 5 ), Scopes::pick( array_slice( $categories, 2, null, true ), self::PARENTS, 9, array() ), 'more products first' );
		self::assertSame( array( 1, 2 ), Scopes::pick( array( 11 => array( 1, 2 ), 12 => array( 1, 2 ), 14 => array( 1, 3 ) ), self::PARENTS, 9, array() ), 'a child holding fewer leaves its parent' );
		self::assertSame( array( 2 ), Scopes::pick( array( 11 => array( 1, 2 ), 12 => array( 1, 2 ), 13 => array( 4 ) ), array( 2 => 1 ), 9, array() ), 'only a known parent gives way' );
		self::assertSame( array( 1, 5 ), Scopes::pick( array( 11 => array( 1, 5 ), 12 => array( 1, 5 ) ), array(), 9, array() ), 'a category whose parent is unknown is no child of category 1' );
	}

	public function test_ties_go_to_the_lower_id(): void {
		$categories = array(
			11 => array( 5 ),
			12 => array( 5 ),
			13 => array( 3 ),
			14 => array( 3 ),
		);
		self::assertSame( array( 3, 5 ), Scopes::pick( $categories, array(), 9, array() ) );
	}

	public function test_a_category_needs_two_of_the_first_ten_products(): void {
		$categories = array();
		for ( $id = 1; $id <= 10; $id++ ) {
			$categories[ $id ] = array( 100 + $id );
		}
		$categories[11] = array( 101 );
		$categories[12] = array( 102 );
		self::assertSame( array(), Scopes::pick( $categories, array(), 20, array() ), 'the eleventh and twelfth are not looked at' );
		$categories[10] = array( 101 );
		self::assertSame( array( 101 ), Scopes::pick( $categories, array(), 20, array() ), 'the tenth is' );
	}

	public function test_a_category_already_suggested_or_holding_everything_found_is_left_out(): void {
		$categories = array(
			11 => array( 2 ),
			12 => array( 2 ),
			13 => array( 5 ),
			14 => array( 5 ),
		);
		self::assertSame( array( 5 ), Scopes::pick( $categories, array(), 9, array( 2 ) ) );
		$all = array(
			11 => array( 2 ),
			12 => array( 2 ),
		);
		self::assertSame( array(), Scopes::pick( $all, array(), 2, array() ), 'every product found is in Decks: searching within it changes nothing' );
		self::assertSame( array( 2 ), Scopes::pick( $all, array(), 3, array() ), 'a third product found elsewhere: it narrows' );
		self::assertSame( array( 2 ), Scopes::pick( $all + array( 13 => array() ), array(), 3, array() ), 'it holds two of three looked at' );
	}
}
