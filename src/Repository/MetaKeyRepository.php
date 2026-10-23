<?php
/**
 * The meta keys products carry.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The custom fields the Tools tab offers to search (D-119): the meta keys the shop's products use, most used
 * first. Keys starting with an underscore are WooCommerce's and WordPress's own (and ACF's references to its field
 * settings), and `total_sales` is WooCommerce's sales counter on every product, so they are left out; the owner can
 * still type one. Read on the admin screen only.
 */
final class MetaKeyRepository extends Repository {

	/**
	 * WooCommerce's own product meta without an underscore: numbers, not text to search.
	 */
	public const OWN_KEYS = array( 'total_sales' );

	/**
	 * The meta keys of products, most used first, then by name.
	 *
	 * @param int $limit At most.
	 * @return string[]
	 */
	public function productKeys( int $limit ): array {
		global $wpdb;
		$rows = $this->rows(
			$this->prepare(
				'SELECT m.meta_key, COUNT(*) AS uses FROM %i m INNER JOIN %i p ON p.ID = m.post_id WHERE p.post_type = %s AND m.meta_key NOT LIKE %s AND m.meta_key <> %s GROUP BY m.meta_key ORDER BY uses DESC, m.meta_key ASC LIMIT %d',
				$wpdb->postmeta,
				$wpdb->posts,
				'product',
				$wpdb->esc_like( '_' ) . '%',
				self::OWN_KEYS[0],
				$limit
			)
		);
		return array_map( static fn( array $row ): string => (string) $row['meta_key'], $rows );
	}
}
