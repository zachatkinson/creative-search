<?php
/**
 * The postings reads, against a recount.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Domain\Fields;
use CreativeBranding\Search\Domain\Kind;
use CreativeBranding\Search\Index\Indexer;
use CreativeBranding\Search\Index\IndexState;
use CreativeBranding\Search\Repository\PostingRepository;
use CreativeBranding\Search\Support\Tables;
use CreativeBranding\Search\Tests\Support\IndexTestCase;

/**
 * What `PostingRepository` answers the search with (the category, tag and brand postings, the retry's product
 * count and the SKU keys) equals what a plain query over the table gives. The ranking statement is held to the
 * Domain by `RankParityTest`; these reads feed both sides of that test alike, so they are checked here (D-048).
 *
 * @group sql-mutation
 */
final class PostingRepositoryTest extends IndexTestCase {

	public function test_the_reads_match_a_recount(): void {
		global $wpdb;
		foreach ( $this->products( array( 0, 1, 2, 3, 4, 5 ) ) as $id ) {
			$this->service( Indexer::class )->indexProduct( $id );
		}
		foreach ( get_terms( array( 'taxonomy' => array( 'product_cat', 'product_tag', 'product_brand' ), 'hide_empty' => false ) ) as $term ) { // phpcs:ignore
			$this->service( Indexer::class )->indexTerm( $term->taxonomy, $term->term_id );
		}
		$postings   = $this->service( PostingRepository::class );
		$generation = $this->service( IndexState::class )->live();
		$terms      = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT t.term FROM %i p INNER JOIN %i t ON t.id = p.term_id ORDER BY t.term', Tables::postings(), Tables::terms() ) ); // phpcs:ignore
		self::assertGreaterThan( 20, count( $terms ) );
		$asked = array_merge( $terms, array( 'zzznotaterm' ) );

		foreach ( array( true, false ) as $products ) {
			$want = array();
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT t.term, p.document_id, p.field, p.tf, p.first_pos FROM %i p INNER JOIN %i t ON t.id = p.term_id INNER JOIN %i d ON d.id = p.document_id WHERE p.generation = %d AND d.lang = %s AND (d.type = %s) = %d', Tables::postings(), Tables::terms(), Tables::documents(), $generation, 'en', 'product', $products ? 1 : 0 ), ARRAY_A ) as $row ) { // phpcs:ignore
				$want[ (string) $row['term'] ][ (int) $row['document_id'] ][ (int) $row['field'] ] = array( (int) $row['tf'], (int) $row['first_pos'] );
			}
			self::assertNotSame( array(), $want );
			$got = $postings->forTerms( $asked, $generation, 'en', $products ? Kind::PRODUCT : Kind::TERM );
			self::assertSame( self::sorted( $want ), self::sorted( $got ), $products ? 'product postings' : 'category, tag and brand postings' );
			self::assertSame( array(), $postings->forTerms( $asked, $generation, 'fr', $products ? Kind::PRODUCT : Kind::TERM ), 'nothing in a language not indexed' );
		}

		foreach ( array( array( 'deck' ), array( 'deck', 'wheels', 'tee' ), array( 'zzznotaterm' ) ) as $some ) {
			$want = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT p.document_id) FROM %i p INNER JOIN %i t ON t.id = p.term_id INNER JOIN %i d ON d.id = p.document_id WHERE p.generation = %d AND d.type = %s AND t.term IN (' . implode( ',', array_fill( 0, count( $some ), '%s' ) ) . ')', Tables::postings(), Tables::terms(), Tables::documents(), $generation, 'product', ...$some ) ); // phpcs:ignore
			self::assertSame( $want, $postings->productCount( $some, $generation, 'en' ), implode( ' ', $some ) );
		}
		self::assertGreaterThan( 0, $postings->productCount( array( 'deck' ), $generation, 'en' ) );
		self::assertSame( 0, $postings->productCount( array( 'deck' ), $generation, 'fr' ) );

		$keys = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT p.document_id, t.term FROM %i p INNER JOIN %i t ON t.id = p.term_id WHERE p.field = %d AND t.term LIKE %s', Tables::postings(), Tables::terms(), Fields::SKU, '=%' ), ARRAY_A ) as $row ) { // phpcs:ignore
			$keys[ (int) $row['document_id'] ][] = substr( (string) $row['term'], 1 );
		}
		self::assertNotSame( array(), $keys );
		$ids = array_merge( array_keys( $keys ), array( 999999 ) );
		self::assertSame( self::sorted( $keys ), self::sorted( $postings->skuKeysOf( $ids, Fields::SKU ) ), 'every SKU key' );
		$doc = array_key_first( $keys );
		$key = $keys[ $doc ][0];
		self::assertSame( array( $doc => array( $key ) ), $postings->skuKeysOf( $ids, Fields::SKU, $key ), 'one key, by itself' );
		self::assertSame( array(), $postings->skuKeysOf( $ids, Fields::SKU, 'zzz-no-such-sku' ) );
		self::assertSame( array(), $postings->skuKeysOf( array(), Fields::SKU ) );
		self::assertSame( array(), $postings->skuKeysOf( $ids, Fields::NAME ), 'keys are only in the SKU field' );
	}

	/**
	 * An array sorted by key at every level, for comparing what two reads returned in different orders.
	 *
	 * @param array<mixed> $value Value.
	 * @return array<mixed>
	 */
	private static function sorted( array $value ): array {
		ksort( $value );
		foreach ( $value as &$inner ) {
			if ( is_array( $inner ) ) {
				$inner = self::sorted( $inner );
				if ( array_is_list( $inner ) && array() === array_filter( $inner, static fn( $v ): bool => ! is_string( $v ) ) ) {
					sort( $inner ); // SKU keys come in any order; a [tf, first position] pair keeps its own.
				}
			}
		}
		return $value;
	}
}
