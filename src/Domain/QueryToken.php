<?php
/**
 * One query token.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A token of a parsed query with what the parser learned about it (spec: Matching → Parsing).
 */
final class QueryToken {

	/**
	 * Constructor.
	 *
	 * @param string   $text     The folded token.
	 * @param string   $source   The lower-cased word before folding, for the stemmer.
	 * @param bool     $negated  A minus word: documents with it are excluded.
	 * @param int      $phrase   The quoted phrase it belongs to (1-based), 0 when none.
	 * @param bool     $prefix   The unfinished last token: widened to the terms it starts.
	 * @param bool     $stopWord Whether it is a stop word in the query's language.
	 * @param string[] $synonyms Alternative terms from the owner's synonyms.
	 * @param string[] $variants The other spellings the tokenizer cut at the same position (`philly` for `phillys`).
	 * @param bool     $synonym  Put in by a phrase synonym (D-109): its exact term and stem score as a synonym.
	 */
	public function __construct(
		public readonly string $text,
		public readonly string $source,
		public readonly bool $negated = false,
		public readonly int $phrase = 0,
		public readonly bool $prefix = false,
		public readonly bool $stopWord = false,
		public readonly array $synonyms = array(),
		public readonly array $variants = array(),
		public readonly bool $synonym = false
	) {
	}

	/**
	 * A copy with other synonyms.
	 *
	 * @param string[] $synonyms Alternative terms.
	 */
	public function withSynonyms( array $synonyms ): self {
		return new self( $this->text, $this->source, $this->negated, $this->phrase, $this->prefix, $this->stopWord, $synonyms, $this->variants, $this->synonym );
	}

	/**
	 * A copy that is not a prefix.
	 */
	public function withoutPrefix(): self {
		return new self( $this->text, $this->source, $this->negated, $this->phrase, false, $this->stopWord, $this->synonyms, $this->variants, $this->synonym );
	}

	/**
	 * Whether the token may be widened to typo candidates: four or more characters, not a number, not SKU-shaped.
	 */
	public function fuzzable(): bool {
		return Distance::allowedFor( $this->text ) > 0 && ! Tokenizer::isNumber( $this->text ) && ! Tokenizer::isSkuShaped( $this->text );
	}

	/**
	 * Whether the token has a stem worth looking up: not a number, not SKU-shaped.
	 */
	public function stemmable(): bool {
		return ! Tokenizer::isNumber( $this->text ) && ! Tokenizer::isSkuShaped( $this->text );
	}
}
