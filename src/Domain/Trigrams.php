<?php
/**
 * Trigrams.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Each term padded (`  deck `) and cut into overlapping threes (spec: Indexing → Trigrams). A misspelling
 * shares most of them: `dekc` and `deck` share 2 of 5, which is how a typo finds candidates without scanning
 * the dictionary.
 */
final class Trigrams {

	/**
	 * The distinct trigrams of a term, in order of first appearance.
	 *
	 * @param string $term A folded term.
	 * @return string[]
	 */
	public static function of( string $term ): array {
		if ( '' === $term ) {
			return array();
		}
		$chars = mb_str_split( '  ' . $term . ' ', 1, 'UTF-8' );
		$out   = array();
		$count = count( $chars ) - 2;
		for ( $i = 0; $i < $count; $i++ ) {
			$out[] = $chars[ $i ] . $chars[ $i + 1 ] . $chars[ $i + 2 ];
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * How many trigrams two terms share.
	 *
	 * @param string $a A term.
	 * @param string $b Another.
	 */
	public static function shared( string $a, string $b ): int {
		return count( array_intersect( self::of( $a ), self::of( $b ) ) );
	}

	/**
	 * How many of a token's trigrams a typo candidate must share: half, rounded down, so the spec's own example
	 * holds (`dekc` and `deck` share 2 of 5), and at least one (D-035).
	 *
	 * @param string $token A folded token.
	 */
	public static function minimumShared( string $token ): int {
		return max( 1, intdiv( count( self::of( $token ) ), 2 ) );
	}
}
