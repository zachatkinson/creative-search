<?php
/**
 * Trigram tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Domain;

use CreativeBranding\Search\Domain\Trigrams;
use PHPUnit\Framework\TestCase;

/**
 * Padded trigrams, the spec's example, the minimum a typo candidate must share.
 */
final class TrigramsTest extends TestCase {

	public function test_the_spec_example(): void {
		self::assertSame( array( '  d', ' de', 'dec', 'eck', 'ck ' ), Trigrams::of( 'deck' ) );
		self::assertSame( array( '  d', ' de', 'dek', 'ekc', 'kc ' ), Trigrams::of( 'dekc' ) );
		self::assertSame( 2, Trigrams::shared( 'dekc', 'deck' ) );
		self::assertSame( 2, Trigrams::minimumShared( 'dekc' ), 'half of five, rounded down, so the example is found (D-035)' );
	}

	public function test_trigrams_are_distinct_and_multibyte_safe(): void {
		self::assertSame( array( '  a', ' aa', 'aaa', 'aa ' ), Trigrams::of( 'aaaa' ) );
		self::assertSame( array( '  é', ' éc', 'éco', 'col', 'ole', 'le ' ), Trigrams::of( 'école' ) );
		self::assertSame( array( '  8', ' 8.', '8.2', '.25', '25 ' ), Trigrams::of( '8.25' ) );
	}

	public function test_short_and_empty_terms(): void {
		self::assertSame( array(), Trigrams::of( '' ) );
		self::assertSame( array( '  a', ' a ' ), Trigrams::of( 'a' ) );
		self::assertSame( 1, Trigrams::minimumShared( 'a' ) );
		self::assertSame( 1, Trigrams::minimumShared( '' ), 'never zero' );
		self::assertSame( 0, Trigrams::shared( '', 'deck' ) );
	}

	public function test_minimum_is_half_rounded_down(): void {
		self::assertSame( 2, Trigrams::minimumShared( 'deck' ) );
		self::assertSame( 3, Trigrams::minimumShared( 'kazumi' ), 'seven trigrams' );
		self::assertSame( 5, Trigrams::minimumShared( 'bearrings' ), 'ten trigrams' );
		self::assertSame( 4, Trigrams::minimumShared( 'bearings' ), 'nine trigrams' );
		self::assertSame( 5, Trigrams::shared( 'deck', 'deck' ) );
		self::assertSame( 4, Trigrams::shared( 'decks', 'deck' ) );
	}
}
