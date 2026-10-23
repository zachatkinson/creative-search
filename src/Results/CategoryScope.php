<?php
/**
 * The category a search is kept to.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Results;

use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search within a category (D-117, Baymard's "search within the current category"): `product_cat=<slug>` beside
 * `s` and `post_type=product` keeps a search to that category and its subcategories, as WooCommerce's own
 * `product_cat` query variable does. The form on a category page offers it; the panel's rows for a category
 * link to it; the ranking reads it as term-taxonomy ids.
 */
final class CategoryScope {

	public const ARG      = 'product_cat';
	public const TAXONOMY = 'product_cat';

	/**
	 * The term-taxonomy ids of a category and its subcategories, by term id, for this request.
	 *
	 * @var array<int, int[]>
	 */
	private static array $ttIds = array();

	/**
	 * The category a slug names, or null.
	 *
	 * @param string $slug A `product_cat` slug.
	 */
	public static function bySlug( string $slug ): ?WP_Term {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}
		$term = get_term_by( 'slug', $slug, self::TAXONOMY );
		return $term instanceof WP_Term ? $term : null;
	}

	/**
	 * The category this request's search is kept to (`product_cat` in the URL), or null.
	 */
	public static function requested(): ?WP_Term {
		$slug = isset( $_GET[ self::ARG ] ) && is_string( $_GET[ self::ARG ] ) ? sanitize_title( wp_unslash( $_GET[ self::ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public, read-only search parameter.
		return self::bySlug( $slug );
	}

	/**
	 * The category the search form offers to search in: the one the search is already kept to, else the category
	 * page the shopper is on, else null.
	 */
	public static function current(): ?WP_Term {
		$requested = self::requested();
		if ( null !== $requested ) {
			return $requested;
		}
		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$term = get_queried_object();
			return $term instanceof WP_Term && self::TAXONOMY === $term->taxonomy ? $term : null;
		}
		return null;
	}

	/**
	 * The term-taxonomy ids a search kept to a category ranks within: the category's and every subcategory's.
	 *
	 * @param WP_Term|null $term The category, or null for none.
	 * @return int[]
	 */
	public static function ttIds( ?WP_Term $term ): array {
		if ( null === $term ) {
			return array();
		}
		if ( ! isset( self::$ttIds[ $term->term_id ] ) ) {
			$ids      = array( $term->term_taxonomy_id );
			$children = get_term_children( $term->term_id, self::TAXONOMY );
			foreach ( is_array( $children ) ? $children : array() as $childId ) {
				$child = get_term( (int) $childId, self::TAXONOMY );
				if ( $child instanceof WP_Term ) {
					$ids[] = $child->term_taxonomy_id;
				}
			}
			sort( $ids );
			self::$ttIds[ $term->term_id ] = $ids;
		}
		return self::$ttIds[ $term->term_id ];
	}

	/**
	 * Forget the request's lookups (tests change categories in one process).
	 */
	public static function reset(): void {
		self::$ttIds = array();
	}
}
