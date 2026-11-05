<?php
/**
 * The ranking, in PHP.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a {@see RankPlan} over postings held in memory (spec: Matching): AND across tokens, minus words
 * excluded, quoted phrases enforced, hidden products only for their exact SKU, every product scored and
 * ordered. The in-memory index and `explain` rank this way; the SQL repository runs the same plan as one
 * statement and is held to this order by the parity test (D-086).
 */
final class Ranker {

	/**
	 * Constructor.
	 *
	 * @param Scorer $scorer The scorer.
	 */
	public function __construct( private Scorer $scorer = new Scorer() ) {
	}

	/**
	 * Rank.
	 *
	 * @param RankPlan                                                     $plan      The plan.
	 * @param array<string, array<int, array<int, array{0: int, 1: int}>>> $postings  Term to product document to field to [tf, pos], for the plan's terms and its excluded terms, in its language.
	 * @param callable(int[], string): array<int, Document>                $documents Documents by id, given the query's key.
	 * @return Hit[] In rank order, at most the plan's limit.
	 */
	public function rank( RankPlan $plan, array $postings, callable $documents ): array {
		$postings  = self::withoutDescriptions( $plan, $postings );
		$widenings = array();
		foreach ( $plan->tokens as $i => $matches ) {
			$widenings[ $i ] = new TokenWidening( $matches, array_intersect_key( $postings, $matches ), array() );
		}
		$set = null;
		foreach ( $widenings as $widening ) {
			$docs = $widening->productDocs();
			$set  = null === $set ? $docs : array_intersect_key( $set, $docs );
		}
		foreach ( $plan->excluded as $term ) {
			$set = array_diff_key( $set ?? array(), $postings[ $term ] ?? array() );
		}
		foreach ( $plan->phrases as $indexes ) {
			$set = array_filter( $set ?? array(), static fn( int $doc ): bool => self::hasPhrase( $widenings, $indexes, $doc ), ARRAY_FILTER_USE_KEY );
		}
		$hits = array();
		foreach ( $documents( array_keys( $set ?? array() ), $plan->key ) as $document ) {
			if ( $document->visible || ( $plan->hiddenBySku && in_array( $plan->key, $document->skuKeys, true ) ) ) {
				$hits[] = new Hit( $document, $this->scorer->score( $plan, $document, $widenings ) );
			}
		}
		usort( $hits, array( Hit::class, $plan->inStockFirst ? 'stockFirst' : 'compare' ) );
		return array_slice( $hits, 0, $plan->limit );
	}

	/**
	 * The postings without the description fields of the terms whose descriptions don't count (D-146): a loose typo
	 * candidate of a short word; a product left with none of the term's postings is not reached by it.
	 *
	 * @param RankPlan                                                     $plan     The plan.
	 * @param array<string, array<int, array<int, array{0: int, 1: int}>>> $postings Term to document to field to (tf, first position).
	 * @return array<string, array<int, array<int, array{0: int, 1: int}>>>
	 */
	private static function withoutDescriptions( RankPlan $plan, array $postings ): array {
		foreach ( $plan->tokens as $matches ) {
			foreach ( $matches as $match ) {
				if ( $match->descriptions || ! isset( $postings[ $match->term ] ) ) {
					continue;
				}
				$kept = array();
				foreach ( $postings[ $match->term ] as $doc => $byField ) {
					$byField = array_diff_key( $byField, array_flip( Fields::DESCRIPTIONS ) );
					if ( array() !== $byField ) {
						$kept[ $doc ] = $byField;
					}
				}
				$postings[ $match->term ] = $kept;
			}
		}
		return $postings;
	}

	/**
	 * Whether some field of a document holds the phrase's tokens adjacent and in order, by first positions.
	 *
	 * @param TokenWidening[] $widenings One per token.
	 * @param int[]           $indexes   The phrase's token indexes, in order.
	 * @param int             $doc       Document id.
	 */
	private static function hasPhrase( array $widenings, array $indexes, int $doc ): bool {
		$fields = array();
		foreach ( $indexes as $order => $i ) {
			foreach ( $widenings[ $i ]->postingsIn( $doc, true ) as $byField ) {
				foreach ( $byField as $field => [ , $position ] ) {
					$fields[ $field ][ $order ] = min( $fields[ $field ][ $order ] ?? PHP_INT_MAX, $position );
				}
			}
		}
		foreach ( $fields as $positions ) {
			if ( count( $positions ) !== count( $indexes ) ) {
				continue;
			}
			$adjacent = true;
			$previous = null;
			foreach ( $positions as $position ) {
				$adjacent = $adjacent && ( null === $previous || $position === $previous + 1 );
				$previous = $position;
			}
			if ( $adjacent ) {
				return true;
			}
		}
		return false;
	}
}
