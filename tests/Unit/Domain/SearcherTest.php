<?php
/**
 * Searcher tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Domain;

use CreativeBranding\Search\Domain\Analyzer;
use CreativeBranding\Search\Domain\Document;
use CreativeBranding\Search\Domain\Fields;
use CreativeBranding\Search\Domain\Hit;
use CreativeBranding\Search\Domain\Kind;
use CreativeBranding\Search\Domain\Query;
use CreativeBranding\Search\Domain\QueryParser;
use CreativeBranding\Search\Domain\QueryToken;
use CreativeBranding\Search\Domain\Score;
use CreativeBranding\Search\Domain\Scorer;
use CreativeBranding\Search\Domain\Searcher;
use CreativeBranding\Search\Domain\SearchOptions;
use CreativeBranding\Search\Domain\SearchResult;
use CreativeBranding\Search\Domain\Synonym;
use CreativeBranding\Search\Domain\Synonyms;
use CreativeBranding\Search\Domain\TermMatch;
use CreativeBranding\Search\Domain\Tokenizer;
use CreativeBranding\Search\Tests\Support\MemoryIndex;
use PHPUnit\Framework\TestCase;

/**
 * Widening, AND, minus words, phrases, the dropped token, hidden products, the fallback language, the order
 * and the limits, against a small hand-built index.
 */
final class SearcherTest extends TestCase {

	private MemoryIndex $index;
	private Analyzer $analyzer;
	private int $nextId = 0;

	protected function setUp(): void {
		$this->analyzer = new Analyzer();
		$this->index    = new MemoryIndex();
		$this->nextId   = 0;
		// Products, English.
		$this->product( 'KAZUMI Skate Deck, Dealer', 'KAZ-DEA-00001', array( 'KAZ-DEA-00001-825' ), true, 340, array( '8.25' ), 'Seven plies of Canadian maple.' );
		$this->product( 'KAZUMI Skate Deck, Ghost Tiger', 'KAZ-GTO-00022', array(), false, 30, array( '8.25' ) );
		$this->product( 'KAZUMI Skate Deck, Dealer Blank', 'KAZ-DBA-00021', array(), true, 0, array( '8.25' ), '', false );
		$this->product( 'Orbit Wheels, Classic', 'ORB-CLA-00007', array(), true, 260, array( '54mm', '99a' ) );
		$this->product( 'Keel Bearings, Seven', 'KEL-SEV-00010', array(), true, 300, array( 'ABEC 7' ) );
		$this->product( 'Skatehouse Hoodie, Logo', 'SKH-SLO-00012', array(), true, 220, array( 'M' ), 'A hoodie with the shop logo printed large and a deck on the back.' );
		// Products, French.
		$this->product( 'Planche KAZUMI, Dealer', 'KAZ-DEA-00001', array(), true, 340, array( '8.25' ), '', true, 'fr' );
		$this->product( 'Roues Orbit, Classic', 'ORB-CLA-00007', array(), true, 260, array( '54mm' ), '', true, 'fr' );
		// Categories, tags and brands.
		$this->term( Document::CATEGORY, 'Decks', 31 );
		$this->term( Document::CATEGORY, 'Skateboards', 150 );
		$this->term( Document::BRAND, 'KAZUMI', 20 );
		$this->term( Document::TAG, 'popsicle', 40 );
		$this->term( Document::CATEGORY, 'Planches', 31, 'fr' );
		$this->term( Document::CATEGORY, 'Planches à roulettes', 150, 'fr' );
		$this->term( Document::CATEGORY, 'Planches complètes', 12, 'fr' );
		$this->term( Document::BRAND, 'KAZUMI', 20, 'fr' );
	}

	/**
	 * Add a product document.
	 *
	 * @param string   $name       Name.
	 * @param string   $sku        SKU.
	 * @param string[] $variations Variation SKUs.
	 * @param bool     $inStock    Stock.
	 * @param int      $sales      Sales.
	 * @param string[] $attributes Attribute values.
	 * @param string   $long       Long description.
	 * @param bool     $visible    Visibility.
	 * @param string   $lang       Language.
	 */
	private function product( string $name, string $sku, array $variations, bool $inStock, int $sales, array $attributes, string $long = '', bool $visible = true, string $lang = 'en' ): Document {
		$id        = ++$this->nextId;
		$tokenizer = $this->analyzer->tokenizer();
		$skus      = array_merge( array( $sku ), $variations );
		$brand     = explode( ' ', $name )[0];
		$document  = new Document(
			$id,
			Document::PRODUCT,
			(int) substr( $sku, -5 ),
			$lang,
			$name,
			$tokenizer->key( $name, $lang ),
			array_map( static fn( string $s ): string => $tokenizer->key( $s, $lang ), $skus ),
			$inStock,
			$visible,
			0,
			$sales,
			$id,
			array( 'brand:' . strtolower( $brand ) )
		);
		$this->index->add(
			$document,
			$this->analyzer->analyze(
				array(
					Fields::NAME      => $name,
					Fields::SKU       => $skus,
					Fields::LONG      => $long,
					Fields::BRAND     => $brand,
					Fields::ATTRIBUTE => $attributes,
				),
				$lang
			)
		);
		return $document;
	}

	/**
	 * Add a category, tag or brand document.
	 *
	 * @param string $type  Type.
	 * @param string $name  Name.
	 * @param int    $count Product count.
	 * @param string $lang  Language.
	 */
	private function term( string $type, string $name, int $count, string $lang = 'en', string $description = '' ): void {
		$id = ++$this->nextId;
		$this->index->add(
			new Document( $id, $type, $id, $lang, $name, $this->analyzer->tokenizer()->key( $name, $lang ), array(), true, true, 0, $count ),
			$this->analyzer->analyze(
				array(
					Fields::NAME  => $name,
					Fields::SHORT => $description,
				),
				$lang
			)
		);
	}

	private function search( string $raw, string $lang = 'en', ?SearchOptions $options = null, ?Synonyms $synonyms = null ): SearchResult {
		$synonyms ??= new Synonyms( array() );
		$parser     = new QueryParser( new Tokenizer(), $synonyms );
		return ( new Searcher( $this->index, new Analyzer(), new Scorer(), $synonyms ) )->search( $parser->parse( $raw, $lang ), $options ?? new SearchOptions() );
	}

	/**
	 * Product titles in order.
	 *
	 * @param SearchResult $r Result.
	 * @return string[]
	 */
	private static function titles( SearchResult $r ): array {
		return array_map( static fn( Hit $h ): string => $h->document->title, $r->products );
	}

	/**
	 * Term titles in order.
	 *
	 * @param SearchResult $r Result.
	 * @return string[]
	 */
	private static function terms( SearchResult $r ): array {
		return array_map( static fn( Hit $h ): string => $h->document->title, $r->terms );
	}

	public function test_empty_query_finds_nothing(): void {
		$r = $this->search( '', 'fr' );
		self::assertTrue( $r->isEmpty() );
		self::assertSame( 'fr', $r->lang, 'no fallback for an empty query' );
		self::assertNull( $r->corrected );
	}

	public function test_exact_tokens_and_the_hidden_product(): void {
		$r = $this->search( 'kazumi dealer' );
		self::assertSame( array( 'KAZUMI Skate Deck, Dealer' ), self::titles( $r ), 'the Blank is hidden' );
		self::assertSame( array( 'KAZUMI' ), self::terms( $r ) );
		self::assertNull( $r->corrected );
		self::assertSame( array( 1 ), $r->productIds() );
		$hit = $r->products[0];
		self::assertSame( TermMatch::EXACT, $hit->score->tokens[0]['kind'] );
		self::assertSame( 4.0, $hit->score->phrase, 'kazumi and dealer are in order in the name, positions 0 and 3' );
	}

	public function test_hidden_products_appear_only_for_their_exact_sku_when_allowed(): void {
		$on = $this->search( 'KAZ-DBA-00021', 'en', new SearchOptions( hiddenBySku: true ) );
		self::assertSame( array( 'KAZUMI Skate Deck, Dealer Blank' ), self::titles( $on ) );
		self::assertSame( 6.0, $on->products[0]->score->exact );
		self::assertContains( 'kaz dba 00021', $this->index->keysAsked, 'the store reads that one SKU key, not every key' );
		$off = $this->search( 'KAZ-DBA-00021', 'en', new SearchOptions( hiddenBySku: false ) );
		self::assertSame( array(), self::titles( $off ) );
		self::assertNull( $off->corrected, 'the retry found nothing visible either' );
		$partial = $this->search( 'kaz dba', 'en', new SearchOptions( hiddenBySku: true ) );
		self::assertNotContains( 'KAZUMI Skate Deck, Dealer Blank', self::titles( $partial ), 'only the whole SKU' );
		self::assertSame( 'kaz', $partial->corrected, 'dba found nothing visible, so it was dropped' );
		$variation = $this->search( 'kaz-dea-00001-825' );
		self::assertSame( array( 'KAZUMI Skate Deck, Dealer' ), self::titles( $variation ), 'a variation SKU finds its parent once' );
		self::assertSame( 6.0, $variation->products[0]->score->exact );
	}

	public function test_stems_match_at_their_weight(): void {
		$r = $this->search( 'decks ' );
		self::assertSame( array( 'KAZUMI Skate Deck, Dealer', 'KAZUMI Skate Deck, Ghost Tiger', 'Skatehouse Hoodie, Logo' ), self::titles( $r ), 'a sold-out name match still beats a description match' );
		self::assertSame( TermMatch::STEM, $r->products[0]->score->tokens[0]['kind'] );
		self::assertSame( Fields::LONG, $r->products[2]->score->tokens[0]['field'] );
		self::assertSame( 'deck', $r->products[0]->score->tokens[0]['term'] );
		self::assertSame( 0.9, $r->products[0]->score->tokens[0]['m'] );
		self::assertSame( array( 'Decks' ), self::terms( $r ) );
		self::assertSame( 12.0, $r->terms[0]->score->total(), 'a stem is as good as exact for a category' );
	}

	public function test_synonyms_add_alternatives_at_their_weight(): void {
		$synonyms = new Synonyms( array( new Synonym( 'en', 'board', 'deck' ) ) );
		$r        = $this->search( 'kazumi board', 'en', null, $synonyms );
		self::assertSame( array( 'KAZUMI Skate Deck, Dealer', 'KAZUMI Skate Deck, Ghost Tiger' ), self::titles( $r ) );
		self::assertSame( TermMatch::SYNONYM, $r->products[0]->score->tokens[1]['kind'] );
		self::assertSame( 0.8, $r->products[0]->score->tokens[1]['m'] );
		self::assertNull( $r->corrected );
		$plain = $this->search( 'kazumi board' );
		self::assertSame( 'kazumi', $plain->corrected, 'without the synonym, board finds nothing and is dropped' );
		self::assertSame( TermMatch::EXACT, $plain->products[0]->score->tokens[0]['kind'] );
	}

	public function test_prefix_terms_join_the_exact_level_for_the_last_token(): void {
		$r = $this->search( 'kaz' );
		self::assertSame( array( 'KAZUMI Skate Deck, Dealer', 'KAZUMI Skate Deck, Ghost Tiger' ), self::titles( $r ) );
		self::assertSame( 'kaz', $r->products[0]->score->tokens[0]['term'], 'the SKU prefix is an exact term' );
		self::assertSame( TermMatch::EXACT, $r->products[0]->score->tokens[0]['kind'] );
		self::assertSame( 1.0, $r->products[0]->score->tokens[0]['m'] );
		self::assertSame( array( 'KAZUMI' ), self::terms( $r ), 'and the brand comes through the prefix (D-033)' );
		self::assertSame( 7.0, $r->terms[0]->score->total() );
		$typed = $this->search( 'kaz ' );
		self::assertSame( array(), self::terms( $typed ), 'a space after the word ends the prefix' );
		self::assertCount( 2, $typed->products );
	}

	public function test_prefix_alone_when_no_exact_term_exists(): void {
		$r = $this->search( 'hoo' );
		self::assertSame( array( 'Skatehouse Hoodie, Logo' ), self::titles( $r ) );
		self::assertSame( TermMatch::PREFIX, $r->products[0]->score->tokens[0]['kind'] );
		self::assertSame( 0.7, $r->products[0]->score->tokens[0]['m'] );
		self::assertSame( array(), self::titles( $this->search( 'hoo ' ) ) );
	}

	public function test_prefix_terms_are_capped_by_frequency(): void {
		for ( $i = 0; $i < 25; $i++ ) {
			$this->product( 'Thing ' . chr( 97 + $i ) . 'x', sprintf( 'T-%05d', 900 + $i ), array(), true, 0, array( 'pref' . $i . 'z' ) );
		}
		$widening = ( new Searcher( $this->index ) )->widen( new QueryToken( 'pref', 'pref', false, 0, true ), 'en' );
		self::assertCount( Searcher::PREFIX_TERMS, $widening->matches );
	}

	public function test_typo_candidates_when_nothing_else_matches(): void {
		self::assertSame( TermMatch::STEM, $this->search( 'kazumy' )->products[0]->score->tokens[0]['kind'], 'kazumy stems to kazumi before any typo is tried' );
		$one = $this->search( 'kazuni' );
		self::assertSame( array( 'KAZUMI Skate Deck, Dealer', 'KAZUMI Skate Deck, Ghost Tiger' ), self::titles( $one ) );
		self::assertSame( TermMatch::TYPO1, $one->products[0]->score->tokens[0]['kind'] );
		self::assertSame( 0.5, $one->products[0]->score->tokens[0]['m'] );
		self::assertSame( array( 'KAZUMI' ), self::terms( $one ) );
		self::assertSame( 4.0, $one->terms[0]->score->total() );
		$two = $this->search( 'skatehoyze ' );
		self::assertSame( array( 'Skatehouse Hoodie, Logo' ), self::titles( $two ) );
		self::assertSame( TermMatch::TYPO2, $two->products[0]->score->tokens[0]['kind'] );
		self::assertSame( 0.3, $two->products[0]->score->tokens[0]['m'] );
		$swap = $this->search( 'dekc ' );
		self::assertSame( 'deck', $swap->products[0]->score->tokens[0]['term'] );
		self::assertSame( TermMatch::TYPO1, $swap->products[0]->score->tokens[0]['kind'], 'a transposition is one edit' );
	}

	public function test_a_typo_says_what_the_results_are_for(): void {
		$one = $this->search( 'kazuni' );
		self::assertSame( 'kazumi', $one->corrected, 'the typo read as the term it matched' );
		self::assertFalse( $one->dropped, 'nothing was left out: the exact words find the same' );
		self::assertNull( $this->search( 'kazumy' )->corrected, 'a stem is no typo' );
		self::assertNull( $this->search( 'kazumi' )->corrected );
		$two = $this->search( 'kazuni dealer' );
		self::assertSame( 'kazumi dealer', $two->corrected, 'only the typo is rewritten' );
		self::assertSame( array( 'KAZUMI Skate Deck, Dealer' ), self::titles( $two ) );
		$both = $this->search( 'kazuni snowboard' );
		self::assertSame( 'kazumi', $both->corrected, 'a word left out and a typo read, together' );
		self::assertTrue( $both->dropped );
		$first = $this->search( 'dekc kazumi' );
		self::assertSame( 'deck kazumi', $first->corrected, 'the closest term, in the typo\'s place' );
		self::assertSame( 'kazumi deck', $this->search( 'kazuni dekc' )->corrected, 'every typo is read' );
		$none = $this->search( 'skatehoyze kazumi', 'en', new SearchOptions( retry: false ) );
		self::assertSame( array(), $none->products );
		self::assertNull( $none->corrected, 'a typo that finds no product reports nothing' );
		self::assertFalse( $none->dropped );
	}

	public function test_typos_are_never_tried_for_short_tokens_numbers_or_sku_shapes(): void {
		self::assertTrue( $this->search( 'dek' )->isEmpty() );
		self::assertTrue( $this->search( '8.52 ' )->isEmpty(), '8.25 is not found from 8.52' );
		self::assertTrue( $this->search( '54mn ' )->isEmpty() );
		self::assertTrue( $this->search( 'KAZ-DEA-00002 ' )->isEmpty() === false, 'the retry drops the wrong number instead' );
		self::assertTrue( $this->search( 'kazumz ' )->isEmpty() === false );
		self::assertTrue( $this->search( 'kazumzz ' )->isEmpty(), 'two edits at six characters is too far' );
	}

	public function test_typo_candidates_are_capped_closest_first(): void {
		foreach ( range( 'a', 'l' ) as $letter ) {
			$this->product( 'Thing', sprintf( 'T-%05d', ord( $letter ) ), array(), true, 0, array( 'wxyz' . $letter ) );
		}
		$this->product( 'Thing', 'T-00999', array(), true, 0, array( 'wxyzqq' ) );
		$widening = ( new Searcher( $this->index ) )->widen( new QueryToken( 'wxyz', 'wxyz' ), 'en' );
		self::assertCount( Searcher::TYPO_TERMS, $widening->matches );
		foreach ( $widening->matches as $match ) {
			self::assertSame( TermMatch::TYPO1, $match->kind );
		}
		self::assertArrayNotHasKey( 'wxyzqq', $widening->matches, 'two edits are not allowed at four characters' );
		$long = ( new Searcher( $this->index ) )->widen( new QueryToken( 'wxyzqqqq', 'wxyzqqqq' ), 'en' );
		self::assertSame( array( 'wxyzqq' ), array_keys( $long->matches ), 'two edits at eight characters' );
		self::assertSame( TermMatch::TYPO2, $long->matches['wxyzqq']->kind );
	}

	public function test_a_typo_finds_what_its_closest_word_finds_typed(): void {
		$this->product( 'Blotto Hoody', 'BLO-HOO-00001', array(), true, 10, array() );
		$this->product( 'Classic Hoodies', 'SKH-CLA-00002', array(), true, 20, array() );
		$this->product( 'Crew Sweater', 'SKH-CRE-00003', array(), true, 30, array() );
		$this->product( 'Light Deck', 'HZR-LIG-00004', array(), true, 90, array(), 'A light body with a stiff pop.' );
		$synonyms = new Synonyms( array( new Synonym( 'en', 'hoody', 'sweater', true ) ) );
		$typed    = $this->search( 'hoody', 'en', null, $synonyms );
		self::assertEqualsCanonicalizing( array( 'Blotto Hoody', 'Classic Hoodies', 'Crew Sweater', 'Skatehouse Hoodie, Logo' ), self::titles( $typed ), 'typed: the word, its stem and its synonym' );
		$typo = $this->search( 'hody', 'en', null, $synonyms );
		self::assertEqualsCanonicalizing( self::titles( $typed ), self::titles( $typo ), 'read as "hoody", it brings what "hoody" brings (D-146)' );
		self::assertSame( 'hoody', $typo->corrected, 'still read as the closest word' );
		self::assertSame( TermMatch::TYPO1, $typo->products[0]->score->tokens[0]['kind'], 'at the typo\'s weight' );
		self::assertSame( array( 'Light Deck' ), self::titles( $this->search( 'body' ) ), 'typed, "body" finds the deck through its description' );
		self::assertSame( array(), self::titles( $this->search( 'bodu' ) ), 'a four-letter typo read as "body" finds nothing through a description alone' );
		self::assertSame( array( 'KAZUMI Skate Deck, Dealer' ), self::titles( $this->search( 'mapel' ) ), 'five letters: read as "maple", the description counts' );

		$widening = ( new Searcher( $this->index, new Analyzer(), new Scorer(), $synonyms ) )->widen( new QueryToken( 'hody', 'hody' ), 'en' );
		self::assertSame( 'hoody', array_key_first( $widening->matches ), 'the closest first' );
		foreach ( array( 'hoody', 'hoodi', 'sweater' ) as $term ) {
			self::assertFalse( $widening->matches[ $term ]->loose, $term . ' is the corrected word\'s' );
			self::assertFalse( $widening->matches[ $term ]->descriptions, 'four letters: not in a description, the corrected word\'s family included' );
			self::assertSame( TermMatch::TYPO1, $widening->matches[ $term ]->kind );
		}
		self::assertTrue( $widening->matches['body']->loose, 'another candidate stays loose' );
		self::assertFalse( $widening->matches['body']->descriptions, 'four letters: its descriptions don\'t count' );
		$this->product( 'Cyrillic Tee', 'SKH-CYR-00006', array(), true, 1, array(), 'тожа тожи' );
		$cyrillic = ( new Searcher( $this->index ) )->widen( new QueryToken( 'тоже', 'тоже' ), 'en' );
		self::assertSame( 'тожа', array_key_first( $cyrillic->matches ) );
		self::assertFalse( $cyrillic->matches['тожи']->descriptions, 'four letters, eight bytes: counted in letters' );
		$long = ( new Searcher( $this->index ) )->widen( new QueryToken( 'tigerz', 'tigerz' ), 'en' );
		self::assertTrue( $long->matches['tiger']->descriptions, 'six letters: they count, last' );
		self::assertFalse( ( new Searcher( $this->index ) )->widen( new QueryToken( 'kazumi', 'kazumi' ), 'en' )->matches['kazumi']->loose, 'no typo, nothing loose' );
		$this->product( 'Body Pads', 'PAD-BOD-00005', array(), true, 1, array(), 'Soft body armour.' );
		$pads = $this->search( 'hody', 'en', null, $synonyms );
		self::assertContains( 'Body Pads', self::titles( $pads ), 'the loose "body" in a name still counts' );
		self::assertNotContains( 'Light Deck', self::titles( $pads ) );
	}

	public function test_a_typo_brings_its_word_s_phrase_synonyms(): void {
		$this->product( 'Tech Deck Mini', 'TDK-MIN-00001', array(), true, 10, array() );
		$this->product( 'Fingerboard Ramp', 'FBR-RMP-00002', array(), true, 20, array() );
		$synonyms = new Synonyms( array( new Synonym( 'en', 'tech deck', 'fingerboard', true ) ) );
		$typed    = $this->search( 'fingerboard ', 'en', null, $synonyms );
		self::assertEqualsCanonicalizing( array( 'Tech Deck Mini', 'Fingerboard Ramp' ), self::titles( $typed ), 'typed: the word and its phrase synonym (D-109)' );
		$typo = $this->search( 'fingerbord ', 'en', null, $synonyms );
		self::assertSame( 'fingerboard', $typo->corrected );
		self::assertEqualsCanonicalizing( self::titles( $typed ), self::titles( $typo ), 'read as "fingerboard", it finds "tech deck" too (D-146)' );
		self::assertSame( array( 'Fingerboard Ramp' ), self::titles( $this->search( 'fingerbord ' ) ), 'no synonyms: the word alone' );
		$this->index->ranks = 0;
		$this->search( 'fingerboard ', 'en', null, $synonyms );
		self::assertSame( 2, $this->index->ranks, 'no typo: the query and its one other form, ranked once each' );
		self::assertSame( array( 'Fingerboard Ramp' ), self::titles( $this->search( 'fingerboard ', 'en', null, new Synonyms( array( new Synonym( 'en', 'tech deck', 'skate' ) ) ) ) ), 'no typo: no other form from a rule that isn\'t the word\'s' );
	}

	public function test_a_term_reached_only_by_a_loose_candidate_in_its_description_is_not_offered(): void {
		$this->product( 'Blotto Hoody', 'BLO-HOO-00001', array(), true, 5, array() );
		$this->term( Document::BRAND, 'HZRD', 3, 'en', 'A light body with a stiff pop.' );
		$this->term( Document::CATEGORY, 'Hoodies', 4 );
		$this->term( Document::TAG, 'Winter', 2, 'en', 'Every hoody we carry.' );
		$names = static fn( SearchResult $r ): array => array_map( static fn( Hit $h ): string => $h->document->title, $r->terms );
		$typo  = $this->search( 'hody' );
		self::assertSame( 'hoody', $typo->corrected );
		self::assertNotContains( 'HZRD', $names( $typo ), '"body" in its description only, a loose candidate of "hody"' );
		self::assertContains( 'Winter', $names( $typo ), 'the corrected word itself in a description still reaches it' );
		self::assertContains( 'HZRD', $names( $this->search( 'body' ) ), 'typed, the word reaches it' );
		self::assertContains( 'HZRD', $names( $this->search( 'hzrd hody' ) ), 'another word reaches it by name' );
	}

	public function test_a_loose_candidate_found_only_in_a_description_ranks_last(): void {
		$this->product( 'Plane Bag', 'BAG-PLA-00001', array(), false, 0, array() );
		$this->product( 'Plant Tee', 'SKH-PLA-00002', array(), true, 5, array() );
		$this->product( 'Ramp Kit', 'RMP-KIT-00003', array(), true, 900, array(), 'Every plank is birch.' );
		$r = $this->search( 'planx' );
		self::assertSame( 'plane', $r->corrected, 'the closest, then the most trigrams shared, then the first in order' );
		self::assertSame( array( 'Plant Tee', 'Plane Bag', 'Ramp Kit' ), self::titles( $r ), 'the loose "plant" in a name counts; "plank" in a description only, last, its 900 sales notwithstanding' );
		self::assertFalse( $r->products[0]->score->looseOnly );
		self::assertFalse( $r->products[1]->score->looseOnly );
		self::assertTrue( $r->products[2]->score->looseOnly );
		self::assertSame( array( 'Plant Tee', 'Plane Bag', 'Ramp Kit' ), self::titles( $this->search( 'planx', 'en', new SearchOptions( inStockFirst: true ) ) ), 'in stock first keeps the loose match last, behind a sold-out name match' );

		$doc   = static fn( int $id ): Document => new Document( $id, Document::PRODUCT, $id, 'en', 'x', 'x', array(), true, true, 0, 0, 0 );
		$loose = new Hit( $doc( 1 ), new Score( array(), 50.0, looseOnly: true ) );
		$firm  = new Hit( $doc( 2 ), new Score( array(), 1.0 ) );
		self::assertSame( 1, Hit::compare( $loose, $firm ), 'whatever its score' );
		self::assertSame( -1, Hit::compare( $firm, $loose ) );
		self::assertSame( 1, Hit::stockFirst( $loose, $firm ) );
		self::assertSame( -1, Hit::stockFirst( $firm, $loose ) );
	}

	public function test_and_across_tokens_then_the_least_matched_token_is_dropped_once(): void {
		$r = $this->search( 'kazumi wheels' );
		self::assertSame( array( 'KAZUMI Skate Deck, Dealer', 'KAZUMI Skate Deck, Ghost Tiger' ), self::titles( $r ) );
		self::assertSame( 'kazumi', $r->corrected, 'wheels matched one product, kazumi three' );
		self::assertTrue( $r->dropped, 'a word was left out: the exact words are worth offering' );
		self::assertSame( array( 0 ), array_keys( $r->products[0]->score->tokens ), 'scored for the reduced query' );
		self::assertSame( 'kazumi', $r->key, 'the key of the query the results answer (D-105)' );
		self::assertSame( array( array( 'kazumi' ) ), $r->tokens, 'the terms of the tokens kept' );
		$three = $this->search( 'kazumi dealer snowboard' );
		self::assertSame( 'kazumi dealer', $three->key );
		self::assertSame( array( array( 'kazumi' ), array( 'dealer' ) ), $three->tokens, 'reindexed from 0' );
		self::assertSame( array( 'KAZUMI Skate Deck, Dealer' ), self::titles( $three ) );
		self::assertSame( 'kazumi dealer', $three->corrected );
		self::assertTrue( $this->search( 'snowboard' )->isEmpty(), 'one token cannot be dropped' );
		self::assertNull( $this->search( 'snowboard' )->corrected );
	}

	public function test_strict_keeps_every_word_as_typed_but_no_completion_or_typo(): void {
		$strict   = new SearchOptions( retry: false, strict: true );
		$synonyms = new Synonyms( array( new Synonym( 'en', 'board', 'deck' ) ) );
		self::assertSame( array( 'KAZUMI Skate Deck, Dealer', 'KAZUMI Skate Deck, Ghost Tiger' ), self::titles( $this->search( 'kazumi decks', 'en', $strict ) ), 'a stem is a strong match' );
		self::assertSame( array( 'KAZUMI Skate Deck, Dealer', 'KAZUMI Skate Deck, Ghost Tiger' ), self::titles( $this->search( 'kazumi board', 'en', $strict, $synonyms ) ), 'so is the owner\'s synonym' );
		self::assertSame( array( 'KAZUMI Skate Deck, Dealer', 'KAZUMI Skate Deck, Ghost Tiger' ), self::titles( $this->search( 'kaz', 'en', $strict ) ), 'a word that is itself a term still matches' );
		self::assertSame( array(), self::titles( $this->search( 'hoo', 'en', $strict ) ), 'a word being typed is not completed (D-118)' );
		self::assertSame( array( 'Skatehouse Hoodie, Logo' ), self::titles( $this->search( 'hoo' ) ), 'it is without strict' );
		self::assertSame( array(), self::titles( $this->search( 'kazuni', 'en', $strict ) ), 'a typo finds nothing strong' );
		self::assertCount( 2, $this->search( 'kazuni' )->products, 'it does without strict' );
		$searcher = new Searcher( $this->index );
		self::assertSame( array(), $searcher->widen( new QueryToken( 'kazuni', 'kazuni' ), 'en', Kind::PRODUCT, true )->matches );
		self::assertSame( array(), $searcher->widen( new QueryToken( 'hoo', 'hoo', false, 0, true ), 'en', Kind::PRODUCT, true )->matches, 'no prefix terms' );
		self::assertArrayHasKey( 'hoodie', $searcher->widen( new QueryToken( 'hoo', 'hoo', false, 0, true ), 'en' )->matches );
	}

	public function test_the_retry_can_be_turned_off_for_the_shoppers_exact_words(): void {
		$exact = $this->search( 'kazumi wheels', 'en', new SearchOptions( retry: false ) );
		self::assertSame( array(), $exact->products, 'no product matches both, and nothing is dropped' );
		self::assertNull( $exact->corrected );
		self::assertSame( 'kazumi wheels', $exact->key, 'nothing dropped: the whole query' );
		self::assertSame( array( array( 'kazumi' ), array( 'wheels', 'wheel' ) ), $exact->tokens, 'each token\'s terms, its stem among them' );
		self::assertSame( array( 'KAZUMI Skate Deck, Dealer' ), self::titles( $this->search( 'kazumi dealer', 'en', new SearchOptions( retry: false ) ) ), 'a query that matches is unaffected' );
	}

	public function test_the_retry_sorts_what_it_adds(): void {
		$this->product( 'Alpha Snowboard', 'SNO-ALP-00001', array(), false, 500, array() );
		$this->product( 'Beta Snowboard', 'SNO-BET-00002', array(), true, 1, array() );
		$r = $this->search( 'snowboard wheels' );
		self::assertSame( 'snowboard', $r->corrected );
		self::assertSame( array( 'Beta Snowboard', 'Alpha Snowboard' ), self::titles( $r ), 'in stock before sold out, whatever the index order' );
		$first = $this->search( 'wheels kazumi' );
		self::assertSame( 'kazumi', $first->corrected, 'the least-matched token goes even when it comes first' );
	}

	public function test_widening_carries_spellings_synonyms_and_the_own_prefix_term(): void {
		$this->product( 'Zorbite Deck', 'ZOR-DEC-00001', array(), true, 0, array() );
		$this->product( 'Truck Axle Kit', 'TRK-AXL-00001', array(), true, 0, array() );
		$searcher = new Searcher( $this->index );
		$spelled  = $searcher->widen( new QueryToken( 'lzorbite', "l'zorbite", false, 0, false, false, array(), array( 'zorbite' ) ), 'en' );
		self::assertSame( array( 'zorbite' ), array_keys( $spelled->matches ), 'the second spelling finds the term the first does not' );
		self::assertSame( TermMatch::EXACT, $spelled->matches['zorbite']->kind );
		$synonyms = $searcher->widen( new QueryToken( 'trucks', 'trucks', false, 0, false, false, array( 'truck', 'trucking', 'axles' ) ), 'en' );
		self::assertSame( TermMatch::STEM, $synonyms->matches['truck']->kind, 'the stem was there first and keeps its weight' );
		self::assertSame( TermMatch::SYNONYM, $synonyms->matches['axl']->kind, 'the stem of the synonym axles, which the kit carries as the stem of axle (D-029)' );
		self::assertSame( array( 'truck', 'axl' ), array_keys( $synonyms->matches ) );
		$prefix = $searcher->widen( new QueryToken( 'kaz', 'kaz', false, 0, true ), 'en' );
		self::assertSame( TermMatch::EXACT, $prefix->matches['kaz']->kind, 'the term itself is exact, not a prefix of itself' );
		self::assertSame( TermMatch::PREFIX, $prefix->matches['kazumi']->kind );
		$excluded = $this->search( "zorbite -l'zorbite" );
		self::assertSame( array(), self::titles( $excluded ), 'a minus word excludes through its spellings too' );
		self::assertSame( array( 'Zorbite Deck' ), self::titles( $this->search( 'zorbite' ) ) );
	}

	public function test_phrases_use_first_positions_and_every_field(): void {
		$this->product( 'Night Board Foo Deck', 'NBF-DEC-00001', array(), true, 0, array() );
		$this->product( 'Night Hoodie', 'NIG-HOO-00001', array(), true, 0, array(), 'A night shift print on the back.' );
		$synonyms = new Synonyms( array( new Synonym( 'en', 'board', 'deck' ) ) );
		$r        = $this->search( '"night board" ', 'en', null, $synonyms );
		self::assertSame( array( 'Night Board Foo Deck' ), self::titles( $r ), 'board at 1 is adjacent to night at 0; its synonym deck at 3 is not' );
		self::assertSame( 8.0, $r->products[0]->score->phrase );
		$long = $this->search( '"night shift" ' );
		self::assertSame( array( 'Night Hoodie' ), self::titles( $long ), 'the name holds night alone, the description holds the phrase' );
		$three = $this->search( '"kazumi skate deck" ' );
		self::assertSame( array( 'KAZUMI Skate Deck, Dealer', 'KAZUMI Skate Deck, Ghost Tiger' ), self::titles( $three ) );
		self::assertNull( $three->corrected );
		$disorder = $this->search( '"kazumi deck skate" ' );
		self::assertSame( array(), self::titles( $disorder ), 'three words out of order are no phrase' );
		self::assertNull( $disorder->corrected );
	}

	public function test_typo_candidates_are_ordered_by_distance_then_shared_trigrams_then_term(): void {
		foreach ( array( 'wxyzqqqqzz', 'wxyzqqqqab', 'awxyzqqqq', 'wxyzqqqb', 'wxyzqqqa', 'wxyzqqqc', 'wxyzqqqd' ) as $i => $term ) {
			$this->product( 'Thing', sprintf( 'T-%05d', 800 + $i ), array(), true, 0, array( $term ) );
		}
		$widening = ( new Searcher( $this->index ) )->widen( new QueryToken( 'wxyzqqqq', 'wxyzqqqq' ), 'en' );
		self::assertSame( array( 'wxyzqqqa', 'wxyzqqqb', 'wxyzqqqc', 'wxyzqqqd', 'awxyzqqqq', 'wxyzqqqqab', 'wxyzqqqqzz' ), array_keys( $widening->matches ), 'one edit before two; seven shared trigrams before six; then the term, whatever order the store returned' );
		self::assertSame( TermMatch::TYPO1, $widening->matches['awxyzqqqq']->kind );
		self::assertSame( TermMatch::TYPO2, $widening->matches['wxyzqqqqzz']->kind );
	}

	public function test_a_dropped_token_is_reported_only_when_it_finds_something(): void {
		$r = $this->search( 'dba snowboard' );
		self::assertTrue( $r->isEmpty(), 'the Blank is hidden' );
		self::assertNull( $r->corrected );
		self::assertFalse( $r->dropped, 'a retry that found nothing is not reported' );
		$tie = $this->search( 'wheels bearings' );
		self::assertSame( 'wheels', $tie->corrected, 'on a tie the later token goes' );
		self::assertSame( array( 'Orbit Wheels, Classic' ), self::titles( $tie ) );
	}

	public function test_minus_words_exclude_products_and_terms(): void {
		$r = $this->search( 'kazumi -dealer' );
		self::assertSame( array( 'KAZUMI Skate Deck, Ghost Tiger' ), self::titles( $r ) );
		self::assertSame( array( 'KAZUMI' ), self::terms( $r ) );
		$stem = $this->search( 'kazumi -decks' );
		self::assertSame( array(), self::titles( $stem ), 'the minus word excludes through its stem too' );
		self::assertSame( array( 'KAZUMI' ), self::terms( $stem ) );
		self::assertNull( $stem->corrected, 'a minus word is never dropped' );
		$term = $this->search( 'deck -decks -kazumi' );
		self::assertSame( array(), self::terms( $term ), 'Decks and KAZUMI are excluded' );
		$number = $this->search( 'kazumi -8.25' );
		self::assertSame( array(), self::titles( $number ) );
		$two = $this->search( 'planches -roulettes -completes', 'fr' );
		self::assertSame( array( 'Planches' ), self::terms( $two ), 'two categories excluded at once' );
	}

	public function test_quoted_phrases_must_be_adjacent_in_one_field(): void {
		$r = $this->search( '"skate deck" ' );
		self::assertSame( array( 'KAZUMI Skate Deck, Dealer', 'KAZUMI Skate Deck, Ghost Tiger' ), self::titles( $r ) );
		self::assertSame( 8.0, $r->products[0]->score->phrase );
		$apart = $this->search( '"kazumi deck" ' );
		self::assertSame( array( 'KAZUMI Skate Deck, Dealer', 'KAZUMI Skate Deck, Ghost Tiger', 'Skatehouse Hoodie, Logo' ), self::titles( $apart ), 'not adjacent: the retry drops the word with fewer matches' );
		self::assertSame( 'deck', $apart->corrected, 'a phrase of one word loses its quotes' );
		$reversed = $this->search( '"deck skate" ' );
		self::assertSame( 'deck', $reversed->corrected );
		$split = $this->search( '"logo deck" ' );
		self::assertSame( 'deck', $split->corrected, 'logo is in the name, deck in the description' );
		$loose = $this->search( 'kazumi "skate deck" ' );
		self::assertCount( 2, $loose->products );
		self::assertNull( $loose->corrected );
	}

	public function test_sold_out_products_rank_below_products_in_stock(): void {
		$r = $this->search( 'kazumi skate deck ' );
		self::assertSame( array( 'KAZUMI Skate Deck, Dealer', 'KAZUMI Skate Deck, Ghost Tiger' ), self::titles( $r ) );
		self::assertSame( -6.0, $r->products[1]->score->stock );
		self::assertSame( 0.0, $r->products[0]->score->stock );
		self::assertSame( 8.0, $r->products[0]->score->phrase );
	}

	public function test_in_stock_first_keeps_a_name_match_ahead_of_a_description_match(): void {
		$off = $this->search( 'decks ' );
		self::assertSame( array( 'KAZUMI Skate Deck, Dealer', 'KAZUMI Skate Deck, Ghost Tiger', 'Skatehouse Hoodie, Logo' ), self::titles( $off ), 'off: the sold-out name match beats the in-stock description match' );
		self::assertFalse( $off->products[1]->score->descriptionOnly );
		self::assertTrue( $off->products[2]->score->descriptionOnly, 'the hoodie only mentions decks in its description' );
		$on = $this->search( 'decks ', 'en', new SearchOptions( inStockFirst: true ) );
		self::assertSame( self::titles( $off ), self::titles( $on ), 'on: a sold-out name match still comes before an in-stock description match (D-142)' );
		self::assertSame( $off->products[1]->score->total(), $on->products[1]->score->total(), 'the score itself is unchanged' );
		$this->product( 'KAZUMI Skate Deck, Sold Again', 'KAZ-SOA-00031', array(), false, 500, array() );
		self::assertSame( array( 'KAZUMI Skate Deck, Dealer', 'KAZUMI Skate Deck, Sold Again', 'KAZUMI Skate Deck, Ghost Tiger', 'Skatehouse Hoodie, Logo' ), self::titles( $this->search( 'decks ', 'en', new SearchOptions( inStockFirst: true ) ) ), 'the sold-out name matches in their own order, before the description match' );
	}

	public function test_in_stock_first_puts_the_in_stock_name_match_first(): void {
		$this->product( 'Omega Wheels', 'WHL-OMG-00001', array(), false, 0, array() );
		$this->product( 'Omega Wheels Pro', 'WHL-OMP-00002', array(), true, 0, array() );
		$this->product( 'Omega Bearings', 'BRG-OMG-00003', array(), true, 0, array(), 'Made to fit omega wheels.' );
		$off = $this->search( 'omega wheels' );
		self::assertSame( array( 'Omega Wheels', 'Omega Wheels Pro', 'Omega Bearings' ), self::titles( $off ), 'off: the sold-out exact name first' );
		$on = $this->search( 'omega wheels', 'en', new SearchOptions( inStockFirst: true ) );
		self::assertSame( array( 'Omega Wheels Pro', 'Omega Wheels', 'Omega Bearings' ), self::titles( $on ), 'on: of two name matches the in-stock one first, the description match after both (D-142)' );
		self::assertTrue( $on->products[2]->score->descriptionOnly, 'wheels reached the bearings only in their description' );
	}

	public function test_exact_name_earns_the_bonus(): void {
		$r = $this->search( 'kazumi skate deck dealer' );
		self::assertSame( 12.0, $r->products[0]->score->exact );
		self::assertSame( array( 'KAZUMI Skate Deck, Dealer' ), self::titles( $r ) );
	}

	public function test_owner_boost_and_rules_apply(): void {
		$options = new SearchOptions( array(), true, 'en', array( 'brand:skatehouse' => 10 ) );
		$r       = $this->search( 'deck ', 'en', $options );
		self::assertSame( 'Skatehouse Hoodie, Logo', self::titles( $r )[0], 'the rule lifts the hoodie above the decks' );
		self::assertSame( 10.0, $r->products[0]->score->owner );
	}

	public function test_fallback_language(): void {
		$fr = $this->search( 'planche kazumi', 'fr' );
		self::assertSame( 'fr', $fr->lang );
		self::assertSame( array( 'Planche KAZUMI, Dealer' ), self::titles( $fr ) );
		self::assertSame( array( 'Planches', 'KAZUMI', 'Planches à roulettes' ), self::terms( $fr ), 'coverage then count (D-037)' );
		$fallback = $this->search( 'ghost tiger', 'fr' );
		self::assertSame( 'en', $fallback->lang );
		self::assertSame( array( 'KAZUMI Skate Deck, Ghost Tiger' ), self::titles( $fallback ) );
		$none = $this->search( 'ghost tiger', 'fr', new SearchOptions( fallbackLang: null ) );
		self::assertTrue( $none->isEmpty() );
		self::assertSame( 'fr', $none->lang );
		$same = $this->search( 'snowboard', 'en', new SearchOptions( fallbackLang: 'en_US' ) );
		self::assertTrue( $same->isEmpty() );
		self::assertSame( 'en', $same->lang );
		$terms = $this->search( 'popsicle', 'fr', new SearchOptions( fallbackLang: 'en' ) );
		self::assertSame( array( 'popsicle' ), self::terms( $terms ), 'a term alone is a result, so English answers' );
		self::assertSame( 'en', $terms->lang );
	}

	public function test_french_stems_and_stop_words(): void {
		$r = $this->search( 'les roues de kazumi', 'fr' );
		self::assertSame( 'roues', $r->corrected, 'stop words gone; one match each, so the later token goes' );
		self::assertSame( array( 'Roues Orbit, Classic' ), self::titles( $r ) );
		$roues = $this->search( 'roue ', 'fr' );
		self::assertSame( array( 'Roues Orbit, Classic' ), self::titles( $roues ) );
		self::assertSame( TermMatch::STEM, $roues->products[0]->score->tokens[0]['kind'] );
	}

	public function test_limits(): void {
		$r = $this->search( 'deck ', 'en', new SearchOptions( limit: 1, termLimit: 0 ) );
		self::assertCount( 1, $r->products );
		self::assertSame( array(), $r->terms );
		$all = $this->search( 'kaz skate deck', 'en', new SearchOptions( termLimit: 1 ) );
		self::assertCount( 1, $all->terms );
		$retry = $this->search( 'kazumi wheels', 'en', new SearchOptions( limit: 1 ) );
		self::assertCount( 1, $retry->products, 'the cap applies after the retry too' );
	}

	public function test_stop_words_alone_search_and_find_nothing(): void {
		$r = $this->search( 'the' );
		self::assertTrue( $r->isEmpty() );
		self::assertNull( $r->corrected );
	}

	public function test_scores_explain_themselves(): void {
		$r     = $this->search( 'kazumi 8.25' );
		$score = $r->products[0]->score;
		self::assertSame( Fields::NAME, $score->tokens[0]['field'] );
		self::assertSame( Fields::ATTRIBUTE, $score->tokens[1]['field'], 'the variation SKU carries 825, the attribute 8.25' );
		self::assertSame( '8.25', $score->tokens[1]['term'] );
		self::assertGreaterThan( 0, $score->tokens[1]['df'] );
		self::assertGreaterThan( 0.0, $score->total() );
	}

	/**
	 * Add a post or page document (1.1): its title, its parent's title and its text.
	 *
	 * @param string $type   Document::POST or Document::PAGE.
	 * @param string $title  Title.
	 * @param string $parent The parent page's title.
	 * @param string $text   Its text.
	 */
	private function page( string $type, string $title, string $parent = '', string $text = '' ): void {
		$id = ++$this->nextId;
		$this->index->add(
			new Document( $id, $type, 500 + $id, 'en', $title, $this->analyzer->tokenizer()->key( $title, 'en' ) ),
			$this->analyzer->analyze(
				array(
					Fields::NAME     => $title,
					Fields::CATEGORY => $parent,
					Fields::LONG     => $text,
				),
				'en'
			)
		);
	}

	private function pages(): void {
		$this->page( Document::PAGE, 'Shipping and returns', '', 'Free shipping over $100. Returns within 30 days.' );
		$this->page( Document::PAGE, 'Size guides' );
		$this->page( Document::PAGE, 'Wheel guide', 'Size guides', 'Which wheel diameter for street or park.' );
		$this->page( Document::PAGE, 'Deck width guide', 'Size guides', 'Pick a deck width by shoe size.' );
		$this->page( Document::POST, 'Dealer deck drop', '', 'The KAZUMI Dealer is back.' );
	}

	private function content( string $raw, int $limit = 3 ): SearchResult {
		return $this->search( $raw, 'en', ( new SearchOptions() )->forContent( $limit ) );
	}

	public function test_the_posts_and_pages_are_searched_on_their_own(): void {
		$this->pages();
		self::assertSame( array( 'Shipping and returns' ), self::titles( $this->content( 'returns' ) ), 'by its text and title' );
		self::assertSame( array( 'Size guides', 'Wheel guide', 'Deck width guide' ), self::titles( $this->content( 'size guide' ) ), 'the parent first, then its children by their parent' );
		self::assertSame( array( 'Wheel guide' ), self::titles( $this->content( 'wheel guide' ) ) );
		self::assertSame( array(), self::terms( $this->content( 'kazumi' ) ), 'no categories, tags or brands' );
		self::assertSame( array( 'Dealer deck drop' ), self::titles( $this->content( 'kazumi dealer' ) ), 'no product either' );
		self::assertSame( array( 'Size guides', 'Wheel guide' ), self::titles( $this->content( 'size guide', 2 ) ), 'the limit' );
		self::assertSame( array( 'Shipping and returns' ), self::titles( $this->content( 'ship' ) ), 'a prefix of a page\'s word' );
	}

	public function test_a_product_search_never_returns_a_post_or_page(): void {
		$this->pages();
		self::assertSame( array(), self::titles( $this->search( 'returns' ) ) );
		self::assertNotContains( 'Dealer deck drop', self::titles( $this->search( 'dealer deck' ) ) );
	}

	public function test_turning_the_pages_on_changes_no_product_s_rank(): void {
		$queries = array( 'deck', 'kazumi dealer', 'wheel', 'size', 'guide', 'dec', 'ship', 'kazumi dealr' );
		$before  = array();
		foreach ( $queries as $q ) {
			$before[ $q ] = array_map( static fn( Hit $h ): array => array( $h->document->title, round( $h->score->total(), 6 ) ), $this->search( $q )->products );
		}
		$this->pages();
		foreach ( $queries as $q ) {
			$after = array_map( static fn( Hit $h ): array => array( $h->document->title, round( $h->score->total(), 6 ) ), $this->search( $q )->products );
			self::assertSame( $before[ $q ], $after, $q );
		}
	}

	public function test_a_content_search_drops_its_least_matched_word_among_the_pages(): void {
		$this->pages();
		$r = $this->content( 'returns policy' );
		self::assertSame( array( 'Shipping and returns' ), self::titles( $r ) );
		self::assertSame( 'returns', $r->corrected );
	}

	public function test_a_typo_of_a_word_only_the_pages_carry_finds_nothing(): void {
		$this->pages();
		self::assertSame( array(), self::titles( $this->content( 'retruns' ) ), 'typo correction reaches the catalogue\'s words only (D-093)' );
		self::assertSame( array( 'Dealer deck drop' ), self::titles( $this->content( 'dealr' ) ), 'a word the products carry too is corrected' );
	}

	public function test_content_options(): void {
		$base    = new SearchOptions( array( 'name' => 50 ), true, 'fr', array( 'brand:kazumi' => 5 ), 400, 3, false );
		$content = $base->forContent( 4 );
		self::assertSame( Kind::CONTENT, $content->kind );
		self::assertSame( 4, $content->limit );
		self::assertSame( 0, $content->termLimit );
		self::assertFalse( $content->hiddenBySku );
		self::assertSame( array(), $content->boostRules, 'the owner\'s boosts are for products' );
		self::assertSame( array( 'name' => 50 ), $content->weights );
		self::assertSame( 'fr', $content->fallbackLang );
		self::assertFalse( $content->retry );
		self::assertSame( Kind::PRODUCT, $base->kind );
		$scoped = new SearchOptions( strict: true, scope: array( 12, 14 ) );
		self::assertFalse( $scoped->forContent( 2 )->strict, 'the posts and pages are never narrowed' );
		self::assertSame( array(), $scoped->forContent( 2 )->scope, 'nor kept to a category' );
	}

	/**
	 * The phrase synonyms of the D-109 tests.
	 */
	private static function phraseSynonyms(): Synonyms {
		return new Synonyms(
			array(
				new Synonym( 'en', 'ss', 'stainless steel' ),
				new Synonym( 'en', 'skate shoes', 'sneakers', true ),
			)
		);
	}

	public function test_a_phrase_synonym_finds_what_its_meaning_names(): void {
		$this->product( 'Zeta SS Bearings', 'BRG-ZSS-00001', array(), true, 0, array() );
		$this->product( 'Zeta Stainless Steel Bearings', 'BRG-ZST-00002', array(), true, 0, array() );
		$this->product( 'Zeta Rusty Steel Bearings', 'BRG-ZRS-00003', array(), true, 0, array() );
		self::assertSame( array( 'Zeta SS Bearings' ), self::titles( $this->search( 'zeta ss bearings' ) ), 'without the synonym' );
		$found = self::titles( $this->search( 'zeta ss bearings', 'en', null, self::phraseSynonyms() ) );
		sort( $found );
		self::assertSame( array( 'Zeta SS Bearings', 'Zeta Stainless Steel Bearings' ), $found, 'the meaning as a whole: steel alone is not stainless steel' );
		self::assertNull( $this->search( 'zeta ss bearings', 'en', null, self::phraseSynonyms() )->corrected );
	}

	public function test_the_forms_merge_each_product_once_at_its_best(): void {
		$this->product( 'Zeta SS Bearings', 'BRG-ZSS-00001', array(), true, 0, array() );
		$this->product( 'Zeta Stainless Steel SS Bearings', 'BRG-ZST-00002', array(), true, 0, array() );
		$this->product( 'Zeta Stainless Steel Bearings', 'BRG-ZSB-00003', array(), true, 0, array() );
		$r = $this->search( 'zeta ss bearings', 'en', null, self::phraseSynonyms() );
		// Each form's exact name earns its bonus: the first two are named exactly as one form reads.
		self::assertSame( array( 'Zeta Stainless Steel Bearings', 'Zeta SS Bearings', 'Zeta Stainless Steel SS Bearings' ), self::titles( $r ), 'found by both forms once, in score order' );
		$scores = array_map( static fn( Hit $h ): float => $h->score->total(), $r->products );
		$sorted = $scores;
		rsort( $sorted );
		self::assertSame( $sorted, $scores );
		$plain = $this->search( 'zeta ss bearings' );
		self::assertSame( 'Zeta Stainless Steel SS Bearings', $plain->products[1]->document->title );
		self::assertGreaterThan( $plain->products[1]->score->total(), $r->products[2]->score->total(), 'the better of its two scores: the other form names more of it' );
		$capped = $this->search( 'zeta ss bearings', 'en', new SearchOptions( limit: 2 ), self::phraseSynonyms() );
		self::assertSame( array( 'Zeta Stainless Steel Bearings', 'Zeta SS Bearings' ), self::titles( $capped ), 'the limit holds over the merged list' );
	}

	public function test_the_merged_forms_keep_what_can_be_bought_first(): void {
		$this->product( 'Zeta SS Bearings', 'BRG-ZSS-00001', array(), true, 0, array() );
		$this->product( 'Zeta Stainless Steel Bearings', 'BRG-ZSB-00003', array(), false, 0, array() );
		$this->product( 'Zeta Stainless Steel SS Bearings', 'BRG-ZST-00002', array(), true, 0, array() );
		$off = $this->search( 'zeta ss bearings', 'en', null, self::phraseSynonyms() );
		self::assertSame( array( 'Zeta Stainless Steel Bearings', 'Zeta SS Bearings', 'Zeta Stainless Steel SS Bearings' ), self::titles( $off ), 'off: the sold-out exact name first' );
		$on = $this->search( 'zeta ss bearings', 'en', new SearchOptions( inStockFirst: true ), self::phraseSynonyms() );
		self::assertSame( array( 'Zeta SS Bearings', 'Zeta Stainless Steel SS Bearings', 'Zeta Stainless Steel Bearings' ), self::titles( $on ), 'on: last, across both forms (D-138)' );
	}

	public function test_without_other_forms_the_ranking_is_the_store_order(): void {
		$this->product( 'Zeta Deck B', 'ZET-DEB-00001', array(), true, 0, array() );
		$this->product( 'Zeta Deck A', 'ZET-DEA-00002', array(), true, 0, array() );
		self::assertSame( self::titles( $this->search( 'zeta deck' ) ), self::titles( $this->search( 'zeta deck', 'en', null, self::phraseSynonyms() ) ) );
	}

	public function test_the_words_a_phrase_synonym_puts_in_score_as_a_synonym(): void {
		$searcher = new Searcher( $this->index );
		$put      = $searcher->widen( new QueryToken( 'bearings', 'bearings', false, 0, false, false, array(), array( 'kazumi' ), true ), 'en' );
		self::assertSame( TermMatch::SYNONYM, $put->matches['bearings']->kind );
		self::assertSame( TermMatch::SYNONYM, $put->matches['kazumi']->kind, 'its spellings too' );
		self::assertSame( TermMatch::SYNONYM, $put->matches['bear']->kind, 'and its stem' );
		$typed = $searcher->widen( new QueryToken( 'bearings', 'bearings' ), 'en' );
		self::assertSame( TermMatch::EXACT, $typed->matches['bearings']->kind );
		self::assertSame( TermMatch::STEM, $typed->matches['bear']->kind );
	}

	public function test_the_forms_reach_their_own_categories(): void {
		$this->term( Document::CATEGORY, 'Sneakers', 12 );
		$this->term( Document::CATEGORY, 'Stainless Steel Bearings', 4 );
		$this->product( 'Zeta Sneakers', 'SNK-ZET-00001', array(), true, 0, array() );
		$r = $this->search( 'skate shoes', 'en', null, self::phraseSynonyms() );
		self::assertSame( array( 'Sneakers' ), self::terms( $r ), 'the category the meaning names' );
		self::assertSame( array( 'Zeta Sneakers' ), self::titles( $r ) );
		$plain = $this->search( 'ss bearings' );
		$alt   = $this->search( 'ss bearings', 'en', null, self::phraseSynonyms() );
		self::assertSame( array( 'Stainless Steel Bearings' ), self::terms( $alt ) );
		self::assertSame( array( 'Stainless Steel Bearings' ), self::terms( $plain ) );
		$scores = array();
		foreach ( array( $plain, $alt ) as $result ) {
			foreach ( $result->terms as $hit ) {
				if ( 'Stainless Steel Bearings' === $hit->document->title ) {
					$scores[] = $hit->score->total();
				}
			}
		}
		self::assertCount( 2, $scores );
		self::assertGreaterThan( $scores[0], $scores[1], 'a category reached by both forms keeps its better score' );
	}
}
