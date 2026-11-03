<?php
/**
 * Plugin header tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Plugins screen shows the header's Description, which the Plugin Handbook keeps under 140 characters.
 */
final class PluginHeaderTest extends TestCase {

	private const LIMIT = 140;

	public function test_description_is_under_the_handbook_limit(): void {
		$description = self::description();

		self::assertNotSame( '', $description );
		self::assertLessThan( self::LIMIT, mb_strlen( $description, 'UTF-8' ), $description );
	}

	public function test_readme_short_description_is_the_header_description(): void {
		$readme = (string) file_get_contents( dirname( __DIR__, 2 ) . '/readme.txt' );
		$parts  = preg_split( '/\R\R/', $readme, 3 );

		self::assertIsArray( $parts );
		self::assertSame( self::description(), trim( $parts[1] ?? '' ) );
	}

	/**
	 * The Description line of the plugin's root file, as WordPress reads it.
	 */
	private static function description(): string {
		$file = (string) file_get_contents( dirname( __DIR__, 2 ) . '/creative-search.php' );

		return 1 === preg_match( '/^[ \t\/*#@]*Description:(.*)$/mi', $file, $match ) ? trim( $match[1] ) : '';
	}
}
