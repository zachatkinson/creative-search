<?php
/**
 * Whether a query matched a title.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A post or page is suggested in the panel only when the query matches its title (D-137): every token of the query
 * reached at least one term the document carries in its name field. A word somewhere in the content is enough for
 * the results page, not for the panel.
 */
final class TitleMatch {

	/**
	 * Whether every token reached a term of the title.
	 *
	 * @param array<int, string[]> $tokens The terms each token of the query reached, by token index.
	 * @param array<int, string[]> $fields The document's matched terms, by field id.
	 */
	public static function covers( array $tokens, array $fields ): bool {
		$title = array_flip( $fields[ Fields::NAME ] ?? array() );
		if ( array() === $tokens || array() === $title ) {
			return false;
		}
		foreach ( $tokens as $terms ) {
			if ( array() === array_intersect_key( array_flip( $terms ), $title ) ) {
				return false;
			}
		}
		return true;
	}
}
