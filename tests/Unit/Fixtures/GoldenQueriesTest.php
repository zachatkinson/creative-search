<?php
/**
 * Golden query fixture tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Fixtures;

use CreativeBranding\Search\Tests\Fixtures\Catalogue;
use CreativeBranding\Search\Tests\Fixtures\GoldenQueries;
use PHPUnit\Framework\TestCase;

/**
 * The file is well-formed, has 50 queries, covers every rule, and names only things the catalogue has.
 */
final class GoldenQueriesTest extends TestCase {

	public function test_fifty_queries_with_distinct_text(): void {
		$queries = GoldenQueries::queries();
		self::assertCount( 50, $queries );
		$texts = array_map( static fn( array $q ): string => $q['lang'] . ':' . $q['q'], $queries );
		self::assertSame( count( $texts ), count( array_unique( $texts ) ), 'no query is listed twice in one language' );
	}

	public function test_every_query_is_well_formed(): void {
		foreach ( GoldenQueries::queries() as $q ) {
			self::assertNotSame( '', trim( $q['q'] ) );
			self::assertLessThanOrEqual( 200, strlen( $q['q'] ), 'queries are capped at 200 characters' );
			self::assertContains( $q['lang'], array( 'en', 'fr' ) );
			self::assertContains( $q['kind'], GoldenQueries::KINDS, $q['q'] );
			self::assertNotSame( '', $q['why'], "{$q['q']} needs a reason" );
			$e = $q['expect'];
			if ( $e['zero'] ) {
				self::assertNull( $e['top'], "{$q['q']}: a zero result has no top" );
				self::assertSame( array(), $e['products'] );
			} else {
				self::assertTrue( null !== $e['top'] || array() !== $e['products'], "{$q['q']} expects something" );
			}
			if ( null !== $e['top'] && ! str_starts_with( $e['top'], 'term:' ) ) {
				self::assertContains( $e['top'], $e['products'], "{$q['q']}: the top result is among the expected products" );
			}
			self::assertSame( array(), array_intersect( $e['products'], $e['absent'] ), "{$q['q']}: a SKU cannot be both expected and absent" );
		}
	}

	public function test_every_kind_the_spec_names_has_a_query(): void {
		$kinds = array_unique( array_column( GoldenQueries::queries(), 'kind' ) );
		foreach ( GoldenQueries::KINDS as $kind ) {
			self::assertContains( $kind, $kinds, "no query of kind {$kind}" );
		}
	}

	public function test_every_sku_and_term_exists_in_the_catalogue_at_both_sizes(): void {
		$c     = new Catalogue();
		$small = $c->generate( 300 );
		$large = $c->generate( 10000 );
		foreach ( array( 300 => $small, 10000 => $large ) as $size => $products ) {
			$skus  = array_fill_keys( array_column( $products, 'sku' ), true );
			$terms = array();
			foreach ( Catalogue::CATEGORIES as $category ) {
				$terms[ $category[0] ] = true;
				$terms[ $category[1] ] = true;
			}
			foreach ( array_keys( Catalogue::BRANDS ) as $brand ) {
				$terms[ $brand ] = true;
			}
			foreach ( $products as $product ) {
				foreach ( $product['tags'] as $tag ) {
					$terms[ $tag ] = true;
				}
			}
			foreach ( GoldenQueries::queries() as $q ) {
				$e = $q['expect'];
				foreach ( array_merge( $e['products'], $e['absent'] ) as $sku ) {
					self::assertArrayHasKey( $sku, $skus, "{$q['q']}: {$sku} is not in the {$size}-product catalogue" );
				}
				if ( null !== $e['top'] && str_starts_with( $e['top'], 'term:' ) ) {
					self::assertArrayHasKey( substr( $e['top'], 5 ), $terms, "{$q['q']}: unknown term" );
				}
				foreach ( $e['terms'] as $term ) {
					self::assertArrayHasKey( $term, $terms, "{$q['q']}: {$term} is not a category, brand or tag" );
				}
			}
		}
	}

	public function test_hero_anchored_expectations_hold_at_both_sizes(): void {
		// The heroes are the first products, so an expectation written against the 300-product catalogue is the
		// same product in the 10,000-product one. Spot check the anchors the most queries lean on.
		$large = ( new Catalogue() )->generate( 10000 );
		self::assertSame( 'KAZUMI Skate Deck, Dealer', $large[0]['name'] );
		self::assertSame( 'KAZ-DEA-00001', $large[0]['sku'] );
		self::assertSame( 'Orbit Wheels, Classic', $large[23]['name'] );
	}

	public function test_synonyms_are_the_seed_the_owner_would_enter(): void {
		$synonyms = GoldenQueries::load()['synonyms'];
		self::assertNotEmpty( $synonyms );
		foreach ( $synonyms as $row ) {
			self::assertContains( $row['lang'], array( 'en', 'fr' ) );
			self::assertNotSame( '', $row['word'] );
			self::assertNotSame( '', $row['means'] );
			self::assertNotSame( $row['word'], $row['means'] );
			self::assertIsBool( $row['both_ways'] );
		}
		self::assertSame( 'deck', $synonyms[0]['means'], 'the spec\'s own example: board finds decks' );
	}
}
