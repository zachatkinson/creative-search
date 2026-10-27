<?php
/**
 * Category-scope suggestions for the panel.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Search;

use CreativeBranding\Search\Domain\Scopes;
use CreativeBranding\Search\Domain\Tokenizer;
use CreativeBranding\Search\Repository\DocumentRepository;
use CreativeBranding\Search\Repository\RankRepository;
use CreativeBranding\Search\Results\CategoryScope;
use CreativeBranding\Search\Settings\Settings;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "kazumi in Decks": up to two categories holding most of the first ranked products, each a link to the
 * search within that category (Baymard: scope suggestions; D-113). One read of the first ten products' categories
 * and one of the terms; the results page narrows the search by WooCommerce's own `product_cat` parameter.
 * On a category page the shopper is in comes first, when it holds any of the ranked products (D-130): the way to
 * search within it, in place of 1.3's checkbox. Switched off by `suggest_scopes`.
 */
final class CategoryScopes {

	public const TAXONOMY = 'product_cat';

	/**
	 * Constructor.
	 *
	 * @param DocumentRepository $documents The products' categories.
	 * @param RankRepository     $rank      Which ranked products a category holds.
	 */
	public function __construct( private DocumentRepository $documents, private RankRepository $rank ) {
	}

	/**
	 * The categories to offer.
	 *
	 * @param string       $query      Clean query.
	 * @param int[]        $productIds The ranked product ids.
	 * @param int[]        $shown      Category ids already suggested.
	 * @param WP_Term|null $here       The category the shopper is in, or null.
	 * @return array<int, array{id: int, name: string, url: string}>
	 */
	public function for( string $query, array $productIds, array $shown, ?WP_Term $here = null ): array {
		if ( '' === $query || ! Settings::isOn( 'suggest_scopes' ) || array() === $productIds ) {
			return array();
		}
		$first = null !== $here && array() !== $this->rank->scopedObjects( $productIds, CategoryScope::ttIds( $here ) )
			? array(
				'id'   => $here->term_id,
				'name' => Tokenizer::decode( $here->name ),
				'url'  => self::url( $query, $here->slug ),
			)
			: null;
		$out   = null === $first ? array() : array( $first );
		if ( count( $productIds ) < Scopes::MINIMUM ) {
			return $out;
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
				'taxonomy'               => self::TAXONOMY,
				'include'                => $ids,
				'hide_empty'             => false,
				'update_term_meta_cache' => false,
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
		foreach ( Scopes::pick( $ranks, $parents, count( $productIds ), $shown ) as $id ) {
			if ( isset( $byId[ $id ] ) && count( $out ) < Scopes::MOST && ( null === $first || $id !== $first['id'] ) ) {
				$out[] = array(
					'id'   => $id,
					'name' => Tokenizer::decode( $byId[ $id ]->name ),
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
