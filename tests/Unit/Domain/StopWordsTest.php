<?php
/**
 * Stop word tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Domain;

use CreativeBranding\Search\Domain\StopWords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The built-in English and French lists, the owner's additions, and language codes.
 */
final class StopWordsTest extends TestCase {

	public function test_built_in_english_and_french(): void {
		$s = new StopWords();
		self::assertSame( array( 'en', 'fr' ), $s->languages() );
		foreach ( array( 'the', 'a', 'and', 'of', 'dont', 'wont', 'very', 'i' ) as $word ) {
			self::assertTrue( $s->isStopWord( $word, 'en' ), $word );
		}
		foreach ( array( 'le', 'la', 'les', 'de', 'des', 'et', 'a', 'au', 'aux', 'meme', 'etait', 'qu' ) as $word ) {
			self::assertTrue( $s->isStopWord( $word, 'fr' ), $word );
		}
		self::assertFalse( $s->isStopWord( 'deck', 'en' ) );
		self::assertFalse( $s->isStopWord( 'planche', 'fr' ) );
		self::assertFalse( $s->isStopWord( 'the', 'fr' ), 'lists are per language' );
		self::assertFalse( $s->isStopWord( 'les', 'en' ) );
		self::assertFalse( $s->isStopWord( 'the', 'de' ), 'an unknown language has no stop words' );
		self::assertFalse( $s->isStopWord( '', 'en' ) );
	}

	public function test_lists_are_folded_and_sorted(): void {
		$s = new StopWords();
		foreach ( $s->listFor( 'fr' ) as $word ) {
			self::assertMatchesRegularExpression( '/^[a-z]+$/', $word, 'folded, no accents, no apostrophes' );
		}
		$en = $s->listFor( 'en' );
		$sorted = $en;
		sort( $sorted );
		self::assertSame( $sorted, $en );
		self::assertSame( array_unique( $en ), $en );
		self::assertContains( 'dont', $en, "don't folds to dont as the tokenizer does" );
		self::assertSame( array(), $s->listFor( 'de' ) );
	}

	public function test_owner_additions_merge_per_language_without_touching_the_original(): void {
		$s    = new StopWords();
		$with = $s->with( 'en', array( 'Skate', 'DECK', "L'" ) );
		self::assertTrue( $with->isStopWord( 'skate', 'en' ) );
		self::assertTrue( $with->isStopWord( 'deck', 'en' ) );
		self::assertFalse( $with->isStopWord( 'skate', 'fr' ) );
		self::assertFalse( $s->isStopWord( 'skate', 'en' ), 'the original is unchanged' );
		self::assertTrue( $with->isStopWord( 'the', 'en' ), 'the built-in list is kept' );
		self::assertContains( 'l', $with->listFor( 'en' ), 'apostrophes are removed' );
		$curly = $s->with( 'en', array( 'ain’t', "'" ) );
		self::assertTrue( $curly->isStopWord( 'aint', 'en' ), 'a curly apostrophe is removed too' );
		self::assertNotContains( '', $curly->listFor( 'en' ), 'a word that is only an apostrophe is nothing' );
	}

	public function test_additions_for_a_new_language_create_its_list(): void {
		$with = ( new StopWords() )->with( 'de_DE', array( 'der', 'die', 'das', '' ) );
		self::assertSame( array( 'en', 'fr', 'de' ), $with->languages() );
		self::assertSame( array( 'das', 'der', 'die' ), $with->listFor( 'de' ), 'an empty word is dropped' );
		self::assertTrue( $with->isStopWord( 'der', 'de-AT' ) );
	}

	public function test_custom_lists_replace_the_built_in_ones(): void {
		$s = new StopWords( array( 'EN' => array( 'Foo' ) ) );
		self::assertSame( array( 'en' ), $s->languages() );
		self::assertTrue( $s->isStopWord( 'foo', 'en' ) );
		self::assertFalse( $s->isStopWord( 'the', 'en' ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function codes(): array {
		return array(
			'plain'        => array( 'en', 'en' ),
			'region'       => array( 'fr_CA', 'fr' ),
			'dash'         => array( 'fr-ca', 'fr' ),
			'upper'        => array( 'FR', 'fr' ),
			'spaces'       => array( ' en ', 'en' ),
			'empty'        => array( '', '' ),
			'only dash'    => array( '-x', '-x' ),
		);
	}

	#[DataProvider( 'codes' )]
	public function test_language_codes_reduce_to_the_language( string $in, string $out ): void {
		self::assertSame( $out, StopWords::code( $in ) );
	}
}
