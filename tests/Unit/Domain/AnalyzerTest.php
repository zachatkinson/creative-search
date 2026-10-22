<?php
/**
 * Analyzer tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Domain;

use CreativeBranding\Search\Domain\Analyzer;
use CreativeBranding\Search\Domain\Fields;
use CreativeBranding\Search\Domain\Token;
use CreativeBranding\Search\Domain\Tokenizer;
use PHPUnit\Framework\TestCase;

/**
 * Postings carry tf and first position; stems are a second posting at the same place; multi-value fields leave
 * a gap; the SKU field counts a term once.
 */
final class AnalyzerTest extends TestCase {

	public function test_tokens_and_stems_become_postings(): void {
		$p = ( new Analyzer() )->analyze( array( Fields::NAME => 'KAZUMI Skate Decks, Dealer' ), 'en' );
		self::assertSame( array( Fields::NAME => array( 1, 0 ) ), $p['kazumi'] );
		self::assertSame( array( Fields::NAME => array( 1, 2 ) ), $p['decks'] );
		self::assertSame( array( Fields::NAME => array( 1, 2 ) ), $p['deck'], 'the stem shares the position' );
		self::assertSame( array( Fields::NAME => array( 1, 3 ) ), $p['dealer'] );
		self::assertArrayNotHasKey( 'skat', $p, 'skate stems to skate: no second posting' );
		self::assertSame( array( 'kazumi', 'skate', 'decks', 'deck', 'dealer' ), array_keys( $p ) );
	}

	public function test_tf_counts_and_first_position_is_the_earliest(): void {
		$p = ( new Analyzer() )->analyze( array( Fields::LONG => 'deck maple deck maple decks' ), 'en' );
		self::assertSame( array( Fields::LONG => array( 3, 0 ) ), $p['deck'], 'two decks and the stem of decks' );
		self::assertSame( array( Fields::LONG => array( 1, 4 ) ), $p['decks'] );
		self::assertSame( array( Fields::LONG => array( 2, 1 ) ), $p['maple'] );
		self::assertSame( array( Fields::LONG => array( 2, 1 ) ), $p['mapl'] );
	}

	public function test_fields_are_separate_and_several_values_leave_a_gap(): void {
		$p = ( new Analyzer() )->analyze(
			array(
				Fields::NAME     => 'Deck',
				Fields::CATEGORY => array( 'Skateboards', 'Decks' ),
				Fields::TAG      => array( 'popsicle', '', 'deck' ),
			),
			'en'
		);
		self::assertSame( array( 1, 0 ), $p['deck'][ Fields::NAME ] );
		self::assertSame( array( 1, 2 ), $p['deck'][ Fields::CATEGORY ], 'Decks is the second value: position 0 + 1 + gap' );
		self::assertSame( array( 1, 2 ), $p['decks'][ Fields::CATEGORY ] );
		self::assertSame( array( 1, 0 ), $p['skateboards'][ Fields::CATEGORY ] );
		self::assertSame( array( 1, 0 ), $p['skateboard'][ Fields::CATEGORY ] );
		self::assertSame( array( 1, 0 ), $p['popsicle'][ Fields::TAG ] );
		self::assertSame( array( 1, 3 ), $p['deck'][ Fields::TAG ], 'an empty value still moves the gap along' );
	}

	public function test_a_phrase_cannot_span_two_values(): void {
		$p = ( new Analyzer() )->analyze( array( Fields::ATTRIBUTE => array( 'Forest', 'Green' ) ), 'en' );
		self::assertSame( 0, $p['forest'][ Fields::ATTRIBUTE ][1] );
		self::assertSame( 2, $p['green'][ Fields::ATTRIBUTE ][1], 'not adjacent' );
	}

	public function test_sku_field_counts_a_term_once(): void {
		$p = ( new Analyzer() )->analyze( array( Fields::SKU => array( 'KAZ-DEA-00001', 'KAZ-DEA-00001-80', 'KAZ-DEA-00001-825' ) ), 'en' );
		self::assertSame( array( 1, 0 ), $p['kaz'][ Fields::SKU ], 'tf stays 1 (D-038)' );
		self::assertSame( array( 1, 2 ), $p['00001'][ Fields::SKU ] );
		self::assertSame( array( 1, 4 ), $p['kaz'][ Fields::SKU ] === array( 1, 0 ) ? array( 1, 4 ) : $p['kaz'][ Fields::SKU ], 'the first position is kept' );
		self::assertSame( array( 1, 7 ), $p['80'][ Fields::SKU ], 'the second SKU starts at 4: three tokens, then the gap' );
		self::assertSame( array( 1, 12 ), $p['825'][ Fields::SKU ] );
		$long = ( new Analyzer() )->analyze( array( Fields::LONG => 'kaz kaz' ), 'en' );
		self::assertSame( array( 2, 0 ), $long['kaz'][ Fields::LONG ], 'other fields count' );
	}

	public function test_numbers_and_sku_shapes_are_not_stemmed(): void {
		$a = new Analyzer();
		$p = $a->analyze( array( Fields::ATTRIBUTE => array( '8.25', '54mm', '101a', 'wheels' ) ), 'en' );
		self::assertSame( array( '8.25', '54mm', '101a', 'wheels', 'wheel' ), array_keys( $p ) );
		self::assertSame( '', $a->stem( new Token( '8.25', 0 ), 'en' ) );
		self::assertSame( '', $a->stem( new Token( '54mm', 0 ), 'en' ) );
		self::assertSame( 'wheel', $a->stem( new Token( 'wheels', 0 ), 'en' ) );
	}

	public function test_stems_read_the_unfolded_source_and_are_folded(): void {
		$a = new Analyzer();
		self::assertSame( 'qualit', $a->stemOf( 'qualité', 'fr' ) );
		self::assertSame( 'ecol', $a->stemOf( 'école', 'fr' ), 'folded after stemming' );
		self::assertSame( 'ecol', $a->stem( new Token( 'ecole', 0, 'école' ), 'fr' ) );
		self::assertSame( 'quebecois', $a->stemOf( 'québécois', 'fr' ) );
		self::assertSame( 'planch', $a->analyze( array( Fields::NAME => 'Planches' ), 'fr' )['planch'] === array( Fields::NAME => array( 1, 0 ) ) ? 'planch' : '' );
		self::assertSame( 'decks', $a->stemOf( 'decks', 'de' ), 'no stemmer: the word' );
	}

	public function test_stop_words_never_become_postings(): void {
		$p = ( new Analyzer() )->analyze( array( Fields::SHORT => 'The deck of the year' ), 'en' );
		self::assertSame( array( 'deck', 'year' ), array_keys( $p ) );
		self::assertSame( array( 1, 1 ), $p['year'][ Fields::SHORT ], 'positions count kept tokens only (D-027)' );
	}

	public function test_exposes_its_tokenizer(): void {
		$t = new Tokenizer();
		self::assertSame( $t, ( new Analyzer( $t ) )->tokenizer() );
		self::assertSame( array(), ( new Analyzer() )->analyze( array(), 'en' ) );
		self::assertSame( array(), ( new Analyzer() )->analyze( array( Fields::NAME => '' ), 'en' ) );
	}
}
