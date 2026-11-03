<?php
/**
 * The release manifest (D-140).
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Updates;

use CreativeBranding\Search\Updates\Manifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ManifestTest extends TestCase {

	private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	/** @return array<string, mixed> */
	private function good(): array {
		return array(
			'version'      => '1.6.1',
			'package'      => 'https://github.com/zachatkinson/creative-releases/releases/download/creative-search-1.6.1/creative-search-1.6.1.zip',
			'sha256'       => strtoupper( self::SHA ),
			'requires'     => '7.0',
			'requires_php' => '8.5',
			'tested'       => '7.1',
			'url'          => 'https://github.com/zachatkinson/creative-releases',
			'changelog'    => array( 'Updates through wp-admin.', '', 7 ),
		);
	}

	public function test_a_good_manifest(): void {
		$m = Manifest::fromArray( $this->good() );
		$this->assertNotNull( $m );
		$this->assertSame( '1.6.1', $m->version );
		$this->assertSame( self::SHA, $m->sha256 );
		$this->assertSame( '8.5', $m->requires_php );
		$this->assertSame( array( 'Updates through wp-admin.' ), $m->changelog );
	}

	/** @return array<string, array{0: array<string, mixed>}> */
	public static function bad(): array {
		return array(
			'no version'      => array( array( 'version' => '' ) ),
			'odd version'     => array( array( 'version' => '1.2' ) ),
			'http package'    => array( array( 'package' => 'http://github.com/a/b/c.zip' ) ),
			'other host'      => array( array( 'package' => 'https://evil.example/creative-search.zip' ) ),
			'host lookalike'  => array( array( 'package' => 'https://github.com.evil.example/x.zip' ) ),
			'not a zip'       => array( array( 'package' => 'https://github.com/a/b/c.php' ) ),
			'short hash'      => array( array( 'sha256' => 'abc' ) ),
			'hash not hex'    => array( array( 'sha256' => str_repeat( 'g', 64 ) ) ),
			'version not str' => array( array( 'version' => 120 ) ),
		);
	}

	/**
	 * @param array<string, mixed> $change
	 */
	#[DataProvider( 'bad' )]
	public function test_a_bad_manifest_is_none( array $change ): void {
		$this->assertNull( Manifest::fromArray( $change + $this->good() ) );
	}

	public function test_not_an_array(): void {
		$this->assertNull( Manifest::fromArray( null ) );
		$this->assertNull( Manifest::fromArray( 'x' ) );
	}

	public function test_bad_optional_fields_are_dropped(): void {
		$m = Manifest::fromArray( array( 'tested' => 'latest', 'url' => 'javascript:alert(1)', 'changelog' => 'x' ) + $this->good() );
		$this->assertNotNull( $m );
		$this->assertSame( '', $m->tested );
		$this->assertSame( '', $m->url );
		$this->assertSame( array(), $m->changelog );
	}
}
