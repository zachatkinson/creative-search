<?php
/**
 * Cast tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Support;

use CreativeBranding\Search\Support\Cast;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Untyped values become the type asked for, or the fallback.
 */
final class CastTest extends TestCase {

	public function test_int(): void {
		self::assertSame( 12, Cast::int( '12' ) );
		self::assertSame( 12, Cast::int( 12 ) );
		self::assertSame( 3, Cast::int( '3.9' ) );
		self::assertSame( 0, Cast::int( 'twelve' ) );
		self::assertSame( 7, Cast::int( null, 7 ) );
		self::assertSame( 7, Cast::int( array( 1 ), 7 ) );
		self::assertSame( 0, Cast::int( true ), 'a boolean is not numeric' );
	}

	public function test_str(): void {
		self::assertSame( 'deck', Cast::str( 'deck' ) );
		self::assertSame( '12', Cast::str( 12 ) );
		self::assertSame( '1', Cast::str( true ) );
		self::assertSame( '', Cast::str( null ) );
		self::assertSame( 'x', Cast::str( array( 'a' ), 'x' ) );
		self::assertSame( 'x', Cast::str( new stdClass(), 'x' ) );
	}

	public function test_lists(): void {
		self::assertSame( array( 1, 2, 3 ), Cast::ints( array( '1', 2, 'three', null, 3.0 ) ) );
		self::assertSame( array(), Cast::ints( '1,2' ) );
		self::assertSame( array( 'a', '2', '1' ), Cast::strings( array( 'k' => 'a', 2, array( 'x' ), true, null ) ) );
		self::assertSame( array(), Cast::strings( 'a' ) );
	}

	public function test_fields(): void {
		self::assertSame(
			array(
				1 => 'Deck',
				2 => array( 'KAZ-1', '12' ),
				3 => '',
			),
			Cast::fields(
				array(
					1      => 'Deck',
					'2'    => array( 'KAZ-1', 12, array( 'no' ) ),
					3      => null,
					'name' => 'dropped',
				)
			)
		);
		self::assertSame( array(), Cast::fields( 'Deck' ) );
	}
}
