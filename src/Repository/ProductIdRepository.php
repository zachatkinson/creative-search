<?php
/**
 * Product ids for the reindex walk.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Repository;

use CreativeBranding\Search\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The published product ids a full reindex walks, by id (keyset), so a product trashed or added mid-walk never
 * shifts the rest (D-054). Products themselves are read through WooCommerce's CRUD by the indexer; this reads
 * ids only, from `wp_posts`, where WooCommerce keeps products whatever the order storage.
 */
final class ProductIdRepository extends Repository {

	/**
	 * The next published product ids after one, lowest first.
	 *
	 * @param int $after The last id walked (0 to start).
	 * @param int $limit At most.
	 * @return int[]
	 */
	public function publishedAfter( int $after, int $limit ): array {
		global $wpdb;
		$rows = $this->rows( $this->prepare( 'SELECT ID FROM %i WHERE post_type = %s AND post_status = %s AND ID > %d ORDER BY ID LIMIT %d', $wpdb->posts, 'product', 'publish', $after, $limit ) );
		return array_map( static fn( array $row ): int => (int) $row['ID'], $rows );
	}

	/**
	 * Some ids with each variation's replaced by its parent's, as the index folds a variation into its product
	 * (D-162). One read of the posts' primary key; an id that isn't a variation is kept as it is.
	 *
	 * @param int[] $ids Product or variation ids, positive.
	 * @return int[] Unique, in the order first seen.
	 */
	public function foldVariations( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}
		global $wpdb;
		$parents = array();
		foreach ( $this->rows( $this->prepare( 'SELECT ID, post_parent FROM %i WHERE ID IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND post_type = %s AND post_parent > 0', $wpdb->posts, ...array_merge( $ids, array( 'product_variation' ) ) ) ) as $row ) {
			$parents[ (int) $row['ID'] ] = (int) $row['post_parent'];
		}
		return array_values( array_unique( array_map( static fn( int $id ): int => $parents[ $id ] ?? $id, $ids ) ) );
	}

	/**
	 * How many published products there are.
	 */
	public function publishedCount(): int {
		global $wpdb;
		return (int) $this->value( $this->prepare( 'SELECT COUNT(*) FROM %i WHERE post_type = %s AND post_status = %s', $wpdb->posts, 'product', 'publish' ) );
	}

	/**
	 * How many published products the owner keeps out of search (`_csearch_exclude`).
	 */
	public function excludedCount(): int {
		global $wpdb;
		return (int) $this->value( $this->prepare( 'SELECT COUNT(*) FROM %i p INNER JOIN %i m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s WHERE p.post_type = %s AND p.post_status = %s', $wpdb->posts, $wpdb->postmeta, Options::META_EXCLUDE, 'yes', 'product', 'publish' ) );
	}

	/**
	 * The products the owner keeps out of search, any status but trash, newest first.
	 *
	 * @param int $limit At most.
	 * @return int[]
	 */
	public function excluded( int $limit ): array {
		global $wpdb;
		$rows = $this->rows( $this->prepare( 'SELECT p.ID FROM %i p INNER JOIN %i m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s WHERE p.post_type = %s AND p.post_status NOT IN (%s, %s) ORDER BY p.ID DESC LIMIT %d', $wpdb->posts, $wpdb->postmeta, Options::META_EXCLUDE, 'yes', 'product', 'trash', 'auto-draft', $limit ) );
		return array_map( static fn( array $row ): int => (int) $row['ID'], $rows );
	}

	/**
	 * The next product ids, any status but trash and auto-draft, carrying any of some terms, lowest first.
	 *
	 * @param int[] $termIds Term ids (a category with its descendants).
	 * @param int   $after   The last id walked (0 to start).
	 * @param int   $limit   At most.
	 * @return int[]
	 */
	public function withTermsAfter( array $termIds, int $after, int $limit ): array {
		global $wpdb;
		if ( array() === $termIds ) {
			return array();
		}
		$rows = $this->rows(
			$this->prepare(
				'SELECT DISTINCT p.ID FROM %i p INNER JOIN %i r ON r.object_id = p.ID INNER JOIN %i tt ON tt.term_taxonomy_id = r.term_taxonomy_id WHERE tt.term_id IN (' . self::ints( $termIds ) . ') AND p.post_type = %s AND p.post_status NOT IN (%s, %s) AND p.ID > %d ORDER BY p.ID LIMIT %d',
				$wpdb->posts,
				$wpdb->term_relationships,
				$wpdb->term_taxonomy,
				'product',
				'trash',
				'auto-draft',
				$after,
				$limit
			)
		);
		return array_map( static fn( array $row ): int => (int) $row['ID'], $rows );
	}

	/**
	 * The products WooCommerce's filtered listing shows for some attribute terms when it hides what is sold out
	 * (D-151): a product, or the parent of a variation, with an in-stock row in WooCommerce's attributes lookup table
	 * for one of the terms, as its `Filterer` writes the clause. Only read when that table is in use.
	 *
	 * @param int[] $termIds Attribute term ids, of one taxonomy.
	 * @return int[]
	 */
	public function inStockWithTerms( array $termIds ): array {
		global $wpdb;
		if ( array() === $termIds ) {
			return array();
		}
		$rows = $this->rows(
			$this->prepare(
				'SELECT DISTINCT product_or_parent_id FROM %i WHERE term_id IN (' . self::ints( $termIds ) . ') AND in_stock = %d',
				$wpdb->prefix . 'wc_product_attributes_lookup',
				1
			)
		);
		return array_map( static fn( array $row ): int => (int) $row['product_or_parent_id'], $rows );
	}

	/**
	 * The terms of an attribute some products have in stock, as WooCommerce's attributes lookup table keeps them
	 * (D-154): the values its filtered listing would list one of them in.
	 *
	 * @param string     $taxonomy Attribute taxonomy.
	 * @param int[]|null $ids      Product ids, or null for every product.
	 * @return int[] Term ids.
	 */
	public function termsInStock( string $taxonomy, ?array $ids ): array {
		global $wpdb;
		if ( array() === $ids ) {
			return array();
		}
		$among = null === $ids ? '' : ' AND product_or_parent_id IN (' . self::ints( $ids ) . ')';
		$rows  = $this->rows( $this->prepare( 'SELECT DISTINCT term_id FROM %i WHERE taxonomy = %s AND in_stock = %d' . $among, $wpdb->prefix . 'wc_product_attributes_lookup', $taxonomy, 1 ) );
		return array_map( static fn( array $row ): int => (int) $row['term_id'], $rows );
	}

	/**
	 * The terms of an attribute some published products carry (D-154).
	 *
	 * @param string     $taxonomy Attribute taxonomy.
	 * @param int[]|null $ids      Product ids, or null for every product.
	 * @return int[] Term ids.
	 */
	public function termsOf( string $taxonomy, ?array $ids ): array {
		global $wpdb;
		if ( array() === $ids ) {
			return array();
		}
		$among = null === $ids ? '' : ' AND p.ID IN (' . self::ints( $ids ) . ')';
		$rows  = $this->rows( $this->prepare( 'SELECT DISTINCT tt.term_id FROM %i p INNER JOIN %i r ON r.object_id = p.ID INNER JOIN %i tt ON tt.term_taxonomy_id = r.term_taxonomy_id WHERE tt.taxonomy = %s AND p.post_type = %s AND p.post_status = %s' . $among, $wpdb->posts, $wpdb->term_relationships, $wpdb->term_taxonomy, $taxonomy, 'product', 'publish' ) );
		return array_map( static fn( array $row ): int => (int) $row['term_id'], $rows );
	}

	/**
	 * Which of some products have which of some attribute terms in stock, as WooCommerce's attributes lookup table
	 * keeps them (D-155): a product, or a variation of it, with an in-stock row for the term.
	 *
	 * @param int[] $productIds Product ids.
	 * @param int[] $termIds    Attribute term ids.
	 * @return array<string, true> `product:term` for each pair in stock.
	 */
	public function inStockPairs( array $productIds, array $termIds ): array {
		global $wpdb;
		if ( array() === $productIds || array() === $termIds ) {
			return array();
		}
		$rows = $this->rows(
			$this->prepare(
				'SELECT DISTINCT product_or_parent_id, term_id FROM %i WHERE product_or_parent_id IN (' . self::ints( $productIds ) . ') AND term_id IN (' . self::ints( $termIds ) . ') AND in_stock = %d',
				$wpdb->prefix . 'wc_product_attributes_lookup',
				1
			)
		);
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['product_or_parent_id'] . ':' . (int) $row['term_id'] ] = true;
		}
		return $out;
	}

	/**
	 * Whether any published product of a set carries some term of an attribute, sold out or not (D-156): a value read
	 * from a query filters only products that have the attribute at all.
	 *
	 * @param string     $taxonomy   Attribute taxonomy.
	 * @param int[]|null $ids        Product ids, or null for any product.
	 * @param int[]      $categories Term taxonomy ids of a category and its subcategories the products are in, or
	 *                               none for any category.
	 * @param int        $hidden     A visibility term taxonomy id whose products don't count, or 0.
	 */
	public function carries( string $taxonomy, ?array $ids, array $categories = array(), int $hidden = 0 ): bool {
		global $wpdb;
		if ( array() === $ids ) {
			return false;
		}
		$in    = array() === $categories ? '' : ' INNER JOIN %i c ON c.object_id = p.ID AND c.term_taxonomy_id IN (' . self::ints( $categories ) . ')';
		$among = null === $ids ? '' : ' AND p.ID IN (' . self::ints( $ids ) . ')';
		$args  = array( $wpdb->posts, $wpdb->term_relationships, $wpdb->term_taxonomy );
		if ( array() !== $categories ) {
			$args[] = $wpdb->term_relationships;
		}
		array_push( $args, $taxonomy, 'product', 'publish' );
		$shown = '';
		if ( $hidden > 0 ) {
			// A product the page doesn't list (hidden from the catalogue or from search) can't make it filter (D-157).
			$shown = ' AND NOT EXISTS (SELECT 1 FROM %i h WHERE h.object_id = p.ID AND h.term_taxonomy_id = %d)';
			array_push( $args, $wpdb->term_relationships, $hidden );
		}
		return null !== $this->value( $this->prepare( 'SELECT 1 FROM %i p INNER JOIN %i r ON r.object_id = p.ID INNER JOIN %i tt ON tt.term_taxonomy_id = r.term_taxonomy_id' . $in . ' WHERE tt.taxonomy = %s AND p.post_type = %s AND p.post_status = %s' . $among . $shown . ' LIMIT 1', ...$args ) );
	}
}
