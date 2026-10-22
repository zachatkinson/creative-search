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
 * The index store as the searcher sees it: prefix and trigram lookups on the dictionary, document
 * frequencies, the categories', tags' and brands' postings, documents by id, and the ranking of a plan. The in-memory index of the test suite and the SQL repositories
 * of milestones 3 and 4 both implement it, so the ranking is tested once and runs everywhere (D-028).
 *
 * @phpstan-type Posting array{0: int, 1: int}
 * @phpstan-type PostingsByTerm array<string, array<int, array<int, array{0: int, 1: int}>>>
 */
interface IndexStore {

	/**
	 * The number of documents in a language: N in the score. The catalogue's (products, categories, tags and
	 * brands) for a product or term search; the posts and pages' for a content search (D-093).
	 *
	 * @param string $lang Language code.
	 * @param int    $kind The search's kind ({@see Kind}).
	 */
	public function documentCount( string $lang, int $kind = Kind::PRODUCT ): int;

	/**
	 * The terms starting with a prefix, most frequent first: the catalogue's, or for a content search the
	 * terms the posts and pages of a language carry (D-093).
	 *
	 * @param string $prefix Folded prefix.
	 * @param int    $limit  At most this many.
	 * @param int    $kind   The search's kind ({@see Kind}).
	 * @param string $lang   Language code, for a content search.
	 * @return array<string, int> Term to df.
	 */
	public function termsWithPrefix( string $prefix, int $limit, int $kind = Kind::PRODUCT, string $lang = '' ): array;

	/**
	 * The terms sharing at least so many of the given trigrams, most shared first.
	 *
	 * @param string[] $trigrams      The token's trigrams.
	 * @param int      $minimumShared How many a candidate must share.
	 * @return array<string, int> Term to the number of trigrams shared.
	 */
	public function termsSharingTrigrams( array $trigrams, int $minimumShared ): array;

	/**
	 * The postings of some terms in the documents of one language and kind: the products, the categories, tags
	 * and brands (whose name field alone is scored), or the posts and pages.
	 *
	 * @param string[] $terms Folded terms; may be empty, which answers an empty array.
	 * @param string   $lang  Language code.
	 * @param int      $kind  The documents' kind ({@see Kind}).
	 * @return array<string, array<int, array<int, array{0: int, 1: int}>>> Term to document id to field id to
	 *                                                                      [tf, first position].
	 */
	public function postings( array $terms, string $lang, int $kind ): array;

	/**
	 * Documents by id.
	 *
	 * @param int[]  $ids Document ids; may be empty, which answers an empty array.
	 * @param string $key The query's key: when given, a document's SKU keys hold that key alone, when it has it,
	 *                    since the search only compares them with it; empty gives them all.
	 * @return array<int, Document> Keyed by id; a missing id is absent.
	 */
	public function documents( array $ids, string $key = '' ): array;

	/**
	 * The document frequency of some terms in one language: how many documents carry each, counted over the
	 * same set as {@see documentCount()}: the catalogue's, or the posts and pages'.
	 *
	 * @param string[] $terms Folded terms; may be empty.
	 * @param string   $lang  Language code.
	 * @param int      $kind  The search's kind ({@see Kind}).
	 * @return array<string, int> Term to df; a term no document carries is absent.
	 */
	public function frequencies( array $terms, string $lang, int $kind = Kind::PRODUCT ): array;

	/**
	 * How many documents of one language and kind carry any of some terms (the retry drops the token that
	 * reached fewest).
	 *
	 * @param string[] $terms Folded terms; may be empty.
	 * @param string   $lang  Language code.
	 * @param int      $kind Products or content ({@see Kind}).
	 */
	public function productCount( array $terms, string $lang, int $kind = Kind::PRODUCT ): int;

	/**
	 * The documents of a plan's kind, scored and in rank order, at most its limit (D-086).
	 *
	 * @param RankPlan $plan The plan.
	 * @return Hit[]
	 */
	public function rank( RankPlan $plan ): array;
}
