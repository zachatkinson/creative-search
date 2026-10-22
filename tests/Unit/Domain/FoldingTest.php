<?php
/**
 * Folding tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Domain;

use CreativeBranding\Search\Domain\Folding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Case and accents fold with the table; combining marks are dropped; folding is idempotent.
 */
final class FoldingTest extends TestCase {

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function cases(): array {
		return array(
			'lower case'          => array( 'KAZUMI', 'kazumi' ),
			'french accents'      => array( 'Québécois', 'quebecois' ),
			'grave and circumflex' => array( 'à côté', 'a cote' ),
			'cedilla'             => array( 'Façade', 'facade' ),
			'diaeresis'           => array( 'Noël naïf', 'noel naif' ),
			'ligatures'           => array( 'Œuvre Æsir', 'oeuvre aesir' ),
			'eszett'              => array( 'Straße', 'strasse' ),
			'nordic'              => array( 'Ångström Ørsted', 'angstrom orsted' ),
			'slavic'              => array( 'Łódź Žižek Ştefan', 'lodz zizek stefan' ),
			'spanish'             => array( 'Niño', 'nino' ),
			'thorn and eth'       => array( 'Þórður', 'thordur' ),
			'decomposed accent'   => array( "Que\u{0301}bec", 'quebec' ),
			'digits untouched'    => array( '8.25″ 54mm', '8.25″ 54mm' ),
			'empty'               => array( '', '' ),
			'cyrillic lower-cased' => array( 'Привет', 'привет' ),
		);
	}

	#[DataProvider( 'cases' )]
	public function test_folds( string $in, string $out ): void {
		self::assertSame( $out, Folding::fold( $in ) );
	}

	#[DataProvider( 'cases' )]
	public function test_folding_is_idempotent( string $in, string $out ): void {
		self::assertSame( $out, Folding::fold( Folding::fold( $in ) ) );
	}

	public function test_every_table_entry_folds_to_plain_ascii_letters(): void {
		$sample = 'àáâãäåāăąçćčďđðèéêëēėęěğìíîïīįıłñńňòóôõöøōőřśšşșťțþùúûüūůűýÿźżžœæß';
		self::assertMatchesRegularExpression( '/^[a-z]+$/', Folding::fold( $sample ) );
		self::assertSame( mb_strlen( $sample ) + 4, strlen( Folding::fold( $sample ) ), 'th, oe, ae and ss add one letter each' );
	}
}
