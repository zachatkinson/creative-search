<?php
/**
 * Search options.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * What a search is allowed to do: the settings and the owner's rules, read once by the caller (spec: Admin,
 * Matching → Owner's boost). Weights are keyed by field name as in {@see Fields::DEFAULT_WEIGHTS}.
 */
final class SearchOptions {

	/**
	 * Constructor.
	 *
	 * @param array<string, int> $weights      Field weights by name; missing fields take the spec's defaults.
	 * @param bool               $hiddenBySku  Whether a hidden product may be found by its exact SKU.
	 * @param string|null        $fallbackLang The language searched when the query's finds nothing, or null.
	 * @param array<string, int> $boostRules   The owner's rules: a document's term ref (`category:12`) to a boost.
	 * @param int                $limit        At most this many products (the results page's cap).
	 * @param int                $termLimit    At most this many categories, tags and brands.
	 * @param bool               $retry        Whether a query that matches nothing is retried without its least-matched
	 *                                         token (off for "search instead for", the shopper's exact words).
	 * @param int                $kind         What is ranked: the products ({@see Kind::PRODUCT}) or the posts and
	 *                                         pages ({@see Kind::CONTENT}, D-093).
	 */
	public function __construct(
		public readonly array $weights = array(),
		public readonly bool $hiddenBySku = true,
		public readonly ?string $fallbackLang = 'en',
		public readonly array $boostRules = array(),
		public readonly int $limit = 500,
		public readonly int $termLimit = 3,
		public readonly bool $retry = true,
		public readonly int $kind = Kind::PRODUCT
	) {
	}

	/**
	 * The same options for a search of the posts and pages: no categories, tags or brands, no owner's rules.
	 *
	 * @param int $limit At most this many.
	 */
	public function forContent( int $limit ): self {
		return new self( $this->weights, false, $this->fallbackLang, array(), $limit, 0, $this->retry, Kind::CONTENT );
	}

	/**
	 * Weights by field id.
	 *
	 * @return array<int, int>
	 */
	public function weightsById(): array {
		return Fields::weightsById( $this->weights );
	}
}
