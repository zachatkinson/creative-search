<?php
/**
 * A document.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * One thing the index can return (spec: Data model → csearch_documents), with what ranking needs: its folded
 * name and SKU keys for the exactness bonus, its stock flag, visibility, the owner's boost, and the tie-break
 * numbers. The suggestion row's url, image and prices are the repository's business.
 */
final class Document {

	public const PRODUCT  = 'product';
	public const CATEGORY = 'category';
	public const TAG      = 'tag';
	public const BRAND    = 'brand';

	/**
	 * Constructor.
	 *
	 * @param int      $id         Document id.
	 * @param string   $type       product, category, tag or brand.
	 * @param int      $objectId   The product or term id.
	 * @param string   $lang       Language code.
	 * @param string   $title      The name as written, for the suggestion.
	 * @param string   $nameKey    The name's folded tokens joined by spaces.
	 * @param string[] $skuKeys    The folded SKU keys: the product's and its variations'.
	 * @param bool     $inStock    Whether anything purchasable is in stock.
	 * @param bool     $visible    False for a product hidden from the catalogue.
	 * @param int      $boost      The owner's boost on the product, -10 to 10.
	 * @param int      $totalSales WooCommerce's sales count, for the tie-break.
	 * @param int      $recency    Larger is newer, for the last tie-break.
	 * @param string[] $termRefs   The owner's boost rules it can match (`category:12`, `brand:3`, `tag:7`).
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $type,
		public readonly int $objectId,
		public readonly string $lang,
		public readonly string $title,
		public readonly string $nameKey = '',
		public readonly array $skuKeys = array(),
		public readonly bool $inStock = true,
		public readonly bool $visible = true,
		public readonly int $boost = 0,
		public readonly int $totalSales = 0,
		public readonly int $recency = 0,
		public readonly array $termRefs = array()
	) {
	}

	/**
	 * Whether this is a product (the others are categories, tags and brands: the "terms" of the panel).
	 */
	public function isProduct(): bool {
		return self::PRODUCT === $this->type;
	}
}
