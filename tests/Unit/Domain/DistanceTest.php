<?php
/**
 * Edit distance tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Domain;

use CreativeBranding\Search\Domain\Distance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Damerau–Levenshtein with transpositions as one edit, bounded.
 */
final class DistanceTest extends TestCase {

	/**
	 * @return array<string, array{string, string, int, int}>
	 */
	public static function pairs(): array {
		return array(
			'identical'        => array( 'deck', 'deck', 2, 0 ),
			'substitution'     => array( 'kazumy', 'kazumi', 2, 1 ),
			'transposition'    => array( 'dekc', 'deck', 2, 1 ),
			'deletion'         => array( 'horor', 'horror', 2, 1 ),
			'insertion'        => array( 'bearrings', 'bearings', 2, 1 ),
			'two edits'        => array( 'bearringz', 'bearings', 2, 2 ),
			'over the bound'   => array( 'bearringz', 'bearings', 1, 2 ),
			'far apart'        => array( 'skateboard', 'hoodie', 2, 3 ),
			'length gap'       => array( 'deck', 'skateboard', 2, 3 ),
			'empty and word'   => array( '', 'deck', 5, 4 ),
			'word and empty'   => array( 'deck', '', 5, 4 ),
			'both empty'       => array( '', '', 1, 0 ),
			'multibyte'        => array( 'québec', 'quebec', 1, 1 ),
			'multibyte swap'   => array( 'éocle', 'école', 1, 1 ),
			'swap plus edit'   => array( 'dekcs', 'deck', 2, 2 ),
			'swap at the end'  => array( 'decsk', 'decks', 1, 1 ),
			'not a swap'       => array( 'abcd', 'badc', 2, 2 ),
			'bound zero'       => array( 'deck', 'decks', 0, 1 ),
		);
	}

	#[DataProvider( 'pairs' )]
	public function test_distance( string $a, string $b, int $max, int $expected ): void {
		self::assertSame( $expected, Distance::damerauLevenshtein( $a, $b, $max ) );
	}

	public function test_distance_is_symmetric_within_the_bound(): void {
		foreach ( array( array( 'kazumy', 'kazumi' ), array( 'dekc', 'deck' ), array( 'horor', 'horror' ), array( 'ab', 'ba' ) ) as [ $a, $b ] ) {
			self::assertSame( Distance::damerauLevenshtein( $a, $b, 3 ), Distance::damerauLevenshtein( $b, $a, 3 ) );
		}
	}

	/**
	 * @return array<string, array{string, int}>
	 */
	public static function lengths(): array {
		return array(
			'three'  => array( 'dck', 0 ),
			'four'   => array( 'deck', 1 ),
			'seven'  => array( 'bearing', 1 ),
			'eight'  => array( 'bearings', 2 ),
			'long'   => array( 'skateboarding', 2 ),
			'empty'  => array( '', 0 ),
			'multibyte four' => array( 'écol', 1 ),
			'three accents'  => array( 'éàç', 0 ),
			'four accents'   => array( 'éàçé', 1 ),
			'eight accents'  => array( 'éàçéàçéà', 2 ),
		);
	}

	#[DataProvider( 'lengths' )]
	public function test_allowed_distance_by_length( string $token, int $allowed ): void {
		self::assertSame( $allowed, Distance::allowedFor( $token ) );
	}
}
