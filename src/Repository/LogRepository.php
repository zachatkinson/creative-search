<?php
/**
 * The search log.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Repository;

use CreativeBranding\Search\Support\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns `csearch_log`: one row per search, no shopper identity (spec: Analytics and privacy).
 */
final class LogRepository extends Repository {

	/**
	 * Write rows.
	 *
	 * @param array<int, array{query: string, normalised: string, lang: string, results: int, source: string, created_at: string}> $rows Rows.
	 */
	public function insert( array $rows ): void {
		if ( array() === $rows ) {
			return;
		}
		$values = array();
		$args   = array( Tables::log() );
		foreach ( $rows as $row ) {
			$values[] = '(%s,%s,%s,%d,%s,%s)';
			array_push( $args, $row['query'], $row['normalised'], $row['lang'], min( 65535, max( 0, $row['results'] ) ), $row['source'], $row['created_at'] );
		}
		$list = implode( ',', $values );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the list holds placeholders only.
		$this->run( $this->prepare( "INSERT INTO %i (query, normalised, lang, results, source, created_at) VALUES {$list}", ...$args ) );
	}

	/**
	 * Record a click on the latest unclicked row for a query, written since a time.
	 *
	 * @param string $normalised The normalised query.
	 * @param string $lang       Language code.
	 * @param string $since      UTC `Y-m-d H:i:s`.
	 * @param string $type       What was clicked: product, category, tag, brand.
	 * @param int    $id         Its id.
	 * @return bool Whether a row was updated.
	 */
	public function click( string $normalised, string $lang, string $since, string $type, int $id ): bool {
		return 1 === $this->run(
			$this->prepare(
				'UPDATE %i SET clicked_type = %s, clicked_id = %d WHERE normalised = %s AND lang = %s AND created_at >= %s AND clicked_type IS NULL ORDER BY id DESC LIMIT 1',
				Tables::log(),
				$type,
				$id,
				$normalised,
				$lang,
				$since
			)
		);
	}

	/**
	 * Delete a batch of rows older than a time.
	 *
	 * @param string $before UTC `Y-m-d H:i:s`.
	 * @param int    $limit  Batch size.
	 * @return int Rows deleted.
	 */
	public function purge( string $before, int $limit ): int {
		return $this->run( $this->prepare( 'DELETE FROM %i WHERE created_at < %s ORDER BY id LIMIT %d', Tables::log(), $before, $limit ) );
	}

	/**
	 * Counts for one UTC day: searches, searches that found nothing, searches followed by a click.
	 *
	 * @param string $day `Y-m-d`.
	 * @return array{searches: int, zero: int, clicks: int}
	 */
	public function dayCounts( string $day ): array {
		$rows = $this->rows(
			$this->prepare(
				'SELECT COUNT(*) AS searches, COALESCE(SUM(results = 0), 0) AS zero, COALESCE(SUM(clicked_type IS NOT NULL), 0) AS clicks FROM %i WHERE created_at >= %s AND created_at < %s',
				Tables::log(),
				$day . ' 00:00:00',
				gmdate( 'Y-m-d', (int) strtotime( $day . ' 00:00:00 UTC +1 day' ) ) . ' 00:00:00'
			)
		);
		return array(
			'searches' => (int) ( $rows[0]['searches'] ?? 0 ),
			'zero'     => (int) ( $rows[0]['zero'] ?? 0 ),
			'clicks'   => (int) ( $rows[0]['clicks'] ?? 0 ),
		);
	}

	/**
	 * How many rows the log holds.
	 */
	public function count(): int {
		return (int) $this->value( $this->prepare( 'SELECT COUNT(*) FROM %i', Tables::log() ) );
	}

	/**
	 * The latest rows, newest first (WP-CLI `log`, the Analytics table in milestone 7).
	 *
	 * @param int $limit  At most.
	 * @param int $offset Skip.
	 * @return array<int, array<string, string|null>>
	 */
	public function latest( int $limit, int $offset = 0 ): array {
		return $this->rows( $this->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', Tables::log(), $limit, $offset ) );
	}
}
