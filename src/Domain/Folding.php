<?php
/**
 * Case and accent folding.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Lower-cases text and folds accents with a table, not `iconv`, so the result is the same on every host
 * (spec: Indexing → Cutting text into tokens). Combining marks left over from decomposed text are dropped too.
 */
final class Folding {

	/**
	 * Accented and ligature letters to their plain forms. Lower case only: the text is lower-cased first.
	 *
	 * @var array<string, string>
	 */
	private const TABLE = array(
		'à' => 'a',
		'á' => 'a',
		'â' => 'a',
		'ã' => 'a',
		'ä' => 'a',
		'å' => 'a',
		'ā' => 'a',
		'ă' => 'a',
		'ą' => 'a',
		'ç' => 'c',
		'ć' => 'c',
		'č' => 'c',
		'ď' => 'd',
		'đ' => 'd',
		'ð' => 'd',
		'è' => 'e',
		'é' => 'e',
		'ê' => 'e',
		'ë' => 'e',
		'ē' => 'e',
		'ė' => 'e',
		'ę' => 'e',
		'ě' => 'e',
		'ğ' => 'g',
		'ì' => 'i',
		'í' => 'i',
		'î' => 'i',
		'ï' => 'i',
		'ī' => 'i',
		'į' => 'i',
		'ı' => 'i',
		'ł' => 'l',
		'ñ' => 'n',
		'ń' => 'n',
		'ň' => 'n',
		'ò' => 'o',
		'ó' => 'o',
		'ô' => 'o',
		'õ' => 'o',
		'ö' => 'o',
		'ø' => 'o',
		'ō' => 'o',
		'ő' => 'o',
		'ř' => 'r',
		'ś' => 's',
		'š' => 's',
		'ş' => 's',
		'ș' => 's',
		'ť' => 't',
		'ț' => 't',
		'þ' => 'th',
		'ù' => 'u',
		'ú' => 'u',
		'û' => 'u',
		'ü' => 'u',
		'ū' => 'u',
		'ů' => 'u',
		'ű' => 'u',
		'ý' => 'y',
		'ÿ' => 'y',
		'ź' => 'z',
		'ż' => 'z',
		'ž' => 'z',
		'œ' => 'oe',
		'æ' => 'ae',
		'ß' => 'ss',
	);

	/**
	 * Lower-case and fold.
	 *
	 * @param string $text UTF-8 text.
	 */
	public static function fold( string $text ): string {
		$lower  = mb_strtolower( $text, 'UTF-8' );
		$folded = strtr( $lower, self::TABLE );
		// Combining diacritics (U+0300 to U+036F) left by decomposed input.
		$clean = preg_replace( '/[\x{0300}-\x{036F}]/u', '', $folded );
		return null === $clean ? $folded : $clean;
	}
}
