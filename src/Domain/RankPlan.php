<?php
/**
 * What the store ranks.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the store needs to find, score and order the products of one query, decided in PHP from small
 * lookups (D-086): per token the terms it reached and how, with their document frequencies; the field weights;
 * N; the minus words' terms; the quoted phrases; the query's key for the exactness bonus and hidden-by-SKU;
 * the owner's rules; how many to return. The SQL repository runs it as one statement, the in-memory index
 * through {@see Ranker}; both give the same order.
 */
final class RankPlan {

	/**
	 * Constructor.
	 *
	 * @param string                               $lang        Language code.
	 * @param array<int, string>                   $texts       Each token's folded text, by token index.
	 * @param array<int, array<string, TermMatch>> $tokens      Each token's terms (with df), by token index.
	 * @param array<int, int>                      $weights     Field id to weight.
	 * @param int                                  $n           Documents in the language.
	 * @param string[]                             $excluded    The minus words' terms.
	 * @param array<int, int[]>                    $phrases     Each quoted phrase's token indexes, in order.
	 * @param string                               $key         The query's key.
	 * @param bool                                 $hiddenBySku Whether a hidden product answers its exact SKU.
	 * @param array<string, int>                   $boostRules  The owner's rules: `category:12` to points.
	 * @param int                                  $limit       At most this many.
	 */
	public function __construct(
		public readonly string $lang,
		public readonly array $texts,
		public readonly array $tokens,
		public readonly array $weights,
		public readonly int $n,
		public readonly array $excluded,
		public readonly array $phrases,
		public readonly string $key,
		public readonly bool $hiddenBySku,
		public readonly array $boostRules,
		public readonly int $limit
	) {
	}

	/**
	 * The plan of a query and its widenings.
	 *
	 * @param Query           $query     The query.
	 * @param TokenWidening[] $widenings One per token, keyed as the query's tokens are.
	 * @param string          $lang      Language code.
	 * @param int             $n         Documents in the language.
	 * @param string[]        $excluded  The minus words' terms.
	 * @param SearchOptions   $options   Options.
	 */
	public static function of( Query $query, array $widenings, string $lang, int $n, array $excluded, SearchOptions $options ): self {
		$texts  = array();
		$tokens = array();
		foreach ( $widenings as $i => $widening ) {
			$texts[ $i ]  = $query->tokens[ $i ]->text;
			$tokens[ $i ] = $widening->matches;
		}
		return new self( $lang, $texts, $tokens, $options->weightsById(), $n, $excluded, $query->phrases(), $query->key(), $options->hiddenBySku, $options->boostRules, $options->limit );
	}

	/**
	 * The idf of a term: ln((N + 1) / (df + 1)).
	 *
	 * @param int $df Document frequency.
	 */
	public function idf( int $df ): float {
		return log( ( $this->n + 1 ) / ( $df + 1 ) );
	}

	/**
	 * Every term any token reached, once.
	 *
	 * @return string[]
	 */
	public function terms(): array {
		$terms = array();
		foreach ( $this->tokens as $matches ) {
			foreach ( $matches as $match ) {
				$terms[] = $match->term;
			}
		}
		return array_values( array_unique( $terms ) );
	}
}
