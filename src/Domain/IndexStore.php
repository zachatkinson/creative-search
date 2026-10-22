<?php
/**
 * What the searcher needs from an index.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The index store as the searcher sees it: a dictionary with document frequencies, prefix and trigram lookups,
 * postings per language, and documents by id. The in-memory index of the test suite and the SQL repositories
 * of milestones 3 and 4 both implement it, so the ranking is tested once and runs everywhere (D-028).
 *
 * @phpstan-type Posting array{0: int, 1: int}
 * @phpstan-type PostingsByTerm array<string, array<int, array<int, array{0: int, 1: int}>>>
 */
interface IndexStore {

	/**
	 * The number of documents in a language: N in the score.
	 *
	 * @param string $lang Language code.
	 */
	public function documentCount( string $lang ): int;

	/**
	 * The document frequency of each term that exists.
	 *
	 * @param string[] $terms Folded terms; may be empty, which answers an empty array.
	 * @return array<string, int> Term to df, never zero; a term the dictionary lacks is absent.
	 */
	public function documentFrequencies( array $terms ): array;

	/**
	 * The terms starting with a prefix, most frequent first.
	 *
	 * @param string $prefix Folded prefix.
	 * @param int    $limit  At most this many.
	 * @return array<string, int> Term to df.
	 */
	public function termsWithPrefix( string $prefix, int $limit ): array;

	/**
	 * The terms sharing at least so many of the given trigrams, most shared first.
	 *
	 * @param string[] $trigrams      The token's trigrams.
	 * @param int      $minimumShared How many a candidate must share.
	 * @return array<string, int> Term to the number of trigrams shared.
	 */
	public function termsSharingTrigrams( array $trigrams, int $minimumShared ): array;

	/**
	 * The postings of some terms in the documents of one language: in the products, or in the categories, tags
	 * and brands (whose name field alone is scored).
	 *
	 * @param string[] $terms    Folded terms; may be empty, which answers an empty array.
	 * @param string   $lang     Language code.
	 * @param bool     $products True for product documents, false for the others.
	 * @return array<string, array<int, array<int, array{0: int, 1: int}>>> Term to document id to field id to
	 *                                                                      [tf, first position].
	 */
	public function postings( array $terms, string $lang, bool $products ): array;

	/**
	 * Documents by id.
	 *
	 * @param int[] $ids Document ids; may be empty, which answers an empty array.
	 * @return array<int, Document> Keyed by id; a missing id is absent.
	 */
	public function documents( array $ids ): array;
}
