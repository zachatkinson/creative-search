<?php
/**
 * A product read for one language.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Index;

use CreativeBranding\Search\Support\Cast;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything the indexer writes about one product in one language: the field texts to tokenise and the row
 * a suggestion renders from. Built by {@see ProductReader}, filtered through `csearch_document`.
 */
final class ProductText {

	/**
	 * Constructor.
	 *
	 * @param string                      $lang      Language code.
	 * @param string                      $title     The name the suggestion shows.
	 * @param array<int, string|string[]> $fields    Field id to its text or texts (Domain\Fields ids).
	 * @param string[]                    $skus      Every SKU: the product's and its variations'.
	 * @param string                      $url       Permalink.
	 * @param int|null                    $imageId   Main image attachment id.
	 * @param float|null                  $priceMin  Lowest price.
	 * @param float|null                  $priceMax  Highest price.
	 * @param bool                        $inStock   Any purchasable variation in stock.
	 * @param bool                        $visible   Shown in the catalogue (a hidden product is found by SKU only).
	 * @param int                         $boost     The owner's boost, -10 to 10.
	 * @param int                         $totalSales Units sold, for the tie-break.
	 */
	public function __construct(
		public readonly string $lang,
		public readonly string $title,
		public readonly array $fields,
		public readonly array $skus,
		public readonly string $url,
		public readonly ?int $imageId,
		public readonly ?float $priceMin,
		public readonly ?float $priceMax,
		public readonly bool $inStock,
		public readonly bool $visible,
		public readonly int $boost,
		public readonly int $totalSales
	) {
	}

	/**
	 * As the `csearch_document` filter sees it.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'lang'        => $this->lang,
			'title'       => $this->title,
			'fields'      => $this->fields,
			'skus'        => $this->skus,
			'url'         => $this->url,
			'image_id'    => $this->imageId,
			'price_min'   => $this->priceMin,
			'price_max'   => $this->priceMax,
			'in_stock'    => $this->inStock,
			'visible'     => $this->visible,
			'boost'       => $this->boost,
			'total_sales' => $this->totalSales,
		);
	}

	/**
	 * Back from the filter, with every value coerced to its type.
	 *
	 * @param array<string, mixed> $data The filtered array.
	 */
	public static function fromArray( array $data ): self {
		$image = Cast::int( $data['image_id'] ?? null );
		return new self(
			Cast::str( $data['lang'] ?? null, 'en' ),
			Cast::str( $data['title'] ?? null ),
			Cast::fields( $data['fields'] ?? null ),
			Cast::strings( $data['skus'] ?? null ),
			Cast::str( $data['url'] ?? null ),
			$image > 0 ? $image : null,
			isset( $data['price_min'] ) && is_numeric( $data['price_min'] ) ? (float) $data['price_min'] : null,
			isset( $data['price_max'] ) && is_numeric( $data['price_max'] ) ? (float) $data['price_max'] : null,
			(bool) ( $data['in_stock'] ?? true ),
			(bool) ( $data['visible'] ?? true ),
			max( -10, min( 10, Cast::int( $data['boost'] ?? null ) ) ),
			max( 0, Cast::int( $data['total_sales'] ?? null ) )
		);
	}
}
