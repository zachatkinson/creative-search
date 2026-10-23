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
		return self::find( $this->keys( $rules, $lang ), $this->key( $query, $lang ) );
	}

	/**
	 * What each rule that applies in a language is matched by, by its key in the list: a rule for every language
	 * is read with that language's stop words, a rule for another language is left out. Worked out once and
	 * kept, so a query is matched against 200 rules by one lookup (1.3 review M7).
	 *
	 * @param array<int, array{query: string, lang: string}> $rules The rules, in the owner's order.
	 * @param string                                         $lang  The query's language.
	 * @return array<int, string>
	 */
	public function keys( array $rules, string $lang ): array {
		$lang = StopWords::code( $lang );
		$keys = array();
		foreach ( $rules as $i => $rule ) {
			if ( '' === $rule['lang'] || StopWords::code( $rule['lang'] ) === $lang ) {
				$keys[ $i ] = $this->key( $rule['query'], $lang );
			}
		}
		return $keys;
	}

	/**
	 * The first rule matched by a query's key, or null; an empty key (a query of stop words) matches none.
	 *
	 * @param array<int, string> $keys From {@see QueryRules::keys()}.
	 * @param string             $key  The query's, from {@see QueryRules::key()}.
	 */
	public static function find( array $keys, string $key ): ?int {
		if ( '' === $key ) {
			return null;
		}
		$at = array_search( $key, $keys, true );
		return false === $at ? null : $at;
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
