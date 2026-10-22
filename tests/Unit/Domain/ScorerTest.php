<?php
/**
 * Scorer tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Domain;

use CreativeBranding\Search\Domain\Document;
use CreativeBranding\Search\Domain\Fields;
use CreativeBranding\Search\Domain\Query;
use CreativeBranding\Search\Domain\QueryToken;
use CreativeBranding\Search\Domain\Scorer;
use CreativeBranding\Search\Domain\RankPlan;
use CreativeBranding\Search\Domain\SearchOptions;
use CreativeBranding\Search\Domain\TermMatch;
use CreativeBranding\Search\Domain\TokenWidening;
use PHPUnit\Framework\TestCase;

/**
 * The spec's formula, computed by hand, part by part.
 */
final class ScorerTest extends TestCase {

	private const N = 10;

	/**
	 * A query of plain tokens.
	 *
	 * @param string ...$words Tokens.
	 */
	private static function plan( Query $query, SearchOptions $options, int $n ): RankPlan {
		return new RankPlan( 'en', array_map( static fn( QueryToken $t ): string => $t->text, $query->tokens ), array(), $options->weightsById(), $n, array(), $query->phrases(), $query->key(), $options->hiddenBySku, $options->boostRules, $options->limit );
	}

	private static function query( string ...$words ): Query {
		return new Query( implode( ' ', $words ), 'en', array_map( static fn( string $w ): QueryToken => new QueryToken( $w, $w ), $words ) );
	}

	/**
	 * A widening of one term with postings in one document.
	 *
	 * @param string                           $term   Term.
	 * @param string                           $kind   Match kind.
	 * @param int                              $df     Document frequency.
	 * @param array<int, array{0: int, 1: int}> $fields Field to [tf, pos] in document 1.
	 * @param int                              $doc    Document id.
	 * @param bool                             $product Product postings (true) or term postings.
	 */
	private static function widening( string $term, string $kind, int $df, array $fields, int $doc = 1, bool $product = true ): TokenWidening {
		$postings = array( $term => array( $doc => $fields ) );
		return new TokenWidening( array( $term => new TermMatch( $term, $kind, $df ) ), $product ? $postings : array(), $product ? array() : $postings );
	}

	private static function doc( string $nameKey = 'kazumi deck', array $skuKeys = array(), bool $inStock = true, int $boost = 0, array $refs = array() ): Document {
		return new Document( 1, Document::PRODUCT, 1, 'en', 'KAZUMI Deck', $nameKey, $skuKeys, $inStock, true, $boost, 0, 0, $refs );
	}

	public function test_one_token_in_one_field(): void {
		$score = ( new Scorer() )->score( self::plan( self::query( 'deck' ), new SearchOptions(), self::N ), self::doc(), array( self::widening( 'deck', TermMatch::EXACT, 3, array( Fields::NAME => array( 1, 0 ) ) ) ) );
		$want  = 10 * 1.0 * ( 1 + log( 2 ) ) * log( 11 / 4 );
		self::assertEqualsWithDelta( $want, $score->total(), 1e-9 );
		self::assertSame( 'deck', $score->tokens[0]['token'] );
		self::assertSame( 'deck', $score->tokens[0]['term'] );
		self::assertSame( TermMatch::EXACT, $score->tokens[0]['kind'] );
		self::assertSame( Fields::NAME, $score->tokens[0]['field'] );
		self::assertSame( 10, $score->tokens[0]['weight'] );
		self::assertSame( 1.0, $score->tokens[0]['m'] );
		self::assertSame( 1, $score->tokens[0]['tf'] );
		self::assertSame( 3, $score->tokens[0]['df'] );
		self::assertSame( 0.0, $score->phrase );
		self::assertSame( 0.0, $score->exact );
		self::assertSame( 0.0, $score->stock );
		self::assertSame( 0.0, $score->owner );
	}

	public function test_best_field_counts_once_and_tf_is_damped(): void {
		$w = self::widening( 'deck', TermMatch::EXACT, 3, array( Fields::LONG => array( 20, 7 ), Fields::SHORT => array( 1, 0 ), Fields::NAME => array( 1, 1 ) ) );
		$score = ( new Scorer() )->score( self::plan( self::query( 'deck' ), new SearchOptions(), self::N ), self::doc(), array( $w ) );
		self::assertSame( Fields::NAME, $score->tokens[0]['field'], 'name at 10 beats a word repeated twenty times at 1' );
		self::assertEqualsWithDelta( 10 * ( 1 + log( 2 ) ) * log( 11 / 4 ), $score->total(), 1e-9 );
		$long = ( new Scorer() )->score( self::plan( self::query( 'deck' ), new SearchOptions(), self::N ), self::doc(), array( self::widening( 'deck', TermMatch::EXACT, 3, array( Fields::LONG => array( 20, 7 ) ) ) ) );
		self::assertEqualsWithDelta( 1 * ( 1 + log( 21 ) ) * log( 11 / 4 ), $long->total(), 1e-9 );
		self::assertSame( 20, $long->tokens[0]['tf'] );
	}

	public function test_match_weights_and_the_best_term_wins(): void {
		$w = new TokenWidening(
			array( 'decks' => new TermMatch( 'decks', TermMatch::EXACT, 3 ), 'deck' => new TermMatch( 'deck', TermMatch::STEM, 5 ) ),
			array( 'decks' => array( 1 => array( Fields::LONG => array( 1, 0 ) ) ), 'deck' => array( 1 => array( Fields::NAME => array( 1, 0 ) ) ) ),
			array()
		);
		$score = ( new Scorer() )->score( self::plan( self::query( 'decks' ), new SearchOptions(), self::N ), self::doc(), array( $w ) );
		self::assertSame( 'deck', $score->tokens[0]['term'] );
		self::assertSame( 0.9, $score->tokens[0]['m'] );
		self::assertEqualsWithDelta( 10 * 0.9 * ( 1 + log( 2 ) ) * log( 11 / 6 ), $score->total(), 1e-9 );
	}

	public function test_custom_weights_and_unknown_fields(): void {
		$options = new SearchOptions( array( 'name' => 3 ) );
		$score   = ( new Scorer() )->score( self::plan( self::query( 'deck' ), $options, self::N ), self::doc(), array( self::widening( 'deck', TermMatch::EXACT, 3, array( Fields::NAME => array( 1, 0 ), 9 => array( 1, 0 ) ) ) ) );
		self::assertSame( 3, $score->tokens[0]['weight'] );
		self::assertEqualsWithDelta( 3 * ( 1 + log( 2 ) ) * log( 11 / 4 ), $score->total(), 1e-9 );
		$zero = ( new Scorer() )->score( self::plan( self::query( 'deck' ), $options, self::N ), self::doc(), array( self::widening( 'deck', TermMatch::EXACT, 3, array( 9 => array( 1, 0 ) ) ) ) );
		self::assertSame( 0, $zero->tokens[0]['weight'], 'an unknown field weighs nothing' );
		self::assertSame( 0.0, $zero->total() );
	}

	public function test_a_token_without_postings_in_the_document_scores_nothing(): void {
		$score = ( new Scorer() )->score( self::plan( self::query( 'kazumi', 'deck' ), new SearchOptions(), self::N ), self::doc(), array( self::widening( 'kazumi', TermMatch::EXACT, 3, array( Fields::NAME => array( 1, 0 ) ) ), self::widening( 'deck', TermMatch::EXACT, 3, array( Fields::NAME => array( 1, 0 ) ), 2 ) ) );
		self::assertSame( array( 0 ), array_keys( $score->tokens ) );
		self::assertSame( 0.0, $score->phrase );
	}

	public function test_phrase_bonus_adjacent_in_order_and_split(): void {
		$scorer  = new Scorer();
		$q       = self::query( 'kazumi', 'deck' );
		$options = new SearchOptions();
		$adjacent = array( self::widening( 'kazumi', TermMatch::EXACT, 1, array( Fields::NAME => array( 1, 0 ) ) ), self::widening( 'deck', TermMatch::EXACT, 1, array( Fields::NAME => array( 1, 1 ) ) ) );
		self::assertSame( 8.0, $scorer->score( self::plan( $q, $options, self::N ), self::doc(), $adjacent )->phrase );
		$ordered = array( self::widening( 'kazumi', TermMatch::EXACT, 1, array( Fields::NAME => array( 1, 0 ) ) ), self::widening( 'deck', TermMatch::EXACT, 1, array( Fields::NAME => array( 1, 2 ) ) ) );
		self::assertSame( 4.0, $scorer->score( self::plan( $q, $options, self::N ), self::doc(), $ordered )->phrase );
		$reversed = array( self::widening( 'kazumi', TermMatch::EXACT, 1, array( Fields::NAME => array( 1, 2 ) ) ), self::widening( 'deck', TermMatch::EXACT, 1, array( Fields::NAME => array( 1, 0 ) ) ) );
		self::assertSame( 0.0, $scorer->score( self::plan( $q, $options, self::N ), self::doc(), $reversed )->phrase );
		$same = array( self::widening( 'kazumi', TermMatch::EXACT, 1, array( Fields::NAME => array( 1, 2 ) ) ), self::widening( 'deck', TermMatch::EXACT, 1, array( Fields::NAME => array( 1, 2 ) ) ) );
		self::assertSame( 0.0, $scorer->score( self::plan( $q, $options, self::N ), self::doc(), $same )->phrase, 'the same position is not in order' );
		$split = array( self::widening( 'kazumi', TermMatch::EXACT, 1, array( Fields::NAME => array( 1, 0 ) ) ), self::widening( 'deck', TermMatch::EXACT, 1, array( Fields::SHORT => array( 1, 1 ) ) ) );
		self::assertSame( 0.0, $scorer->score( self::plan( $q, $options, self::N ), self::doc(), $split )->phrase, 'one field at a time' );
		$both = array(
			self::widening( 'kazumi', TermMatch::EXACT, 1, array( Fields::NAME => array( 1, 0 ), Fields::SHORT => array( 1, 5 ) ) ),
			self::widening( 'deck', TermMatch::EXACT, 1, array( Fields::NAME => array( 1, 3 ), Fields::SHORT => array( 1, 6 ) ) ),
		);
		self::assertSame( 8.0, $scorer->score( self::plan( $q, $options, self::N ), self::doc(), $both )->phrase, 'the best field decides' );
		$one = $scorer->score( self::plan( self::query( 'deck' ), $options, self::N ), self::doc(), array( $adjacent[1] ) );
		self::assertSame( 0.0, $one->phrase, 'one token is no phrase' );
	}

	public function test_phrase_bonus_uses_the_earliest_position_of_a_token_in_a_field(): void {
		$w = new TokenWidening(
			array( 'decks' => new TermMatch( 'decks', TermMatch::EXACT, 1 ), 'deck' => new TermMatch( 'deck', TermMatch::STEM, 1 ) ),
			array( 'decks' => array( 1 => array( Fields::NAME => array( 1, 5 ) ) ), 'deck' => array( 1 => array( Fields::NAME => array( 1, 1 ) ) ) ),
			array()
		);
		$score = ( new Scorer() )->score( self::plan( self::query( 'kazumi', 'decks' ), new SearchOptions(), self::N ), self::doc(), array( self::widening( 'kazumi', TermMatch::EXACT, 1, array( Fields::NAME => array( 1, 0 ) ) ), $w ) );
		self::assertSame( 8.0, $score->phrase );
	}

	public function test_three_token_phrases(): void {
		$positions = static fn( int $a, int $b, int $c ): array => array(
			self::widening( 'a1', TermMatch::EXACT, 1, array( Fields::NAME => array( 1, $a ) ) ),
			self::widening( 'b2', TermMatch::EXACT, 1, array( Fields::NAME => array( 1, $b ) ) ),
			self::widening( 'c3', TermMatch::EXACT, 1, array( Fields::NAME => array( 1, $c ) ) ),
		);
		$q = self::query( 'a1', 'b2', 'c3' );
		self::assertSame( 8.0, ( new Scorer() )->score( self::plan( $q, new SearchOptions(), self::N ), self::doc(), $positions( 3, 4, 5 ) )->phrase );
		self::assertSame( 4.0, ( new Scorer() )->score( self::plan( $q, new SearchOptions(), self::N ), self::doc(), $positions( 3, 4, 9 ) )->phrase );
		self::assertSame( 4.0, ( new Scorer() )->score( self::plan( $q, new SearchOptions(), self::N ), self::doc(), $positions( 0, 4, 5 ) )->phrase );
		self::assertSame( 0.0, ( new Scorer() )->score( self::plan( $q, new SearchOptions(), self::N ), self::doc(), $positions( 3, 5, 4 ) )->phrase );
	}

	public function test_phrase_bonus_static(): void {
		self::assertSame( 0.0, Scorer::phraseBonus( array( Fields::NAME => array( 0 => 0 ) ), 1 ) );
		self::assertSame( 0.0, Scorer::phraseBonus( array(), 2 ) );
		self::assertSame( 0.0, Scorer::phraseBonus( array( Fields::NAME => array( 0 => 0 ) ), 2 ), 'a field missing a token earns nothing' );
		self::assertSame( 8.0, Scorer::phraseBonus( array( Fields::NAME => array( 1 => 1, 0 => 0 ) ), 2 ), 'positions are read in token order' );
		self::assertSame( 4.0, Scorer::phraseBonus( array( Fields::NAME => array( 0 => 0, 1 => 3 ), Fields::LONG => array( 0 => 3, 1 => 1 ) ), 2 ) );
	}

	public function test_exactness_bonus(): void {
		$w = array( self::widening( 'kazumi', TermMatch::EXACT, 1, array( Fields::NAME => array( 1, 0 ) ) ), self::widening( 'deck', TermMatch::EXACT, 1, array( Fields::NAME => array( 1, 1 ) ) ) );
		$q = self::query( 'kazumi', 'deck' );
		self::assertSame( 12.0, ( new Scorer() )->score( self::plan( $q, new SearchOptions(), self::N ), self::doc( 'kazumi deck' ), $w )->exact );
		self::assertSame( 0.0, ( new Scorer() )->score( self::plan( $q, new SearchOptions(), self::N ), self::doc( 'kazumi deck dealer' ), $w )->exact );
		self::assertSame( 6.0, ( new Scorer() )->score( self::plan( $q, new SearchOptions(), self::N ), self::doc( 'other', array( 'x', 'kazumi deck' ) ), $w )->exact );
		self::assertSame( 18.0, ( new Scorer() )->score( self::plan( $q, new SearchOptions(), self::N ), self::doc( 'kazumi deck', array( 'kazumi deck' ) ), $w )->exact );
	}

	public function test_stock_and_owner(): void {
		$w = array( self::widening( 'deck', TermMatch::EXACT, 1, array( Fields::NAME => array( 1, 0 ) ) ) );
		$q = self::query( 'deck' );
		self::assertSame( -6.0, ( new Scorer() )->score( self::plan( $q, new SearchOptions(), self::N ), self::doc( 'x', array(), false ), $w )->stock );
		self::assertSame( 0.0, ( new Scorer() )->score( self::plan( $q, new SearchOptions(), self::N ), self::doc( 'x', array(), true ), $w )->stock );
		$boosted = self::doc( 'x', array(), true, 3, array( 'brand:kazumi', 'category:decks', 'tag:none' ) );
		$rules   = new SearchOptions( array(), true, 'en', array( 'brand:kazumi' => 2, 'category:decks' => -1, 'tag:other' => 9 ) );
		self::assertSame( 4.0, ( new Scorer() )->score( self::plan( $q, $rules, self::N ), $boosted, $w )->owner );
		self::assertSame( 3.0, ( new Scorer() )->score( self::plan( $q, new SearchOptions(), self::N ), $boosted, $w )->owner );
		self::assertSame( -10.0, ( new Scorer() )->score( self::plan( $q, new SearchOptions(), self::N ), self::doc( 'x', array(), true, -10 ), $w )->owner );
	}

	public function test_numeric_terms_are_strings_in_the_rows(): void {
		$w     = self::widening( '825', TermMatch::EXACT, 1, array( Fields::ATTRIBUTE => array( 1, 0 ) ) );
		$score = ( new Scorer() )->score( self::plan( self::query( '825' ), new SearchOptions(), self::N ), self::doc(), array( $w ) );
		self::assertSame( '825', $score->tokens[0]['term'] );
		$term = ( new Scorer() )->scoreTerm( self::query( '825' ), new Document( 9, Document::TAG, 5, 'en', '825', '825' ), array( self::widening( '825', TermMatch::EXACT, 1, array( Fields::NAME => array( 1, 0 ) ), 9, false ) ) );
		self::assertSame( '825', $term->tokens[0]['term'] );
	}

	public function test_a_tie_between_fields_goes_to_the_first(): void {
		$w     = self::widening( 'kaz', TermMatch::EXACT, 1, array( Fields::NAME => array( 1, 0 ), Fields::SKU => array( 1, 0 ) ) );
		$score = ( new Scorer() )->score( self::plan( self::query( 'kaz' ), new SearchOptions(), self::N ), self::doc(), array( $w ) );
		self::assertSame( Fields::NAME, $score->tokens[0]['field'], 'name and SKU both weigh 10' );
		$flipped = self::widening( 'kaz', TermMatch::EXACT, 1, array( Fields::SKU => array( 1, 0 ), Fields::NAME => array( 1, 0 ) ) );
		self::assertSame( Fields::SKU, ( new Scorer() )->score( self::plan( self::query( 'kaz' ), new SearchOptions(), self::N ), self::doc(), array( $flipped ) )->tokens[0]['field'] );
	}

	public function test_phrase_positions_are_the_first_of_each_token(): void {
		// The second token matches two terms in the name: the synonym at position 1 and the word at 3.
		$second = new TokenWidening(
			array( 'board' => new TermMatch( 'board', TermMatch::EXACT, 1 ), 'deck' => new TermMatch( 'deck', TermMatch::SYNONYM, 1 ) ),
			array( 'board' => array( 1 => array( Fields::NAME => array( 1, 3 ) ) ), 'deck' => array( 1 => array( Fields::NAME => array( 1, 1 ) ) ) ),
			array()
		);
		$w     = array( self::widening( 'night', TermMatch::EXACT, 1, array( Fields::NAME => array( 1, 0 ) ) ), $second );
		$score = ( new Scorer() )->score( self::plan( self::query( 'night', 'board' ), new SearchOptions(), self::N ), self::doc( 'night deck foo board' ), $w );
		self::assertSame( 8.0, $score->phrase, 'night at 0, the second token first at 1' );
		$reversed = new TokenWidening(
			array( 'board' => new TermMatch( 'board', TermMatch::EXACT, 1 ), 'deck' => new TermMatch( 'deck', TermMatch::SYNONYM, 1 ) ),
			array( 'board' => array( 1 => array( Fields::NAME => array( 1, 1 ) ) ), 'deck' => array( 1 => array( Fields::NAME => array( 1, 3 ) ) ) ),
			array()
		);
		$w[1] = $reversed;
		self::assertSame( 8.0, ( new Scorer() )->score( self::plan( self::query( 'night', 'board' ), new SearchOptions(), self::N ), self::doc( 'night board foo deck' ), $w )->phrase );
	}

	public function test_phrase_bonus_looks_past_a_field_missing_a_token(): void {
		self::assertSame( 8.0, Scorer::phraseBonus( array( Fields::NAME => array( 0 => 0 ), Fields::LONG => array( 0 => 4, 1 => 5 ) ), 2 ) );
		self::assertSame( 4.0, Scorer::phraseBonus( array( Fields::NAME => array( 0 => 0 ), Fields::LONG => array( 0 => 4, 1 => 9 ) ), 2 ) );
		self::assertSame( 0.0, Scorer::phraseBonus( array( Fields::NAME => array( 0 => 0 ), Fields::LONG => array( 0 => 9, 1 => 4 ) ), 2 ) );
	}

	public function test_total_adds_every_part(): void {
		$w     = array( self::widening( 'kazumi', TermMatch::EXACT, 1, array( Fields::NAME => array( 1, 0 ) ) ), self::widening( 'deck', TermMatch::EXACT, 1, array( Fields::NAME => array( 1, 1 ) ) ) );
		$score = ( new Scorer() )->score( self::plan( self::query( 'kazumi', 'deck' ), new SearchOptions(), self::N ), self::doc( 'kazumi deck', array(), false, 2 ), $w );
		$token = 10 * ( 1 + log( 2 ) ) * log( 11 / 2 );
		self::assertEqualsWithDelta( 2 * $token + 8 + 12 - 6 + 2, $score->total(), 1e-9 );
	}

	public function test_term_documents_score_by_name_alone(): void {
		$scorer = new Scorer();
		$term   = new Document( 9, Document::CATEGORY, 5, 'en', 'Decks', 'decks' );
		$q      = self::query( 'decks' );
		foreach ( array( TermMatch::EXACT, TermMatch::STEM, TermMatch::SYNONYM ) as $kind ) {
			$s = $scorer->scoreTerm( $q, $term, array( self::widening( 'decks', $kind, 1, array( Fields::NAME => array( 1, 0 ) ), 9, false ) ) );
			self::assertSame( 12.0, $s->total(), $kind );
			self::assertSame( 1.0, $s->coverage );
			self::assertSame( $kind, $s->tokens[0]['kind'] );
			self::assertSame( Fields::NAME, $s->tokens[0]['field'] );
			self::assertSame( 1, $s->tokens[0]['tf'] );
		}
		self::assertSame( 7.0, $scorer->scoreTerm( $q, $term, array( self::widening( 'decks', TermMatch::PREFIX, 1, array( Fields::NAME => array( 1, 0 ) ), 9, false ) ) )->total() );
		self::assertSame( 4.0, $scorer->scoreTerm( $q, $term, array( self::widening( 'decks', TermMatch::TYPO1, 1, array( Fields::NAME => array( 1, 0 ) ), 9, false ) ) )->total() );
		self::assertSame( 4.0, $scorer->scoreTerm( $q, $term, array( self::widening( 'decks', TermMatch::TYPO2, 1, array( Fields::NAME => array( 1, 0 ) ), 9, false ) ) )->total() );
		$other = $scorer->scoreTerm( $q, $term, array( self::widening( 'decks', TermMatch::EXACT, 1, array( Fields::LONG => array( 1, 0 ) ), 9, false ) ) );
		self::assertSame( 0.0, $other->total(), 'a description match does not count' );
		self::assertSame( 0.0, $other->coverage );
		$product = $scorer->scoreTerm( $q, $term, array( self::widening( 'decks', TermMatch::EXACT, 1, array( Fields::NAME => array( 1, 0 ) ), 9, true ) ) );
		self::assertSame( 0.0, $product->total(), 'product postings are not term postings' );
	}

	public function test_term_rows_carry_every_column(): void {
		$scorer = new Scorer();
		$term   = new Document( 9, Document::CATEGORY, 5, 'en', 'Decks', 'decks' );
		$s      = $scorer->scoreTerm( self::query( 'decks' ), $term, array( self::widening( 'decks', TermMatch::EXACT, 3, array( Fields::NAME => array( 2, 0 ) ), 9, false ) ) );
		self::assertSame(
			array( 'token' => 'decks', 'term' => 'decks', 'kind' => TermMatch::EXACT, 'field' => Fields::NAME, 'weight' => 0, 'm' => 1.0, 'tf' => 2, 'df' => 3, 'score' => 12.0 ),
			$s->tokens[0]
		);
		// A term posting on another field is skipped, the next term still counts; equal values keep the first term.
		$w = new TokenWidening(
			array( 'decking' => new TermMatch( 'decking', TermMatch::STEM, 1 ), 'decks' => new TermMatch( 'decks', TermMatch::EXACT, 1 ), 'deck' => new TermMatch( 'deck', TermMatch::STEM, 1 ) ),
			array(),
			array(
				'decking' => array( 9 => array( Fields::LONG => array( 1, 0 ) ) ),
				'decks'   => array( 9 => array( Fields::NAME => array( 1, 0 ) ) ),
				'deck'    => array( 9 => array( Fields::NAME => array( 1, 0 ) ) ),
			)
		);
		$two = $scorer->scoreTerm( self::query( 'decks' ), $term, array( $w ) );
		self::assertSame( 12.0, $two->total() );
		self::assertSame( 'decks', $two->tokens[0]['term'] );
	}

	public function test_term_score_sums_tokens_and_keeps_the_best_kind_and_coverage(): void {
		$scorer = new Scorer();
		$term   = new Document( 9, Document::CATEGORY, 5, 'en', 'Planches à roulettes', 'planches roulettes' );
		$w      = new TokenWidening(
			array( 'planches' => new TermMatch( 'planches', TermMatch::PREFIX, 1 ), 'planch' => new TermMatch( 'planch', TermMatch::STEM, 1 ) ),
			array(),
			array( 'planches' => array( 9 => array( Fields::NAME => array( 1, 0 ) ) ), 'planch' => array( 9 => array( Fields::NAME => array( 1, 0 ) ) ) )
		);
		$one = $scorer->scoreTerm( self::query( 'planche' ), $term, array( $w ) );
		self::assertSame( 12.0, $one->total(), 'the stem beats the prefix' );
		self::assertSame( 'planch', $one->tokens[0]['term'] );
		self::assertSame( 0.5, $one->coverage );
		$two = $scorer->scoreTerm( self::query( 'planche', 'roulettes' ), $term, array( $w, self::widening( 'roulettes', TermMatch::TYPO1, 1, array( Fields::NAME => array( 1, 1 ) ), 9, false ) ) );
		self::assertSame( 16.0, $two->total() );
		self::assertSame( 1.0, $two->coverage );
		$none = $scorer->scoreTerm( self::query( 'x' ), new Document( 9, Document::TAG, 1, 'en', '' ), array( self::widening( 'x', TermMatch::EXACT, 1, array( Fields::NAME => array( 1, 0 ) ), 9, false ) ) );
		self::assertSame( 0.0, $none->coverage, 'no name, no coverage' );
		self::assertSame( 12.0, $none->total() );
	}
}
