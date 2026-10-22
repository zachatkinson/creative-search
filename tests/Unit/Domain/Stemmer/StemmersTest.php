<?php
/**
 * Stemmer selection tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Domain\Stemmer;

use CreativeBranding\Search\Domain\Stemmer\FrenchStemmer;
use CreativeBranding\Search\Domain\Stemmer\IdentityStemmer;
use CreativeBranding\Search\Domain\Stemmer\PorterStemmer;
use CreativeBranding\Search\Domain\Stemmer\Stemmers;
use PHPUnit\Framework\TestCase;

/**
 * Porter for English, Snowball French for French (including fr_CA), the identity elsewhere; one instance each.
 */
final class StemmersTest extends TestCase {

	public function test_picks_by_language(): void {
		$s = new Stemmers();
		self::assertInstanceOf( PorterStemmer::class, $s->for( 'en' ) );
		self::assertInstanceOf( PorterStemmer::class, $s->for( 'en_US' ) );
		self::assertInstanceOf( FrenchStemmer::class, $s->for( 'fr' ) );
		self::assertInstanceOf( FrenchStemmer::class, $s->for( 'fr_CA' ) );
		self::assertInstanceOf( IdentityStemmer::class, $s->for( 'de' ) );
		self::assertInstanceOf( IdentityStemmer::class, $s->for( '' ) );
	}

	public function test_reuses_one_instance_per_language(): void {
		$s = new Stemmers();
		self::assertSame( $s->for( 'en' ), $s->for( 'EN' ) );
		self::assertNotSame( $s->for( 'en' ), $s->for( 'fr' ) );
	}

	public function test_identity_leaves_the_word(): void {
		self::assertSame( 'Skateboards', ( new IdentityStemmer() )->stem( 'Skateboards' ) );
		self::assertSame( 'decks', ( new Stemmers() )->for( 'de' )->stem( 'decks' ) );
		self::assertSame( 'deck', ( new Stemmers() )->for( 'en' )->stem( 'decks' ) );
		self::assertSame( 'planch', ( new Stemmers() )->for( 'fr' )->stem( 'planches' ) );
	}
}
