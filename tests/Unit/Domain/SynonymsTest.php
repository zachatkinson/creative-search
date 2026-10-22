<?php
/**
 * Synonym tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Domain;

use CreativeBranding\Search\Domain\Synonym;
use CreativeBranding\Search\Domain\Synonyms;
use PHPUnit\Framework\TestCase;

/**
 * Alternatives are added per language, both ways where set, never the token itself; a multi-word meaning adds
 * each word; a multi-word `word` is ignored (D-029).
 */
final class SynonymsTest extends TestCase {

	public function test_one_way_and_both_ways(): void {
		$s = new Synonyms(
			array(
				new Synonym( 'en', 'board', 'deck' ),
				new Synonym( 'en', 'hoody', 'hoodie', true ),
			)
		);
		self::assertFalse( $s->isEmpty() );
		self::assertSame( array( 'deck' ), $s->alternatives( 'board', 'en' ) );
		self::assertSame( array(), $s->alternatives( 'deck', 'en' ), 'one way' );
		self::assertSame( array( 'hoodie' ), $s->alternatives( 'hoody', 'en' ) );
		self::assertSame( array( 'hoody' ), $s->alternatives( 'hoodie', 'en' ), 'both ways' );
		self::assertSame( array(), $s->alternatives( 'board', 'fr' ), 'per language' );
		self::assertSame( array(), $s->alternatives( 'wheels', 'en' ) );
	}

	public function test_words_are_folded_and_languages_normalised(): void {
		$s = new Synonyms( array( new Synonym( 'fr_CA', 'Skate', 'Planche à roulettes' ) ) );
		self::assertSame( array( 'planche', 'roulettes' ), $s->alternatives( 'skate', 'fr' ), 'each word of the meaning, stop words out' );
		self::assertSame( array( 'planche', 'roulettes' ), $s->alternatives( 'skate', 'fr-CA' ) );
	}

	public function test_meaning_with_a_hyphen_adds_its_tokens(): void {
		$s = new Synonyms( array( new Synonym( 'en', 'tee', 't-shirt' ) ) );
		self::assertSame( array( 'shirt' ), $s->alternatives( 'tee', 'en' ), 'the one-letter t drops as it does in the index' );
	}

	public function test_rows_that_cannot_apply_are_ignored(): void {
		$s = new Synonyms(
			array(
				new Synonym( 'en', 'skate board', 'deck' ),
				new Synonym( 'en', 'board', '' ),
				new Synonym( 'en', '', 'deck' ),
				new Synonym( 'en', 'the', 'deck' ),
				new Synonym( 'en', 'deck', 'deck' ),
			)
		);
		self::assertSame( array(), $s->alternatives( 'skate', 'en' ) );
		self::assertSame( array(), $s->alternatives( 'board', 'en' ) );
		self::assertSame( array(), $s->alternatives( 'the', 'en' ), 'a stop word never gets a synonym' );
		self::assertSame( array(), $s->alternatives( 'deck', 'en' ), 'a word is never its own alternative' );
	}

	public function test_empty_table(): void {
		$s = new Synonyms( array() );
		self::assertTrue( $s->isEmpty() );
		self::assertSame( array(), $s->alternatives( 'board', 'en' ) );
	}

	public function test_duplicates_merge(): void {
		$s = new Synonyms(
			array(
				new Synonym( 'en', 'board', 'deck' ),
				new Synonym( 'en', 'board', 'deck planche' ),
				new Synonym( 'en', 'Board', 'skateboard' ),
			)
		);
		self::assertSame( array( 'deck', 'planche', 'skateboard' ), $s->alternatives( 'board', 'en' ) );
	}
}
