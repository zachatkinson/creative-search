<?php
/**
 * The inverted index.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Repository;

use CreativeBranding\Search\Domain\Tokenizer;
use CreativeBranding\Search\Support\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns `csearch_postings`: which terms are in which document's fields, how often and where first. Each row
 * carries its document's language and kind (D-087), so a lookup by term reads the primary key alone.
 */
final class PostingRepository extends Repository {

	/**
	 * Rows per INSERT statement.
	 */
	public const CHUNK = 500;

	/**
	 * Write a document's postings.
	 *
	 * @param int                                              $documentId Document id.
	 * @param int                                              $generation Generation.
	 * @param string                                           $lang       The document's language.
	 * @param bool                                             $product    Whether the document is a product.
	 * @param array<string, array<int, array{0: int, 1: int}>> $postings   Term to field to [tf, first position].
	 * @param array<string, int>                               $termIds    Term to id.
	 */
	public function write( int $documentId, int $generation, string $lang, bool $product, array $postings, array $termIds ): void {
		$rows = array();
		foreach ( $postings as $term => $byField ) {
			$termId = $termIds[ (string) $term ] ?? null;
			if ( null === $termId ) {
				continue;
			}
			foreach ( $byField as $field => [ $tf, $position ] ) {
				$rows[] = array( $termId, $documentId, $field, $generation, $lang, $product ? 1 : 0, min( $tf, 65535 ), min( $position, 65535 ) );
			}
		}
		foreach ( array_chunk( $rows, self::CHUNK ) as $chunk ) {
			$values = implode( ',', array_fill( 0, count( $chunk ), '(%d,%d,%d,%d,%s,%d,%d,%d)' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the list holds placeholders only.
			$this->run( $this->prepare( "INSERT INTO %i (term_id, document_id, field, generation, lang, product, tf, first_pos) VALUES {$values}", Tables::postings(), ...array_merge( ...$chunk ) ) );
		}
	}

	/**
	 * The terms of some documents, each with how many of those documents carry it, per generation and language.
	 *
	 * @param int[] $documentIds Document ids.
	 * @return array<int, array{term_id: int, generation: int, lang: string, n: int}>
	 */
	public function termCountsOf( array $documentIds ): array {
		if ( array() === $documentIds ) {
			return array();
		}
		$rows = $this->rows( $this->prepare( 'SELECT term_id, generation, lang, COUNT(DISTINCT document_id) AS n FROM %i WHERE document_id IN (' . self::ints( $documentIds ) . ') GROUP BY term_id, generation, lang', Tables::postings() ) );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'term_id'    => (int) $row['term_id'],
				'generation' => (int) $row['generation'],
				'lang'       => (string) $row['lang'],
				'n'          => (int) $row['n'],
			);
		}
		return $out;
	}

	/**
	 * Delete the postings of some documents.
	 *
	 * @param int[] $documentIds Document ids.
	 */
	public function deleteFor( array $documentIds ): void {
		if ( array() === $documentIds ) {
			return;
		}
		$this->run( $this->prepare( 'DELETE FROM %i WHERE document_id IN (' . self::ints( $documentIds ) . ')', Tables::postings() ) );
	}

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
		$rows = $this->rows(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the list holds placeholders only.
			$this->prepare(
				"SELECT t.term, p.document_id, p.field, p.tf, p.first_pos FROM %i t INNER JOIN %i p ON p.term_id = t.id AND p.generation = %d AND p.lang = %s AND p.product = %d WHERE t.term IN ({$list}) ORDER BY p.document_id, p.field",
				Tables::terms(),
				Tables::postings(),
				...array_merge( array( $generation, $lang, $products ? 1 : 0 ), array_values( $terms ) )
			)
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['term'] ][ (int) $row['document_id'] ][ (int) $row['field'] ] = array( (int) $row['tf'], (int) $row['first_pos'] );
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
				'SELECT COUNT(DISTINCT p.document_id) FROM %i t INNER JOIN %i p ON p.term_id = t.id AND p.generation = %d AND p.lang = %s AND p.product = 1 WHERE t.term IN (' . self::strings( count( $terms ) ) . ')',
				Tables::terms(),
				Tables::postings(),
				...array_merge( array( $generation, $lang ), array_values( $terms ) )
			)
		);
	}

	/**
	 * The SKU keys of some documents: the SKU-field postings whose term is a whole key, stored with the `=`
	 * prefix (D-041) and returned without it.
	 *
	 * @param int[]  $documentIds Document ids.
	 * @param int    $field       The SKU field id.
	 * @param string $key         One key to look for (one term, by its unique index), or empty for every key.
	 * @return array<int, string[]> Document id to keys.
	 */
	public function skuKeysOf( array $documentIds, int $field, string $key = '' ): array {
		if ( array() === $documentIds ) {
			return array();
		}
		$rows = $this->rows(
			$this->prepare(
				'SELECT p.document_id, t.term FROM %i p INNER JOIN %i t ON t.id = p.term_id WHERE p.document_id IN (' . self::ints( $documentIds ) . ') AND p.field = %d AND ' . ( '' === $key ? 't.term LIKE %s' : 't.term = %s' ),
				Tables::postings(),
				Tables::terms(),
				$field,
				'' === $key ? '=%' : '=' . mb_substr( $key, 0, Tokenizer::MAX_LENGTH - 1, 'UTF-8' )
			)
		);
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['document_id'] ][] = substr( (string) $row['term'], 1 );
		}
		return $out;
	}

	/**
	 * How many postings rows a generation holds.
	 *
	 * @param int $generation Generation.
	 */
	public function count( int $generation ): int {
		return (int) $this->value( $this->prepare( 'SELECT COUNT(*) FROM %i WHERE generation = %d', Tables::postings(), $generation ) );
	}

	/**
	 * Refresh the table's index statistics after a reindex has rewritten it (D-089).
	 */
	public function analyze(): void {
		$this->run( $this->prepare( 'ANALYZE TABLE %i', Tables::postings() ) );
	}
}
