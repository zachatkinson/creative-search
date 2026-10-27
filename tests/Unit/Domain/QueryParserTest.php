<?php
/**
 * Query parser tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Domain;

use CreativeBranding\Search\Domain\Query;
use CreativeBranding\Search\Domain\QueryParser;
use CreativeBranding\Search\Domain\QueryToken;
use CreativeBranding\Search\Domain\Synonym;
use CreativeBranding\Search\Domain\Synonyms;
use CreativeBranding\Search\Domain\Tokenizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every parsing rule of the spec: synonyms, stop words, phrases, minus words, the prefix, the caps.
 */
final class QueryParserTest extends TestCase {

	private static function parser(): QueryParser {
		return new QueryParser( new Tokenizer(), new Synonyms( array( new Synonym( 'en', 'board', 'deck' ), new Synonym( 'en', 'tee', 't-shirt' ) ) ) );
	}

	/**
	 * Tokens as compact strings: text, then flags (P prefix, N negated, S stop word), then phrase number, then synonyms.
	 *
	 * @param QueryToken[] $tokens Tokens.
	 * @return string[]
	 */
	private static function flat( array $tokens ): array {
		return array_map(
			static fn( QueryToken $t ): string => $t->text
				. ( $t->prefix ? ' P' : '' ) . ( $t->negated ? ' N' : '' ) . ( $t->stopWord ? ' S' : '' )
				. ( $t->phrase > 0 ? ' "' . $t->phrase : '' )
				. ( array() !== $t->synonyms ? ' =' . implode( ',', $t->synonyms ) : '' )
				. ( array() !== $t->variants ? ' |' . implode( ',', $t->variants ) : '' ),
			$tokens
		);
	}

	/**
	 * @return array<string, array{string, string[], string[]}>
	 */
	public static function cases(): array {
		return array(
			'plain'                     => array( 'kazumi deck', array( 'kazumi', 'deck P' ), array() ),
			'trailing space: no prefix' => array( 'kazumi deck ', array( 'kazumi', 'deck' ), array() ),
			'one char last: no prefix'  => array( 'size 8', array( 'size', '8' ), array() ),
			'two chars is a prefix'     => array( 'ka', array( 'ka P' ), array() ),
			'stop words dropped'        => array( 'the deck of the year', array( 'deck', 'year P' ), array() ),
			'all stop words kept'       => array( 'the and', array( 'the S', 'and S' ), array() ),
			'phrase'                    => array( '"horror party" deck', array( 'horror "1', 'party "1', 'deck P' ), array() ),
			'phrase last: no prefix'    => array( 'deck "horror party"', array( 'deck', 'horror "1', 'party "1' ), array() ),
			'two phrases'               => array( '"ab cd" "ef gh"', array( 'ab "1', 'cd "1', 'ef "2', 'gh "2' ), array() ),
			'curly quotes'              => array( '“horror party”', array( 'horror "1', 'party "1' ), array() ),
			'unpaired quote ignored'    => array( '"horror party', array( 'horror', 'party P' ), array() ),
			'empty phrase'              => array( '"" deck', array( 'deck P' ), array() ),
			'inch mark is not a quote'  => array( '8.25" deck', array( '8.25', 'deck P' ), array() ),
			'inch mark then phrase'     => array( '8.25" "horror party"', array( '8.25', 'horror "1', 'party "1' ), array() ),
			'minus word'                => array( 'kazumi -dealer', array( 'kazumi' ), array( 'dealer N' ) ),
			'minus last: no prefix'     => array( 'kazumi -dea', array( 'kazumi' ), array( 'dea N' ) ),
			'minus stop word kept'      => array( 'kazumi -the', array( 'kazumi' ), array( 'the N S' ) ),
			'hyphen inside a word'      => array( 'KAZ-DEA-00001', array( 'kaz', 'dea', '00001 P' ), array() ),
			'minus alone'               => array( 'kazumi -', array( 'kazumi' ), array() ),
			'minus then quote'          => array( '-"ab"', array( 'ab P' ), array() ),
			'synonym added'             => array( 'kazumi board', array( 'kazumi', 'board P =deck' ), array() ),
			'synonym multi token'       => array( 'skatehouse tee', array( 'skatehouse', 'tee P =shirt' ), array() ),
			'apostrophe variants'       => array( "philly's rascals", array( 'phillys |philly', 'rascals P' ), array() ),
			'accents folded'            => array( 'Québécois', array( 'quebecois P' ), array() ),
			'empty'                     => array( '', array(), array() ),
			'spaces only'               => array( '   ', array(), array() ),
			'punctuation only'          => array( '--- ...', array(), array() ),
			'french stop words'         => array( 'chandail à capuchon', array( 'chandail', 'capuchon P' ), array(), 'fr' ),
			// D-098: a unit after a space joins its number before the query is cut into chunks.
			'inches spaced'             => array( '8.25 in', array( '8.25' ), array() ),
			'inch spelled'              => array( '8.25 inch deck', array( '8.25', 'deck P' ), array() ),
			'trailing zero'             => array( '8.50 deck', array( '8.5', 'deck P' ), array() ),
			'millimetres spaced'        => array( '54 mm wheels', array( '54mm |54', 'wheels P' ), array() ),
			'millimetres attached'      => array( '54mm wheels', array( '54mm |54', 'wheels P' ), array() ),
			'pair spaced'               => array( '33 X 9 grip', array( '33', '9', 'grip P' ), array() ),
			'pair attached'             => array( '9x33 grip', array( '9', '33', 'grip P' ), array() ),
			'minus a size'              => array( 'wheels -54 mm', array( 'wheels' ), array( '54mm N |54' ) ),
			'french inches'             => array( 'planche 8,25 po', array( 'planche', '8.25' ), array(), 'fr' ),
			// D-133: entities decoded as the indexed text's are, before the query is cut into chunks.
			'entity apostrophe'         => array( 'Philly&#8217;s hoodie', array( 'phillys |philly', 'hoodie P' ), array() ),
			'entity ampersand'          => array( 'rock &amp; roll', array( 'rock', 'roll P' ), array() ),
			'entity quotes'             => array( '&quot;horror party&quot;', array( 'horror "1', 'party "1' ), array() ),
		);
	}

	#[DataProvider( 'cases' )]
	public function test_parses( string $raw, array $tokens, array $excluded, string $lang = 'en' ): void {
		$q = self::parser()->parse( $raw, $lang );
		self::assertSame( $tokens, self::flat( $q->tokens ) );
		self::assertSame( $excluded, self::flat( $q->excluded ) );
		self::assertSame( StopWords( $lang ), $q->lang );
	}

	public function test_all_stop_words_flag_and_no_widening(): void {
		$q = self::parser()->parse( 'the', 'en' );
		self::assertTrue( $q->allStopWords );
		self::assertFalse( $q->isEmpty() );
		self::assertFalse( $q->tokens[0]->prefix, 'a stop word alone matches exactly or not at all (D-026)' );
		self::assertFalse( self::parser()->parse( 'the deck', 'en' )->allStopWords );
		self::assertFalse( self::parser()->parse( '', 'en' )->allStopWords );
		self::assertTrue( self::parser()->parse( '', 'en' )->isEmpty() );
	}

	public function test_caps_at_200_characters(): void {
		$raw = str_repeat( 'deck ', 50 ) . 'wheels';
		$q   = self::parser()->parse( $raw, 'en' );
		self::assertSame( 199, mb_strlen( $q->text ), 'two hundred characters, the trailing space trimmed' );
		self::assertSame( rtrim( mb_substr( $raw, 0, 200 ) ), $q->text );
		self::assertStringNotContainsString( 'wheels', $q->text );
		self::assertCount( Query::MAX_TOKENS, $q->tokens );
		self::assertFalse( $q->tokens[ count( $q->tokens ) - 1 ]->prefix, 'the typed last token was cut away' );
		$exact = self::parser()->parse( str_repeat( 'é', 199 ) . 'x', 'en' );
		self::assertSame( 200, mb_strlen( $exact->text ) );
		self::assertTrue( $exact->tokens[0]->prefix, 'exactly 200 characters is within the cap' );
	}

	public function test_caps_at_8_tokens_and_the_rest_is_ignored(): void {
		$q = self::parser()->parse( 'a1 b2 c3 d4 e5 f6 g7 h8 i9 j10', 'en' );
		self::assertSame( array( 'a1', 'b2', 'c3', 'd4', 'e5', 'f6', 'g7', 'h8' ), array_map( static fn( QueryToken $t ): string => $t->text, $q->tokens ) );
		self::assertFalse( $q->tokens[7]->prefix, 'the eighth token is not the one being typed' );
		$eight = self::parser()->parse( 'a1 b2 c3 d4 e5 f6 g7 h8', 'en' );
		self::assertTrue( $eight->tokens[7]->prefix, 'exactly eight keeps the prefix' );
		$stops = self::parser()->parse( 'the a1 b2 c3 d4 e5 f6 g7 h8', 'en' );
		self::assertCount( 8, $stops->tokens, 'stop words do not count against the cap' );
		self::assertTrue( $stops->tokens[7]->prefix );
	}

	public function test_text_is_trimmed_and_kept_as_typed(): void {
		$q = self::parser()->parse( '  Kazumi  Deck ', 'en' );
		self::assertSame( 'Kazumi  Deck', $q->text );
		self::assertSame( 0, $q->tokens[0]->phrase, 'a plain token belongs to no phrase' );
		$led = self::parser()->parse( '   ' . str_repeat( 'é', 199 ) . 'x', 'en' );
		self::assertSame( 200, mb_strlen( $led->text ), 'leading blanks do not count against the cap' );
		self::assertTrue( $led->tokens[0]->prefix );
	}

	public function test_any_unicode_blank_at_the_end_closes_the_last_token(): void {
		$q = self::parser()->parse( "deck\u{00A0}", 'en' );
		self::assertSame( array( 'deck' ), self::flat( $q->tokens ) );
	}

	public function test_an_unpaired_quote_is_a_blank(): void {
		self::assertSame( array( 'night', 'shift P' ), self::flat( self::parser()->parse( 'night"shift', 'en' )->tokens ) );
		self::assertSame( array( 'night P' ), self::flat( self::parser()->parse( '"night', 'en' )->tokens ), 'not a phrase, so it is the token being typed' );
		self::assertSame( array( 'night P' ), self::flat( self::parser()->parse( 'night"', 'en' )->tokens ) );
		self::assertSame( array( 'night "1', 'shift "1', 'deck P' ), self::flat( self::parser()->parse( '"night shift" deck"', 'en' )->tokens ), 'the last quote is the unpaired one' );
	}

	public function test_key_phrases_and_without(): void {
		$q = self::parser()->parse( "philly's \"horror party\" deck -blank", 'en' );
		self::assertSame( 'phillys horror party deck', $q->key(), 'one spelling per position' );
		self::assertSame( array( 1 => array( 1, 2 ) ), $q->phrases() );
		$without = $q->without( 0 );
		self::assertSame( '"horror party" deck -blank', $without->text );
		self::assertSame( array( 1 => array( 0, 1 ) ), $without->phrases() );
		self::assertSame( 'en', $without->lang );
		self::assertCount( 1, $without->excluded );
		self::assertSame( 'phillys "horror party" -blank', $q->without( 3 )->text );
		self::assertSame( 'phillys deck -blank', $q->without( 1 )->without( 1 )->text, 'a one-token phrase is no phrase and loses its quotes' );
		self::assertSame( array(), $q->without( 1 )->phrases(), 'a phrase of one token is no phrase' );
		self::assertSame( 'québécois', self::parser()->parse( 'Québécois', 'en' )->without( 5 )->text, 'the source spelling is written back, lower-cased' );
	}

	public function test_rewritten_replaces_words_and_leaves_one_out(): void {
		$q = self::parser()->parse( 'phillys "horor party" deck -blank', 'en' );
		self::assertSame( 'phillys "horror party" deck -blank', $q->rewritten( array( 1 => 'horror' ) ), 'the typo is replaced inside its phrase' );
		self::assertSame( 'phillys "horror party" -blank', $q->rewritten( array( 1 => 'horror' ), 3 ) );
		self::assertSame( 'phillys horror deck -blank', $q->rewritten( array( 1 => 'horror' ), 2 ), 'a phrase left with one word loses its quotes' );
		self::assertSame( $q->without( 0 )->text, $q->rewritten( array(), 0 ), 'with no word replaced, the same as without()' );
		self::assertSame( 'phillys "horor party" deck -blank', $q->rewritten( array() ) );
		self::assertSame( 'phillys "horror party" deck -blank', $q->rewritten( array( 1 => 'horror' ), 9 ), 'an index out of range leaves nothing out' );
	}

	public function test_render_of_an_empty_query(): void {
		self::assertSame( '', Query::render( array(), array() ) );
		$empty = new Query( '', 'en', array() );
		self::assertFalse( $empty->allStopWords );
		self::assertSame( array(), $empty->excluded );
		self::assertTrue( $empty->isEmpty() );
		self::assertSame( '-deck', Query::render( array(), array( new QueryToken( 'deck', 'deck', true ) ) ) );
	}

	public function test_query_token_shapes(): void {
		$word = new QueryToken( 'kazumi', 'kazumi' );
		self::assertFalse( $word->negated );
		self::assertSame( 0, $word->phrase );
		self::assertFalse( $word->prefix );
		self::assertFalse( $word->stopWord );
		self::assertSame( array(), $word->synonyms );
		self::assertSame( array(), $word->variants );
		$num  = new QueryToken( '8.25', '8.25' );
		$sku  = new QueryToken( '54mm', '54mm' );
		$shrt = new QueryToken( 'dck', 'dck' );
		self::assertTrue( $word->fuzzable() );
		self::assertTrue( $word->stemmable() );
		self::assertFalse( $num->fuzzable() );
		self::assertFalse( $num->stemmable() );
		self::assertFalse( $sku->fuzzable() );
		self::assertFalse( $sku->stemmable() );
		self::assertFalse( $shrt->fuzzable(), 'three characters are never fuzzed' );
		self::assertTrue( $shrt->stemmable() );
		$copy = $word->withSynonyms( array( 'deck' ) );
		self::assertSame( array( 'deck' ), $copy->synonyms );
		self::assertSame( array(), $word->synonyms );
		$prefixed = new QueryToken( 'ka', 'ka', false, 2, true, false, array( 'x' ), array( 'y' ) );
		$plain    = $prefixed->withoutPrefix();
		self::assertFalse( $plain->prefix );
		self::assertSame( 2, $plain->phrase );
		self::assertSame( array( 'x' ), $plain->synonyms );
		self::assertSame( array( 'y' ), $plain->variants );
		self::assertSame( array( 'y' ), $prefixed->withSynonyms( array() )->variants );
		self::assertFalse( $word->synonym );
		$put = new QueryToken( 'steel', 'steel', false, 0, true, false, array(), array(), true );
		self::assertTrue( $put->withSynonyms( array( 'x' ) )->synonym, 'a copy keeps the mark' );
		self::assertTrue( $put->withoutPrefix()->synonym );
	}

	/**
	 * A parser with phrase synonyms (D-109).
	 */
	private static function phrased(): QueryParser {
		return new QueryParser(
			new Tokenizer(),
			new Synonyms(
				array(
					new Synonym( 'en', 'ss', 'stainless steel' ),
					new Synonym( 'en', 'skate shoes', 'sneakers', true ),
					new Synonym( 'en', 'steel', 'inox' ),
					new Synonym( 'en', 'grip tape', 'griptape' ),
					new Synonym( 'en', 'bolts', 'hardware kit' ),
					new Synonym( 'fr', 'ss', 'acier inoxydable' ),
				)
			)
		);
	}

	public function test_a_phrase_synonym_gives_the_query_another_form(): void {
		$q = self::phrased()->parse( '-rusty ss bearings', 'en' );
		self::assertSame( array( 'ss', 'bearings P' ), self::flat( $q->tokens ), 'the query itself is unchanged' );
		self::assertCount( 1, $q->alternatives );
		$alt = $q->alternatives[0];
		self::assertSame( array( 'stainless', 'steel =inox', 'bearings P' ), self::flat( $alt->tokens ), 'the meaning in place, with its own one-word synonyms; the word being typed still a prefix' );
		self::assertSame( 'stainless steel bearings -rusty', $alt->text );
		self::assertSame( 'en', $alt->lang );
		self::assertCount( 1, $alt->excluded, 'the minus words stay' );
		self::assertTrue( $alt->tokens[0]->synonym );
		self::assertTrue( $alt->tokens[1]->synonym );
		self::assertFalse( $alt->tokens[2]->synonym );
		self::assertSame( array(), $alt->alternatives, 'one level only' );
		self::assertFalse( $alt->allStopWords );
	}

	public function test_a_phrase_of_the_query_is_rewritten_where_it_stands(): void {
		$q = self::phrased()->parse( 'black skate shoes size 9', 'en' );
		self::assertSame( array( 'black sneakers size 9' ), array_map( static fn( Query $a ): string => implode( ' ', self::flat( $a->tokens ) ), $q->alternatives ) );
		self::assertSame( 'black sneakers size 9', $q->alternatives[0]->text );
		$back = self::phrased()->parse( 'sneakers ', 'en' );
		self::assertSame( 'skate shoes', $back->alternatives[0]->text, 'both ways' );
		self::assertSame( array( 'skate', 'shoes' ), self::flat( $back->alternatives[0]->tokens ), 'never a prefix' );
		self::assertSame( array(), self::phrased()->parse( 'skate black shoes', 'en' )->alternatives, 'side by side only' );
		self::assertSame( array(), self::phrased()->parse( 'skate sho', 'en' )->alternatives, 'a word still being typed matches only when complete' );
	}

	public function test_alternatives_keep_the_phrase_and_the_caps(): void {
		$q = self::phrased()->parse( '"ss bearings" spacers', 'en' );
		self::assertSame( array( 'stainless "1', 'steel "1 =inox', 'bearings "1', 'spacers P' ), self::flat( $q->alternatives[0]->tokens ), 'inside quotes, the words put in join the phrase' );
		$long = self::phrased()->parse( 'a1 b1 c1 d1 e1 f1 g1 ss', 'en' );
		self::assertSame( 8, count( $long->alternatives[0]->tokens ), 'eight tokens at most' );
		self::assertSame( 'stainless', $long->alternatives[0]->tokens[7]->text );
		$many = self::phrased()->parse( 'ss skate shoes grip tape bolts', 'en' );
		self::assertCount( 3, $many->alternatives, 'three other forms at most' );
		self::assertSame( 'stainless steel skate shoes grip tape bolts', $many->alternatives[0]->text, 'one rule a form, in the rules’ order' );
		self::assertSame( 'ss sneakers grip tape bolts', $many->alternatives[1]->text );
		self::assertSame( 'ss skate shoes griptape bolts', $many->alternatives[2]->text );
		self::assertSame( 'acier inoxydable', self::phrased()->parse( 'ss', 'fr' )->alternatives[0]->text, 'per language' );
		self::assertSame( array(), self::phrased()->parse( '-ss deck', 'en' )->alternatives, 'a minus word is not rewritten' );
	}

	public function test_default_dependencies(): void {
		$q = ( new QueryParser() )->parse( 'kazumi board', 'EN' );
		self::assertSame( array( 'kazumi', 'board P' ), self::flat( $q->tokens ), 'no synonyms by default' );
		self::assertSame( 'en', $q->lang );
	}
}

/**
 * The language code a query ends with.
 *
 * @param string $lang Language code.
 */
function StopWords( string $lang ): string {
	return \CreativeBranding\Search\Domain\StopWords::code( $lang );
}
