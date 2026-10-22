<?php
/**
 * French stemmer tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Domain\Stemmer;

use CreativeBranding\Search\Domain\Stemmer\FrenchStemmer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Snowball's 21,653 test vectors for the French algorithm, plus the shop's words and the edges the vectors miss.
 */
final class FrenchStemmerTest extends TestCase {

	public const FIXTURES = __DIR__ . '/../../../Fixtures/snowball/french';

	public function test_every_snowball_vector(): void {
		$voc = file( self::FIXTURES . '/voc.txt', FILE_IGNORE_NEW_LINES );
		$out = file( self::FIXTURES . '/output.txt', FILE_IGNORE_NEW_LINES );
		self::assertNotFalse( $voc );
		self::assertNotFalse( $out );
		self::assertCount( count( $out ), $voc );
		self::assertGreaterThan( 20000, count( $voc ) );
		$stemmer = new FrenchStemmer();
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
	public static function words(): array {
		return array(
			'roulements'          => array( 'roulements', 'roul' ),
			'roulement'           => array( 'roulement', 'roul' ),
			'roues'               => array( 'roues', 'rou' ),
			'planches'            => array( 'planches', 'planch' ),
			'chandails'           => array( 'chandails', 'chandail' ),
			'québécois'           => array( 'québécois', 'québécois' ),
			'qualité'             => array( 'qualité', 'qualit' ),
			'nationalité'         => array( 'nationalité', 'national' ),
			'folded qualite'      => array( 'qualite', 'qualit' ),
			'elision'             => array( "l'école", 'écol' ),
			'elision qu'          => array( "qu'elle", 'elle' ),
			'elision alone'       => array( "l'", "l'" ),
			'elision needs more'  => array( "d'", "d'" ),
			'ë marker'            => array( 'noël', 'noël' ),
			'ï marker'            => array( 'aïeul', 'aïeul' ),
			'oux rule'            => array( 'bijoux', 'bijou' ),
			'oux not applied'     => array( 'roux', 'roux' ),
			'eaux'                => array( 'châteaux', 'château' ),
			'aux'                 => array( 'journaux', 'journal' ),
			'amment'              => array( 'constamment', 'const' ),
			'emment'              => array( 'évidemment', 'évident' ),
			'ais exception al'    => array( 'balais', 'balais' ),
			'ais exception auv'   => array( 'mauvais', 'mauvais' ),
			'ais exception épl'   => array( 'déplais', 'déplais' ),
			'ais applied'         => array( 'chalais', 'chal' ),
			'ais applied short'   => array( 'vais', 'vais' ),
			'un_double'           => array( 'donnée', 'don' ),
			'un_accent'           => array( 'complète', 'complet' ),
			'y to i'              => array( 'essayer', 'essai' ),
			'y to i after ais'    => array( 'balayer', 'balai' ),
			'ç kept in ons'       => array( 'commençons', 'commençon' ),
			'issement'            => array( 'vieillissement', 'vieil' ),
			'ement eus'           => array( 'heureusement', 'heureux' ),
			'ité abil'            => array( 'responsabilité', 'respons' ),
			'ité ic'              => array( 'publicité', 'publiqu' ),
			'if at ic'            => array( 'significatif', 'signif' ),
			'ion with s'          => array( 'passion', 'passion' ),
			'ier'                 => array( 'premier', 'premi' ),
			'pV after vowel pair' => array( 'jouaient', 'jou' ),
			'pV exception par'    => array( 'paris', 'paris' ),
			'pV exception ni'     => array( 'niais', 'niais' ),
			'pV exception col'    => array( 'colis', 'colis' ),
			'pV exception tap'    => array( 'tapis', 'tapis' ),
			'ni without vowel'    => array( 'nid', 'nid' ),
			'yeux'                => array( 'yeux', 'yeux' ),
			'ée'                  => array( 'aimée', 'aim' ),
			'issaient'            => array( 'finissaient', 'fin' ),
			'ion'                 => array( 'ambition', 'ambit' ),
			'logie'               => array( 'géologie', 'géolog' ),
			'usion'               => array( 'conclusion', 'conclus' ),
			'ence'                => array( 'patience', 'patienc' ),
			'é before ment'       => array( 'élément', 'élément' ),
			'majesté'             => array( 'majesté', 'majest' ),
			'ive'                 => array( 'active', 'activ' ),
			'ité iv in R2'        => array( 'relativité', 'relat' ),
			'ité then é verb'     => array( 'spontanéité', 'spontané' ),
			'usion in R2'         => array( 'rediffusion', 'rediffu' ),
			'usions in R2'        => array( 'rediffusions', 'rediffu' ),
			'ai drops e'          => array( 'mangeai', 'mang' ),
			'âmes drops e'        => array( 'mangeâmes', 'mang' ),
			'âtes drops e'        => array( 'mangeâtes', 'mang' ),
			'asse drops e'        => array( 'mangeasse', 'mang' ),
			'asses drops e'       => array( 'mangeasses', 'mang' ),
			'assent drops e'      => array( 'mangeassent', 'mang' ),
			'assions drops e'     => array( 'mangeassions', 'mang' ),
			'assiez drops e'      => array( 'mangeassiez', 'mang' ),
			'i-verb at RV start'  => array( 'parie', 'pari' ),
			'è then s'            => array( 'ès', 'es' ),
			'euse'                => array( 'heureuse', 'heureux' ),
			'empty'               => array( '', '' ),
			'digits'              => array( '8.25', '8.25' ),
			'outside latin-1'     => array( 'skateboardı', 'skateboardı' ),
			'cyrillic unchanged'  => array( 'привет', 'привет' ),
		);
	}

	#[DataProvider( 'words' )]
	public function test_words( string $word, string $stem ): void {
		self::assertSame( $stem, ( new FrenchStemmer() )->stem( $word ) );
	}

	/**
	 * Strings no French word reaches, traced by hand against the Snowball definition: the stemmer runs on brand
	 * names and typos too, so each rule has to hold at its boundary.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function boundaries(): array {
		return array(
			'two vowels, three letters: RV empty'     => array( 'aea', 'aea' ),
			'ni then non-vowel: RV after the i'       => array( 'nidira', 'nid' ),
			'emment fails on purpose, verb step runs' => array( 'aboliremment', 'abol' ),
			'Ièr after ement'                         => array( 'paièrement', 'pai' ),
			'oux with nothing before it'              => array( 'oux', 'oux' ),
		);
	}

	#[DataProvider( 'boundaries' )]
	public function test_boundaries( string $word, string $stem ): void {
		self::assertSame( $stem, ( new FrenchStemmer() )->stem( $word ) );
	}
}
