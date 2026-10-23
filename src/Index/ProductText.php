<?php
/**
 * A product read for one language.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Index;

use CreativeBranding\Search\Domain\Fields;
use CreativeBranding\Search\Support\Cast;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything the indexer writes about one product in one language: the field texts to tokenise and the row
 * a suggestion renders from. Built by {@see ProductReader}, filtered through `csearch_document`.
 *
 * @phpstan-type FactRow array{variation: int, field: int, attribute: string, name: string, value: string, label: string, image_id: int|null}
 */
final class ProductText {

	/**
	 * Constructor.
	 *
	 * @param string                      $lang       Language code.
	 * @param string                      $title      The name the suggestion shows.
	 * @param array<int, string|string[]> $fields     Field id to its text or texts (Domain\Fields ids).
	 * @param string[]                    $skus       Every SKU: the product's and its variations'.
	 * @param string                      $url        Permalink.
	 * @param int|null                    $imageId    Main image attachment id.
	 * @param float|null                  $priceMin   Lowest price.
	 * @param float|null                  $priceMax   Highest price.
	 * @param bool                        $inStock    Any purchasable variation in stock.
	 * @param bool                        $visible    Shown in the catalogue (a hidden product is found by SKU only).
	 * @param int                         $boost      The owner's boost, -10 to 10.
	 * @param int                         $totalSales Units sold, for the tie-break.
	 * @param bool                        $cart       Whether the panel may add it to the cart as it is: a simple
	 *                                                product, purchasable and in stock (D-106).
	 * @param array<int, FactRow>         $facts      The product's and its variations' SKUs and attribute values,
	 *                                                in the shop's order (D-104).
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
		public readonly int $totalSales,
		public readonly bool $cart = false,
		public readonly array $facts = array()
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
			'cart'        => $this->cart,
			'facts'       => $this->facts,
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
			max( 0, Cast::int( $data['total_sales'] ?? null ) ),
			(bool) ( $data['cart'] ?? false ),
			self::facts( $data['facts'] ?? null )
		);
	}

	/**
	 * The facts back from the filter: each a SKU or an attribute value with a label, every value coerced.
	 *
	 * @param mixed $facts What the filter returned.
	 * @return array<int, FactRow>
	 */
	private static function facts( mixed $facts ): array {
		$out = array();
		foreach ( is_array( $facts ) ? $facts : array() as $fact ) {
			if ( ! is_array( $fact ) ) {
				continue;
			}
			$field = Cast::int( $fact['field'] ?? null );
			$label = Cast::str( $fact['label'] ?? null );
			if ( ! in_array( $field, array( Fields::SKU, Fields::ATTRIBUTE ), true ) || '' === trim( $label ) ) {
				continue;
			}
			$image = Cast::int( $fact['image_id'] ?? null );
			$out[] = array(
				'variation' => max( 0, Cast::int( $fact['variation'] ?? null ) ),
				'field'     => $field,
				'attribute' => Cast::str( $fact['attribute'] ?? null ),
				'name'      => Cast::str( $fact['name'] ?? null ),
				'value'     => Cast::str( $fact['value'] ?? null ),
				'label'     => $label,
				'image_id'  => $image > 0 ? $image : null,
			);
		}
		return $out;
	}
}
