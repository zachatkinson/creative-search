<?php
/**
 * A search's result.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The ranked products, the categories, tags and brands that match, the corrected query when a token was
 * dropped, and the language that answered (spec: The search API).
 */
final class SearchResult {

	/**
	 * Constructor.
	 *
	 * @param Hit[]       $products  Products in rank order.
	 * @param Hit[]       $terms     Categories, tags and brands in rank order.
	 * @param string      $lang      The language whose documents answered.
	 * @param string|null $corrected The query the results are for when a token was dropped, else null.
	 */
	public function __construct(
		public readonly array $products,
		public readonly array $terms,
		public readonly string $lang,
		public readonly ?string $corrected = null
	) {
	}

	/**
	 * Whether nothing at all was found.
	 */
	public function isEmpty(): bool {
		return array() === $this->products && array() === $this->terms;
	}

	/**
	 * The product documents' object ids in rank order, for `post__in`.
	 *
	 * @return int[]
	 */
	public function productIds(): array {
		return array_map( static fn( Hit $hit ): int => $hit->document->objectId, $this->products );
	}
}
