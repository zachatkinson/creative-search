<?php
/**
 * Works out which variation a query named and why a product matched.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * For one ranked product (D-104, D-105):
 *
 * - **The variation.** A variation's SKU typed in full names it. Otherwise each query token that reaches the
 *   attribute values of some of the variations but not all is specific; the variation reaching the most specific
 *   tokens is named (the first in the shop's order on a tie), with the arguments of the values those tokens
 *   reached, so "mumber fur fire" opens the Fire design and leaves the width to the shopper. A token every
 *   variation carries, or none, names nothing.
 * - **Why it matched.** Nothing when every token is in the name, which the card shows. Otherwise the first token
 *   outside the name says it: a SKU (the one it reached, the named variation's first), an attribute value (its
 *   attribute's name and the value), or the description. A token found only in a category, tag or brand says
 *   nothing: those are not what the shopper asked about a product.
 */
final class MatchExplainer {

	/**
	 * Explain one product.
	 *
	 * @param array<int, string[]> $tokens The terms each query token reached.
	 * @param string               $skuKey The query's SKU key term, or empty (no fact or field carries an empty term).
	 * @param array<int, string[]> $fields Field id to the query's terms the product's document carries there.
	 * @param Fact[]               $facts  The product's SKUs and attribute values, in the shop's order.
	 */
	public function explain( array $tokens, string $skuKey, array $fields, array $facts ): MatchDetail {
		[ $variation, $args ] = $this->variation( $tokens, $skuKey, $facts );
		$reason               = $this->reason( $tokens, $skuKey, $fields, self::ordered( $facts, $variation ) );
		return new MatchDetail(
			$variation,
			0 === $variation ? null : self::image( $facts, $variation ),
			$args,
			$reason[0],
			$reason[1],
			$reason[2]
		);
	}

	/**
	 * The variation a query names, and the arguments that preselect it.
	 *
	 * @param array<int, string[]> $tokens The terms each token reached.
	 * @param string               $skuKey The query's SKU key term, or empty.
	 * @param Fact[]               $facts  The facts.
	 * @return array{0: int, 1: array<string, string>}
	 */
	private function variation( array $tokens, string $skuKey, array $facts ): array {
		$byVariation = array();
		foreach ( $facts as $fact ) {
			if ( $fact->variationId > 0 ) {
				$byVariation[ $fact->variationId ][] = $fact;
			}
		}
		foreach ( $byVariation as $id => $list ) {
			foreach ( $list as $fact ) {
				if ( Fields::SKU === $fact->field && in_array( $skuKey, $fact->terms, true ) ) {
					return array( $id, self::args( $list ) );
				}
			}
		}
		$reached = array(); // Variation id to the specific tokens it reached, each with the arguments of its values.
		foreach ( $tokens as $i => $terms ) {
			$hit = array();
			foreach ( $byVariation as $id => $list ) {
				foreach ( $list as $fact ) {
					if ( Fields::ATTRIBUTE === $fact->field && $fact->reaches( $terms ) ) {
						$hit[ $id ][] = $fact;
					}
				}
			}
			if ( count( $hit ) === count( $byVariation ) ) {
				continue; // Every variation carries it (or there are none): it names none of them.
			}
			foreach ( $hit as $id => $values ) {
				$reached[ $id ][ $i ] = self::args( $values );
			}
		}
		if ( array() === $reached ) {
			return array( 0, array() );
		}
		$scores  = array_map( 'count', $reached );
		$best    = max( $scores );
		$winners = array_keys( array_filter( $scores, static fn( int $score ): bool => $score === $best ) );
		$id      = array_values( array_intersect( array_keys( $byVariation ), $winners ) )[0];
		return array( $id, array_merge( ...$reached[ $id ] ) );
	}

	/**
	 * Why the product matched: the kind, the attribute's name and the label, or three empty strings.
	 *
	 * @param array<int, string[]> $tokens The terms each token reached.
	 * @param string               $skuKey The query's SKU key term, or empty.
	 * @param array<int, string[]> $fields Field id to the query's terms found there.
	 * @param Fact[]               $facts  The facts, the named variation's first.
	 * @return array{0: string, 1: string, 2: string}
	 */
	private function reason( array $tokens, string $skuKey, array $fields, array $facts ): array {
		$name    = $fields[ Fields::NAME ] ?? array();
		$outside = array_filter( $tokens, static fn( array $terms ): bool => array() === array_intersect( $terms, $name ) );
		if ( array() === $outside ) {
			return array( '', '', '' );
		}
		if ( in_array( $skuKey, $fields[ Fields::SKU ] ?? array(), true ) ) {
			$fact = self::first( $facts, Fields::SKU, array( $skuKey ) );
			if ( null !== $fact ) {
				return array( MatchDetail::SKU, '', $fact->label );
			}
		}
		$description = array_merge( $fields[ Fields::SHORT ] ?? array(), $fields[ Fields::LONG ] ?? array() );
		foreach ( $outside as $terms ) {
			$fact = array() === array_intersect( $terms, $fields[ Fields::SKU ] ?? array() ) ? null : self::first( $facts, Fields::SKU, $terms );
			if ( null !== $fact ) {
				return array( MatchDetail::SKU, '', $fact->label );
			}
			$fact = array() === array_intersect( $terms, $fields[ Fields::ATTRIBUTE ] ?? array() ) ? null : self::first( $facts, Fields::ATTRIBUTE, $terms );
			if ( null !== $fact ) {
				return array( MatchDetail::ATTRIBUTE, $fact->name, $fact->label );
			}
			if ( array() !== array_intersect( $terms, $description ) ) {
				return array( MatchDetail::DESCRIPTION, '', '' );
			}
		}
		return array( '', '', '' );
	}

	/**
	 * The first fact of a field that some terms reach.
	 *
	 * @param Fact[]   $facts Facts.
	 * @param int      $field Field id.
	 * @param string[] $terms Terms.
	 */
	private static function first( array $facts, int $field, array $terms ): ?Fact {
		foreach ( $facts as $fact ) {
			if ( $field === $fact->field && $fact->reaches( $terms ) ) {
				return $fact;
			}
		}
		return null;
	}

	/**
	 * The facts with one variation's first, the rest in order.
	 *
	 * @param Fact[] $facts     Facts.
	 * @param int    $variation The named variation, or 0.
	 * @return Fact[]
	 */
	private static function ordered( array $facts, int $variation ): array {
		usort( $facts, static fn( Fact $a, Fact $b ): int => (int) ( $variation === $b->variationId ) <=> (int) ( $variation === $a->variationId ) );
		return $facts;
	}

	/**
	 * A variation's own image, or null.
	 *
	 * @param Fact[] $facts     Facts.
	 * @param int    $variation The variation.
	 */
	private static function image( array $facts, int $variation ): ?int {
		foreach ( $facts as $fact ) {
			if ( $variation === $fact->variationId && null !== $fact->imageId ) {
				return $fact->imageId;
			}
		}
		return null;
	}

	/**
	 * The query arguments of some attribute values (a SKU carries none; "any" value carries none).
	 *
	 * @param Fact[] $facts Facts.
	 * @return array<string, string>
	 */
	private static function args( array $facts ): array {
		$args = array();
		foreach ( $facts as $fact ) {
			if ( '' !== $fact->attribute && '' !== $fact->value ) {
				$args[ $fact->attribute ] = $fact->value;
			}
		}
		return $args;
	}
}
