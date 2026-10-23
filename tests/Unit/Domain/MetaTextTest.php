<?php
/**
 * MetaText tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Domain;

use CreativeBranding\Search\Domain\MetaText;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * A custom field's stored value as the texts the index reads (D-119).
 */
final class MetaTextTest extends TestCase {

	public function test_strings_and_numbers_are_kept_trimmed(): void {
		self::assertSame( array( 'Canadian maple' ), MetaText::values( '  Canadian maple ' ) );
		self::assertSame( array( '32' ), MetaText::values( 32 ) );
		self::assertSame( array( '8.25' ), MetaText::values( 8.25 ) );
		self::assertSame( array( '0' ), MetaText::values( '0' ), 'a zero is a value' );
		self::assertSame( array(), MetaText::values( '   ' ) );
		self::assertSame( array(), MetaText::values( '' ) );
	}

	public function test_true_false_null_and_objects_are_dropped(): void {
		self::assertSame( array(), MetaText::values( true ) );
		self::assertSame( array(), MetaText::values( false ) );
		self::assertSame( array(), MetaText::values( null ) );
		self::assertSame( array(), MetaText::values( new stdClass() ) );
	}

	public function test_lists_are_flattened_in_order_three_levels_deep(): void {
		self::assertSame( array( 'red', 'blue' ), MetaText::values( array( 'red', '', 'blue', null ) ), 'an ACF checkbox' );
		self::assertSame( array( 'a', 'b', 'c', 'd' ), MetaText::values( array( 'x' => 'a', array( 'b', array( 'c' ) ), 'd' ) ) );
		self::assertSame( array( 'one', 'two' ), MetaText::values( array( 'one', array( array( array( 'four' ) ), 'two' ) ) ), 'the fourth level is not read' );
		self::assertSame( array(), MetaText::values( array( 'x' ), 0 ), 'a list at no depth left is not read' );
		self::assertSame( array( 'x' ), MetaText::values( 'x', 0 ), 'a value is read at any depth' );
		self::assertSame( array( 'x' ), MetaText::values( array( 'x' ), 1 ) );
		self::assertSame( array(), MetaText::values( array( array( 'x' ) ), 1 ) );
	}

	public function test_texts_are_capped_together(): void {
		self::assertSame( array( 'abc', 'de' ), MetaText::cap( array( 'abc', 'defg', 'hi' ), 5 ), 'the text that crosses the cap is cut' );
		self::assertSame( array( 'abc', 'defg' ), MetaText::cap( array( 'abc', 'defg', 'hi' ), 7 ), 'nothing is left after the cap' );
		self::assertSame( array( 'abc', 'defg', 'hi' ), MetaText::cap( array( 'abc', 'defg', 'hi' ), 9 ) );
		self::assertSame( array( 'éé' ), MetaText::cap( array( 'ééé' ), 2 ), 'characters, not bytes' );
		self::assertSame( array( 'éé', 'a' ), MetaText::cap( array( 'éé', 'ab' ), 3 ), 'what is left counts characters too' );
		self::assertSame( array(), MetaText::cap( array( 'abc' ), 0 ) );
		self::assertSame( array(), MetaText::cap( array(), 10 ) );
	}
}
