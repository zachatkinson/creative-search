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
	 */
	public function __construct(
		public readonly array $weights = array(),
		public readonly bool $hiddenBySku = true,
		public readonly ?string $fallbackLang = 'en',
		public readonly array $boostRules = array(),
		public readonly int $limit = 500,
		public readonly int $termLimit = 3
	) {
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
