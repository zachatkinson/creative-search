<?php
/**
 * The index as the searcher reads it.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Repository;

use CreativeBranding\Search\Domain\Document;
use CreativeBranding\Search\Domain\Fields;
use CreativeBranding\Search\Domain\IndexStore;
use CreativeBranding\Search\Index\AnalyzerFactory;
use CreativeBranding\Search\Index\IndexState;
use CreativeBranding\Search\Index\TermReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `Domain\IndexStore` over the four tables (D-028): every read is against the live generation, so a reindex
 * in progress is invisible to the searcher until the switch. The searcher counts each term's documents from the
 * postings it reads for the query's language (D-050); the dictionary's own `df` only orders prefix candidates.
 */
final class IndexRepository implements IndexStore {

	/**
	 * Whether documents carry their boost-rule references (only when the owner has rules).
	 *
	 * @var bool
	 */
	private bool $termRefs = false;

	/**
	 * The generation this copy reads, when pinned for one search.
	 *
	 * @var int|null
	 */
	private ?int $pinned = null;

	/**
	 * Constructor.
	 *
	 * @param IndexState         $state      The live generation.
	 * @param DocumentRepository $documents  Documents.
	 * @param TermRepository     $dictionary Terms.
	 * @param PostingRepository  $postings   Postings.
	 * @param TrigramRepository  $trigrams   Trigrams.
	 * @param AnalyzerFactory    $analyzers  The tokenizer, for the name key.
	 */
	public function __construct(
		private IndexState $state,
		private DocumentRepository $documents,
		private TermRepository $dictionary,
		private PostingRepository $postings,
		private TrigramRepository $trigrams,
		private AnalyzerFactory $analyzers
	) {
	}

	/**
	 * The number of documents in a language in the live generation.
	 *
	 * @param string $lang Language code.
	 */
	public function documentCount( string $lang ): int {
		return $this->documents->count( $this->generation(), $lang );
	}

	/**
	 * The terms starting with a prefix, most frequent first.
	 *
	 * @param string $prefix Folded prefix.
	 * @param int    $limit  At most.
	 * @return array<string, int>
	 */
	public function termsWithPrefix( string $prefix, int $limit ): array {
		return $this->dictionary->withPrefix( $prefix, $limit );
	}

	/**
	 * The terms sharing at least so many trigrams, most shared first.
	 *
	 * @param string[] $trigrams      The token's trigrams.
	 * @param int      $minimumShared Floor.
	 * @return array<string, int>
	 */
	public function termsSharingTrigrams( array $trigrams, int $minimumShared ): array {
		return $this->trigrams->termsSharing( $trigrams, $minimumShared );
	}

	/**
	 * The postings of some terms in the live generation's documents of one language.
	 *
	 * @param string[] $terms    Folded terms.
	 * @param string   $lang     Language code.
	 * @param bool     $products Product documents, or the others.
	 * @return array<string, array<int, array<int, array{0: int, 1: int}>>>
	 */
	public function postings( array $terms, string $lang, bool $products ): array {
		return $this->postings->forTerms( $terms, $this->generation(), $lang, $products );
	}

	/**
	 * Documents by id, with the name key computed from the title and the SKU keys from the postings.
	 *
	 * @param int[]  $ids Document ids.
	 * @param string $key The query's key: only that SKU key is read (one term, not every key of every document).
	 * @return array<int, Document>
	 */
	public function documents( array $ids, string $key = '' ): array {
		$rows      = $this->documents->rowsOf( $ids );
		$keys      = $this->postings->skuKeysOf( array_keys( $rows ), Fields::SKU, $key );
		$refs      = $this->termRefs ? $this->documents->termRefs( $this->productIds( $rows ), TermReader::taxonomies() ) : array();
		$tokenizer = $this->analyzers->tokenizer();
		$out       = array();
		foreach ( $rows as $id => $row ) {
			$lang       = (string) $row['lang'];
			$title      = (string) $row['title'];
			$out[ $id ] = new Document(
				$id,
				(string) $row['type'],
				(int) $row['object_id'],
				$lang,
				$title,
				$tokenizer->key( $title, $lang ),
				$keys[ $id ] ?? array(),
				'1' === $row['in_stock'],
				'1' === $row['visible'],
				(int) $row['boost'],
				(int) $row['total_sales'],
				(int) $row['object_id'],
				'product' === $row['type'] ? ( $refs[ (int) $row['object_id'] ] ?? array() ) : array()
			);
		}
		return $out;
	}

	/**
	 * A copy whose documents carry their boost-rule references (`category:12`), for a search with rules.
	 *
	 * @param bool $on Read them.
	 */
	public function withTermRefs( bool $on ): self {
		$copy           = clone $this;
		$copy->termRefs = $on;
		return $copy;
	}

	/**
	 * A copy that reads one generation throughout: a search reads the state once, not on every lookup.
	 *
	 * @param int $generation Generation.
	 */
	public function at( int $generation ): self {
		$copy         = clone $this;
		$copy->pinned = $generation;
		return $copy;
	}

	/**
	 * The generation read: the pinned one, else the live one, fresh.
	 */
	public function generation(): int {
		return $this->pinned ?? $this->state->live();
	}

	/**
	 * The product ids of some document rows.
	 *
	 * @param array<int, array<string, string|null>> $rows Rows.
	 * @return int[]
	 */
	private function productIds( array $rows ): array {
		$ids = array();
		foreach ( $rows as $row ) {
			if ( 'product' === $row['type'] ) {
				$ids[] = (int) $row['object_id'];
			}
		}
		return $ids;
	}

	/**
	 * The suggestion rows of some documents (url, image, prices), for milestone 4's renderer.
	 *
	 * @param int[] $ids Document ids.
	 * @return array<int, array<string, string|null>> By id.
	 */
	public function rows( array $ids ): array {
		return $this->documents->rowsOf( $ids );
	}
}
