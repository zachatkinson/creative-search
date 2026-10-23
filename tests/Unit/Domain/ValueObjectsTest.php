<?php
/**
 * Tests of the small domain values.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Domain;

use CreativeBranding\Search\Domain\Document;
use CreativeBranding\Search\Domain\Fields;
use CreativeBranding\Search\Domain\Hit;
use CreativeBranding\Search\Domain\Kind;
use CreativeBranding\Search\Domain\Score;
use CreativeBranding\Search\Domain\SearchOptions;
use CreativeBranding\Search\Domain\SearchResult;
use CreativeBranding\Search\Domain\TermMatch;
use CreativeBranding\Search\Domain\Token;
use CreativeBranding\Search\Domain\TokenWidening;
use PHPUnit\Framework\TestCase;

/**
 * Document, TermMatch, Score, SearchOptions, Hit, SearchResult, TokenWidening, Token.
 */
final class ValueObjectsTest extends TestCase {

	private static function doc( int $id, string $type = Document::PRODUCT, bool $inStock = true, int $sales = 0, int $recency = 0, string $title = '' ): Document {
		return new Document( $id, $type, $id * 10, 'en', $title, '', array(), $inStock, true, 0, $sales, $recency );
	}

	public function test_document(): void {
		$d = new Document( 1, Document::PRODUCT, 7, 'en', 'Deck', 'deck', array( 'kaz dea 00001' ), false, false, 3, 40, 99, array( 'brand:kazumi' ) );
		self::assertTrue( $d->isProduct() );
		self::assertSame( 7, $d->objectId );
		self::assertFalse( $d->inStock );
		self::assertFalse( $d->visible );
		self::assertSame( 3, $d->boost );
		self::assertSame( array( 'brand:kazumi' ), $d->termRefs );
		foreach ( array( Document::CATEGORY, Document::TAG, Document::BRAND ) as $type ) {
			self::assertFalse( ( new Document( 2, $type, 1, 'en', 'x' ) )->isProduct() );
		}
		$defaults = new Document( 3, Document::TAG, 1, 'fr', 'x' );
		self::assertTrue( $defaults->inStock );
		self::assertTrue( $defaults->visible );
		self::assertSame( '', $defaults->nameKey );
		self::assertSame( array(), $defaults->skuKeys );
		self::assertSame( 0, $defaults->boost );
		self::assertSame( 0, $defaults->totalSales );
		self::assertSame( 0, $defaults->recency );
		self::assertSame( array(), $defaults->termRefs );
	}

	public function test_term_match_weights_are_the_spec_table(): void {
		self::assertSame( 1.0, ( new TermMatch( 'deck', TermMatch::EXACT ) )->weight() );
		self::assertSame( 0.9, ( new TermMatch( 'deck', TermMatch::STEM ) )->weight() );
		self::assertSame( 0.8, ( new TermMatch( 'deck', TermMatch::SYNONYM ) )->weight() );
		self::assertSame( 0.7, ( new TermMatch( 'deck', TermMatch::PREFIX ) )->weight() );
		self::assertSame( 0.5, ( new TermMatch( 'deck', TermMatch::TYPO1 ) )->weight() );
		self::assertSame( 0.3, ( new TermMatch( 'deck', TermMatch::TYPO2 ) )->weight() );
		$m = ( new TermMatch( 'deck', TermMatch::STEM ) )->withDf( 12 );
		self::assertSame( 12, $m->df );
		self::assertSame( 'deck', $m->term );
		self::assertSame( TermMatch::STEM, $m->kind );
		self::assertSame( 0, ( new TermMatch( 'deck', TermMatch::EXACT ) )->df );
	}

	public function test_score_total_sums_every_part(): void {
		$row   = array( 'token' => 'deck', 'term' => 'deck', 'kind' => 'exact', 'field' => 1, 'weight' => 10, 'm' => 1.0, 'tf' => 1, 'df' => 1, 'score' => 10.5 );
		$score = new Score( array( $row, array_merge( $row, array( 'score' => 2.0 ) ) ), 8.0, 12.0, -6.0, 3.0, 0.5 );
		self::assertSame( 29.5, $score->total() );
		self::assertSame( 0.5, $score->coverage );
		self::assertSame( 0.0, ( new Score( array() ) )->total() );
		self::assertSame( 0.0, ( new Score( array() ) )->coverage );
	}

	public function test_search_options_defaults_and_weights(): void {
		$o = new SearchOptions();
		self::assertTrue( $o->hiddenBySku );
		self::assertSame( 'en', $o->fallbackLang );
		self::assertSame( 500, $o->limit );
		self::assertSame( 3, $o->termLimit );
		self::assertSame( array(), $o->boostRules );
		self::assertSame( Fields::weightsById( array() ), $o->weightsById() );
		$custom = new SearchOptions( array( 'name' => 1 ), false, null, array( 'brand:kazumi' => 2 ), 10, 1 );
		self::assertSame( 1, $custom->weightsById()[ Fields::NAME ] );
		self::assertSame( 10, $custom->weightsById()[ Fields::SKU ] );
		self::assertNull( $custom->fallbackLang );
		self::assertFalse( $custom->hiddenBySku );
	}

	public function test_hits_order_by_score_stock_sales_recency_then_id(): void {
		$s = static fn( float $total ): Score => new Score( array(), $total );
		$hits = array(
			'low score'   => new Hit( self::doc( 1, Document::PRODUCT, true, 999, 999 ), $s( 1.0 ) ),
			'out of stock' => new Hit( self::doc( 2, Document::PRODUCT, false, 999, 999 ), $s( 5.0 ) ),
			'fewer sales' => new Hit( self::doc( 3, Document::PRODUCT, true, 10, 999 ), $s( 5.0 ) ),
			'older'       => new Hit( self::doc( 4, Document::PRODUCT, true, 50, 1 ), $s( 5.0 ) ),
			'higher id'   => new Hit( self::doc( 6, Document::PRODUCT, true, 50, 2 ), $s( 5.0 ) ),
			'best'        => new Hit( self::doc( 5, Document::PRODUCT, true, 50, 2 ), $s( 5.0 ) ),
		);
		$sorted = array_values( $hits );
		usort( $sorted, array( Hit::class, 'compare' ) );
		self::assertSame( array( 5, 6, 4, 3, 2, 1 ), array_map( static fn( Hit $h ): int => $h->document->id, $sorted ) );
		self::assertSame( 0, Hit::compare( $hits['best'], $hits['best'] ) );
	}

	public function test_term_hits_order_by_score_coverage_count_title_then_id(): void {
		$s = static fn( float $total, float $coverage ): Score => new Score( array(), $total, 0.0, 0.0, 0.0, $coverage );
		$hits = array(
			new Hit( self::doc( 1, Document::CATEGORY, true, 50, 0, 'Planches à roulettes' ), $s( 12.0, 0.5 ) ),
			new Hit( self::doc( 2, Document::CATEGORY, true, 20, 0, 'Planches' ), $s( 12.0, 1.0 ) ),
			new Hit( self::doc( 3, Document::BRAND, true, 30, 0, 'KAZUMI' ), $s( 12.0, 1.0 ) ),
			new Hit( self::doc( 4, Document::TAG, true, 30, 0, 'Alpha' ), $s( 7.0, 1.0 ) ),
			new Hit( self::doc( 5, Document::TAG, true, 30, 0, 'Beta' ), $s( 12.0, 1.0 ) ),
			new Hit( self::doc( 6, Document::TAG, true, 30, 0, 'Beta' ), $s( 12.0, 1.0 ) ),
		);
		usort( $hits, array( Hit::class, 'compareTerms' ) );
		self::assertSame( array( 5, 6, 3, 2, 1, 4 ), array_map( static fn( Hit $h ): int => $h->document->id, $hits ) );
	}

	public function test_search_result(): void {
		$empty = new SearchResult( array(), array(), 'fr' );
		self::assertTrue( $empty->isEmpty() );
		self::assertNull( $empty->corrected );
		self::assertFalse( $empty->dropped, 'nothing left out unless said' );
		self::assertSame( 'fr', $empty->lang );
		self::assertSame( array(), $empty->productIds() );
		$hit = new Hit( self::doc( 4 ), new Score( array() ) );
		$r   = new SearchResult( array( $hit, new Hit( self::doc( 2 ), new Score( array() ) ) ), array(), 'en', 'kazumi' );
		self::assertFalse( $r->isEmpty() );
		self::assertSame( array( 40, 20 ), $r->productIds() );
		self::assertSame( 'kazumi', $r->corrected );
		self::assertFalse( $r->dropped );
		self::assertFalse( ( new SearchResult( array(), array( $hit ), 'en' ) )->isEmpty(), 'terms alone are a result' );
	}

	public function test_token_widening(): void {
		$empty = new TokenWidening( array(), array(), array() );
		self::assertTrue( $empty->isEmpty() );
		self::assertSame( array(), $empty->productDocs() );
		self::assertSame( array(), $empty->termDocs() );
		self::assertSame( array(), $empty->postingsIn( 1, true ) );
		$w = new TokenWidening(
			array( 'deck' => new TermMatch( 'deck', TermMatch::EXACT, 2 ), '80' => new TermMatch( '80', TermMatch::EXACT, 1 ) ),
			array(
				'deck' => array( 1 => array( Fields::NAME => array( 1, 0 ) ), 2 => array( Fields::LONG => array( 2, 5 ) ) ),
				'80'   => array( 2 => array( Fields::SKU => array( 1, 3 ) ) ),
			),
			array( 'deck' => array( 9 => array( Fields::NAME => array( 1, 0 ) ) ) )
		);
		self::assertFalse( $w->isEmpty() );
		self::assertSame( array( 1 => true, 2 => true ), $w->productDocs() );
		self::assertSame( array( 9 => true ), $w->termDocs() );
		self::assertSame( array( 'deck' => array( Fields::LONG => array( 2, 5 ) ), '80' => array( Fields::SKU => array( 1, 3 ) ) ), $w->postingsIn( 2, true ) );
		self::assertSame( array( 'deck' => array( Fields::NAME => array( 1, 0 ) ) ), $w->postingsIn( 9, false ) );
		self::assertSame( array(), $w->postingsIn( 9, true ) );
		self::assertSame( array( 'deck', '80' ), array_map( 'strval', array_keys( $w->postingsIn( 2, true ) ) ) );
		self::assertTrue( ( new TokenWidening( array(), array(), array( 'x' => array( 9 => array() ) ) ) )->isEmpty(), 'empty means no term found, whatever is attached' );
		self::assertFalse( ( new TokenWidening( array( 'x' => new TermMatch( 'x', TermMatch::EXACT, 1 ) ), array(), array() ) )->isEmpty(), 'a found term alone counts (the store ranks the products)' );
	}

	public function test_token(): void {
		$t = new Token( 'ecole', 3, 'école' );
		self::assertSame( 'école', $t->source() );
		self::assertSame( 3, $t->position );
		self::assertSame( 'deck', ( new Token( 'deck', 0 ) )->source() );
	}

	public function test_a_document_type_has_a_kind(): void {
		self::assertSame( Kind::PRODUCT, Kind::ofType( Document::PRODUCT ) );
		self::assertSame( Kind::CONTENT, Kind::ofType( Document::POST ) );
		self::assertSame( Kind::CONTENT, Kind::ofType( Document::PAGE ) );
		self::assertSame( Kind::TERM, Kind::ofType( 'category' ) );
	}
}
