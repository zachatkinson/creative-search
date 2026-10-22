<?php
/**
 * Term statistics.
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
 * Owns `csearch_term_stats`: how many documents of each generation and language carry each term (D-087). The
 * indexer adds a document's terms when it writes one and takes them off when it removes one, so the search
 * reads a term's df by its key instead of counting postings.
 */
final class StatRepository extends Repository {

	/**
	 * Add one document to the count of some terms.
	 *
	 * @param int[]  $termIds    Distinct term ids.
	 * @param int    $generation Generation.
	 * @param string $lang       Language code.
	 */
	public function increment( array $termIds, int $generation, string $lang ): void {
		foreach ( array_chunk( $termIds, PostingRepository::CHUNK ) as $chunk ) {
			$values = implode( ',', array_fill( 0, count( $chunk ), '(%d,%d,%s,1)' ) );
			$args   = array();
			foreach ( $chunk as $termId ) {
				array_push( $args, (int) $termId, $generation, $lang );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the list holds placeholders only.
			$this->run( $this->prepare( "INSERT INTO %i (term_id, generation, lang, df) VALUES {$values} ON DUPLICATE KEY UPDATE df = df + 1", Tables::stats(), ...$args ) );
		}
	}

	/**
	 * Take removed documents off the counts, never below zero, and drop the rows that reach zero.
	 *
	 * @param array<int, array{term_id: int, generation: int, lang: string, n: int}> $counts What the removed documents carried.
	 */
	public function decrement( array $counts ): void {
		$groups = array();
		foreach ( $counts as $row ) {
			$groups[ $row['generation'] ][ $row['lang'] ][ $row['n'] ][] = $row['term_id'];
		}
		foreach ( $groups as $generation => $byLang ) {
			foreach ( $byLang as $lang => $byCount ) {
				foreach ( $byCount as $n => $termIds ) {
					foreach ( array_chunk( $termIds, 1000 ) as $chunk ) {
						$this->run( $this->prepare( 'UPDATE %i SET df = IF(df > %d, df - %d, 0) WHERE generation = %d AND lang = %s AND term_id IN (' . self::ints( $chunk ) . ')', Tables::stats(), $n, $n, $generation, (string) $lang ) );
						$this->run( $this->prepare( 'DELETE FROM %i WHERE df = 0 AND generation = %d AND lang = %s AND term_id IN (' . self::ints( $chunk ) . ')', Tables::stats(), $generation, (string) $lang ) );
					}
				}
			}
		}
	}

	/**
	 * Delete every count of a generation.
	 *
	 * @param int $generation Generation.
	 */
	public function deleteGeneration( int $generation ): void {
		$this->run( $this->prepare( 'DELETE FROM %i WHERE generation = %d', Tables::stats(), $generation ) );
	}

	/**
	 * How many documents of one language carry each of some terms, in a generation.
	 *
	 * @param string[] $terms      Folded terms.
	 * @param int      $generation Generation.
	 * @param string   $lang       Language code.
	 * @return array<string, int> Term to df; absent when none (a count at zero has no row).
	 */
	public function frequencies( array $terms, int $generation, string $lang ): array {
		if ( array() === $terms ) {
			return array();
		}
		$rows = $this->rows(
			$this->prepare(
				'SELECT t.term, s.df FROM %i t INNER JOIN %i s ON s.term_id = t.id AND s.generation = %d AND s.lang = %s WHERE t.term IN (' . self::strings( count( $terms ) ) . ')',
				Tables::terms(),
				Tables::stats(),
				...array_merge( array( $generation, $lang ), array_values( $terms ) )
			)
		);
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['term'] ] = (int) $row['df'];
		}
		return $out;
	}

	/**
	 * Refresh the table's index statistics after a reindex has rewritten it (D-089).
	 */
	public function analyze(): void {
		$this->run( $this->prepare( 'ANALYZE TABLE %i', Tables::stats() ) );
	}
}
