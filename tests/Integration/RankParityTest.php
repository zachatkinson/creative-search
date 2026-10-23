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
 * rules, hidden-by-SKU and custom weights. The Domain's ranking is the one Infection proves; this holds the SQL
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
		);
		$compared  = 0;
		$different = array();
		foreach ( $rounds as $name => $settings ) {
			foreach ( $settings as $key => $value ) {
				Settings::set( $key, $value );
			}
			foreach ( $queries as [ $q, $lang ] ) {
				$sql = $search->run( $q, $lang, SearchService::MAX_IDS, 0 );
				$php = $search->run( $q, $lang, SearchService::MAX_IDS, 0, true, true );
				++$compared;
				if ( self::ranking( $sql->products ) !== self::ranking( $php->products ) || $sql->corrected !== $php->corrected ) {
					$different[] = sprintf( '%s [%s, %s]: sql %s | php %s', $q, $lang, $name, implode( ' ', array_slice( self::ranking( $sql->products ), 0, 6 ) ), implode( ' ', array_slice( self::ranking( $php->products ), 0, 6 ) ) );
				}
			}
		}
		remove_filter( 'csearch_languages', array( WooCommerceCatalogue::class, 'bilingual' ) );
		self::assertSame( array(), $different, "The statement and the Domain differ ({$compared} compared):\n" . implode( "\n", array_slice( $different, 0, 20 ) ) );
	}

	/**
	 * Each hit as `id:total`, the total to six decimals.
	 *
	 * @param Hit[] $hits Hits.
	 * @return string[]
	 */
	private static function ranking( array $hits ): array {
		return array_map( static fn( Hit $h ): string => $h->document->objectId . ':' . number_format( round( $h->score->total(), 6 ), 6, '.', '' ), $hits );
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
		// Every hidden product's SKU, so hidden-by-SKU is always exercised.
		foreach ( $specs as $spec ) {
			if ( ! $spec['visible'] ) {
				$out[] = array( (string) $spec['sku'], 'en' );
			}
		}
		return $out;
	}
}
