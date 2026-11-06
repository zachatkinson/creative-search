<?php
/**
 * Attribute values by name.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Search;

use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How the page and the panel name attribute values: "Width 8.25 and Wheel size 54mm" (D-101), and the line that
 * says a value asked for was left out (D-154, D-155).
 */
final class ValueText {

	/**
	 * "Nothing in Width 9.00″ is in stock right now."; an empty string when no value was left out.
	 *
	 * @param array<string, string[]> $unstocked Attribute taxonomy to the slugs left out.
	 */
	public static function unstocked( array $unstocked ): string {
		if ( array() === $unstocked ) {
			return '';
		}
		return sprintf(
			/* translators: %s: the values the shopper asked for, such as Width 9.00″ */
			__( 'Nothing in %s is in stock right now.', 'creative-search' ),
			self::values( $unstocked )
		);
	}

	/**
	 * Attribute values by name: "Width 8.25 and Wheel size 54mm".
	 *
	 * @param array<string, string[]> $values Attribute taxonomy to slugs.
	 */
	public static function values( array $values ): string {
		$parts = array();
		foreach ( $values as $taxonomy => $slugs ) {
			$names = array();
			foreach ( $slugs as $slug ) {
				$term    = get_term_by( 'slug', $slug, $taxonomy );
				$names[] = $term instanceof WP_Term ? $term->name : $slug;
			}
			if ( array() !== $names ) {
				/* translators: 1: an attribute, such as Width, 2: its values, such as 8.25 */
				$parts[] = sprintf( _x( '%1$s %2$s', 'an attribute and its values, such as Width 8.25', 'creative-search' ), wc_attribute_label( $taxonomy ), wp_sprintf( '%l', $names ) );
			}
		}
		return wp_sprintf( '%l', $parts );
	}
}
