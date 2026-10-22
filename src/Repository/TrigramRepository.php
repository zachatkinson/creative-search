<?php
/**
 * Trigrams.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Repository;

use CreativeBranding\Search\Domain\Trigrams;
use CreativeBranding\Search\Support\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns `csearch_trigrams`: written once per term when the term is first seen (spec: Indexing → Trigrams).
 * SKU keys (terms opening with `=`, D-041) get none: a typo is matched against tokens, not whole SKUs.
 */
final class TrigramRepository extends Repository {

	/**
	 * Write the trigrams of some terms. Idempotent.
	 *
	 * @param array<string, int> $termIds Term to id.
	 */
	public function write( array $termIds ): void {
		$values = array();
		$args   = array( Tables::trigrams() );
		foreach ( $termIds as $term => $id ) {
			$term = (string) $term;
			if ( str_starts_with( $term, '=' ) ) {
				continue;
			}
			foreach ( Trigrams::of( $term ) as $trigram ) {
				$values[] = '(%s,%d)';
				$args[]   = $trigram;
				$args[]   = $id;
			}
		}
		if ( array() === $values ) {
			return;
		}
		$list = implode( ',', $values );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the list holds placeholders only.
		$this->run( $this->prepare( "INSERT IGNORE INTO %i (trigram, term_id) VALUES {$list}", ...$args ) );
	}

	/**
	 * The terms sharing at least so many trigrams, most shared first, bounded.
	 *
	 * @param string[] $trigrams      Trigrams of the token.
	 * @param int      $minimumShared Floor.
	 * @param int      $limit         At most.
	 * @return array<string, int> Term to shared count.
	 */
	public function termsSharing( array $trigrams, int $minimumShared, int $limit = 200 ): array {
		$trigrams = array_values( array_unique( $trigrams ) );
		if ( array() === $trigrams ) {
			return array();
		}
		$list = self::strings( count( $trigrams ) );
		$rows = $this->rows(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the list holds placeholders only.
			$this->prepare(
				"SELECT t.term, COUNT(*) AS shared FROM %i g INNER JOIN %i t ON t.id = g.term_id WHERE g.trigram IN ({$list}) AND t.df > 0 GROUP BY g.term_id, t.term HAVING shared >= %d ORDER BY shared DESC, t.term ASC LIMIT %d",
				Tables::trigrams(),
				Tables::terms(),
				...array_merge( $trigrams, array( $minimumShared, $limit ) )
			)
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['term'] ] = (int) $row['shared'];
		}
		return $out;
	}
}
