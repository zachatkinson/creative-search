<?php
/**
 * The punctuation spacing of the shipped translations.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/tools/i18n-lib.php';

/**
 * D-158 (1.6.6 brief, item 2): Canadian French puts a no-break space (U+00A0) before a colon and inside « », so the
 * colon or the guillemet never starts or ends a line of its own. "Le plus proche en stock :" shipped with a plain
 * space; the i18n check (`composer i18n:check`, in CI) now fails on that, and so does this test, without wp-env.
 */
final class I18nTypographyTest extends TestCase {

	private const NBSP = "\u{00A0}";

	public function test_every_shipped_translation_is_spaced_as_its_locale_wants(): void {
		$files = glob( dirname( __DIR__, 2 ) . '/languages/*.po' );
		self::assertNotEmpty( $files );
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			$locale = csearch_po_locale( $file );
			self::assertNotSame( '', $locale, $file );
			foreach ( csearch_po_entries( $file ) as $entry ) {
				foreach ( $entry['strs'] as $n => $text ) {
					self::assertSame( array(), csearch_typography( $locale, $text ), $file . ': "' . $entry['id'] . '" form ' . $n );
				}
			}
		}
	}

	public function test_the_fixed_strings(): void {
		$entries = csearch_po_entries( dirname( __DIR__, 2 ) . '/languages/creative-search-fr_CA.po' );
		self::assertSame( array( 'Le plus proche en stock' . self::NBSP . ':', 'Les plus proches en stock' . self::NBSP . ':' ), $entries[ "\4Closest in stock:" ]['strs'] );
	}

	/**
	 * Strings the check refuses in fr_CA.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function wrong(): array {
		return array(
			'a plain space before a colon'  => array( 'Le plus proche en stock :' ),
			'a narrow space before a colon' => array( "Correspond à %1\$s\u{202F}: %2\$s" ),
			'a plain space after «'         => array( '« kazumi' . self::NBSP . '»' ),
			'a plain space before »'        => array( '«' . self::NBSP . 'kazumi »' ),
			'no space inside « »'           => array( 'Résultats pour «%s».' ),
			'no space before a colon'       => array( 'Le plus proche en stock:' ),
			'a placeholder then a colon'    => array( 'Correspond à %1$s: %2$s' ),
		);
	}

	#[DataProvider( 'wrong' )]
	public function test_a_plain_space_before_a_colon_or_inside_guillemets_fails( string $text ): void {
		self::assertNotSame( array(), csearch_typography( 'fr_CA', $text ) );
	}

	public function test_the_right_spacing_and_other_locales_pass(): void {
		self::assertSame( array(), csearch_typography( 'fr_CA', 'Résultats pour «' . self::NBSP . '%s' . self::NBSP . '».' ) );
		self::assertSame( array(), csearch_typography( 'fr_CA', 'Correspond à %1$s' . self::NBSP . ': %2$s' ) );
		self::assertSame( array(), csearch_typography( 'fr_CA', 'https://example.com' ), 'an address' );
		self::assertSame( array(), csearch_typography( 'fr_CA', 'à 10:30' ), 'a time' );
		self::assertSame( array(), csearch_typography( 'en_US', 'Closest in stock :' ), 'only the locales with the rule' );
	}

	public function test_the_locale_is_read_from_the_header_or_the_file_name(): void {
		$dir    = sys_get_temp_dir();
		$header = $dir . '/csearch-header.po';
		$named  = $dir . '/creative-search-fr_CA.po';
		file_put_contents( $header, "msgid \"\"\nmsgstr \"\"\n\"Language: fr_CA\\n\"\n" );
		file_put_contents( $named, "msgid \"\"\nmsgstr \"\"\n" );
		try {
			self::assertSame( 'fr_CA', csearch_po_locale( $header ) );
			self::assertSame( 'fr_CA', csearch_po_locale( $named ) );
		} finally {
			unlink( $header );
			unlink( $named );
		}
	}
}
