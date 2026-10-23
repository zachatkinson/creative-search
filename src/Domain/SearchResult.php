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
 * dropped, the language that answered (spec: The search API), and the terms each kept token reached with the
 * query's key, so a result can say which of its variations and values matched (D-104, D-105).
 */
final class SearchResult {

	/**
	 * Constructor.
	 *
	 * @param Hit[]                $products  Products in rank order.
	 * @param Hit[]                $terms     Categories, tags and brands in rank order.
	 * @param string               $lang      The language whose documents answered.
	 * @param string|null          $corrected The query the results are for when a token was dropped, else null.
	 * @param array<int, string[]> $tokens The terms each token the results answer reached, by token index.
	 * @param string               $key    The folded tokens of the query the results answer, joined by a space.
	 */
	public function __construct(
		public readonly array $products,
		public readonly array $terms,
		public readonly string $lang,
		public readonly ?string $corrected = null,
		public readonly array $tokens = array(),
		public readonly string $key = ''
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
