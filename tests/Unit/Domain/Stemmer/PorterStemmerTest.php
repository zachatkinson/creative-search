<?php
/**
 * Porter stemmer tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Domain\Stemmer;

use CreativeBranding\Search\Domain\Stemmer\PorterStemmer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Snowball's 42,603 test vectors for the Porter algorithm, plus the cases the shop leans on.
 */
final class PorterStemmerTest extends TestCase {

	public const FIXTURES = __DIR__ . '/../../../Fixtures/snowball/porter';

	public function test_every_snowball_vector(): void {
		$voc = file( self::FIXTURES . '/voc.txt', FILE_IGNORE_NEW_LINES );
		$out = file( self::FIXTURES . '/output.txt', FILE_IGNORE_NEW_LINES );
		self::assertNotFalse( $voc );
		self::assertNotFalse( $out );
		self::assertCount( count( $out ), $voc );
		self::assertGreaterThan( 40000, count( $voc ) );
		$stemmer = new PorterStemmer();
		$wrong   = array();
		foreach ( $voc as $i => $word ) {
			$got = $stemmer->stem( $word );
			if ( $got !== $out[ $i ] ) {
				$wrong[] = "{$word} => {$got} (want {$out[ $i ]})";
			}
		}
		self::assertSame( array(), $wrong );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function shopWords(): array {
		return array(
			'plural'          => array( 'decks', 'deck' ),
			'bearings'        => array( 'bearings', 'bear' ),
			'bearing'         => array( 'bearing', 'bear' ),
			'wheels'          => array( 'wheels', 'wheel' ),
			'hoodies'         => array( 'hoodies', 'hoodi' ),
			'hoodie'          => array( 'hoodie', 'hoodi' ),
			'completes'       => array( 'completes', 'complet' ),
			'perforated'      => array( 'perforated', 'perfor' ),
			'philly'          => array( 'philly', 'philli' ),
			'skateboarding'   => array( 'skateboarding', 'skateboard' ),
			'short word'      => array( 'is', 'i' ),
			'one letter'      => array( 'a', 'a' ),
			'empty'           => array( '', '' ),
			'digits'          => array( '8.25', '8.25' ),
			'non ascii bytes' => array( 'québécois', 'québécoi' ),
			'y handling'      => array( 'yearly', 'yearli' ),
			'double l'        => array( 'controlling', 'control' ),
			'short syllable'  => array( 'hopping', 'hop' ),
			'cvc adds e'      => array( 'filing', 'file' ),
			'ion needs s or t' => array( 'adoption', 'adopt' ),
		);
	}

	#[DataProvider( 'shopWords' )]
	public function test_shop_words( string $word, string $stem ): void {
		self::assertSame( $stem, ( new PorterStemmer() )->stem( $word ) );
	}
}
