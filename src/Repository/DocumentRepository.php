<?php
/**
 * Documents.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Repository;

use CreativeBranding\Search\Domain\Document;
use CreativeBranding\Search\Support\Clock;
use CreativeBranding\Search\Support\PluginException;
use CreativeBranding\Search\Support\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns `csearch_documents`: one row per thing the index can return, with what a suggestion needs.
 */
final class DocumentRepository extends Repository {

	/**
	 * Insert a document row.
	 *
	 * @param array{generation: int, type: string, object_id: int, lang: string, title: string, name_key: string, url: string, image_id: ?int, price_min: ?float, price_max: ?float, in_stock: bool, visible: bool, boost: int, total_sales: int} $row The row.
	 * @return int The new id.
	 * @throws PluginException When the row was not written (another process wrote the same document first).
	 */
	public function insert( array $row ): int {
		$written = $this->run(
			$this->prepare(
				'INSERT INTO %i (generation, type, object_id, lang, title, name_key, url, image_id, price_min, price_max, in_stock, visible, boost, total_sales, indexed_at) VALUES (%d, %s, %d, %s, %s, %s, %s, ' . ( null === $row['image_id'] ? 'NULL' : '%d' ) . ', ' . ( null === $row['price_min'] ? 'NULL' : '%f' ) . ', ' . ( null === $row['price_max'] ? 'NULL' : '%f' ) . ', %d, %d, %d, %d, %s)',
				Tables::documents(),
				...array_merge(
					array( $row['generation'], $row['type'], $row['object_id'], $row['lang'], $row['title'], $row['name_key'], $row['url'] ),
					null === $row['image_id'] ? array() : array( $row['image_id'] ),
					null === $row['price_min'] ? array() : array( $row['price_min'] ),
					null === $row['price_max'] ? array() : array( $row['price_max'] ),
					array( (int) $row['in_stock'], (int) $row['visible'], $row['boost'], $row['total_sales'], Clock::now() )
				)
			)
		);
		if ( 1 !== $written ) {
			throw new PluginException( sprintf( 'Document %s %d (%s, generation %d) was not written.', esc_html( $row['type'] ), (int) $row['object_id'], esc_html( $row['lang'] ), (int) $row['generation'] ) );
		}
		return $this->insertId();
	}

	/**
	 * The ids of an object's documents in a generation, every language.
	 *
	 * @param string $type       Document type.
	 * @param int    $objectId   Object id.
	 * @param int    $generation Generation.
	 * @return int[]
	 */
	public function idsOf( string $type, int $objectId, int $generation ): array {
		$rows = $this->rows( $this->prepare( 'SELECT id FROM %i WHERE generation = %d AND type = %s AND object_id = %d', Tables::documents(), $generation, $type, $objectId ) );
		return array_map( static fn( array $row ): int => (int) $row['id'], $rows );
	}

	/**
	 * Some document ids of one type, in every generation (a type switched off loses its documents in batches).
	 *
	 * @param string $type  Document type.
	 * @param int    $limit At most.
	 * @return int[]
	 */
	public function idsOfType( string $type, int $limit ): array {
		$rows = $this->rows( $this->prepare( 'SELECT id FROM %i WHERE type = %s ORDER BY id LIMIT %d', Tables::documents(), $type, $limit ) );
		return array_map( static fn( array $row ): int => (int) $row['id'], $rows );
	}

	/**
	 * Whether any of some products, in one generation and language, has a name whose key is exactly this one
	 * (by the unique key on generation, type, object id and language).
	 *
	 * @param int    $generation Generation.
	 * @param string $lang       Language code.
	 * @param int[]  $productIds Product ids.
	 * @param string $key        A folded name key.
	 */
	public function anyNamed( int $generation, string $lang, array $productIds, string $key ): bool {
		if ( array() === $productIds || '' === $key ) {
			return false;
		}
		return null !== $this->value( $this->prepare( 'SELECT 1 FROM %i WHERE generation = %d AND type = %s AND lang = %s AND object_id IN (' . self::ints( $productIds ) . ') AND name_key = %s LIMIT 1', Tables::documents(), $generation, Document::PRODUCT, $lang, $key ) );
	}

	/**
	 * The document ids of some products in one generation and language, by product id (by the unique key on
	 * generation, type, object id and language).
	 *
	 * @param int    $generation Generation.
	 * @param string $lang       Language code.
	 * @param int[]  $productIds Product ids.
	 * @return array<int, int> Product id to document id.
	 */
	public function productDocumentIds( int $generation, string $lang, array $productIds ): array {
		if ( array() === $productIds ) {
			return array();
		}
		$out = array();
		foreach ( $this->rows( $this->prepare( 'SELECT id, object_id FROM %i WHERE generation = %d AND type = %s AND lang = %s AND object_id IN (' . self::ints( $productIds ) . ')', Tables::documents(), $generation, Document::PRODUCT, $lang ) ) as $row ) {
			$out[ (int) $row['object_id'] ] = (int) $row['id'];
		}
		return $out;
	}

	/**
	 * Delete some documents.
	 *
	 * @param int[] $ids Document ids.
	 */
	public function delete( array $ids ): void {
		if ( array() === $ids ) {
			return;
		}
		$this->run( $this->prepare( 'DELETE FROM %i WHERE id IN (' . self::ints( $ids ) . ')', Tables::documents() ) );
	}

	/**
	 * Flip the stock flag of a product's documents without touching anything else.
	 *
	 * @param int  $productId  Product id.
	 * @param int  $generation Generation.
	 * @param bool $inStock    In stock.
	 * @return int Rows changed.
	 */
	public function setStock( int $productId, int $generation, bool $inStock ): int {
		return $this->run( $this->prepare( 'UPDATE %i SET in_stock = %d WHERE generation = %d AND type = %s AND object_id = %d', Tables::documents(), (int) $inStock, $generation, Document::PRODUCT, $productId ) );
	}

	/**
	 * How many documents of one language a generation holds, the catalogue's or the posts and pages': N in the
	 * score, over the same set as the term counts (D-093).
	 *
	 * @param int    $generation Generation.
	 * @param string $lang       Language code.
	 * @param bool   $content    The posts and pages, or the products, categories, tags and brands.
	 */
	public function countOf( int $generation, string $lang, bool $content ): int {
		return (int) $this->value(
			$this->prepare(
				'SELECT COUNT(*) FROM %i WHERE generation = %d AND lang = %s AND type ' . ( $content ? 'IN' : 'NOT IN' ) . ' (%s, %s)',
				Tables::documents(),
				$generation,
				$lang,
				Document::POST,
				Document::PAGE
			)
		);
	}

	/**
	 * How many documents a generation holds, in one language or all.
	 *
	 * @param int         $generation Generation.
	 * @param string|null $lang       Language code, or null for every language.
	 * @param string|null $type       Document type, or null for every type.
	 */
	public function count( int $generation, ?string $lang = null, ?string $type = null ): int {
		$sql  = 'SELECT COUNT(*) FROM %i WHERE generation = %d';
		$args = array( Tables::documents(), $generation );
		if ( null !== $lang ) {
			$sql   .= ' AND lang = %s';
			$args[] = $lang;
		}
		if ( null !== $type ) {
			$sql   .= ' AND type = %s';
			$args[] = $type;
		}
		return (int) $this->value( $this->prepare( $sql, ...$args ) );
	}

	/**
	 * A batch of document ids in a generation, lowest first, for the cleanup after a switch.
	 *
	 * @param int $generation Generation.
	 * @param int $limit      At most.
	 * @return int[]
	 */
	public function idsInGeneration( int $generation, int $limit ): array {
		$rows = $this->rows( $this->prepare( 'SELECT id FROM %i WHERE generation = %d ORDER BY id LIMIT %d', Tables::documents(), $generation, $limit ) );
		return array_map( static fn( array $row ): int => (int) $row['id'], $rows );
	}

	/**
	 * The rows of some documents, as the suggestion renderer and the searcher read them.
	 *
	 * @param int[] $ids Document ids.
	 * @return array<int, array<string, string|null>> By id.
	 */
	public function rowsOf( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}
		$out = array();
		foreach ( $this->rows( $this->prepare( 'SELECT * FROM %i WHERE id IN (' . self::ints( $ids ) . ')', Tables::documents() ) ) as $row ) {
			$out[ (int) $row['id'] ] = $row;
		}
		return $out;
	}

	/**
	 * The product ids a generation holds, for the status screen's "missing from the index" count.
	 *
	 * @param int $generation Generation.
	 */
	public function productCount( int $generation ): int {
		return (int) $this->value( $this->prepare( 'SELECT COUNT(DISTINCT object_id) FROM %i WHERE generation = %d AND type = %s', Tables::documents(), $generation, Document::PRODUCT ) );
	}

	/**
	 * When a product was last written to a generation, or null when it is not in it.
	 *
	 * @param int $productId  Product id.
	 * @param int $generation Generation.
	 */
	public function indexedAt( int $productId, int $generation ): ?string {
		$at = $this->value( $this->prepare( 'SELECT MAX(indexed_at) FROM %i WHERE generation = %d AND type = %s AND object_id = %d', Tables::documents(), $generation, Document::PRODUCT, $productId ) );
		return is_string( $at ) && '' !== $at ? $at : null;
	}

	/**
	 * The boost-rule references of some products: `category:12`, `tag:7`, `brand:3` for each term they carry in
	 * an indexed taxonomy (spec: Matching → owner's boost rules). Read only when the owner has rules.
	 *
	 * @param int[]                 $productIds Product ids.
	 * @param array<string, string> $taxonomies Taxonomy to document type.
	 * @return array<int, string[]> Product id to references.
	 */
	public function termRefs( array $productIds, array $taxonomies ): array {
		global $wpdb;
		if ( array() === $productIds || array() === $taxonomies ) {
			return array();
		}
		$list = self::strings( count( $taxonomies ) );
		$rows = $this->rows(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the id list is integers, the taxonomy list placeholders.
			$this->prepare(
				'SELECT r.object_id, tt.term_id, tt.taxonomy FROM %i r INNER JOIN %i tt ON tt.term_taxonomy_id = r.term_taxonomy_id WHERE r.object_id IN (' . self::ints( $productIds ) . ") AND tt.taxonomy IN ({$list})",
				$wpdb->term_relationships,
				$wpdb->term_taxonomy,
				...array_keys( $taxonomies )
			)
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['object_id'] ][] = $taxonomies[ (string) $row['taxonomy'] ] . ':' . (int) $row['term_id'];
		}
		return $out;
	}

	/**
	 * Refresh the table's index statistics after a reindex has rewritten it (D-089).
	 */
	public function analyze(): void {
		$this->run( $this->prepare( 'ANALYZE TABLE %i', Tables::documents() ) );
	}
}
