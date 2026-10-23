<?php
/**
 * The words of a query that name a product feature.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Splits a query into the words that name a value of an attribute the shop filters by (`8.25` is Width 8.25)
 * and the rest (D-101, Skatehouse handoff item 9). The results then apply the shop's own filter for those
 * words instead of requiring them, so the shopper sees the filter and can remove it.
 *
 * A word becomes a filter only when it names exactly one attribute's value: `8` that is both a deck width and a
 * shoe size stays a word. A minus word or a word inside a quoted phrase stays a word too.
 */
final class FeatureFilters {

	/**
	 * Split a query's tokens.
	 *
	 * @param QueryToken[]                         $tokens The query's tokens, in order.
	 * @param array<string, array<string, string>> $values A folded token to the values it names: attribute
	 *                                                     taxonomy to term slug.
	 * @return array{filters: array<string, string[]>, rest: QueryToken[]} Each attribute's slugs, in the order
	 *     typed and once each, and the tokens left.
	 */
	public static function split( array $tokens, array $values ): array {
		$filters = array();
		$rest    = array();
		foreach ( $tokens as $token ) {
			$named = $values[ $token->text ] ?? array();
			if ( $token->negated || 0 !== $token->phrase || 1 !== count( $named ) ) {
				$rest[] = $token;
				continue;
			}
			foreach ( $named as $taxonomy => $slug ) {
				if ( ! in_array( $slug, $filters[ $taxonomy ] ?? array(), true ) ) {
					$filters[ $taxonomy ][] = $slug;
				}
			}
		}
		return array(
			'filters' => $filters,
			'rest'    => $rest,
		);
	}
}
