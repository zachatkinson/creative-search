<?php
/**
 * Category-scope suggestions.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Chooses the categories the panel offers to search within ("kazumi in Decks"; Baymard: scope suggestions,
 * D-113): the ones holding most of the first ranked products, at least two of them, the deeper category when a
 * parent holds no more of them than its child, never one already suggested as a category, and never one that
 * would show exactly the products the search already shows.
 */
final class Scopes {

	/**
	 * Categories at most.
	 */
	public const MOST = 2;

	/**
	 * The ranked products looked at.
	 */
	public const TOP = 10;

	/**
	 * Of those, how many a category must hold.
	 */
	public const MINIMUM = 2;

	/**
	 * The categories to offer, most products first, then the lower id.
	 *
	 * @param array<int, int[]> $categories Product id to its category ids, for the ranked products in rank order.
	 * @param array<int, int>   $parents    Category id to its parent's id (0 for none).
	 * @param int               $total      Products the search found in all.
	 * @param int[]             $shown      Category ids already suggested.
	 * @param int               $most       At most this many.
	 * @return int[]
	 */
	public static function pick( array $categories, array $parents, int $total, array $shown, int $most = self::MOST ): array {
		$top    = array_slice( $categories, 0, self::TOP, true );
		$counts = array();
		foreach ( $top as $ids ) {
			foreach ( array_unique( $ids ) as $id ) {
				$counts[ $id ] = ( $counts[ $id ] ?? 0 ) + 1;
			}
		}
		$counts = array_filter( $counts, static fn( int $count ): bool => $count >= self::MINIMUM );
		$kept   = array();
		foreach ( $counts as $id => $count ) {
			// Searching within it would show every product already shown and no other.
			$same = count( $top ) === $count && $total === $count;
			if ( ! $same && ! self::narrower( $id, $count, $counts, $parents ) && ! in_array( $id, $shown, true ) ) {
				$kept[ $id ] = $count;
			}
		}
		uksort( $kept, static fn( int $a, int $b ): int => array( $kept[ $b ], $a ) <=> array( $kept[ $a ], $b ) );
		return array_slice( array_keys( $kept ), 0, max( 0, $most ) );
	}

	/**
	 * Whether a child of a category holds as many of the products as it does: then the child says it better.
	 *
	 * @param int             $id      A category.
	 * @param int             $count   The products it holds.
	 * @param array<int, int> $counts  Category to the products it holds.
	 * @param array<int, int> $parents Category to its parent.
	 */
	private static function narrower( int $id, int $count, array $counts, array $parents ): bool {
		foreach ( $counts as $child => $childCount ) {
			if ( ( $parents[ $child ] ?? null ) === $id && $childCount === $count ) {
				return true;
			}
		}
		return false;
	}
}
