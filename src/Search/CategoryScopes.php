<?php
/**
 * Category-scope suggestions for the panel.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Search;

use CreativeBranding\Search\Domain\Scopes;
use CreativeBranding\Search\Repository\DocumentRepository;
use CreativeBranding\Search\Settings\Settings;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "kazumi in Decks": up to two categories holding most of the first ranked products, each a link to the
 * search within that category (Baymard: scope suggestions; D-113). One read of the first ten products' categories
 * and one of the terms; the results page narrows the search by WooCommerce's own `product_cat` parameter.
 * Switched off by `suggest_scopes`.
 */
final class CategoryScopes {

	public const TAXONOMY = 'product_cat';

	/**
	 * Constructor.
	 *
	 * @param DocumentRepository $documents The products' categories.
	 */
	public function __construct( private DocumentRepository $documents ) {
	}

	/**
	 * The categories to offer.
	 *
	 * @param string $query      Clean query.
	 * @param int[]  $productIds The ranked product ids.
	 * @param int[]  $shown      Category ids already suggested.
	 * @return array<int, array{id: int, name: string, url: string}>
	 */
	public function for( string $query, array $productIds, array $shown ): array {
		if ( '' === $query || ! Settings::isOn( 'suggest_scopes' ) || count( $productIds ) < Scopes::MINIMUM ) {
			return array();
		}
		$top   = array_slice( $productIds, 0, Scopes::TOP );
		$read  = $this->documents->categoriesOf( $top );
		$ranks = array();
		foreach ( $top as $id ) {
			$ranks[ $id ] = $read[ $id ] ?? array();
		}
		$ids     = array_values( array_unique( array_merge( array(), ...array_values( $ranks ) ) ) );
		$terms   = array() === $ids ? array() : get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'include'    => $ids,
				'hide_empty' => false,
			)
		);
		$byId    = array();
		$parents = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( $term instanceof WP_Term ) {
				$byId[ $term->term_id ]    = $term;
				$parents[ $term->term_id ] = $term->parent;
			}
		}
		$out = array();
		foreach ( Scopes::pick( $ranks, $parents, count( $productIds ), $shown ) as $id ) {
			if ( isset( $byId[ $id ] ) ) {
				$out[] = array(
					'id'   => $id,
					'name' => $byId[ $id ]->name,
					'url'  => self::url( $query, $byId[ $id ]->slug ),
				);
			}
		}
		return $out;
	}

	/**
	 * The search within one category: the shop's search with WooCommerce's category parameter.
	 *
	 * @param string $query The query.
	 * @param string $slug  The category's slug.
	 */
	public static function url( string $query, string $slug ): string {
		return add_query_arg(
			array(
				's'            => rawurlencode( $query ),
				'post_type'    => 'product',
				self::TAXONOMY => rawurlencode( $slug ),
			),
			home_url( '/' )
		);
	}
}
