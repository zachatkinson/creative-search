<?php
/**
 * Edit distance.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Damerau–Levenshtein distance with a transposition counted as one edit (optimal string alignment), bounded so
 * a hopeless candidate is abandoned early (spec: Matching → Widening 3).
 */
final class Distance {

	/**
	 * The distance between two strings, or `$max + 1` when it exceeds `$max`.
	 *
	 * @param string $a   A string.
	 * @param string $b   Another.
	 * @param int    $max The largest distance of interest.
	 */
	public static function damerauLevenshtein( string $a, string $b, int $max ): int {
		$x     = mb_str_split( $a, 1, 'UTF-8' );
		$y     = mb_str_split( $b, 1, 'UTF-8' );
		$n     = count( $x );
		$m     = count( $y );
		$prev2 = array();
		$prev  = range( 0, $m );
		for ( $i = 1; $i <= $n; $i++ ) {
			$row = array( $i );
			for ( $j = 1; $j <= $m; $j++ ) {
				$cost = $x[ $i - 1 ] === $y[ $j - 1 ] ? 0 : 1;
				$best = min( $prev[ $j ] + 1, $row[ $j - 1 ] + 1, $prev[ $j - 1 ] + $cost );
				if ( $i > 1 && $j > 1 && $x[ $i - 1 ] === $y[ $j - 2 ] && $x[ $i - 2 ] === $y[ $j - 1 ] ) {
					$best = min( $best, $prev2[ $j - 2 ] + 1 );
				}
				$row[ $j ] = $best;
			}
			$prev2 = $prev;
			$prev  = $row;
		}
		return min( $prev[ $m ], $max + 1 );
	}

	/**
	 * The distance the spec allows a token of a length: 1 for four to seven characters, 2 for eight or more,
	 * 0 for shorter tokens (never fuzzed).
	 *
	 * @param string $token A folded token.
	 */
	public static function allowedFor( string $token ): int {
		$length = mb_strlen( $token, 'UTF-8' );
		if ( $length < 4 ) {
			return 0;
		}
		return $length >= 8 ? 2 : 1;
	}
}
