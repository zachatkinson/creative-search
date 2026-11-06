<?php
/**
 * Why a product matched, and which of its variations a query named.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * What a result shows beyond its own row (D-104, D-105): the variation a query named, with its image and the
 * query arguments that preselect it, and the line saying what matched when it is something the card does not
 * show (a SKU, an attribute value, the description).
 */
final class MatchDetail {

	public const SKU         = 'sku';
	public const ATTRIBUTE   = 'attribute';
	public const DESCRIPTION = 'description';

	/**
	 * Constructor.
	 *
	 * @param int                   $variationId The variation the query named, or 0.
	 * @param int|null              $imageId     That variation's own image, or null to keep the product's.
	 * @param array<string, string> $args        Query arguments that preselect it (`attribute_pa_design` => `fire`).
	 * @param string                $reason      What matched: one of the constants, or empty for nothing to say.
	 * @param string                $name        The attribute's name, for an attribute value.
	 * @param string                $label       The SKU or the value.
	 * @param string                $attribute   For an attribute value, its variation key (`attribute_pa_width`).
	 * @param string                $value       For an attribute value, its slug (`9-00`), so a caller can check it is in stock (D-155).
	 */
	public function __construct(
		public readonly int $variationId = 0,
		public readonly ?int $imageId = null,
		public readonly array $args = array(),
		public readonly string $reason = '',
		public readonly string $name = '',
		public readonly string $label = '',
		public readonly string $attribute = '',
		public readonly string $value = ''
	) {
	}

	/**
	 * Whether there is nothing to show.
	 */
	public function isEmpty(): bool {
		return 0 === $this->variationId && '' === $this->reason;
	}

	/**
	 * The same details without the line saying what matched: the value it names is sold out on this product (D-155).
	 */
	public function withoutReason(): self {
		return new self( $this->variationId, $this->imageId, $this->args );
	}
}
