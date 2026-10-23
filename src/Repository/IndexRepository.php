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
use CreativeBranding\Search\Domain\Hit;
use CreativeBranding\Search\Domain\Fact;
use CreativeBranding\Search\Domain\IndexStore;
use CreativeBranding\Search\Domain\Kind;
use CreativeBranding\Search\Domain\RankPlan;
use CreativeBranding\Search\Domain\Ranker;
use CreativeBranding\Search\Domain\Score;
use CreativeBranding\Search\Index\AnalyzerFactory;
use CreativeBranding\Search\Install\Upgrader;
use CreativeBranding\Search\Index\IndexState;
use CreativeBranding\Search\Index\TermReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `Domain\IndexStore` over the index tables (D-028): every read is against the live generation, so a reindex
 * in progress is invisible to the searcher until the switch. Each term's df is read for the query's language
 * and the generation from `csearch_term_stats` (D-050, D-087); the dictionary's own `df` only orders prefix
 * candidates. Products are ranked by one statement
 * (`RankRepository`, D-086); `explaining()` ranks them in PHP instead, from their postings, for the full
 * breakdown `explain` shows and for the parity test. While the schema upgrade waits, every read is schema 1's
 * (`SchemaOneReads`, D-090), so the shop keeps searching between an update and its upgrade.
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
	 * Whether products are ranked in PHP, with every part of each score.
	 *
	 * @var bool
	 */
	private bool $explaining = false;

	/**
	 * Constructor.
	 *
	 * @param IndexState         $state      The live generation.
	 * @param DocumentRepository $documents  Documents.
	 * @param TermRepository     $dictionary Terms.
	 * @param PostingRepository  $postings   Postings.
	 * @param TrigramRepository  $trigrams   Trigrams.
	 * @param AnalyzerFactory    $analyzers  The tokenizer, for the name key of an older generation.
	 * @param RankRepository     $ranks      The ranking statement.
	 * @param StatRepository     $stats      Term counts per generation and language.
	 * @param SchemaOneReads     $schemaOne  The reads while the schema upgrade waits (D-090).
	 * @param FactRepository     $facts      The products' SKUs and attribute values (D-104).
	 */
	public function __construct(
		private IndexState $state,
		private DocumentRepository $documents,
		private TermRepository $dictionary,
		private PostingRepository $postings,
		private TrigramRepository $trigrams,
		private AnalyzerFactory $analyzers,
		private RankRepository $ranks,
		private StatRepository $stats,
		private SchemaOneReads $schemaOne,
		private FactRepository $facts
	) {
	}

	/**
	 * The documents whose product is in a category or its subcategories, as the ranking statement keeps them (D-117):
	 * all of them when the search is not kept to one.
	 *
	 * @param array<int, Document> $documents Documents by id.
	 * @param int[]                $scope     Term-taxonomy ids; none keeps every document.
	 * @return array<int, Document>
	 */
	private function inScope( array $documents, array $scope ): array {
		if ( array() === $scope || array() === $documents ) {
			return $documents;
		}
		$objects = array_map( static fn( Document $d ): int => $d->objectId, $documents );
		$rows    = $this->ranks->scopedObjects( $objects, $scope );
		return array_filter( $documents, static fn( Document $d ): bool => isset( $rows[ $d->objectId ] ) );
	}

	/**
	 * The number of documents in a language in the live generation: the catalogue's, or the posts and pages'.
	 *
	 * @param string $lang Language code.
	 * @param int    $kind The search's kind.
	 */
	public function documentCount( string $lang, int $kind = Kind::PRODUCT ): int {
		return $this->documents->countOf( $this->generation(), $lang, Kind::CONTENT === $kind );
	}

	/**
	 * The terms starting with a prefix, most frequent first: the dictionary's for the catalogue, the posts and
	 * pages' own counted from their postings for a content search (D-093).
	 *
	 * @param string $prefix Folded prefix.
	 * @param int    $limit  At most.
	 * @param int    $kind   The search's kind.
	 * @param string $lang   Language code, for a content search.
	 * @return array<string, int>
	 */
	public function termsWithPrefix( string $prefix, int $limit, int $kind = Kind::PRODUCT, string $lang = '' ): array {
		if ( Kind::CONTENT === $kind ) {
			return Upgrader::below( '2' ) ? array() : $this->postings->contentPrefix( $prefix, $limit, $this->generation(), $lang );
		}
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
	 * @param int      $kind     The documents' kind.
	 * @return array<string, array<int, array<int, array{0: int, 1: int}>>>
	 */
	public function postings( array $terms, string $lang, int $kind ): array {
		if ( Upgrader::below( '2' ) ) {
			// Schema 1 had no posts or pages (D-090, D-093).
			return Kind::CONTENT === $kind ? array() : $this->schemaOne->forTerms( $terms, $this->generation(), $lang, Kind::PRODUCT === $kind );
		}
		return $this->postings->forTerms( $terms, $this->generation(), $lang, $kind );
	}

	/**
	 * Documents by id, with the name key from its column and the SKU keys from the postings.
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
			$nameKey    = (string) ( $row['name_key'] ?? '' );
			$out[ $id ] = new Document(
				$id,
				(string) $row['type'],
				(int) $row['object_id'],
				$lang,
				$title,
				// Written at index time since schema 2 (D-086); a document from an older generation is keyed here.
				'' !== $nameKey ? $nameKey : $tokenizer->key( $title, $lang ),
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
	 * How many documents of a language carry each term.
	 *
	 * @param string[] $terms Folded terms.
	 * @param string   $lang  Language code.
	 * @param int      $kind  The search's kind: the catalogue's stored counts, or the posts and pages' counted
	 *                        from their postings (D-093).
	 * @return array<string, int>
	 */
	public function frequencies( array $terms, string $lang, int $kind = Kind::PRODUCT ): array {
		if ( Kind::CONTENT === $kind ) {
			return Upgrader::below( '2' ) ? array() : $this->postings->contentFrequencies( $terms, $this->generation(), $lang );
		}
		return Upgrader::below( '2' )
			? $this->schemaOne->frequencies( $terms, $this->generation(), $lang )
			: $this->stats->frequencies( $terms, $this->generation(), $lang );
	}

	/**
	 * How many products of a language carry any of some terms.
	 *
	 * @param string[] $terms Folded terms.
	 * @param string   $lang  Language code.
	 * @param int      $kind  Products or content.
	 */
	public function productCount( array $terms, string $lang, int $kind = Kind::PRODUCT ): int {
		if ( Upgrader::below( '2' ) ) {
			return Kind::CONTENT === $kind ? 0 : $this->schemaOne->productCount( $terms, $this->generation(), $lang );
		}
		return $this->postings->productCount( $terms, $this->generation(), $lang, $kind );
	}

	/**
	 * The products of a plan, ranked: by one statement, or in PHP when explaining.
	 *
	 * @param RankPlan $plan The plan.
	 * @return Hit[]
	 */
	public function rank( RankPlan $plan ): array {
		if ( $this->explaining || Upgrader::below( '2' ) ) { // While the schema upgrade waits, the statement's columns may not be there yet (D-090).
			return ( new Ranker() )->rank(
				$plan,
				$this->postings( array_merge( $plan->terms(), $plan->excluded ), $plan->lang, $plan->kind ),
				fn( array $ids, string $key ): array => $this->inScope( $this->documents( $ids, $key ), $plan->scope )
			);
		}
		$hits = array();
		foreach ( $this->ranks->rank( $plan, $this->generation(), TermReader::taxonomies() ) as $row ) {
			$tokens = array();
			foreach ( explode( ',', (string) $row['bests'] ) as $pair ) {
				[ $i, $best ]       = array_pad( explode( ':', $pair, 2 ), 2, '0' );
				$tokens[ (int) $i ] = array(
					'token'  => $plan->texts[ (int) $i ] ?? '',
					'term'   => '',
					'kind'   => '',
					'field'  => 0,
					'weight' => 0,
					'm'      => 0.0,
					'tf'     => 0,
					'df'     => 0,
					'score'  => (float) $best,
				);
			}
			$hits[] = new Hit(
				new Document(
					(int) $row['id'],
					(string) $row['type'],
					(int) $row['object_id'],
					(string) $row['lang'],
					(string) $row['title'],
					(string) $row['name_key'],
					'1' === (string) $row['sku_hit'] ? array( $plan->key ) : array(),
					'1' === $row['in_stock'],
					'1' === $row['visible'],
					(int) $row['boost'],
					(int) $row['total_sales'],
					(int) $row['object_id']
				),
				new Score( $tokens, (float) $row['phrase'], (float) $row['exact'], (float) $row['stock'], (float) $row['owner'] )
			);
		}
		return $hits;
	}

	/**
	 * A copy that ranks products in PHP, with every part of each score (`explain`, the parity test).
	 */
	public function explaining(): self {
		$copy             = clone $this;
		$copy->explaining = true;
		$copy->termRefs   = true;
		return $copy;
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
	 * Whether any of some products, in the live generation, is named exactly as a query reads (its name key).
	 *
	 * @param string $lang       Language code.
	 * @param int[]  $productIds Product ids (the first of a ranked list: the exact name ranks high).
	 * @param string $key        The query's name key.
	 */
	public function anyNamed( string $lang, array $productIds, string $key ): bool {
		return $this->documents->anyNamed( $this->generation(), $lang, $productIds, $key );
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
	 * Which of some terms some product documents of a language carry, field by field, in the live generation
	 * (D-105).
	 *
	 * @param int[]    $documentIds Product document ids.
	 * @param string[] $terms       Terms.
	 * @param string   $lang        Language code.
	 * @return array<int, array<int, string[]>> Document id to field id to terms.
	 */
	public function fieldsOf( array $documentIds, array $terms, string $lang ): array {
		return Upgrader::below( '3' ) ? array() : $this->postings->fieldsOf( $documentIds, $terms, $this->generation(), $lang );
	}

	/**
	 * The SKUs and attribute values of some product documents (D-104); none until the schema has them.
	 *
	 * @param int[] $documentIds Product document ids.
	 * @return array<int, Fact[]> Document id to its facts.
	 */
	public function facts( array $documentIds ): array {
		return Upgrader::below( '3' ) ? array() : $this->facts->of( $documentIds );
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
