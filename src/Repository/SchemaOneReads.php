<?php
/**
 * The search's reads while the schema upgrade waits.
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
 * The postings reads as schema 1 made them (D-090): the language and the kind from the document, the df
 * counted from the postings. Between an update and the end of its schema upgrade the postings may have no
 * language column yet, or one not filled in, and there may be no term counts; these reads need neither, so a
 * shopper's search keeps answering. `IndexRepository` uses them only while `Upgrader::needed()`.
 */
final class SchemaOneReads extends Repository {

	/**
	 * The postings of some terms in one generation and language, for product documents or for the others.
	 *
	 * @param string[] $terms      Terms.
	 * @param int      $generation Generation.
	 * @param string   $lang       Language code.
	 * @param bool     $products   Product documents, or category, tag and brand documents.
	 * @return array<string, array<int, array<int, array{0: int, 1: int}>>> Term to document id to field to [tf, first position].
	 */
	public function forTerms( array $terms, int $generation, string $lang, bool $products ): array {
		if ( array() === $terms ) {
			return array();
		}
		$list = self::strings( count( $terms ) );
		$type = $products ? '=' : '<>';
		$rows = $this->rows(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the list holds placeholders only; the operator is one of two literals.
			$this->prepare(
				"SELECT t.term, p.document_id, p.field, p.tf, p.first_pos FROM %i p INNER JOIN %i t ON t.id = p.term_id INNER JOIN %i d ON d.id = p.document_id WHERE t.term IN ({$list}) AND p.generation = %d AND d.lang = %s AND d.type {$type} %s",
				Tables::postings(),
				Tables::terms(),
				Tables::documents(),
				...array_merge( array_values( $terms ), array( $generation, $lang, 'product' ) )
			)
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['term'] ][ (int) $row['document_id'] ][ (int) $row['field'] ] = array( (int) $row['tf'], (int) $row['first_pos'] );
		}
		return $out;
	}

	/**
	 * How many documents of one language carry each of some terms, in a generation.
	 *
	 * @param string[] $terms      Folded terms.
	 * @param int      $generation Generation.
	 * @param string   $lang       Language code.
	 * @return array<string, int> Term to df; absent when none.
	 */
	public function frequencies( array $terms, int $generation, string $lang ): array {
		if ( array() === $terms ) {
			return array();
		}
		$rows = $this->rows(
			$this->prepare(
				'SELECT t.term, COUNT(DISTINCT p.document_id) AS df FROM %i p INNER JOIN %i t ON t.id = p.term_id INNER JOIN %i d ON d.id = p.document_id WHERE t.term IN (' . self::strings( count( $terms ) ) . ') AND p.generation = %d AND d.lang = %s GROUP BY t.term',
				Tables::postings(),
				Tables::terms(),
				Tables::documents(),
				...array_merge( array_values( $terms ), array( $generation, $lang ) )
			)
		);
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['term'] ] = (int) $row['df'];
		}
		return $out;
	}

	/**
	 * How many products of one language carry any of some terms, in a generation.
	 *
	 * @param string[] $terms      Folded terms.
	 * @param int      $generation Generation.
	 * @param string   $lang       Language code.
	 */
	public function productCount( array $terms, int $generation, string $lang ): int {
		if ( array() === $terms ) {
			return 0;
		}
		return (int) $this->value(
			$this->prepare(
				'SELECT COUNT(DISTINCT p.document_id) FROM %i p INNER JOIN %i t ON t.id = p.term_id INNER JOIN %i d ON d.id = p.document_id WHERE t.term IN (' . self::strings( count( $terms ) ) . ') AND p.generation = %d AND d.lang = %s AND d.type = %s',
				Tables::postings(),
				Tables::terms(),
				Tables::documents(),
				...array_merge( array_values( $terms ), array( $generation, $lang, 'product' ) )
			)
		);
	}
}
