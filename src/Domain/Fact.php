<?php
/**
 * A SKU or attribute value of a product or one of its variations.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * One SKU or attribute value as the index keeps it beside a product's document (D-104): whose it is (the product
 * itself, or a variation), the query argument that preselects it on the product page, what the shopper reads,
 * the variation's own image, and the terms its text was cut into, so a query's terms can be compared with it.
 */
final class Fact {

	/**
	 * Constructor.
	 *
	 * @param int      $variationId The variation, or 0 for the product itself.
	 * @param int      $field       {@see Fields::SKU} or {@see Fields::ATTRIBUTE}.
	 * @param string   $attribute   The variation's query argument (`attribute_pa_design`); empty for a SKU and for
	 *                              the product's own values.
	 * @param string   $name        The attribute's name as the shop shows it ("Design"); empty for a SKU.
	 * @param string   $value       The argument's value (`fire`).
	 * @param string   $label       What the shopper reads ("Fire", "KZ-825").
	 * @param int|null $imageId     The variation's own image, or null.
	 * @param string[] $terms       The terms the label was cut into (a SKU's key term among them).
	 */
	public function __construct(
		public readonly int $variationId,
		public readonly int $field,
		public readonly string $attribute,
		public readonly string $name,
		public readonly string $value,
		public readonly string $label,
		public readonly ?int $imageId,
		public readonly array $terms
	) {
	}

	/**
	 * Whether any of some terms is one of this value's.
	 *
	 * @param string[] $terms Terms.
	 */
	public function reaches( array $terms ): bool {
		return array() !== array_intersect( $this->terms, $terms );
	}
}
