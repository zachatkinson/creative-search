<?php
/**
 * Suggested searches.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Chooses the searches the panel suggests above the products (Baymard: keyword query suggestions; D-112) from
 * what other shoppers searched: the most searched first, never the query typed, never a redacted one, and only
 * a query whose every word the index still holds (as written, as another spelling or by its stem), so a word
 * the catalogue does not carry never reaches another shopper's screen.
 */
final class Completions {

	/**
	 * Suggested searches at most.
	 */
	public const MOST = 3;

	/**
	 * Constructor.
	 *
	 * @param QueryParser $parser   Cuts a candidate into its words (no synonyms).
	 * @param Analyzer    $analyzer For the words' stems.
	 */
	public function __construct( private QueryParser $parser = new QueryParser(), private Analyzer $analyzer = new Analyzer() ) {
	}

	/**
	 * The terms the candidates' words may be indexed under, to look up in one go.
	 *
	 * @param string[] $candidates Past searches.
	 * @param string   $lang       Language code.
	 * @return string[]
	 */
	public function terms( array $candidates, string $lang ): array {
		$terms = array();
		foreach ( $candidates as $candidate ) {
			foreach ( $this->parser->parse( $candidate, $lang )->tokens as $token ) {
				array_push( $terms, ...$this->forms( $token, $lang ) );
			}
		}
		return array_values( array_unique( $terms ) );
	}

	/**
	 * The searches to suggest, in the candidates' order.
	 *
	 * @param string             $typed      What the shopper typed.
	 * @param string[]           $candidates Past searches, the most searched first.
	 * @param array<string, int> $known      The terms the index holds, to their document frequency.
	 * @param string             $lang       Language code.
	 * @param int                $most       At most this many.
	 * @return string[]
	 */
	public function pick( string $typed, array $candidates, array $known, string $lang, int $most = self::MOST ): array {
		$typedKey = $this->parser->parse( $typed, $lang )->key();
		$seen     = array( $typedKey => $typedKey );
		$out      = array();
		foreach ( $candidates as $candidate ) {
			$query = $this->parser->parse( $candidate, $lang );
			$key   = $query->key();
			if ( count( $out ) >= $most || isset( $seen[ $key ] ) || str_contains( $candidate, Scrub::REDACTED ) || ! $this->known( $query, $known, $lang ) ) {
				continue;
			}
			$seen[ $key ] = $key;
			$out[]        = $candidate;
		}
		return $out;
	}

	/**
	 * Whether the index holds every word of a query: it has words, and each is indexed in some form.
	 *
	 * @param Query              $query A candidate, parsed.
	 * @param array<string, int> $known Terms the index holds.
	 * @param string             $lang  Language code.
	 */
	private function known( Query $query, array $known, string $lang ): bool {
		foreach ( $query->tokens as $token ) {
			if ( array() === array_intersect_key( $known, array_flip( $this->forms( $token, $lang ) ) ) ) {
				return false;
			}
		}
		return array() !== $query->tokens;
	}

	/**
	 * The forms a word may be indexed under: as written, its other spellings, its stem.
	 *
	 * @param QueryToken $token A word.
	 * @param string     $lang  Language code.
	 * @return string[]
	 */
	private function forms( QueryToken $token, string $lang ): array {
		$forms = array( $token->text, ...$token->variants );
		if ( $token->stemmable() ) {
			$forms[] = $this->analyzer->stemOf( $token->source, $lang );
		}
		return $forms;
	}
}
