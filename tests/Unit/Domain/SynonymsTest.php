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
 * A word for a word adds an alternative per language, both ways where set, never the token itself (D-029); a row
 * with more than one word on either side is a phrase rule, both ways where set (D-109).
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
		self::assertSame( array(), $s->phrases( 'en' ), 'a word for a word is no phrase rule' );
	}

	public function test_phrase_rules_are_kept_apart_by_both_sides(): void {
		$s     = new Synonyms(
			array(
				new Synonym( 'en', 'aa', 'bbcc dd' ),
				new Synonym( 'en', 'aabb', 'cc dd' ),
				new Synonym( 'en', 'ss', 'stainless steel', true ),
				new Synonym( 'en', 'ss', 'inox steel', true ),
				new Synonym( 'en', 'skate shoes', 'kicks', true ),
				new Synonym( 'en', 'trainer shoes', 'kicks', true ),
			)
		);
		$rules = array_map( static fn( array $r ): string => implode( ' ', $r['from'] ) . ' > ' . implode( ' ', array_map( static fn( $t ) => $t->text, $r['to'] ) ), $s->phrases( 'en' ) );
		self::assertSame( array( 'aa > bbcc dd', 'aabb > cc dd', 'ss > stainless steel', 'ss > inox steel', 'stainless steel > ss', 'inox steel > ss', 'skate shoes > kicks', 'kicks > skate shoes', 'kicks > trainer shoes', 'trainer shoes > kicks' ), $rules, 'grouped by the words they find, in the order first added' );
	}

	public function test_words_are_folded_and_languages_normalised(): void {
		$s = new Synonyms( array( new Synonym( 'fr_CA', 'Skate', 'Planche à roulettes' ), new Synonym( 'fr_CA', 'Roue', 'Rouë' ) ) );
		self::assertSame( array(), $s->alternatives( 'skate', 'fr' ), 'a meaning of two words is a phrase rule, no alternative' );
		$rules = $s->phrases( 'fr-CA' );
		self::assertCount( 1, $rules );
		self::assertSame( array( 'skate' ), $rules[0]['from'] );
		self::assertSame( array( 'planche', 'roulettes' ), array_map( static fn( $t ) => $t->text, $rules[0]['to'] ), 'stop words out' );
		self::assertSame( array( 'planche', 'roulettes' ), array_map( static fn( $t ) => $t->source(), $rules[0]['to'] ), 'the words before folding, for the stemmer' );
		self::assertSame( $rules, $s->phrases( 'fr' ) );
		self::assertSame( array(), $s->phrases( 'en' ) );
		self::assertSame( array(), $s->alternatives( 'roue', 'fr' ), 'one word folded the same is no synonym' );
	}

	public function test_phrase_rules_both_ways(): void {
		$s = new Synonyms(
			array(
				new Synonym( 'en', 'ss', 'stainless steel' ),
				new Synonym( 'en', 'skate shoes', 'sneakers', true ),
				new Synonym( 'en', 'Skate Shoes', 'sneakers', true ),
				new Synonym( 'en', 'ollie bar', 'grind rail' ),
			)
		);
		self::assertFalse( $s->isEmpty() );
		$rules = array_map( static fn( array $r ): string => implode( ' ', $r['from'] ) . ' > ' . implode( ' ', array_map( static fn( $t ) => $t->text, $r['to'] ) ), $s->phrases( 'en' ) );
		self::assertSame( array( 'ss > stainless steel', 'skate shoes > sneakers', 'sneakers > skate shoes', 'ollie bar > grind rail' ), $rules, 'in order, a repeat once, both ways where set' );
		self::assertSame( array(), $s->alternatives( 'ss', 'en' ) );
		self::assertSame( array(), $s->alternatives( 'sneakers', 'en' ) );
	}

	public function test_a_phrase_rule_alone_is_not_empty(): void {
		self::assertFalse( ( new Synonyms( array( new Synonym( 'en', 'ss', 'stainless steel' ) ) ) )->isEmpty() );
	}

	public function test_a_word_with_two_spellings_counts_once(): void {
		$s = new Synonyms( array( new Synonym( 'en', "philly's", 'cheesesteak shop' ) ) );
		self::assertSame( array( 'phillys' ), $s->phrases( 'en' )[0]['from'], 'the first spelling of a position' );
	}

	public function test_find(): void {
		self::assertSame( 0, Synonyms::find( array( 'ss', 'bearings' ), array( 'ss' ) ) );
		self::assertSame( 1, Synonyms::find( array( 'kazumi', 'skate', 'shoes' ), array( 'skate', 'shoes' ) ) );
		self::assertSame( 1, Synonyms::find( array( 'a', 'b', 'c' ), array( 'b', 'c' ) ), 'a run ending at the last word' );
		self::assertNull( Synonyms::find( array( 'skate', 'black', 'shoes' ), array( 'skate', 'shoes' ) ), 'side by side only' );
		self::assertNull( Synonyms::find( array( 'shoes', 'skate' ), array( 'skate', 'shoes' ) ), 'in order only' );
		self::assertNull( Synonyms::find( array( 'skate' ), array( 'skate', 'shoes' ) ) );
		self::assertSame( 0, Synonyms::find( array( 3 => 'ss', 9 => 'x' ), array( 'ss' ) ), 'keys do not matter' );
		self::assertSame( 1, Synonyms::find( array( 'a', 'b', 'b' ), array( 'b' ) ), 'the first place' );
	}

	public function test_meaning_with_a_hyphen_adds_its_tokens(): void {
		$s = new Synonyms( array( new Synonym( 'en', 'tee', 't-shirt' ) ) );
		self::assertSame( array( 'shirt' ), $s->alternatives( 'tee', 'en' ), 'the one-letter t drops as it does in the index' );
	}

	public function test_rows_that_cannot_apply_are_ignored(): void {
		$s = new Synonyms(
			array(
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
		self::assertTrue( $s->isEmpty() );
		$same = new Synonyms( array( new Synonym( 'en', 'skate shoes', 'Skate  shoes' ), new Synonym( 'en', 'the skate', '' ) ) );
		self::assertTrue( $same->isEmpty(), 'a phrase that is itself, or means nothing, applies nowhere' );
	}

	public function test_empty_table(): void {
		$s = new Synonyms( array() );
		self::assertTrue( $s->isEmpty() );
		self::assertSame( array(), $s->phrases( 'en' ) );
		self::assertSame( array(), $s->alternatives( 'board', 'en' ) );
	}

	public function test_duplicates_merge(): void {
		$s = new Synonyms(
			array(
				new Synonym( 'en', 'board', 'deck' ),
				new Synonym( 'en', 'board', 'deck' ),
				new Synonym( 'en', 'Board', 'skateboard' ),
			)
		);
		self::assertSame( array( 'deck', 'skateboard' ), $s->alternatives( 'board', 'en' ) );
	}
}
