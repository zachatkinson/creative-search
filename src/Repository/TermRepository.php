<?php
/**
 * The dictionary.
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
 * Owns `csearch_terms`: every distinct token, stem and SKU key, with the number of documents carrying it.
 * A term at `df` 0 keeps its row (spec: Removal).
 */
final class TermRepository extends Repository {

	/**
	 * Ids by term, creating the missing ones.
	 *
	 * @param string[] $terms Terms (at most 64 bytes each).
	 * @return array<string, int> Term to id.
	 * @return-taint none
	 */
	public function ensure( array $terms ): array {
		$terms = array_values( array_unique( $terms ) );
		if ( array() === $terms ) {
			return array();
		}
		$ids     = $this->idsOf( $terms );
		$missing = array_diff( $terms, array_keys( $ids ) );
		if ( array() !== $missing ) {
			$values = implode( ',', array_fill( 0, count( $missing ), '(%s)' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the list holds placeholders only.
			$this->run( $this->prepare( "INSERT IGNORE INTO %i (term) VALUES {$values}", Tables::terms(), ...array_values( $missing ) ) );
			$ids = $this->idsOf( $terms );
		}
		return $ids;
	}

	/**
	 * Ids of the terms that exist.
	 *
	 * @param string[] $terms Terms.
	 * @return array<string, int> Term to id.
	 */
	public function idsOf( array $terms ): array {
		if ( array() === $terms ) {
			return array();
		}
		$list = self::strings( count( $terms ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the list holds placeholders only.
		$rows = $this->rows( $this->prepare( "SELECT id, term FROM %i WHERE term IN ({$list})", Tables::terms(), ...array_values( $terms ) ) );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['term'] ] = (int) $row['id'];
		}
		return $out;
	}

	/**
	 * Add one document to the count of some terms.
	 *
	 * @param int[] $termIds Term ids.
	 */
	public function increment( array $termIds ): void {
		if ( array() === $termIds ) {
			return;
		}
		$this->run( $this->prepare( 'UPDATE %i SET df = df + 1 WHERE id IN (' . self::ints( $termIds ) . ')', Tables::terms() ) );
	}

	/**
	 * Take documents off the count of some terms, each by its own number; never below zero. One statement per
	 * distinct number, so a cleanup batch is a handful of statements.
	 *
	 * @param array<int, int> $counts Term id to documents removed.
	 */
	public function decrement( array $counts ): void {
		$byCount = array();
		foreach ( $counts as $termId => $n ) {
			if ( $n > 0 ) {
				$byCount[ $n ][] = (int) $termId;
			}
		}
		foreach ( $byCount as $n => $termIds ) {
			foreach ( array_chunk( $termIds, 1000 ) as $chunk ) {
				$this->run( $this->prepare( 'UPDATE %i SET df = IF(df > %d, df - %d, 0) WHERE id IN (' . self::ints( $chunk ) . ')', Tables::terms(), $n, $n ) );
			}
		}
	}

	/**
	 * Document frequencies of the terms that have one.
	 *
	 * @param string[] $terms Terms.
	 * @return array<string, int>
	 */
	public function frequencies( array $terms ): array {
		if ( array() === $terms ) {
			return array();
		}
		$list = self::strings( count( $terms ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the list holds placeholders only.
		$rows = $this->rows( $this->prepare( "SELECT term, df FROM %i WHERE df > 0 AND term IN ({$list})", Tables::terms(), ...array_values( $terms ) ) );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['term'] ] = (int) $row['df'];
		}
		return $out;
	}

	/**
	 * The terms starting with a prefix that documents carry, most common first. A SKU key opens with `=`
	 * (D-041) and a prefix never does, so keys stay out by construction.
	 *
	 * @param string $prefix Folded prefix.
	 * @param int    $limit  At most.
	 * @return array<string, int> Term to df.
	 */
	public function withPrefix( string $prefix, int $limit ): array {
		global $wpdb;
		$rows = $this->rows(
			$this->prepare(
				'SELECT term, df FROM %i WHERE df > 0 AND term LIKE %s ORDER BY df DESC, term ASC LIMIT %d',
				Tables::terms(),
				$wpdb->esc_like( $prefix ) . '%',
				$limit
			)
		);
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['term'] ] = (int) $row['df'];
		}
		return $out;
	}

	/**
	 * How many terms the dictionary holds, and how many documents carry.
	 *
	 * @return array{terms: int, used: int}
	 */
	public function counts(): array {
		$row = $this->rows( $this->prepare( 'SELECT COUNT(*) AS terms, COALESCE(SUM(df > 0), 0) AS used FROM %i', Tables::terms() ) );
		return array(
			'terms' => (int) ( $row[0]['terms'] ?? 0 ),
			'used'  => (int) ( $row[0]['used'] ?? 0 ),
		);
	}
}
