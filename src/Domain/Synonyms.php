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
 * The owner's synonyms, applied to queries: each adds alternative terms to the typed token and never replaces
 * it (spec: Matching → Parsing). A `word` is one token; a `means` of several words adds each of them as an
 * alternative (D-029). Both-ways rows also map every word of `means` back to `word`.
 */
final class Synonyms {

	/**
	 * Alternatives by language, then by folded token: a set of folded terms.
	 *
	 * @var array<string, array<string, array<string, string>>>
	 */
	private array $map = array();

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
	 * Whether any synonym is defined.
	 */
	public function isEmpty(): bool {
		return array() === $this->map;
	}

	/**
	 * Add one row.
	 *
	 * @param Synonym $row The row.
	 */
	private function add( Synonym $row ): void {
		$lang  = StopWords::code( $row->lang );
		$words = $this->terms( $row->word, $lang );
		$means = $this->terms( $row->means, $lang );
		if ( 1 !== count( $words ) || array() === $means ) {
			return;
		}
		foreach ( $means as $term ) {
			$this->map[ $lang ][ $words[0] ][ $term ] = $term;
			if ( $row->bothWays ) {
				$this->map[ $lang ][ $term ][ $words[0] ] = $words[0];
			}
		}
	}

	/**
	 * The distinct folded terms of a phrase, stop words out.
	 *
	 * @param string $phrase Text.
	 * @param string $lang   Language code.
	 * @return string[]
	 */
	private function terms( string $phrase, string $lang ): array {
		$terms = array();
		foreach ( $this->tokenizer->tokenize( $phrase, $lang ) as $token ) {
			$terms[ $token->text ] = $token->text;
		}
		return array_values( $terms );
	}
}
