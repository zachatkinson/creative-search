<?php
/**
 * The owner's rules for single queries.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the owner's rule for a query: a redirect (D-108) or pinned products (D-110). A rule answers a query whose
 * words are its words, read as the index reads them: case, accents, punctuation, stop words and the way a size
 * is written do not matter (`Grip Tape!`, `grip-tape` and `the grip tape` are one query), the order of the words
 * does. A rule with a language answers that language only; one without answers every language.
 */
final class QueryRules {

	/**
	 * Constructor.
	 *
	 * @param Tokenizer $tokenizer The index's tokenizer.
	 */
	public function __construct( private Tokenizer $tokenizer = new Tokenizer() ) {
	}

	/**
	 * The first rule that answers a query.
	 *
	 * @param array<int, array{query: string, lang: string}> $rules The rules, in the owner's order.
	 * @param string                                         $query The query.
	 * @param string                                         $lang  Its language.
	 * @return int|null The rule's key, or null when none answers.
	 */
	public function match( array $rules, string $query, string $lang ): ?int {
		$lang = StopWords::code( $lang );
		$key  = $this->key( $query, $lang );
		if ( '' === $key ) {
			return null;
		}
		foreach ( $rules as $i => $rule ) {
			if ( ( '' === $rule['lang'] || StopWords::code( $rule['lang'] ) === $lang ) && $this->key( $rule['query'], $lang ) === $key ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * What a query is matched by: its words as the index reads them, in order; empty when it has none (only stop
	 * words, or only punctuation), and such a rule never answers.
	 *
	 * @param string $query The query.
	 * @param string $lang  Language code.
	 */
	public function key( string $query, string $lang ): string {
		return $this->tokenizer->key( $query, $lang );
	}
}
