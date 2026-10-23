<?php
/**
 * A parsed query.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A query after parsing: its tokens in order (stop words already dropped, unless every token was one), the
 * minus words, the quoted phrases, within the caps (spec: Matching → Parsing), and the other forms the owner's
 * phrase synonyms give it (D-109), searched beside it.
 */
final class Query {

	public const MAX_CHARS  = 200;
	public const MAX_TOKENS = 8;

	/**
	 * Other forms of one query at most: each is one more ranking.
	 */
	public const MAX_ALTERNATIVES = 3;

	/**
	 * Constructor.
	 *
	 * @param string       $text         The query, trimmed and capped.
	 * @param string       $lang         Language code.
	 * @param QueryToken[] $tokens       The tokens to match, in order.
	 * @param QueryToken[] $excluded     The minus words.
	 * @param bool         $allStopWords Whether every token was a stop word (they are kept, and the query runs).
	 * @param Query[]      $alternatives The query with a phrase synonym in place of the words it rewrites.
	 */
	public function __construct(
		public readonly string $text,
		public readonly string $lang,
		public readonly array $tokens,
		public readonly array $excluded = array(),
		public readonly bool $allStopWords = false,
		public readonly array $alternatives = array()
	) {
	}

	/**
	 * Whether there is nothing to search for.
	 */
	public function isEmpty(): bool {
		return array() === $this->tokens;
	}

	/**
	 * The folded tokens joined by one space: what the exactness bonus compares with a name or SKU key.
	 */
	public function key(): string {
		return implode( ' ', array_map( static fn( QueryToken $t ): string => $t->text, $this->tokens ) );
	}

	/**
	 * The tokens of each quoted phrase, by phrase number, in order.
	 *
	 * @return array<int, int[]> Phrase number to indexes into {@see Query::$tokens}.
	 */
	public function phrases(): array {
		$out = array();
		foreach ( $this->tokens as $i => $token ) {
			if ( $token->phrase > 0 ) {
				$out[ $token->phrase ][] = $i;
			}
		}
		return array_filter( $out, static fn( array $indexes ): bool => count( $indexes ) > 1 );
	}

	/**
	 * The query with one token dropped (the retry after AND found too little), its text rewritten so the
	 * suggestions can say "showing results for …". The retry searches this form alone.
	 *
	 * @param int $index Index into {@see Query::$tokens}.
	 */
	public function without( int $index ): self {
		$tokens = array_values( array_filter( $this->tokens, static fn( int $i ): bool => $i !== $index, ARRAY_FILTER_USE_KEY ) );
		return new self( self::render( $tokens, $this->excluded ), $this->lang, $tokens, $this->excluded, $this->allStopWords );
	}

	/**
	 * The query written back with some words replaced (a typo by the term it matched) and one token left out,
	 * so the shopper reads what the results are for (D-034, D-114).
	 *
	 * @param array<int, string> $words   Index into {@see Query::$tokens} to the word written in its place.
	 * @param int|null           $without A token left out, or null.
	 */
	public function rewritten( array $words, ?int $without = null ): string {
		$tokens = array();
		foreach ( $this->tokens as $i => $token ) {
			if ( $i === $without ) {
				continue;
			}
			$tokens[] = isset( $words[ $i ] )
				? new QueryToken( $token->text, $words[ $i ], $token->negated, $token->phrase, $token->prefix, $token->stopWord, $token->synonyms, $token->variants )
				: $token;
		}
		return self::render( $tokens, $this->excluded );
	}

	/**
	 * Tokens written back as a query: phrases of two or more tokens quoted, minus words with their minus.
	 *
	 * @param QueryToken[] $tokens   The tokens to match.
	 * @param QueryToken[] $excluded The minus words.
	 */
	public static function render( array $tokens, array $excluded ): string {
		$sizes = array();
		foreach ( $tokens as $token ) {
			$sizes[ $token->phrase ] = ( $sizes[ $token->phrase ] ?? 0 ) + 1;
		}
		$parts  = array();
		$phrase = null;
		foreach ( $tokens as $i => $token ) {
			$word   = $token->source;
			$quoted = $token->phrase > 0 && $sizes[ $token->phrase ] > 1;
			if ( $quoted && $token->phrase !== $phrase ) {
				$word = '"' . $word;
			}
			$next = $tokens[ $i + 1 ] ?? null;
			if ( $quoted && ( null === $next || $next->phrase !== $token->phrase ) ) {
				$word .= '"';
			}
			$phrase  = $token->phrase;
			$parts[] = $word;
		}
		foreach ( $excluded as $token ) {
			$parts[] = '-' . $token->source;
		}
		return implode( ' ', $parts );
	}
}
