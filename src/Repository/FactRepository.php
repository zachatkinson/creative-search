<?php
/**
 * The products' SKUs and attribute values.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Repository;

use CreativeBranding\Search\Domain\Fact;
use CreativeBranding\Search\Support\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns `csearch_facts` (D-104): beside a product's document, its SKUs and attribute values and its variations',
 * each with the terms its text was cut into. Written with the document and deleted with it, so a generation's
 * facts come and go with its documents. Read only for the products a panel or a results page shows, by document.
 *
 * @phpstan-type FactTermRow array{variation: int, field: int, attribute: string, name: string, value: string, label: string, image_id: int|null, terms: string}
 */
final class FactRepository extends Repository {

	/**
	 * Rows per insert statement.
	 */
	public const CHUNK = 200;

	/**
	 * Write a document's facts.
	 *
	 * @param int                     $documentId Document id.
	 * @param array<int, FactTermRow> $facts      In order.
	 */
	public function write( int $documentId, array $facts ): void {
		$rows = array();
		foreach ( array_values( $facts ) as $position => $fact ) {
			$rows[] = array(
				$documentId,
				min( $position, 65535 ),
				$fact['variation'],
				$fact['field'],
				mb_substr( $fact['attribute'], 0, 191, 'UTF-8' ),
				mb_substr( $fact['name'], 0, 191, 'UTF-8' ),
				mb_substr( $fact['value'], 0, 191, 'UTF-8' ),
				mb_substr( $fact['label'], 0, 191, 'UTF-8' ),
				$fact['image_id'] ?? 0,
				$fact['terms'],
			);
		}
		foreach ( array_chunk( $rows, self::CHUNK ) as $chunk ) {
			$values = implode( ',', array_fill( 0, count( $chunk ), '(%d,%d,%d,%d,%s,%s,%s,%s,NULLIF(%d,0),%s)' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the list holds placeholders only.
			$this->run( $this->prepare( "INSERT INTO %i (document_id, position, variation_id, field, attribute, name, value, label, image_id, terms) VALUES {$values}", Tables::facts(), ...array_merge( ...$chunk ) ) );
		}
	}

	/**
	 * The facts of some documents, in order.
	 *
	 * @param int[] $documentIds Document ids.
	 * @return array<int, Fact[]> Document id to its facts.
	 */
	public function of( array $documentIds ): array {
		if ( array() === $documentIds ) {
			return array();
		}
		$out = array();
		foreach ( $this->rows( $this->prepare( 'SELECT * FROM %i WHERE document_id IN (' . self::ints( $documentIds ) . ') ORDER BY document_id, position', Tables::facts() ) ) as $row ) {
			$image                              = (int) $row['image_id'];
			$out[ (int) $row['document_id'] ][] = new Fact(
				(int) $row['variation_id'],
				(int) $row['field'],
				(string) $row['attribute'],
				(string) $row['name'],
				(string) $row['value'],
				(string) $row['label'],
				$image > 0 ? $image : null,
				array_values( array_filter( explode( ' ', (string) $row['terms'] ), static fn( string $t ): bool => '' !== $t ) )
			);
		}
		return $out;
	}

	/**
	 * Delete the facts of some documents.
	 *
	 * @param int[] $documentIds Document ids.
	 */
	public function deleteFor( array $documentIds ): void {
		if ( array() === $documentIds ) {
			return;
		}
		$this->run( $this->prepare( 'DELETE FROM %i WHERE document_id IN (' . self::ints( $documentIds ) . ')', Tables::facts() ) );
	}
}
