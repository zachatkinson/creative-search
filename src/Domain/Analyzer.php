<?php
/**
 * Text to postings.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

use CreativeBranding\Search\Domain\Stemmer\Stemmers;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a document's fields into postings: each token, and its stem as a second posting with the same field
 * and position (spec: Indexing → Stems). A field with several values (categories, SKUs, attribute values)
 * leaves a gap of two positions between values so a phrase never spans two of them. In the SKU field a term
 * counts once however many variation SKUs carry it: an identifier matches or does not (D-038).
 */
final class Analyzer {

	/**
	 * Fields where tf stays 1.
	 *
	 * @var int[]
	 */
	private const IDENTIFIER_FIELDS = array( Fields::SKU );

	/**
	 * Constructor.
	 *
	 * @param Tokenizer $tokenizer The tokenizer.
	 * @param Stemmers  $stemmers  The stemmers.
	 */
	public function __construct( private Tokenizer $tokenizer = new Tokenizer(), private Stemmers $stemmers = new Stemmers() ) {
	}

	/**
	 * The postings of a document.
	 *
	 * @param array<int, string|string[]> $fields Field id to its text, or its texts.
	 * @param string                      $lang   Language code.
	 * @return array<string, array<int, array{0: int, 1: int}>> Term to field id to [tf, first position].
	 */
	public function analyze( array $fields, string $lang ): array {
		$postings = array();
		foreach ( $fields as $field => $values ) {
			$offset = 0;
			foreach ( (array) $values as $value ) {
				$tokens = $this->tokenizer->tokenize( $value, $lang );
				$last   = -1;
				foreach ( $tokens as $token ) {
					$position = $offset + $token->position;
					$last     = max( $last, $token->position );
					self::count( $postings, $token->text, $field, $position );
					$stem = $this->stem( $token, $lang );
					if ( '' !== $stem && $stem !== $token->text ) {
						self::count( $postings, $stem, $field, $position );
					}
				}
				$offset += $last + 2;
			}
		}
		return $postings;
	}

	/**
	 * The folded stem of a token, or an empty string for a token that is not stemmed (numbers, SKU-shaped).
	 *
	 * @param Token  $token A token.
	 * @param string $lang  Language code.
	 */
	public function stem( Token $token, string $lang ): string {
		if ( Tokenizer::isNumber( $token->text ) || Tokenizer::isSkuShaped( $token->text ) ) {
			return '';
		}
		return $this->stemOf( $token->source(), $lang );
	}

	/**
	 * The folded stem of a lower-cased word.
	 *
	 * @param string $source A lower-cased word, accents kept.
	 * @param string $lang   Language code.
	 */
	public function stemOf( string $source, string $lang ): string {
		return Folding::fold( $this->stemmers->for( $lang )->stem( $source ) );
	}

	/**
	 * The tokenizer in use.
	 */
	public function tokenizer(): Tokenizer {
		return $this->tokenizer;
	}

	/**
	 * Count one occurrence.
	 *
	 * @param array<string, array<int, array{0: int, 1: int}>> $postings The postings so far, by reference.
	 * @param string                                           $term     The term.
	 * @param int                                              $field    Field id.
	 * @param int                                              $position Position.
	 */
	private static function count( array &$postings, string $term, int $field, int $position ): void {
		if ( ! isset( $postings[ $term ][ $field ] ) ) {
			$postings[ $term ][ $field ] = array( 1, $position );
			return;
		}
		if ( ! in_array( $field, self::IDENTIFIER_FIELDS, true ) ) {
			++$postings[ $term ][ $field ][0];
		}
		$postings[ $term ][ $field ][1] = min( $postings[ $term ][ $field ][1], $position );
	}
}
