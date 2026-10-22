<?php
/**
 * Tokenizer tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Domain;

use CreativeBranding\Search\Domain\StopWords;
use CreativeBranding\Search\Domain\Token;
use CreativeBranding\Search\Domain\Tokenizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every rule of "Cutting text into tokens" has a case; any string tokenises without error; tokenising is
 * idempotent.
 */
final class TokenizerTest extends TestCase {

	/**
	 * Tokens as "text@position" strings.
	 *
	 * @param Token[] $tokens Tokens.
	 * @return string[]
	 */
	private static function flat( array $tokens ): array {
		return array_map( static fn( Token $t ): string => $t->text . '@' . $t->position, $tokens );
	}

	/**
	 * @return array<string, array{string, string[], string}>
	 */
	public static function cases(): array {
		return array(
			'the spec example'      => array( 'Philly’s Skate Deck, Rascals', array( 'phillys@0', 'philly@0', 'skate@1', 'deck@2', 'rascals@3' ), 'en' ),
			'straight apostrophe'   => array( "Philly's", array( 'phillys@0', 'philly@0' ), 'en' ),
			'lower case and fold'   => array( 'Québécois T-Shirt', array( 'quebecois@0', 'shirt@1' ), 'en' ),
			'decimal point'         => array( 'Deck 8.25', array( 'deck@0', '8.25@1' ), 'en' ),
			'inch mark'             => array( '8.25″ deck', array( '8.25@0', 'deck@1' ), 'en' ),
			'straight inch mark'    => array( '8.25" deck', array( '8.25@0', 'deck@1' ), 'en' ),
			'unit stays attached'   => array( '54mm 99a', array( '54mm@0', '99a@1' ), 'en' ),
			'trailing point drops'  => array( 'size 8.', array( 'size@0', '8@1' ), 'en' ),
			'leading point splits'  => array( '.25 inch', array( '25@0', 'inch@1' ), 'en' ),
			'sku splits on hyphens' => array( 'KAZ-DEA-00001', array( 'kaz@0', 'dea@1', '00001@2' ), 'en' ),
			'punctuation splits'    => array( 'trucks/wheels; bearings (set of 8)', array( 'trucks@0', 'wheels@1', 'bearings@2', 'set@3', '8@4' ), 'en' ),
			'one char dropped'      => array( 'a b c deck', array( 'deck@0' ), 'en' ),
			'one digit kept'        => array( 'size 7 shoe', array( 'size@0', '7@1', 'shoe@2' ), 'en' ),
			'stop words dropped'    => array( 'the deck of the year', array( 'deck@0', 'year@1' ), 'en' ),
			'french stop words'     => array( 'Chandail à capuchon', array( 'chandail@0', 'capuchon@1' ), 'fr' ),
			'french elision'        => array( "l'école d'été", array( 'lecole@0', 'ecole@0', 'dete@1', 'ete@1' ), 'fr' ),
			'french elision is not english' => array( "l'école", array( 'lecole@0', 'ecole@0' ), 'en' ),
			'contraction stop word' => array( "don't stop", array( 'stop@0' ), 'en' ),
			'contraction kept'      => array( "y'all's deck", array( 'yalls@0', 'deck@1' ), 'en' ),
			'stop word side'        => array( "rock'n'the roll", array( 'rocknthe@0', 'rock@0', 'roll@1' ), 'en' ),
			'curly quotes'          => array( '“Horror Party”', array( 'horror@0', 'party@1' ), 'en' ),
			'decomposed accent'     => array( "Que\u{0301}bec", array( 'quebec@0' ), 'en' ),
			'other scripts'         => array( 'スケート 滑板 Привет', array( 'スケート@0', '滑板@1', 'привет@2' ), 'en' ),
			'emoji splits'          => array( 'deck🔥sale', array( 'deck@0', 'sale@1' ), 'en' ),
			'empty'                 => array( '', array(), 'en' ),
			'only punctuation'      => array( '--- !!! ...', array(), 'en' ),
			'newlines and tabs'     => array( "deck\n\twheels", array( 'deck@0', 'wheels@1' ), 'en' ),
			'apostrophe at edges'   => array( "'deck' 'n' roll", array( 'deck@0', 'roll@1' ), 'en' ),
			'ligature fold'         => array( 'Œuvre', array( 'oeuvre@0' ), 'en' ),
			'eszett alone is one char' => array( 'ß deck', array( 'deck@0' ), 'en' ),
		);
	}

	#[DataProvider( 'cases' )]
	public function test_cuts_text_into_tokens( string $text, array $expected, string $lang ): void {
		self::assertSame( $expected, self::flat( ( new Tokenizer() )->tokenize( $text, $lang ) ) );
	}

	public function test_stop_words_can_be_kept(): void {
		$t = new Tokenizer();
		self::assertSame( array( 'the@0', 'deck@1' ), self::flat( $t->tokenize( 'the deck', 'en', false ) ) );
		self::assertTrue( $t->isStopWord( 'the', 'en' ) );
		self::assertFalse( $t->isStopWord( 'deck', 'en' ) );
	}

	public function test_owner_stop_words_apply(): void {
		$t = new Tokenizer( ( new StopWords() )->with( 'en', array( 'deck' ) ) );
		self::assertSame( array( 'kazumi@0' ), self::flat( $t->tokenize( 'KAZUMI deck', 'en' ) ) );
		self::assertSame( array( 'deck' ), array_values( array_diff( $t->stopWords()->listFor( 'en' ), ( new StopWords() )->listFor( 'en' ) ) ) );
	}

	public function test_source_keeps_the_unfolded_word_only_when_it_differs(): void {
		$tokens = ( new Tokenizer() )->tokenize( "Québécois l'été deck", 'en' );
		self::assertSame( 'québécois', $tokens[0]->source() );
		self::assertSame( 'québécois', $tokens[0]->source );
		self::assertSame( 'lete', $tokens[1]->text );
		self::assertSame( "lété", $tokens[1]->source() );
		self::assertSame( 'ete', $tokens[2]->text );
		self::assertSame( 'été', $tokens[2]->source() );
		self::assertSame( 'deck', $tokens[3]->source() );
		self::assertSame( '', $tokens[3]->source, 'nothing stored when the token is its own source' );
	}

	public function test_source_is_lower_cased_beyond_ascii(): void {
		$tokens = ( new Tokenizer() )->tokenize( 'École' );
		self::assertSame( 'ecole', $tokens[0]->text );
		self::assertSame( 'école', $tokens[0]->source() );
	}

	public function test_a_stop_word_side_does_not_end_the_word(): void {
		self::assertSame( array( 'quorbite@0', 'orbite@0' ), self::flat( ( new Tokenizer() )->tokenize( "qu'orbite", 'fr' ) ), 'qu is a stop word, orbite is kept' );
		self::assertSame( array( 'ababcd', 'ab', 'cd' ), Tokenizer::variants( "ab'ab'cd" ), 'a repeated side is skipped, the next one kept' );
	}

	public function test_digits_of_other_scripts_are_numbers(): void {
		self::assertSame( array( '٣@0', 'ab٣@1' ), self::flat( ( new Tokenizer() )->tokenize( '٣ ab٣' ) ) );
		self::assertTrue( Tokenizer::isSkuShaped( 'ab٣' ) );
	}

	public function test_the_cap_counts_characters(): void {
		$token = ( new Tokenizer() )->tokenize( str_repeat( 'É', 70 ) )[0];
		self::assertSame( str_repeat( 'e', Tokenizer::MAX_LENGTH ), $token->text );
		self::assertSame( str_repeat( 'é', Tokenizer::MAX_LENGTH ), $token->source() );
		$cyrillic = ( new Tokenizer() )->tokenize( str_repeat( 'П', 70 ) )[0];
		self::assertSame( str_repeat( 'п', Tokenizer::MAX_LENGTH ), $cyrillic->text, 'a script the folding keeps is still cut by characters' );
		self::assertSame( $cyrillic->text, $cyrillic->source(), 'nothing folded, so the spelling is the text' );
	}

	public function test_tokens_are_capped_at_the_dictionary_width(): void {
		$long   = str_repeat( 'é', 70 ) . ' deck';
		$tokens = ( new Tokenizer() )->tokenize( $long, 'en' );
		self::assertSame( str_repeat( 'e', Tokenizer::MAX_LENGTH ), $tokens[0]->text );
		self::assertSame( str_repeat( 'é', Tokenizer::MAX_LENGTH ), $tokens[0]->source() );
		self::assertSame( 'deck@1', self::flat( $tokens )[1] );
		$exact = ( new Tokenizer() )->tokenize( str_repeat( 'a', Tokenizer::MAX_LENGTH ), 'en' );
		self::assertSame( Tokenizer::MAX_LENGTH, mb_strlen( $exact[0]->text ) );
	}

	public function test_two_spellings_at_one_position_are_distinct(): void {
		// "o'o" folds to "oo" and splits to "o" (dropped) twice: one token.
		self::assertSame( array( 'oo@0' ), self::flat( ( new Tokenizer() )->tokenize( "o'o", 'en' ) ) );
		// A word whose joined form equals a side is listed once.
		self::assertSame( array( 'deck@0' ), self::flat( ( new Tokenizer() )->tokenize( "deck'", 'en' ) ) );
	}

	public function test_key_is_one_text_per_position(): void {
		$t = new Tokenizer();
		self::assertSame( 'phillys skate deck rascals', $t->key( 'Philly’s Skate Deck, Rascals' ) );
		self::assertSame( 'kaz dea 00001 825', $t->key( 'KAZ-DEA-00001-825' ) );
		self::assertSame( 'deck', $t->key( 'The Deck' ) );
		self::assertSame( '', $t->key( 'the' ) );
		self::assertSame( 'chandails capuchon', $t->key( 'Chandails à capuchon', 'fr' ) );
	}

	public function test_words_and_variants(): void {
		self::assertSame( array( 'philly\'s', 'skate', '8.25″' ), Tokenizer::words( 'Philly’s Skate 8.25″' ) );
		self::assertSame( array(), Tokenizer::words( '...' ) );
		self::assertSame( array( 'phillys', 'philly' ), Tokenizer::variants( "philly's" ) );
		self::assertSame( array( '8.25' ), Tokenizer::variants( '8.25″' ) );
		self::assertSame( array( '8.25' ), Tokenizer::variants( '8.25"' ) );
		self::assertSame( array(), Tokenizer::variants( 'x' ) );
		self::assertSame( array( '7' ), Tokenizer::variants( '7' ) );
		self::assertSame( array( 'rocknroll', 'rock', 'roll' ), Tokenizer::variants( "rock'n'roll" ), 'joined form first, one-character sides dropped' );
		self::assertSame( array( 'abab', 'ab' ), Tokenizer::variants( "ab'ab" ), 'a repeated side is listed once' );
		self::assertSame( array( 'ab' ), Tokenizer::variants( "ab'" ), 'the joined form is the side' );
	}

	/**
	 * @return array<string, array{string, bool, bool}>
	 */
	public static function shapes(): array {
		return array(
			'integer'       => array( '54', true, false ),
			'decimal'       => array( '8.25', true, false ),
			'zero padded'   => array( '00001', true, false ),
			'unit'          => array( '54mm', false, true ),
			'durometer'     => array( '101a', false, true ),
			'sku part'      => array( 'abc123', false, true ),
			'word'          => array( 'deck', false, false ),
			'two points'    => array( '1.2.3', true, false ),
			'other digits'  => array( '٣٤', true, false ),
			'empty'         => array( '', false, false ),
		);
	}

	#[DataProvider( 'shapes' )]
	public function test_numbers_and_sku_shapes( string $token, bool $number, bool $sku ): void {
		self::assertSame( $number, Tokenizer::isNumber( $token ) );
		self::assertSame( $sku, Tokenizer::isSkuShaped( $token ) );
	}

	/**
	 * Deterministic pseudo-random strings mixing letters, digits, accents, punctuation and other scripts.
	 *
	 * @return array<int, array{string}>
	 */
	public static function randomStrings(): array {
		$alphabet = array( 'a', 'e', 'k', 'z', 'é', 'ç', 'œ', 'ß', '8', '.', '2', '5', ' ', "'", '’', '-', '"', '″', ',', 'Я', 'ス', '滑', '🔥', "\n", 'The', 'the', 'and', 'à', 'ı' );
		$state    = 0x9E3779B9;
		$out      = array();
		for ( $n = 0; $n < 200; $n++ ) {
			$length = $n % 23;
			$s      = '';
			for ( $i = 0; $i < $length; $i++ ) {
				$state ^= ( $state << 13 ) & 0xFFFFFFFF;
				$state ^= $state >> 17;
				$state ^= ( $state << 5 ) & 0xFFFFFFFF;
				$s     .= $alphabet[ $state % count( $alphabet ) ];
			}
			$out[] = array( $s );
		}
		return $out;
	}

	#[DataProvider( 'randomStrings' )]
	public function test_any_string_tokenises_and_tokenising_is_idempotent( string $text ): void {
		$t      = new Tokenizer();
		$tokens = $t->tokenize( $text, 'en' );
		$last   = -1;
		foreach ( $tokens as $token ) {
			self::assertNotSame( '', $token->text );
			self::assertLessThanOrEqual( Tokenizer::MAX_LENGTH, mb_strlen( $token->text ) );
			self::assertSame( $token->text, mb_strtolower( $token->text ) );
			self::assertDoesNotMatchRegularExpression( '/[\s\'"″,-]/u', $token->text );
			self::assertGreaterThanOrEqual( $last, $token->position );
			self::assertLessThanOrEqual( $last + 1, $token->position, 'positions are consecutive' );
			$last = $token->position;
		}
		$texts = array_map( static fn( Token $x ): string => $x->text, $tokens );
		$again = array_map( static fn( Token $x ): string => $x->text, $t->tokenize( implode( ' ', $texts ), 'en' ) );
		self::assertSame( $texts, $again, 'tokenising the tokens gives the tokens' );
	}
}
