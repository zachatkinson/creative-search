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
	 * Order for products: score, then in stock, then sales, then newest, then id (spec: Matching → Ties).
	 *
	 * @param Hit $a A hit.
	 * @param Hit $b Another.
	 */
	public static function compare( Hit $a, Hit $b ): int {
		return array( $b->score->total(), $b->document->inStock, $b->document->totalSales, $b->document->recency, $a->document->id )
			<=> array( $a->score->total(), $a->document->inStock, $a->document->totalSales, $a->document->recency, $b->document->id );
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
