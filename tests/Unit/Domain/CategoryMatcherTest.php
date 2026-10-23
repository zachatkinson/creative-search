<?php
/**
 * Whether a query names a category.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Domain;

use CreativeBranding\Search\Domain\CategoryMatcher;
use PHPUnit\Framework\TestCase;

final class CategoryMatcherTest extends TestCase {

	private const CATEGORIES = array(
		10 => array( 'deck' ),
		11 => array( 'fingerboard', 'deck' ),
		12 => array( 'wheel' ),
		13 => array( 'hat', 'toqu' ),
		14 => array( 'wheel', 'wheel' ),
	);

	public function test_a_query_names_the_category_whose_whole_name_it_is(): void {
		self::assertSame( array( 10 ), CategoryMatcher::match( array( array( 'deck' ) ), self::CATEGORIES ), 'Decks, not Fingerboard decks' );
		self::assertSame( array( 11 ), CategoryMatcher::match( array( array( 'deck' ), array( 'fingerboard' ) ), self::CATEGORIES ), 'in any order' );
		self::assertSame( array( 13 ), CategoryMatcher::match( array( array( 'toqu' ), array( 'hat' ) ), self::CATEGORIES ) );
	}

	public function test_a_synonym_counts_as_the_word(): void {
		self::assertSame( array( 10 ), CategoryMatcher::match( array( array( 'board', 'deck' ) ), self::CATEGORIES ) );
	}

	public function test_part_of_a_name_or_more_than_it_names_nothing(): void {
		self::assertSame( array(), CategoryMatcher::match( array( array( 'fingerboard' ) ), self::CATEGORIES ) );
		self::assertSame( array(), CategoryMatcher::match( array( array( 'deck' ), array( 'kazumi' ) ), self::CATEGORIES ) );
		self::assertSame( array(), CategoryMatcher::match( array( array( 'hat' ), array( 'hat' ) ), self::CATEGORIES ), 'one word cannot match two' );
		self::assertSame( array(), CategoryMatcher::match( array(), self::CATEGORIES ) );
		self::assertSame( array(), CategoryMatcher::match( array(), array( 9 => array() ) ), 'a name with no word left names nothing' );
	}

	public function test_two_categories_named_alike_both_match(): void {
		self::assertSame( array( 12, 14 ), CategoryMatcher::match( array( array( 'wheel' ) ), self::CATEGORIES ), 'a repeated word in a name counts once' );
		self::assertSame( array( 7, 3 ), CategoryMatcher::match( array( 5 => array( 'deck' ) ), array( 7 => array( 'deck' ), 3 => array( 'deck' ) ) ), 'the ids as given, whatever the query\'s keys' );
	}
}
