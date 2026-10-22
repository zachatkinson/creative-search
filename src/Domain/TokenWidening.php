<?php
/**
 * A token widened to its terms.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The terms one query token reached at the widening level that found something, with their postings in the
 * products and in the categories, tags and brands (spec: Matching → Widening each token).
 *
 * @phpstan-type PostingsByTerm array<string, array<int, array<int, array{0: int, 1: int}>>>
 */
final class TokenWidening {

	/**
	 * Constructor.
	 *
	 * @param array<string, TermMatch>                                     $matches  Term to how it was reached.
	 * @param array<string, array<int, array<int, array{0: int, 1: int}>>> $products Term to product document id to field to [tf, pos].
	 * @param array<string, array<int, array<int, array{0: int, 1: int}>>> $terms    The same in the category, tag and brand documents.
	 */
	public function __construct( public readonly array $matches, public readonly array $products, public readonly array $terms ) {
	}

	/**
	 * No term reached any document.
	 */
	public function isEmpty(): bool {
		return array() === $this->products && array() === $this->terms;
	}

	/**
	 * The product documents reached, as a set.
	 *
	 * @return array<int, true>
	 */
	public function productDocs(): array {
		return self::docs( $this->products );
	}

	/**
	 * The category, tag and brand documents reached, as a set.
	 *
	 * @return array<int, true>
	 */
	public function termDocs(): array {
		return self::docs( $this->terms );
	}

	/**
	 * This token's postings in one document: term to field to [tf, pos].
	 *
	 * @param int  $doc      Document id.
	 * @param bool $products Whether it is a product.
	 * @return array<string, array<int, array{0: int, 1: int}>>
	 */
	public function postingsIn( int $doc, bool $products ): array {
		$out = array();
		foreach ( $products ? $this->products : $this->terms as $term => $byDoc ) {
			if ( isset( $byDoc[ $doc ] ) ) {
				$out[ $term ] = $byDoc[ $doc ];
			}
		}
		return $out;
	}

	/**
	 * The documents of some postings, as a set.
	 *
	 * @param array<string, array<int, array<int, array{0: int, 1: int}>>> $postings Term to document to fields.
	 * @return array<int, true>
	 */
	private static function docs( array $postings ): array {
		$set = array();
		foreach ( $postings as $byDoc ) {
			foreach ( array_keys( $byDoc ) as $doc ) {
				$set[ $doc ] = true;
			}
		}
		return $set;
	}
}
