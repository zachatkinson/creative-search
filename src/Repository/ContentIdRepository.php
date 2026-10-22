<?php
/**
 * Post and page ids for the reindex walk.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The published post and page ids a reindex walks when they are switched on (D-093), by id (keyset), as
 * {@see ProductIdRepository} walks the products. The posts themselves are read through WordPress by the indexer.
 */
final class ContentIdRepository extends Repository {

	/**
	 * The next published ids of some post types after one, lowest first.
	 *
	 * @param string[] $postTypes Post types.
	 * @param int      $after     The last id walked (0 to start).
	 * @param int      $limit     At most.
	 * @return int[]
	 */
	public function publishedAfter( array $postTypes, int $after, int $limit ): array {
		global $wpdb;
		if ( array() === $postTypes ) {
			return array();
		}
		$rows = $this->rows(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the list holds placeholders only.
			$this->prepare(
				'SELECT ID FROM %i WHERE post_type IN (' . self::strings( count( $postTypes ) ) . ') AND post_status = %s AND ID > %d ORDER BY ID LIMIT %d',
				$wpdb->posts,
				...array_merge( array_values( $postTypes ), array( 'publish', $after, $limit ) )
			)
		);
		return array_map( static fn( array $row ): int => (int) $row['ID'], $rows );
	}
}
