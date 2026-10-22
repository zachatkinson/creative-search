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
 * digits kept with their decimal point and inch mark as one token (`8.25`, `8.25″`, `54mm`); a split on
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
		foreach ( self::words( $text ) as $word ) {
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
	 * @return string[]
	 */
	public static function words( string $text ): array {
		$lower = mb_strtolower( $text, 'UTF-8' );
		$lower = str_replace( array( '’', '‘', 'ʼ', '`', '´' ), "'", $lower );
		preg_match_all( self::WORD, $lower, $matches );
		return $matches[0];
	}

	/**
	 * The variants one word yields: the inch mark dropped, the apostrophe joined, each side of two or more
	 * characters added, one-character non-digits dropped.
	 *
	 * @param string $word A lower-cased word.
	 * @return string[] Distinct variants in order; empty when the word yields nothing.
	 */
	public static function variants( string $word ): array {
		$word = rtrim( $word, '"″' );
		$out  = array();
		if ( str_contains( $word, "'" ) ) {
			$candidates = array_merge( array( str_replace( "'", '', $word ) ), explode( "'", $word ) );
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
