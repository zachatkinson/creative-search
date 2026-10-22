<?php
/**
 * The tokenizer.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Cuts text into tokens (spec: Indexing → Cutting text into tokens): lower-case; accents folded with a table;
 * sizes read one way whatever way they are written ({@see units()}, D-098); digits kept with their decimal
 * point and inch mark as one token (`8.25`, `8.25″`, `54mm`, with `54` beside it); a split on
 * everything else that is not a letter or digit; an apostrophe folded, with each side of two or more characters
 * kept as a second token at the same position (`Philly’s` → `phillys`, `philly`); tokens of one character
 * dropped unless digits; stop words dropped per language; positions kept and counted over the kept tokens.
 *
 * The one tokenizer: the indexer, the query parser, the results hook and WP-CLI all use it (spec: DRY).
 */
final class Tokenizer {

	/**
	 * `csearch_terms.term` is varchar(64).
	 */
	public const MAX_LENGTH = 64;

	/**
	 * What a text becomes: raised when the rules change what is indexed, so an index built under older rules is
	 * rebuilt once (2: the size rules of D-098).
	 */
	public const VERSION = 2;

	/**
	 * A word: letters, digits and combining marks, joined across an apostrophe or a decimal point between digits,
	 * with an optional inch mark after a digit.
	 */
	private const WORD = '/[\p{L}\p{N}\p{Mn}]+(?:(?:\'|(?<=\p{N})\.(?=\p{N}))[\p{L}\p{N}\p{Mn}]+)*(?:(?<=\p{N})["″])?/u';

	/**
	 * Constructor.
	 *
	 * @param StopWords $stopWords Stop words per language.
	 */
	public function __construct( private StopWords $stopWords = new StopWords() ) {
	}

	/**
	 * Cut text into tokens.
	 *
	 * @param string $text          Text in any script.
	 * @param string $lang          Language code for the stop-word list.
	 * @param bool   $dropStopWords False keeps stop words (the query parser decides for itself).
	 * @return Token[]
	 */
	public function tokenize( string $text, string $lang = 'en', bool $dropStopWords = true ): array {
		$tokens = array();
		$pos    = 0;
		foreach ( self::words( $text, $lang ) as $word ) {
			$kept = array();
			foreach ( self::variants( $word ) as $i => $source ) {
				$folded = mb_substr( Folding::fold( $source ), 0, self::MAX_LENGTH, 'UTF-8' );
				$source = mb_substr( $source, 0, self::MAX_LENGTH, 'UTF-8' );
				if ( $dropStopWords && $this->stopWords->isStopWord( $folded, $lang ) ) {
					if ( 0 === $i ) {
						// The word itself (`don't` as `dont`) is a stop word: its sides are not tokens either.
						break;
					}
					continue;
				}
				if ( ! isset( $kept[ $folded ] ) ) {
					$kept[ $folded ] = $source;
				}
			}
			if ( array() === $kept ) {
				continue;
			}
			foreach ( $kept as $folded => $source ) {
				$tokens[] = new Token( (string) $folded, $pos, $source === $folded ? '' : $source );
			}
			++$pos;
		}
		return $tokens;
	}

	/**
	 * Whether a folded token is a stop word in a language.
	 *
	 * @param string $token Folded token.
	 * @param string $lang  Language code.
	 */
	public function isStopWord( string $token, string $lang ): bool {
		return $this->stopWords->isStopWord( $token, $lang );
	}

	/**
	 * The stop words in use.
	 */
	public function stopWords(): StopWords {
		return $this->stopWords;
	}

	/**
	 * The folded texts of a text's tokens, in order, one per position (the first variant of a shared position).
	 * This is the key the exactness bonus compares a query with a name or SKU by.
	 *
	 * @param string $text Text.
	 * @param string $lang Language code.
	 */
	public function key( string $text, string $lang = 'en' ): string {
		$parts = array();
		foreach ( $this->tokenize( $text, $lang ) as $token ) {
			$parts[ $token->position ] ??= $token->text;
		}
		return implode( ' ', $parts );
	}

	/**
	 * The lower-cased words of a text, before folding, the apostrophe rule and the one-character rule.
	 *
	 * @param string $text Text.
	 * @param string $lang Language code: French reads a decimal comma ({@see units()}).
	 * @return string[]
	 */
	public static function words( string $text, string $lang = 'en' ): array {
		$lower = self::units( mb_strtolower( $text, 'UTF-8' ), $lang );
		$lower = str_replace( array( '’', '‘', 'ʼ', '`', '´' ), "'", $lower );
		preg_match_all( self::WORD, $lower, $matches );
		return $matches[0];
	}

	/**
	 * Sizes read one way however they are written (D-098, extending D-024), on the index side and the query side
	 * alike (the parser runs this before it cuts a query into chunks, since a unit may follow a space). A number
	 * here stands alone: one a letter runs into (`kz825x2`, a SKU) is left as it is.
	 *
	 * - Inches: `8.25in`, `8.25 in`, `8.25 inch`, `8.25 inches`, `8.25"`, `8.25''` and `8.25″` all become `8.25″`,
	 *   whose mark the tokenizer drops; with French, `8,25` is `8.25` and `po` (pouces) is an inch too.
	 * - Trailing zeros: `8.50` is `8.5`, `8.00` is `8`; `8.125` stays.
	 * - Millimetres: `54 mm` is `54mm` (the tokenizer adds `54` beside it, {@see variants()}).
	 * - Pairs: `x`, `×` and `*` between two numbers split them (`9x33`, `33″ x 9″`, `110*38mm`).
	 *
	 * @param string $text Lower-cased text.
	 * @param string $lang Language code.
	 */
	public static function units( string $text, string $lang = 'en' ): string {
		$fr    = 'fr' === StopWords::code( $lang );
		$rules = array( "/(?<=\p{N})(?:''|’’)/u" => '″' );
		if ( $fr ) {
			$rules['/(?<![\p{L}\p{N}.,])(\p{N}+),(\p{N}+)(?![\p{N},])/u'] = '$1.$2';
		}
		$rules['/(?<![\p{L}\p{N}.])(\p{N}+(?:\.\p{N}+)?)\s*([″"]?)\s*[x×*]\s*(?=\p{N})/u'] = '$1$2 ';
		$rules[ '/(?<![\p{L}\p{N}.])(\p{N}+(?:\.\p{N}+)?)\s*(?:inches|inch|in|″|"' . ( $fr ? '|pouces|pouce|po' : '' ) . ')(?![\p{L}\p{N}])/u' ] = '$1″';
		$rules['/(?<![\p{L}\p{N}.])(\p{N}+(?:\.\p{N}+)?)\s+mm(?![\p{L}\p{N}])/u'] = '$1mm';
		$text = (string) preg_replace( array_keys( $rules ), $rules, $text );
		return (string) preg_replace_callback(
			'/(?<![\p{L}\p{N}.])(\p{N}+)\.(\p{N}+)(?![\p{N}.])/u',
			static function ( array $m ): string {
				$fraction = rtrim( $m[2], '0' );
				return '' === $fraction ? $m[1] : $m[1] . '.' . $fraction;
			},
			$text
		);
	}

	/**
	 * The variants one word yields: the inch mark dropped, the apostrophe joined, each side of two or more
	 * characters added, the number of a millimetre size added (`54mm` and `54`), one-character non-digits
	 * dropped.
	 *
	 * @param string $word A lower-cased word.
	 * @return string[] Distinct variants in order; empty when the word yields nothing.
	 */
	public static function variants( string $word ): array {
		$word = rtrim( $word, '"″' );
		$out  = array();
		if ( str_contains( $word, "'" ) ) {
			$candidates = array_merge( array( str_replace( "'", '', $word ) ), explode( "'", $word ) );
		} elseif ( 1 === preg_match( '/^(\p{N}+(?:\.\p{N}+)?)mm$/u', $word, $size ) ) {
			$candidates = array( $word, $size[1] );
		} else {
			$candidates = array( $word );
		}
		foreach ( $candidates as $candidate ) {
			if ( '' === $candidate || in_array( $candidate, $out, true ) ) {
				continue;
			}
			if ( 1 === mb_strlen( $candidate, 'UTF-8' ) && 1 !== preg_match( '/\p{N}/u', $candidate ) ) {
				continue;
			}
			$out[] = $candidate;
		}
		return $out;
	}

	/**
	 * Whether a token is a number (`8.25`, `54`, `00001`): never fuzzed, never stemmed.
	 *
	 * @param string $token Folded token.
	 */
	public static function isNumber( string $token ): bool {
		return 1 === preg_match( '/^\p{N}+(?:\.\p{N}+)*$/u', $token );
	}

	/**
	 * Whether a token mixes letters and digits (`54mm`, `101a`, `abc123`): SKU-shaped, never fuzzed, never stemmed.
	 *
	 * @param string $token Folded token.
	 */
	public static function isSkuShaped( string $token ): bool {
		return 1 === preg_match( '/\p{L}/u', $token ) && 1 === preg_match( '/\p{N}/u', $token );
	}
}
