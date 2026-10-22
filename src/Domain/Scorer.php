<?php
/**
 * The score.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The score of a document for a query (spec: Matching → The score):
 *
 *     Σ over matched tokens of w_field · m · (1 + ln(1 + tf)) · ln((N + 1) / (df + 1)), best field once,
 *     + B_phrase (+8 adjacent in order in one field, +4 in order) + B_exact (+12 name, +6 SKU)
 *     + B_stock (−6 sold out) + B_owner (the product's boost and the owner's rules).
 *
 * Categories, tags and brands score by their name alone: exact 12, prefix 7, typo 4 per token.
 */
final class Scorer {

	public const PHRASE_ADJACENT = 8.0;
	public const PHRASE_ORDERED  = 4.0;
	public const EXACT_NAME      = 12.0;
	public const EXACT_SKU       = 6.0;
	public const SOLD_OUT        = -6.0;

	public const TERM_EXACT  = 12.0;
	public const TERM_PREFIX = 7.0;
	public const TERM_TYPO   = 4.0;

	/**
	 * A product's score.
	 *
	 * @param RankPlan        $plan      The plan: token texts, weights, N, the key and the owner's rules.
	 * @param Document        $document  The product.
	 * @param TokenWidening[] $widenings One per query token, in order, with this product's postings.
	 */
	public function score( RankPlan $plan, Document $document, array $widenings ): Score {
		$weights = $plan->weights;
		$tokens  = array();
		$fields  = array();
		foreach ( $widenings as $i => $widening ) {
			$best = null;
			foreach ( $widening->postingsIn( $document->id, true ) as $term => $byField ) {
				$match = $widening->matches[ $term ];
				$term  = (string) $term;
				foreach ( $byField as $field => [ $tf, $position ] ) {
					$value = ( $weights[ $field ] ?? 0 ) * $match->weight() * ( 1 + log( 1 + $tf ) ) * $plan->idf( $match->df );
					if ( null === $best || $value > $best['score'] ) {
						$best = array(
							'token'  => $plan->texts[ $i ],
							'term'   => $term,
							'kind'   => $match->kind,
							'field'  => $field,
							'weight' => $weights[ $field ] ?? 0,
							'm'      => $match->weight(),
							'tf'     => $tf,
							'df'     => $match->df,
							'score'  => $value,
						);
					}
					$fields[ $field ][ $i ] = min( $fields[ $field ][ $i ] ?? PHP_INT_MAX, $position );
				}
			}
			if ( null !== $best ) {
				$tokens[ $i ] = $best;
			}
		}
		$owner = $document->boost;
		foreach ( $document->termRefs as $ref ) {
			$owner += $plan->boostRules[ $ref ] ?? 0;
		}
		return new Score(
			$tokens,
			self::phraseBonus( $fields, count( $widenings ) ),
			( $plan->key === $document->nameKey ? self::EXACT_NAME : 0.0 ) + ( in_array( $plan->key, $document->skuKeys, true ) ? self::EXACT_SKU : 0.0 ),
			$document->inStock ? 0.0 : self::SOLD_OUT,
			(float) $owner
		);
	}

	/**
	 * A category's, tag's or brand's score: by name alone, per token the best of exact 12, prefix 7, typo 4. The
	 * share of the name's tokens the query covers is kept as a tie-break (`Planches` before `Planches à
	 * roulettes` for "planche", D-037).
	 *
	 * @param Query           $query     The query.
	 * @param Document        $document  The term document.
	 * @param TokenWidening[] $widenings One per query token, in order.
	 */
	public function scoreTerm( Query $query, Document $document, array $widenings ): Score {
		$tokens = array();
		foreach ( $widenings as $i => $widening ) {
			$best = null;
			foreach ( $widening->postingsIn( $document->id, false ) as $term => $byField ) {
				if ( ! isset( $byField[ Fields::NAME ] ) ) {
					continue;
				}
				$match = $widening->matches[ $term ];
				$term  = (string) $term;
				$value = match ( $match->kind ) {
					TermMatch::EXACT, TermMatch::STEM, TermMatch::SYNONYM => self::TERM_EXACT,
					TermMatch::PREFIX => self::TERM_PREFIX,
					default => self::TERM_TYPO,
				};
				if ( null === $best || $value > $best['score'] ) {
					$best = array(
						'token'  => $query->tokens[ $i ]->text,
						'term'   => $term,
						'kind'   => $match->kind,
						'field'  => Fields::NAME,
						'weight' => 0,
						'm'      => $match->weight(),
						'tf'     => $byField[ Fields::NAME ][0],
						'df'     => $match->df,
						'score'  => $value,
					);
				}
			}
			if ( null !== $best ) {
				$tokens[ $i ] = $best;
			}
		}
		$nameTokens = '' === $document->nameKey ? 0 : count( explode( ' ', $document->nameKey ) );
		return new Score( $tokens, 0.0, 0.0, 0.0, 0.0, 0 === $nameTokens ? 0.0 : count( $tokens ) / $nameTokens );
	}

	/**
	 * +8 when every token appears in one field in query order and adjacent, +4 in order, by first positions.
	 *
	 * @param array<int, array<int, int>> $fields Field to token index to first position.
	 * @param int                         $count  The number of tokens.
	 */
	public static function phraseBonus( array $fields, int $count ): float {
		if ( $count < 2 ) {
			return 0.0;
		}
		$bonus = 0.0;
		foreach ( $fields as $positions ) {
			if ( count( $positions ) < $count ) {
				continue;
			}
			ksort( $positions );
			$ordered  = true;
			$adjacent = true;
			$previous = null;
			foreach ( $positions as $position ) {
				if ( null !== $previous ) {
					$ordered  = $ordered && $position > $previous;
					$adjacent = $adjacent && $position === $previous + 1;
				}
				$previous = $position;
			}
			if ( $ordered ) {
				$bonus = max( $bonus, $adjacent ? self::PHRASE_ADJACENT : self::PHRASE_ORDERED );
			}
		}
		return $bonus;
	}
}
