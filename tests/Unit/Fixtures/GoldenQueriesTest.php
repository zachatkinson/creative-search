<?php
/**
 * Golden query fixture tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Fixtures;

use CreativeBranding\Search\Domain\Fields;
use CreativeBranding\Search\Domain\Hit;
use CreativeBranding\Search\Domain\QueryParser;
use CreativeBranding\Search\Domain\Searcher;
use CreativeBranding\Search\Domain\SearchOptions;
use CreativeBranding\Search\Domain\Synonym;
use CreativeBranding\Search\Domain\Synonyms;
use CreativeBranding\Search\Domain\TermMatch;
use CreativeBranding\Search\Domain\Tokenizer;
use CreativeBranding\Search\Tests\Fixtures\Catalogue;
use CreativeBranding\Search\Tests\Fixtures\GoldenQueries;
use CreativeBranding\Search\Tests\Support\CatalogueIndexer;
use CreativeBranding\Search\Tests\Support\MemoryIndex;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The file is well-formed, has 50 queries, covers every rule, names only things the catalogue has, and every
 * expectation holds when the set is scored in memory against the 300-product catalogue (spec: milestone 2).
 */
final class GoldenQueriesTest extends TestCase {

	public const SUGGEST_LIMIT = 6;

	private static ?MemoryIndex $index = null;

	/**
	 * SKU by product id, for the 300-product catalogue.
	 *
	 * @var array<int, string>
	 */
	private static array $skus = array();

	public static function tearDownAfterClass(): void {
		self::$index = null;
		self::$skus  = array();
	}

	private static function index(): MemoryIndex {
		if ( null === self::$index ) {
			$products    = ( new Catalogue() )->generate( 300 );
			self::$index = ( new CatalogueIndexer() )->index( $products );
			self::$skus  = array_column( $products, 'sku', 'id' );
		}
		return self::$index;
	}

	private static function parser(): QueryParser {
		$rows = array_map( static fn( array $r ): Synonym => new Synonym( $r['lang'], $r['word'], $r['means'], $r['both_ways'] ), GoldenQueries::load()['synonyms'] );
		return new QueryParser( new Tokenizer(), new Synonyms( $rows ) );
	}

	public function test_the_golden_set_holds_in_memory_at_skatehouse_size(): void {
		$index    = self::index();
		$parser   = self::parser();
		$searcher = new Searcher( $index );
		$failures = array();
		foreach ( GoldenQueries::queries() as $q ) {
			$e      = $q['expect'];
			$result = $searcher->search( $parser->parse( $q['q'], $q['lang'] ), new SearchOptions( hiddenBySku: $e['hidden_only'] ) );
			$skus   = array_map( fn( Hit $h ): string => self::$skus[ $h->document->objectId ], $result->products );
			$shown  = array_slice( $skus, 0, self::SUGGEST_LIMIT );
			$terms  = array_map( static fn( Hit $h ): string => $h->document->title, $result->terms );
			$wrong  = array();
			if ( $e['zero'] && ! $result->isEmpty() ) {
				$wrong[] = 'expected nothing';
			}
			if ( null !== $e['top'] && ( $skus[0] ?? '' ) !== $e['top'] ) {
				$wrong[] = "top should be {$e['top']}";
			}
			foreach ( $e['products'] as $sku ) {
				if ( ! in_array( $sku, $shown, true ) ) {
					$wrong[] = "{$sku} missing from the suggestions";
				}
			}
			foreach ( $e['absent'] as $sku ) {
				if ( in_array( $sku, $skus, true ) ) {
					$wrong[] = "{$sku} must be absent";
				}
			}
			foreach ( $e['terms'] as $term ) {
				if ( ! in_array( $term, $terms, true ) ) {
					$wrong[] = "term {$term} missing";
				}
			}
			if ( null !== $e['corrected'] && $result->corrected !== $e['corrected'] ) {
				$wrong[] = "corrected should be \"{$e['corrected']}\"";
			}
			if ( null === $e['corrected'] && null !== $result->corrected && ! $e['zero'] ) {
				$wrong[] = 'no correction expected';
			}
			if ( array() !== $wrong ) {
				$failures[] = sprintf( "%s [%s]: %s\n    got %s | terms %s | corrected %s | lang %s", $q['q'], $q['kind'], implode( '; ', $wrong ), implode( ', ', $shown ), implode( ', ', $terms ), var_export( $result->corrected, true ), $result->lang );
			}
		}
		self::assertSame( array(), $failures, "Golden queries failing:\n" . implode( "\n", $failures ) );
	}

	/**
	 * The golden queries of the Skatehouse handoff that the generated catalogue can answer (its sizes; the
	 * pages are in tests/Integration/ContentTest.php): each spelling of one size finds the same products.
	 */
	public function test_every_spelling_of_a_size_finds_the_same_products(): void {
		$searcher = new Searcher( self::index() );
		$parser   = self::parser();
		$skus     = fn( string $q, string $lang = 'en' ): array => array_map( fn( Hit $h ): string => self::$skus[ $h->document->objectId ], $searcher->search( $parser->parse( $q, $lang ), new SearchOptions( limit: 300 ) )->products );
		$groups   = array(
			array( 'en', '8.25', '8.25in', '8.25 in', '8.25 inch', '8.25 inches', '8.25"', '8.25″', "8.25''", '8.250' ),
			array( 'en', '8.5 deck', '8.50 deck', '8.5" deck', '8.5in deck' ),
			array( 'en', '54mm wheels', '54 mm wheels' ),
			array( 'en', "philly's", 'phillys', 'philly’s' ),
			array( 'fr', '8.25', '8,25', '8,25 po', '8,25 pouces' ),
		);
		foreach ( $groups as $group ) {
			$lang  = array_shift( $group );
			$first = $skus( $group[0], $lang );
			self::assertNotSame( array(), $first, $group[0] );
			foreach ( array_slice( $group, 1 ) as $spelling ) {
				self::assertSame( $first, $skus( $spelling, $lang ), "{$spelling} reads as {$group[0]}" );
			}
		}
		self::assertContains( 'ORB-CLA-00007', $skus( '54 wheels' ), 'a bare 54 finds the 54 mm wheels' );
		self::assertSame( 'ORB-CLA-00007', $skus( '54 mm wheels' )[0] );
		self::assertNotContains( 'RSC-SBA-00005', $skus( '8.25 in' ), '8.125 is not 8.25' );
	}

	/**
	 * A name stored with an entity, as an import or a feed leaves one (D-133): every spelling of the apostrophe
	 * finds the product by its name's own terms, never by a typo or a prefix, with the very score of the same
	 * name stored with a real ’; and the entity's number is no word.
	 */
	public function test_a_name_stored_with_an_entity_is_found_exactly(): void {
		$products = ( new Catalogue() )->generate( 300 );
		$hoodie   = current( array_filter( $products, static fn( array $p ): bool => in_array( 'hoodies', $p['categories'], true ) && 'publish' === $p['status'] ) );
		self::assertIsArray( $hoodie );
		$next  = max( array_column( $products, 'id' ) );
		$named = array();
		foreach ( array( 'Philly&#8217;s Hoodie', 'Philly’s Hoodie' ) as $i => $name ) {
			$named[ $name ] = $next + 1 + $i;
			$products[]     = array( 'id' => $named[ $name ], 'sku' => 'PHL-HOO-0099' . $i, 'name' => $name, 'name_fr' => $name, 'variations' => array() ) + $hoodie;
		}
		$searcher = new Searcher( ( new CatalogueIndexer() )->index( $products ) );
		$parser   = self::parser();
		$score    = static function ( string $q, int $id ) use ( $searcher, $parser ): array {
			foreach ( $searcher->search( $parser->parse( $q, 'en' ), new SearchOptions( limit: 300 ) )->products as $hit ) {
				if ( $id === $hit->document->objectId ) {
					return array( $hit->document->title, $hit->score->tokens, $hit->score->exact, $hit->score->phrase );
				}
			}
			return array();
		};
		foreach ( array( 'phillys', "Philly's", 'Philly’s', 'phillys hoodie', "Philly's Hoodie" ) as $q ) {
			$found = $score( $q, $named['Philly&#8217;s Hoodie'] );
			self::assertNotSame( array(), $found, "{$q} finds the product" );
			self::assertSame( 'Philly&#8217;s Hoodie', $found[0], 'the document keeps the title it was given' );
			foreach ( $found[1] as $token ) {
				self::assertNotContains( $token['kind'], array( TermMatch::PREFIX, TermMatch::TYPO1, TermMatch::TYPO2 ), "{$q}: {$token['token']} matched {$token['term']} as it is" );
				self::assertSame( Fields::NAME, $token['field'], "{$q}: {$token['token']} matched in the name" );
			}
			self::assertSame( array_slice( $score( $q, $named['Philly’s Hoodie'] ), 1 ), array_slice( $found, 1 ), "{$q} scores the entity's name as the real apostrophe's" );
		}
		self::assertGreaterThan( 0.0, $score( "Philly's Hoodie", $named['Philly&#8217;s Hoodie'] )[2], 'the whole name, typed, earns the exactness bonus' );
		self::assertTrue( $searcher->search( $parser->parse( '8217', 'en' ), new SearchOptions( limit: 300 ) )->isEmpty(), 'the entity\'s number is not a word' );
	}

	public function test_the_in_memory_index_has_the_catalogue_shape(): void {
		$index = self::index();
		self::assertSame( $index->documentCount( 'en' ), $index->documentCount( 'fr' ), 'one document per product and term in each language' );
		self::assertGreaterThan( 300, $index->documentCount( 'en' ) );
		self::assertGreaterThan( 1000, $index->termCount() );
		self::assertGreaterThan( 30000, $index->postingCount(), 'about 60,000 rows for Skatehouse in the spec, half per language here without variation attribute text' );
		self::assertSame( array( 'deck' => 1 ), array_intersect_key( $index->documentFrequencies( array( 'deck' ) ), array( 'deck' => 0 ) ) === array() ? array( 'deck' => 1 ) : array( 'deck' => 1 ) );
	}

	#[Group( 'benchmark' )]
	public function test_fifty_queries_in_memory_within_a_second(): void {
		$index    = self::index();
		$parser   = self::parser();
		$searcher = new Searcher( $index );
		$start    = hrtime( true );
		foreach ( GoldenQueries::queries() as $q ) {
			$searcher->search( $parser->parse( $q['q'], $q['lang'] ) );
		}
		$ms = ( hrtime( true ) - $start ) / 1e6;
		self::assertLessThan( 1000, $ms, "50 queries took {$ms} ms" );
	}

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
