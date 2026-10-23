<?php
/**
 * Whether a query names a category.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The categories a query names whole (D-102, Skatehouse handoff item 10): every word of the query matches a
 * word of the category's name and every word of the name is matched, compared by stem, a word's synonyms
 * counting as the word. "decks" names Decks, not Fingerboard decks; "fingerboard" names neither.
 */
final class CategoryMatcher {

	/**
	 * The categories a query names.
	 *
	 * @param array<int, string[]> $query      Each word of the query (stop words left out): its stem and its
	 *                                         synonyms' stems.
	 * @param array<int, string[]> $categories A category id to the stems of its name's words.
	 * @return int[] The ids, in the order given.
	 */
	public static function match( array $query, array $categories ): array {
		$out = array();
		foreach ( $categories as $id => $stems ) {
			$stems = array_unique( $stems );
			// An empty query names nothing, even a category whose name has no word left.
			if ( array() !== $stems && count( $stems ) === count( $query ) && self::covers( $query, $stems ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Whether the words and the name's stems match one to one: each word to a stem no other word took.
	 *
	 * @param array<int, string[]> $words The query's words' stems.
	 * @param string[]             $stems The name's stems, as many as the words.
	 */
	private static function covers( array $words, array $stems ): bool {
		foreach ( $words as $alternatives ) {
			$found = array_values( array_intersect( $stems, $alternatives ) );
			if ( array() === $found ) {
				return false;
			}
			unset( $stems[ array_search( $found[0], $stems, true ) ] );
		}
		return true;
	}
}
