<?php
/**
 * The synonym table.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The owner's synonyms, applied to queries: each adds to what the typed words find and never replaces them
 * (spec: Matching → Parsing). A word for a word adds an alternative term to the typed token (D-029). A row with
 * more than one word on either side is a phrase rule (D-109): where the query holds the `word`'s words side by
 * side, the query is also searched with the `means` in their place (`ss bearings` also as `stainless steel
 * bearings`). Both-ways rows also work from `means` back to `word`.
 */
final class Synonyms {

	/**
	 * Alternatives by language, then by folded token: a set of folded terms.
	 *
	 * @var array<string, array<string, array<string, string>>>
	 */
	private array $map = array();

	/**
	 * Phrase rules by language, then by the words they find and the words they put in (each once): the folded
	 * words to find, side by side, and the tokens that take their place.
	 *
	 * @var array<string, array<string, array<string, array{from: string[], to: Token[]}>>>
	 */
	private array $phrases = array();

	/**
	 * Constructor.
	 *
	 * @param Synonym[] $rows      The rows.
	 * @param Tokenizer $tokenizer The tokenizer that cuts `word` and `means`.
	 */
	public function __construct( array $rows, private Tokenizer $tokenizer = new Tokenizer() ) {
		foreach ( $rows as $row ) {
			$this->add( $row );
		}
	}

	/**
	 * The alternative terms for a folded token in a language, never the token itself.
	 *
	 * @param string $token Folded token.
	 * @param string $lang  Language code.
	 * @return string[]
	 */
	public function alternatives( string $token, string $lang ): array {
		$set = $this->map[ StopWords::code( $lang ) ][ $token ] ?? array();
		unset( $set[ $token ] );
		return array_keys( $set );
	}

	/**
	 * The phrase rules of a language: grouped by the words they find, in the order those were first added.
	 *
	 * @param string $lang Language code.
	 * @return array<int, array{from: string[], to: Token[]}>
	 */
	public function phrases( string $lang ): array {
		$out = array();
		foreach ( $this->phrases[ StopWords::code( $lang ) ] ?? array() as $rules ) {
			foreach ( $rules as $rule ) {
				$out[] = $rule;
			}
		}
		return $out;
	}

	/**
	 * Where a run of words first appears, side by side, in a list of words.
	 *
	 * @param string[] $texts The words, in order.
	 * @param string[] $run   The run to find.
	 * @return int|null The index of its first word, or null.
	 */
	public static function find( array $texts, array $run ): ?int {
		// array_slice() counts places, not keys; a slice that runs past the end is shorter than the run.
		foreach ( array_keys( array_values( $texts ) ) as $at ) {
			if ( array_slice( $texts, $at, count( $run ) ) === $run ) {
				return $at;
			}
		}
		return null;
	}

	/**
	 * Whether any synonym is defined.
	 */
	public function isEmpty(): bool {
		return array() === $this->map && array() === $this->phrases;
	}

	/**
	 * Add one row.
	 *
	 * @param Synonym $row The row.
	 */
	private function add( Synonym $row ): void {
		$lang  = StopWords::code( $row->lang );
		$words = $this->tokens( $row->word, $lang );
		$means = $this->tokens( $row->means, $lang );
		$from  = self::texts( $words );
		$to    = self::texts( $means );
		if ( array() === $from || array() === $to || $from === $to ) {
			return;
		}
		if ( 1 === count( $from ) && 1 === count( $to ) ) {
			$this->map[ $lang ][ $from[0] ][ $to[0] ] = $to[0];
			if ( $row->bothWays ) {
				$this->map[ $lang ][ $to[0] ][ $from[0] ] = $from[0];
			}
			return;
		}
		$this->phrases[ $lang ][ implode( ' ', $from ) ][ implode( ' ', $to ) ] = array(
			'from' => $from,
			'to'   => $means,
		);
		if ( $row->bothWays ) {
			$this->phrases[ $lang ][ implode( ' ', $to ) ][ implode( ' ', $from ) ] = array(
				'from' => $to,
				'to'   => $words,
			);
		}
	}

	/**
	 * The tokens of a text, one per position (the first spelling), stop words out; keyed by position, which counts
	 * the kept tokens from 0.
	 *
	 * @param string $text Text.
	 * @param string $lang Language code.
	 * @return Token[]
	 */
	private function tokens( string $text, string $lang ): array {
		$tokens = array();
		foreach ( $this->tokenizer->tokenize( $text, $lang ) as $token ) {
			$tokens[ $token->position ] ??= $token;
		}
		return $tokens;
	}

	/**
	 * The folded texts of some tokens.
	 *
	 * @param Token[] $tokens Tokens.
	 * @return string[]
	 */
	private static function texts( array $tokens ): array {
		return array_map( static fn( Token $t ): string => $t->text, $tokens );
	}
}
