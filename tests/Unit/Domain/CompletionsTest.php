<?php
/**
 * Completions tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Domain;

use CreativeBranding\Search\Domain\Analyzer;
use CreativeBranding\Search\Domain\Completions;
use CreativeBranding\Search\Domain\QueryParser;
use CreativeBranding\Search\Domain\Scrub;
use CreativeBranding\Search\Domain\Tokenizer;
use PHPUnit\Framework\TestCase;

/**
 * The searches the panel suggests: the past searches whose every word the index holds, never the one typed,
 * never a redacted one, three at most (D-112).
 */
final class CompletionsTest extends TestCase {

	/**
	 * Terms an index of skate products would hold: words and their stems.
	 *
	 * @var array<string, int>
	 */
	private const KNOWN = array(
		'kazumi'  => 3,
		'deck'    => 9,
		'decks'   => 2,
		'dealer'  => 1,
		'wheel'   => 4,
		'philly'  => 2,
		'hoodie'  => 2,
		'vet'     => 1,
	);

	private static function completions(): Completions {
		return new Completions( new QueryParser( new Tokenizer() ), new Analyzer() );
	}

	public function test_the_terms_a_candidate_may_be_indexed_under(): void {
		$terms = self::completions()->terms( array( 'Kazumi decks', "philly's", 'kazumi 8.25', 'the' ), 'en' );
		self::assertContains( 'kazumi', $terms );
		self::assertContains( 'decks', $terms, 'as written' );
		self::assertContains( 'deck', $terms, 'by its stem' );
		self::assertContains( 'philly', $terms, 'another spelling' );
		self::assertContains( '8.25', $terms );
		self::assertNotContains( '', $terms, 'a number has no stem' );
		self::assertSame( array_values( array_unique( $terms ) ), $terms, 'each once, in a list' );
		self::assertContains( 'the', $terms, 'a query of stop words alone keeps them' );
		self::assertSame( array(), self::completions()->terms( array(), 'en' ) );
	}

	public function test_suggests_known_searches_in_order_without_the_typed_one(): void {
		$picked = self::completions()->pick( 'kaz', array( 'kazumi', 'kazumi deck', 'kazumi dealer', 'kazumi wheels' ), self::KNOWN, 'en' );
		self::assertSame( array( 'kazumi', 'kazumi deck', 'kazumi dealer' ), $picked, 'three at most, in the order given' );
		self::assertSame( array( 'kazumi deck', 'kazumi dealer' ), self::completions()->pick( 'Kazumi ', array( 'kazumi', 'kazumi deck', 'kazumi dealer' ), self::KNOWN, 'en' ), 'never what was typed, case and spaces aside' );
		self::assertSame( array( 'kazumi wheels' ), self::completions()->pick( 'kaz', array( 'kazumi wheels' ), self::KNOWN, 'en', 1 ), 'wheels is found by its stem' );
		self::assertSame( array(), self::completions()->pick( 'kaz', array( 'kazumi' ), self::KNOWN, 'en', 0 ) );
	}

	public function test_every_word_must_be_indexed(): void {
		$picked = self::completions()->pick( 'ka', array( 'kazumi snowboard', 'kazumi rude word', 'kazumi deck' ), self::KNOWN, 'en' );
		self::assertSame( array( 'kazumi deck' ), $picked, 'a word the catalogue does not carry is never shown' );
		self::assertSame( array( "philly's" ), self::completions()->pick( 'ph', array( "philly's" ), self::KNOWN, 'en' ), 'another spelling counts' );
		self::assertSame( array( 'vêtements' ), self::completions()->pick( 'vê', array( 'vêtements' ), self::KNOWN, 'fr' ), 'a French stem counts' );
		self::assertSame( array(), self::completions()->pick( 'ka', array( '' ), self::KNOWN, 'en' ), 'an empty query has no words' );
	}

	public function test_duplicates_and_redacted_searches_are_dropped(): void {
		$picked = self::completions()->pick( 'ka', array( 'kazumi deck', 'Kazumi  Deck', 'kazumi ' . Scrub::REDACTED, 'kazumi dealer' ), self::KNOWN, 'en' );
		self::assertSame( array( 'kazumi deck', 'kazumi dealer' ), $picked );
	}

	public function test_default_dependencies(): void {
		self::assertSame( array( 'kazumi deck' ), ( new Completions() )->pick( 'k', array( 'kazumi deck' ), self::KNOWN, 'en' ) );
	}
}
