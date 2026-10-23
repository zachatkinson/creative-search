<?php
/**
 * Scrub tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Domain;

use CreativeBranding\Search\Domain\Scrub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Personal-looking queries are redacted; the shop's numbers are not.
 */
final class ScrubTest extends TestCase {

	/**
	 * @return array<string, array{string}>
	 */
	public static function personal(): array {
		return array(
			'email'                  => array( 'zach@example.com' ),
			'email in a sentence'    => array( 'send to zach.atkinson+shop@mail.example.org please' ),
			'phone with dashes'      => array( '514-555-1234' ),
			'phone with dots'        => array( '514.555.1234' ),
			'phone with spaces'      => array( '514 555 1234' ),
			'phone with brackets'    => array( '(514) 555-1234' ),
			'phone plain'            => array( '5145551234' ),
			'phone with country'     => array( '+1 514 555 1234' ),
			'phone with 1'           => array( '1-514-555-1234' ),
			'international'          => array( '+33 6 12 34 56 78' ),
			'card with spaces'       => array( '4111 1111 1111 1111' ),
			'card with dashes'       => array( '4111-1111-1111-1111' ),
			'card plain'             => array( '4111111111111111' ),
			'card 19 digits'         => array( '6011 1111 1111 1111 111' ),
			'card 13 digits'         => array( '4222222222222' ),
			'postal code'            => array( 'H2X 1Y4' ),
			'postal code no space'   => array( 'h2x1y4' ),
			'postal code with dash'  => array( 'H2X-1Y4' ),
			'zip plus four'          => array( '12345-6789' ),
			'mixed with words'       => array( 'deliver to H2X 1Y4 tomorrow' ),
			'email being typed'      => array( 'jean.tremblay@g' ),
			'email before the dot'   => array( 'jean.tremblay@gmail' ),
			'phone being typed'      => array( '514 555 12' ),
			'phone typed plain'      => array( '5145551' ),
			'phone typed brackets'   => array( '(514) 555-1' ),
		);
	}

	#[DataProvider( 'personal' )]
	public function test_redacts( string $query ): void {
		self::assertTrue( Scrub::looksPersonal( $query ), $query );
		self::assertSame( Scrub::REDACTED, Scrub::redact( $query ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function shop(): array {
		return array(
			'width'              => array( '8.25' ),
			'unit'               => array( '54mm' ),
			'sku'                => array( 'KAZ-DEA-00001' ),
			'variation sku'      => array( 'KAZ-DEA-00001-825' ),
			'truck size'         => array( 'trucks 149' ),
			'zero padded id'     => array( '00001' ),
			'five digit zip'     => array( '12345' ),
			'six digits'         => array( '123456' ),
			'two sizes'          => array( '139 144' ),
			'three sizes'        => array( '139 144 149' ),
			'twelve digits'      => array( '123456789012' ),
			'twenty digits'      => array( '12345678901234567890' ),
			'at without domain'  => array( 'deck @ home' ),
			'empty'              => array( '' ),
			'words'              => array( 'kazumi skate deck dealer' ),
			'postal-like word'   => array( 'a1b 2c' ),
			'phone too short'    => array( '555 1234' ),
			'year'               => array( '2026 catalogue' ),
			'price'              => array( '84.99' ),
			'at the end'         => array( 'deck@' ),
			'sizes then a digit' => array( '139 144 1' ),
			'seven digit code'   => array( '1234567' ),
			'phone in the middle of typing words' => array( '514 555 deck' ),
		);
	}

	#[DataProvider( 'shop' )]
	public function test_leaves_the_shop_alone( string $query ): void {
		self::assertFalse( Scrub::looksPersonal( $query ), $query );
		self::assertSame( $query, Scrub::redact( $query ) );
	}

	public function test_sources_are_the_patterns_without_their_delimiters_and_flags(): void {
		$sources = Scrub::sources();
		self::assertSame( '[^\s@]+@[^\s@]', $sources[0], 'the e-mail pattern, its /u gone' );
		self::assertSame( '(?<!\d)\d{5}-\d{4}(?!\d)', $sources[ count( $sources ) - 1 ], 'the ZIP+4 pattern, no flag to strip' );
		self::assertCount( 7, $sources );
	}

	#[DataProvider( 'personal' )]
	public function test_the_sources_redact_what_the_log_redacts( string $query ): void {
		self::assertTrue( self::anySource( $query ), $query );
	}

	#[DataProvider( 'shop' )]
	public function test_the_sources_leave_the_shop_alone( string $query ): void {
		self::assertFalse( self::anySource( $query ), $query );
	}

	/**
	 * Whether any source, compiled as the browser compiles it (the `u` flag), matches.
	 *
	 * @param string $query The query.
	 */
	private static function anySource( string $query ): bool {
		foreach ( Scrub::sources() as $source ) {
			if ( 1 === preg_match( '/' . $source . '/u', $query ) ) {
				return true;
			}
		}
		return false;
	}
}
