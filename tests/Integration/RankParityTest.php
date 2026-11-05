<?php
/**
 * The ranking statement against the PHP ranking it replaced.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Domain\Hit;
use CreativeBranding\Search\Domain\Synonym;
use CreativeBranding\Search\Index\AnalyzerFactory;
use CreativeBranding\Search\Index\IndexQueue;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Repository\SynonymRepository;
use CreativeBranding\Search\Search\SearchService;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Tests\Fixtures\Catalogue;
use CreativeBranding\Search\Tests\Fixtures\GoldenQueries;
use CreativeBranding\Search\Tests\Support\IndexTestCase;
use CreativeBranding\Search\Tests\Support\WooCommerceCatalogue;

/**
 * D-086: the one statement (`RankRepository`) and `Domain\Ranker` over the same postings must give the same
 * products in the same order with the same totals, for the golden set and a few hundred generated queries
 * (words, prefixes, typos, minus words, quoted phrases, SKUs, both languages), with and without the owner's
 * rules, hidden-by-SKU, custom weights and in stock first. The Domain's ranking is the one Infection proves; this holds the SQL
 * to it on every database in the matrix.
 */
final class RankParityTest extends IndexTestCase {

	public function test_the_statement_ranks_as_the_domain_does(): void {
		$this->assertParity( 300, 240, true );
	}

	/**
	 * The same on a smaller catalogue, for Infection's run over the SQL builder (D-048): each mutant reruns it.
	 *
	 * @group sql-mutation
	 */
	public function test_the_statement_ranks_as_the_domain_does_on_a_small_catalogue(): void {
		$this->assertParity( 40, 60, false );
	}

	/**
	 * D-132: the statement starts from the rarest word when that reads fewer rows, keeps the range scans when it
	 * does not or for one word, and finds what the PHP ranking finds either way.
	 *
	 * @group sql-mutation
	 */
	public function test_the_statement_starts_from_the_rarest_word(): void {
		$this->catalogue->load( ( new Catalogue() )->generate( 40 ) );
		$this->service( IndexQueue::class )->clear();
		self::assertTrue( $this->service( Reindex::class )->runInline() );
		$search = $this->service( SearchService::class );
		$seen   = array();
		$watch  = static function ( string $query ) use ( &$seen ): string {
			if ( str_starts_with( $query, 'WITH q AS' ) ) {
				$seen[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $watch );
		$cases = array(
			'kazumi deck' => 0,
			'deck kazumi' => 1,
			'skate deck'  => null,
			'kazumi'      => null,
		);
		foreach ( $cases as $q => $lead ) {
			$seen = array();
			$sql  = $search->run( $q, 'en', SearchService::MAX_IDS, 0 );
			self::assertCount( 1, $seen, $q );
			if ( null === $lead ) {
				self::assertStringNotContainsString( 'c AS (', $seen[0], "{$q}: every word is common, or there is one" );
			} else {
				self::assertStringContainsString( "WHERE q.tok = {$lead})", $seen[0], "{$q}: the rare word leads" );
				self::assertStringContainsString( 'c STRAIGHT_JOIN q STRAIGHT_JOIN', $seen[0], $q );
				self::assertStringContainsString( 'AND p.document_id = c.doc', $seen[0], "{$q}: each candidate looked up by the key" );
			}
			self::assertNotSame( array(), $sql->products, $q );
			self::assertSame( self::ranking( $search->run( $q, 'en', SearchService::MAX_IDS, 0, true, true )->products ), self::ranking( $sql->products ), $q );
		}
		remove_filter( 'query', $watch );
	}

	/**
	 * Load a catalogue, rank every query both ways in each settings round, and compare.
	 *
	 * @param int  $products  Catalogue size.
	 * @param int  $generated Generated queries beside the golden set.
	 * @param bool $golden    Whether the golden set is asked too.
	 */
	private function assertParity( int $products, int $generated, bool $golden ): void {
		add_filter( 'csearch_languages', array( WooCommerceCatalogue::class, 'bilingual' ) );
		$this->service( AnalyzerFactory::class )->flush();
		$specs = ( new Catalogue() )->generate( $products );
		$this->catalogue->load( $specs );
		$this->service( IndexQueue::class )->clear();
		foreach ( GoldenQueries::load()['synonyms'] as $row ) {
			$this->service( SynonymRepository::class )->add( new Synonym( $row['lang'], $row['word'], $row['means'], $row['both_ways'] ) );
		}
		self::assertTrue( $this->service( Reindex::class )->runInline() );

		$queries = self::queries( $specs, $generated, $golden );
		self::assertGreaterThan( (int) ( $generated * 0.9 ), count( $queries ) );
		$search  = $this->service( SearchService::class );
		$terms   = get_terms(
			array(
				'taxonomy'   => array( 'product_cat', 'product_brand', 'product_tag' ),
				'hide_empty' => true,
				'number'     => 4,
			)
		);
		$rules   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $k => $term ) {
			if ( $term instanceof \WP_Term ) {
				$rules[] = array(
					'taxonomy' => $term->taxonomy,
					'term_id'  => $term->term_id,
					'boost'    => array( 5, -3, 10, 2 )[ $k % 4 ],
				);
			}
		}
		self::assertNotSame( array(), $rules );
		$rounds = array(
			'defaults'           => array(),
			'rules, hidden off'  => array(
				'boost_rules'   => $rules,
				'hidden_by_sku' => 'no',
			),
			'weights, hidden on' => array(
				'hidden_by_sku' => 'yes',
				'fields'        => array(
					'name'      => 3,
					'sku'       => 9,
					'short'     => 0,
					'long'      => 1,
					'category'  => 4,
					'tag'       => 2,
					'brand'     => 6,
					'attribute' => 5,
				),
			),
			// D-138, D-142: name matches, then description-only ones, each in stock first; on the round's weights above.
			'in stock first'     => array( 'in_stock_first' => 'yes' ),
		);
		$compared  = 0;
		$different = array();
		$phrased   = 0;
		foreach ( $rounds as $name => $settings ) {
			foreach ( $settings as $key => $value ) {
				Settings::set( $key, $value );
			}
			foreach ( $queries as [ $q, $lang ] ) {
				$sql = $search->run( $q, $lang, SearchService::MAX_IDS, 0 );
				$php = $search->run( $q, $lang, SearchService::MAX_IDS, 0, true, true );
				++$compared;
				if ( str_contains( $q, '"' ) && array() !== $sql->products ) {
					++$phrased;
				}
				if ( self::ranking( $sql->products ) !== self::ranking( $php->products ) || $sql->corrected !== $php->corrected ) {
					$different[] = sprintf( '%s [%s, %s]: sql %s | php %s', $q, $lang, $name, implode( ' ', array_slice( self::ranking( $sql->products ), 0, 6 ) ), implode( ' ', array_slice( self::ranking( $php->products ), 0, 6 ) ) );
				}
			}
		}
		remove_filter( 'csearch_languages', array( WooCommerceCatalogue::class, 'bilingual' ) );
		self::assertGreaterThan( 0, $phrased, 'a quoted phrase finds products, so the phrase SQL is held' );
		self::assertSame( array(), $different, "The statement and the Domain differ ({$compared} compared):\n" . implode( "\n", array_slice( $different, 0, 20 ) ) );
	}

	/**
	 * Each hit as `id:total` (the total to six decimals), with `:d` when some word reached it only in its description.
	 *
	 * @param Hit[] $hits Hits.
	 * @return string[]
	 */
	private static function ranking( array $hits ): array {
		return array_map( static fn( Hit $h ): string => $h->document->objectId . ':' . number_format( round( $h->score->total(), 6 ), 6, '.', '' ) . ( $h->score->descriptionOnly ? ':d' : '' ), $hits );
	}

	/**
	 * The golden set and generated queries, seeded, so a failure repeats.
	 *
	 * @param array<int, array<string, mixed>> $specs     The catalogue.
	 * @param int                              $generated How many to generate.
	 * @param bool                             $golden    Whether the golden set comes first.
	 * @return array<int, array{0: string, 1: string}>
	 */
	private static function queries( array $specs, int $generated, bool $golden ): array {
		$out = array();
		foreach ( $golden ? GoldenQueries::queries() : array() as $q ) {
			$out[] = array( (string) $q['q'], (string) $q['lang'] );
		}
		mt_srand( 86 );
		$words = static function ( string $name ): array {
			return array_values( array_filter( preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $name ) ) ?: array(), static fn( string $w ): bool => mb_strlen( $w ) > 1 ) );
		};
		$typo  = static function ( string $w ): string {
			if ( strlen( $w ) < 5 ) {
				return $w;
			}
			$i = mt_rand( 1, strlen( $w ) - 2 );
			return substr( $w, 0, $i ) . $w[ $i + 1 ] . $w[ $i ] . substr( $w, $i + 2 );
		};
		for ( $k = 0; $k < $generated; $k++ ) {
			$spec = $specs[ mt_rand( 0, count( $specs ) - 1 ) ];
			$lang = 0 === $k % 3 ? 'fr' : 'en';
			$name = (string) ( 'fr' === $lang && isset( $spec['name_fr'] ) ? $spec['name_fr'] : $spec['name'] );
			$w    = $words( $name );
			if ( array() === $w ) {
				continue;
			}
			$a = $w[ mt_rand( 0, count( $w ) - 1 ) ];
			$b = $w[ mt_rand( 0, count( $w ) - 1 ) ];
			$c = $w[ mt_rand( 0, count( $w ) - 1 ) ];
			switch ( $k % 8 ) {
				case 0:
					$q = $a;
					break;
				case 1:
					$q = "{$a} {$b}";
					break;
				case 2:
					$q = "{$a} " . mb_substr( $b, 0, max( 2, (int) ( mb_strlen( $b ) / 2 ) ) );
					break;
				case 3:
					$q = $typo( $a ) . " {$b}";
					break;
				case 4:
					$q = "{$a} -{$b}";
					break;
				case 5:
					// Two neighbouring words of the name, so the phrase finds its product and the phrase SQL is held
					// (random pairs were rarely adjacent, D-088); no extra draw, so the other queries stay the same.
					$at = (int) array_search( $a, $w, true );
					$q  = isset( $w[ $at + 1 ] ) ? "\"{$a} {$w[ $at + 1 ]}\" {$c}" : "\"{$a} {$b}\" {$c}";
					break;
				case 6:
					$q = (string) $spec['sku'];
					break;
				default:
					$q = implode( ' ', array_slice( $w, 0, 4 ) );
			}
			$out[] = array( $q, $lang );
		}
		// Two quoted phrases in one query, from names of four words or more: each phrase has its own flag (D-088).
		$two = 0;
		foreach ( $specs as $spec ) {
			$w = $words( (string) $spec['name'] );
			if ( count( $w ) >= 4 && $two < 3 ) {
				$out[] = array( "\"{$w[0]} {$w[1]}\" \"{$w[2]} {$w[3]}\"", 'en' );
				$out[] = array( "\"{$w[0]} {$w[1]}\" \"{$w[3]} {$w[2]}\"", 'en' );
				++$two;
			}
		}
		// Typos of words the descriptions carry (D-146): a letter dropped from a five-letter word reads as four letters,
		// whose loose candidates count nowhere in a description; a transposed longer word, whose loose candidates found
		// only in a description rank last. Appended, so the queries above stay the same.
		$seen = array();
		foreach ( array_slice( $specs, 0, 80 ) as $spec ) {
			foreach ( $words( (string) $spec['short'] . ' ' . (string) $spec['long'] ) as $w ) {
				if ( count( $seen ) >= 40 || isset( $seen[ $w ] ) || ! preg_match( '/^\p{L}+$/u', $w ) ) {
					continue;
				}
				if ( 5 === strlen( $w ) ) {
					$seen[ $w ] = true;
					$typoed     = substr( $w, 0, 2 ) . substr( $w, 3 );
					$out[]      = array( $typoed, 'en' );
					// The word beside the typo, or excluded: one word's descriptions not counting must leave another
					// word's, and the minus word's, alone.
					$out[] = array( "{$typoed} {$w}", 'en' );
					$out[] = array( "{$typoed} -{$w}", 'en' );
				} elseif ( strlen( $w ) >= 6 ) {
					$seen[ $w ] = true;
					$out[]      = array( $typo( $w ), 'en' );
				}
			}
		}
		// Every hidden product's SKU, so hidden-by-SKU is always exercised.
		foreach ( $specs as $spec ) {
			if ( ! $spec['visible'] ) {
				$out[] = array( (string) $spec['sku'], 'en' );
			}
		}
		return $out;
	}
}
