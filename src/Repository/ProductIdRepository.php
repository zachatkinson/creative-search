<?php
/**
 * Product ids for the reindex walk.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Repository;

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
	 * How many published products there are.
	 */
	public function publishedCount(): int {
		global $wpdb;
		return (int) $this->value( $this->prepare( 'SELECT COUNT(*) FROM %i WHERE post_type = %s AND post_status = %s', $wpdb->posts, 'product', 'publish' ) );
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
}
