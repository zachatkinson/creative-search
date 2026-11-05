<?php
/**
 * A score with its parts.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A document's score for a query and every part of it, so `wp csearch explain` and the Analytics screen can
 * show the owner how a ranking came about (spec: Matching → Explain).
 *
 * @phpstan-type TokenScore array{token: string, term: string, kind: string, field: int, weight: int, m: float, tf: int, df: int, score: float}
 */
final class Score {

	/**
	 * Constructor.
	 *
	 * @param array<int, array{token: string, term: string, kind: string, field: int, weight: int, m: float, tf: int, df: int, score: float}> $tokens The best field of each matched token.
	 * @param float                                                                                                                           $phrase B_phrase.
	 * @param float                                                                                                                           $exact  B_exact.
	 * @param float                                                                                                                           $stock  B_stock.
	 * @param float                                                                                                                           $owner  B_owner.
	 * @param float                                                                                                                           $coverage For a category, tag or brand: the share of its name's tokens the query matched, a tie-break.
	 * @param bool                                                                                                                            $descriptionOnly Some searched word reached the product only in its description (D-142).
	 * @param bool                                                                                                                            $looseOnly       Some searched word reached it only through a loose typo candidate in its description (D-146).
	 */
	public function __construct(
		public readonly array $tokens,
		public readonly float $phrase = 0.0,
		public readonly float $exact = 0.0,
		public readonly float $stock = 0.0,
		public readonly float $owner = 0.0,
		public readonly float $coverage = 0.0,
		public readonly bool $descriptionOnly = false,
		public readonly bool $looseOnly = false
	) {
	}

	/**
	 * The total.
	 */
	public function total(): float {
		$sum = $this->phrase + $this->exact + $this->stock + $this->owner;
		foreach ( $this->tokens as $token ) {
			$sum += $token['score'];
		}
		return $sum;
	}
}
