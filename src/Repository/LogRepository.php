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
	 * Delete every row (the Analytics tab's Clear analytics, `wp csearch analytics clear`, D-139).
	 *
	 * @return int Rows deleted.
	 */
	public function clear(): int {
		return $this->run( $this->prepare( 'DELETE FROM %i', Tables::log() ) );
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

	/**
	 * Searches per UTC day since a time.
	 *
	 * @param string $since UTC `Y-m-d H:i:s`.
	 * @return array<string, array{searches: int, zero: int, clicks: int}> Day to counts.
	 */
	public function daily( string $since ): array {
		$rows = $this->rows( $this->prepare( 'SELECT DATE(created_at) AS day, COUNT(*) AS searches, SUM(results = 0) AS zero, SUM(clicked_type IS NOT NULL) AS clicks FROM %i WHERE created_at >= %s GROUP BY DATE(created_at) ORDER BY day', Tables::log(), $since ) );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['day'] ] = array(
				'searches' => (int) $row['searches'],
				'zero'     => (int) $row['zero'],
				'clicks'   => (int) $row['clicks'],
			);
		}
		return $out;
	}

	/**
	 * The most searched queries since a time: searches, average results, clicks.
	 *
	 * @param string $since    UTC `Y-m-d H:i:s`.
	 * @param bool   $zeroOnly Only searches that found nothing.
	 * @param int    $limit    At most.
	 * @param int    $offset   Skip.
	 * @return array<int, array{query: string, lang: string, searches: int, results: int, clicks: int}>
	 */
	public function topQueries( string $since, bool $zeroOnly, int $limit, int $offset = 0 ): array {
		$rows = $this->rows(
			$this->prepare(
				'SELECT normalised, lang, COUNT(*) AS searches, ROUND(AVG(results)) AS results, SUM(clicked_type IS NOT NULL) AS clicks FROM %i WHERE created_at >= %s' . ( $zeroOnly ? ' AND results = 0' : '' ) . ' GROUP BY normalised, lang ORDER BY searches DESC, normalised ASC LIMIT %d OFFSET %d',
				Tables::log(),
				$since,
				$limit,
				$offset
			)
		);
		return array_map(
			static fn( array $row ): array => array(
				'query'    => (string) $row['normalised'],
				'lang'     => (string) $row['lang'],
				'searches' => (int) $row['searches'],
				'results'  => (int) $row['results'],
				'clicks'   => (int) $row['clicks'],
			),
			$rows
		);
	}

	/**
	 * Past searches that start with some text and found something, the most searched first (suggested searches,
	 * D-112): from the results page, or from the panel when the shopper chose a suggestion, so the words typed on
	 * the way to a search are never counted. A prefix range on the `normalised` key.
	 *
	 * @param string $prefix  The normalised text typed.
	 * @param string $lang    Language code.
	 * @param string $since   UTC `Y-m-d H:i:s`.
	 * @param int    $minimum Searches a query needs.
	 * @param int    $limit   At most.
	 * @return string[] Normalised queries.
	 */
	public function completions( string $prefix, string $lang, string $since, int $minimum, int $limit ): array {
		global $wpdb;
		$rows = $this->rows(
			$this->prepare(
				'SELECT normalised, COUNT(*) AS searches FROM %i WHERE normalised LIKE %s AND lang = %s AND created_at >= %s AND results > 0 AND (source = %s OR clicked_type IS NOT NULL) GROUP BY normalised HAVING COUNT(*) >= %d ORDER BY searches DESC, normalised ASC LIMIT %d',
				Tables::log(),
				$wpdb->esc_like( $prefix ) . '%',
				$lang,
				$since,
				'page',
				$minimum,
				$limit
			)
		);
		return array_map( static fn( array $row ): string => (string) $row['normalised'], $rows );
	}

	/**
	 * How many distinct queries since a time.
	 *
	 * @param string $since    UTC `Y-m-d H:i:s`.
	 * @param bool   $zeroOnly Only searches that found nothing.
	 */
	public function distinctQueries( string $since, bool $zeroOnly ): int {
		return (int) $this->value( $this->prepare( 'SELECT COUNT(DISTINCT normalised, lang) FROM %i WHERE created_at >= %s' . ( $zeroOnly ? ' AND results = 0' : '' ), Tables::log(), $since ) );
	}

	/**
	 * The most clicked suggestions since a time.
	 *
	 * @param string $since UTC `Y-m-d H:i:s`.
	 * @param int    $limit At most.
	 * @return array<int, array{type: string, id: int, clicks: int}>
	 */
	public function topClicked( string $since, int $limit ): array {
		$rows = $this->rows( $this->prepare( 'SELECT clicked_type, clicked_id, COUNT(*) AS clicks FROM %i WHERE created_at >= %s AND clicked_type IS NOT NULL GROUP BY clicked_type, clicked_id ORDER BY clicks DESC, clicked_id ASC LIMIT %d', Tables::log(), $since, $limit ) );
		return array_map(
			static fn( array $row ): array => array(
				'type'   => (string) $row['clicked_type'],
				'id'     => (int) $row['clicked_id'],
				'clicks' => (int) $row['clicks'],
			),
			$rows
		);
	}

	/**
	 * Searches by source since a time.
	 *
	 * @param string $since UTC `Y-m-d H:i:s`.
	 * @return array<string, int> Source to searches.
	 */
	public function sources( string $since ): array {
		$out = array(
			'suggest' => 0,
			'page'    => 0,
		);
		foreach ( $this->rows( $this->prepare( 'SELECT source, COUNT(*) AS n FROM %i WHERE created_at >= %s GROUP BY source', Tables::log(), $since ) ) as $row ) {
			$out[ (string) $row['source'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * A batch of rows since a time, oldest first, for the CSV export.
	 *
	 * @param string $since UTC `Y-m-d H:i:s`.
	 * @param int    $after The last id exported.
	 * @param int    $limit At most.
	 * @return array<int, array<string, string|null>>
	 */
	public function since( string $since, int $after, int $limit ): array {
		return $this->rows( $this->prepare( 'SELECT id, query, normalised, lang, results, clicked_type, clicked_id, source, created_at FROM %i WHERE created_at >= %s AND id > %d ORDER BY id LIMIT %d', Tables::log(), $since, $after, $limit ) );
	}
}
