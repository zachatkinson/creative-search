<?php
/**
 * One result.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A document and its score.
 */
final class Hit {

	/**
	 * Constructor.
	 *
	 * @param Document $document The document.
	 * @param Score    $score    Its score.
	 */
	public function __construct( public readonly Document $document, public readonly Score $score ) {
	}

	/**
	 * Order for products: those some word reached only through a loose typo candidate in the description last (D-146),
	 * then score to six decimals, then in stock, then sales, then newest, then id (spec: Matching →
	 * Ties). Six decimals, because the SQL store sums the same parts in another order and must tie where PHP ties
	 * (D-086).
	 *
	 * @param Hit $a A hit.
	 * @param Hit $b Another.
	 */
	public static function compare( Hit $a, Hit $b ): int {
		return array( $a->score->looseOnly, round( $b->score->total(), 6 ), $b->document->inStock, $b->document->totalSales, $b->document->recency, $a->document->id )
			<=> array( $b->score->looseOnly, round( $a->score->total(), 6 ), $a->document->inStock, $a->document->totalSales, $a->document->recency, $b->document->id );
	}

	/**
	 * Order for products when the owner puts what can be bought first (`in_stock_first`, D-138, D-142): products
	 * that match on more than their description first, then those found by some word only there; in each group
	 * in stock (which includes on backorder) before sold out, each part in {@see Hit::compare()}'s order.
	 *
	 * @param Hit $a A hit.
	 * @param Hit $b Another.
	 */
	public static function stockFirst( Hit $a, Hit $b ): int {
		$order = array( $a->score->looseOnly, $a->score->descriptionOnly, $b->document->inStock ) <=> array( $b->score->looseOnly, $b->score->descriptionOnly, $a->document->inStock );
		return 0 !== $order ? $order : self::compare( $a, $b );
	}

	/**
	 * Order for categories, tags and brands: score, then how much of the name the query covers, then the count
	 * the document carries in `totalSales`, then title, then id (D-037).
	 *
	 * @param Hit $a A hit.
	 * @param Hit $b Another.
	 */
	public static function compareTerms( Hit $a, Hit $b ): int {
		return array( $b->score->total(), $b->score->coverage, $b->document->totalSales, $a->document->title, $a->document->id )
			<=> array( $a->score->total(), $a->score->coverage, $a->document->totalSales, $b->document->title, $b->document->id );
	}
}
