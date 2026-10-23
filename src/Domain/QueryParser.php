<?php
/**
 * The query parser.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The same tokenizer as indexing, then: synonyms added as alternatives; stop words dropped unless every token
 * is one; a quoted phrase kept as a phrase; a minus word excluded; a token of two or more characters at the end
 * with no space after it treated as a prefix; 200 characters and 8 tokens at most, the rest ignored (spec:
 * Matching → Parsing).
 */
final class QueryParser {

	/**
	 * A quoted phrase, a minus word, or a plain run of non-space characters.
	 */
	private const CHUNK = '/"([^"]*)"|(?<!\S)-(?=[\p{L}\p{N}])(\S+)|(\S+)/u';

	/**
	 * Constructor.
	 *
	 * @param Tokenizer $tokenizer The tokenizer.
	 * @param Synonyms  $synonyms  The owner's synonyms.
	 */
	public function __construct( private Tokenizer $tokenizer = new Tokenizer(), private Synonyms $synonyms = new Synonyms( array() ) ) {
	}

	/**
	 * Parse a query.
	 *
	 * @param string $raw  The query as typed.
	 * @param string $lang Language code.
	 */
	public function parse( string $raw, string $lang ): Query {
		$text = trim( mb_substr( trim( $raw ), 0, Query::MAX_CHARS, 'UTF-8' ) );
		$lang = StopWords::code( $lang );
		// Sizes first: a unit may follow its number after a space, which the chunks below would split (D-098).
		$prepared = Tokenizer::units( mb_strtolower( self::prepare( $text ), 'UTF-8' ), $lang );
		// A size whose unit was typed (`8.25 in`, `8.25″`) is finished: it is no prefix.
		$endsOpen = 1 !== preg_match( '/\s$/u', $raw ) && mb_strlen( trim( $raw ), 'UTF-8' ) <= Query::MAX_CHARS && ! str_ends_with( $prepared, '″' );

		$tokens   = array();
		$excluded = array();
		$phrases  = 0;
		preg_match_all( self::CHUNK, $prepared, $matches, PREG_SET_ORDER );
		$last = count( $matches ) - 1;
		foreach ( $matches as $n => $match ) {
			$isPhrase = '"' === $match[0][0];
			$isMinus  = ! $isPhrase && ! isset( $match[3] );
			$phrase   = 0;
			if ( $isPhrase ) {
				++$phrases;
				$phrase = $phrases;
			}
			// The quotes and the minus are not word characters: the tokenizer skips them.
			$cut = self::byPosition( $this->tokenizer->tokenize( $match[0], $lang, false ) );
			foreach ( $cut as $i => $group ) {
				$token  = $group[0];
				$isLast = $n === $last && count( $cut ) - 1 === $i && ! $isPhrase && ! $isMinus;
				$prefix = $isLast && $endsOpen && mb_strlen( $token->text, 'UTF-8' ) >= 2;
				$others = array_map( static fn( Token $t ): string => $t->text, array_slice( $group, 1 ) );
				$made   = new QueryToken( $token->text, $token->source(), $isMinus, $phrase, $prefix, $this->tokenizer->isStopWord( $token->text, $lang ), array(), $others );
				if ( $isMinus ) {
					$excluded[] = $made;
				} else {
					$tokens[] = $made;
				}
			}
		}

		$kept = array_filter( $tokens, static fn( QueryToken $t ): bool => ! $t->stopWord );
		$all  = array() === $kept && array() !== $tokens;
		if ( $all ) {
			// Stop words alone still search, but match exactly or not at all: no prefix, no typo (D-026).
			$kept = array_map( static fn( QueryToken $t ): QueryToken => $t->withoutPrefix(), $tokens );
		}
		// Past the cap the token being typed is cut away with the rest, so no kept token carries the prefix flag.
		$kept = array_slice( $kept, 0, Query::MAX_TOKENS );
		foreach ( $kept as $i => $token ) {
			$kept[ $i ] = $token->withSynonyms( $this->synonyms->alternatives( $token->text, $lang ) );
		}
		return new Query( $text, $lang, $kept, $excluded, $all, $this->alternatives( $kept, $excluded, $lang, $all ) );
	}

	/**
	 * The query's other forms (D-109): for each phrase synonym whose words the query holds side by side, the
	 * query with the synonym's words in their place, three at most. The words put in keep the place's quoted
	 * phrase, are never a prefix, and score as a synonym.
	 *
	 * @param QueryToken[] $kept     The tokens to match, keyed from 0 (array_slice() renumbers them).
	 * @param QueryToken[] $excluded The minus words.
	 * @param string       $lang     Language code.
	 * @param bool         $all      Whether every token was a stop word.
	 * @return Query[]
	 */
	private function alternatives( array $kept, array $excluded, string $lang, bool $all ): array {
		$texts = array_map( static fn( QueryToken $t ): string => $t->text, $kept );
		$out   = array();
		foreach ( $this->synonyms->phrases( $lang ) as $rule ) {
			$at = Synonyms::find( $texts, $rule['from'] );
			if ( null === $at ) {
				continue;
			}
			$made = array();
			foreach ( $rule['to'] as $word ) {
				$made[] = new QueryToken( $word->text, $word->source(), false, $kept[ $at ]->phrase, false, false, $this->synonyms->alternatives( $word->text, $lang ), array(), true );
			}
			$tokens = array_slice( array_merge( array_slice( $kept, 0, $at ), $made, array_slice( $kept, $at + count( $rule['from'] ) ) ), 0, Query::MAX_TOKENS );
			$out[]  = new Query( Query::render( $tokens, $excluded ), $lang, $tokens, $excluded, $all );
			if ( count( $out ) === Query::MAX_ALTERNATIVES ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Tokens grouped by position (dense from 0, so the keys are the positions): the apostrophe rule puts two
	 * spellings at one position.
	 *
	 * @param Token[] $tokens Tokens.
	 * @return array<int, Token[]>
	 */
	private static function byPosition( array $tokens ): array {
		$groups = array();
		foreach ( $tokens as $token ) {
			$groups[ $token->position ][] = $token;
		}
		return $groups;
	}

	/**
	 * Straighten curly quotes, turn a quote after a digit into an inch mark so it is not a phrase quote, and
	 * drop an unpaired quote.
	 *
	 * @param string $text The capped query.
	 */
	private static function prepare( string $text ): string {
		$text = str_replace( array( '“', '”' ), '"', $text );
		$text = preg_replace( '/(?<=\p{N})"/u', '″', $text ) ?? $text;
		if ( 1 === substr_count( $text, '"' ) % 2 ) {
			$at = strrpos( $text, '"' );
			if ( false !== $at ) {
				$text = substr_replace( $text, ' ', $at, 1 );
			}
		}
		return $text;
	}
}
