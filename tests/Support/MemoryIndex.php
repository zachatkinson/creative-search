<?php
/**
 * An in-memory index for the unit suite.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Support;

use CreativeBranding\Search\Domain\Document;
use CreativeBranding\Search\Domain\IndexStore;
use CreativeBranding\Search\Domain\Kind;
use CreativeBranding\Search\Domain\RankPlan;
use CreativeBranding\Search\Domain\Ranker;
use CreativeBranding\Search\Domain\Trigrams;

/**
 * The hand-built index the golden set is scored against (spec: milestone 2). It keeps exactly what the six
 * tables keep: documents, a dictionary with df, postings per term, document and field, and trigrams per term.
 */
final class MemoryIndex implements IndexStore {

	/**
	 * Documents by id.
	 *
	 * @var array<int, Document>
	 */
	private array $documents = array();

	/**
	 * Term to document id to field to [tf, first position].
	 *
	 * @var array<string, array<int, array<int, array{0: int, 1: int}>>>
	 */
	private array $postings = array();

	/**
	 * Term to df.
	 *
	 * @var array<string, int>
	 */
	private array $df = array();

	/**
	 * Trigram to the terms carrying it.
	 *
	 * @var array<string, array<string, true>>
	 */
	private array $trigrams = array();

	/**
	 * Documents per language.
	 *
	 * @var array<string, int>
	 */
	private array $counts = array();

	/**
	 * The query keys documents() was asked with, in order.
	 *
	 * @var string[]
	 */
	public array $keysAsked = array();

	/**
	 * The plans rank() was asked to run, in order.
	 *
	 * @var RankPlan[]
	 */
	public array $plans = array();

	/**
	 * Add a document with its postings.
	 *
	 * @param Document                                        $document The document.
	 * @param array<string, array<int, array{0: int, 1: int}>> $postings Term to field to [tf, first position].
	 */
	public function add( Document $document, array $postings ): void {
		$this->documents[ $document->id ] = $document;
		$content                          = $document->isContent();
		$count                            = $content ? 'content:' . $document->lang : $document->lang;
		$this->counts[ $count ]           = ( $this->counts[ $count ] ?? 0 ) + 1;
		foreach ( $postings as $term => $byField ) {
			$term = (string) $term;
			if ( ! isset( $this->df[ $term ] ) ) {
				$this->df[ $term ] = 0;
				foreach ( Trigrams::of( $term ) as $trigram ) {
					$this->trigrams[ $trigram ][ $term ] = true;
				}
			}
			// The dictionary counts the catalogue only, as the SQL store's does (D-093).
			if ( ! $content ) {
				++$this->df[ $term ];
			}
			$this->postings[ $term ][ $document->id ] = $byField;
		}
	}

	/**
	 * How many terms the dictionary holds.
	 */
	public function termCount(): int {
		return count( $this->df );
	}

	/**
	 * How many postings rows there are.
	 */
	public function postingCount(): int {
		$n = 0;
		foreach ( $this->postings as $byDoc ) {
			foreach ( $byDoc as $byField ) {
				$n += count( $byField );
			}
		}
		return $n;
	}

	/**
	 * {@inheritDoc}
	 */
	public function documentCount( string $lang, int $kind = Kind::PRODUCT ): int {
		return $this->counts[ Kind::CONTENT === $kind ? 'content:' . $lang : $lang ] ?? 0;
	}

	/**
	 * The document frequency of each term that exists, across languages (the fixture's own check).
	 *
	 * @param string[] $terms Terms.
	 * @return array<string, int>
	 */
	public function documentFrequencies( array $terms ): array {
		$out = array();
		foreach ( $terms as $term ) {
			if ( isset( $this->df[ $term ] ) ) {
				$out[ $term ] = $this->df[ $term ];
			}
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 */
	public function termsWithPrefix( string $prefix, int $limit, int $kind = Kind::PRODUCT, string $lang = '' ): array {
		$out = array();
		foreach ( $this->df as $term => $df ) {
			if ( ! str_starts_with( (string) $term, $prefix ) ) {
				continue;
			}
			if ( Kind::CONTENT === $kind ) {
				$df = count( $this->postings( array( (string) $term ), $lang, Kind::CONTENT )[ $term ] ?? array() );
			}
			if ( $df > 0 ) { // As the SQL store: a term no document of the kind counts is not offered.
				$out[ $term ] = $df;
			}
		}
		uksort( $out, static fn( string|int $a, string|int $b ): int => array( $out[ $b ], (string) $a ) <=> array( $out[ $a ], (string) $b ) );
		return array_slice( $out, 0, $limit, true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function termsSharingTrigrams( array $trigrams, int $minimumShared ): array {
		$shared = array();
		foreach ( array_unique( $trigrams ) as $trigram ) {
			foreach ( array_keys( $this->trigrams[ $trigram ] ?? array() ) as $term ) {
				$shared[ $term ] = ( $shared[ $term ] ?? 0 ) + 1;
			}
		}
		// As the SQL store: a term no product, category, tag or brand carries (df 0) is no typo candidate (D-093).
		$shared = array_filter( $shared, fn( int $n, string|int $term ): bool => $n >= $minimumShared && ( $this->df[ $term ] ?? 0 ) > 0, ARRAY_FILTER_USE_BOTH );
		arsort( $shared ); // Most shared first; ties keep insertion order, as a SQL store might return them.
		return $shared;
	}

	/**
	 * {@inheritDoc}
	 */
	public function postings( array $terms, string $lang, int $kind ): array {
		$out = array();
		foreach ( $terms as $term ) {
			foreach ( $this->postings[ $term ] ?? array() as $doc => $byField ) {
				$document = $this->documents[ $doc ];
				if ( $document->lang === $lang && Kind::of( $document ) === $kind ) {
					$out[ $term ][ $doc ] = $byField;
				}
			}
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 */
	public function documents( array $ids, string $key = '' ): array {
		$this->keysAsked[] = $key;
		$out               = array();
		foreach ( $ids as $id ) {
			if ( isset( $this->documents[ $id ] ) ) {
				$out[ $id ] = $this->documents[ $id ];
			}
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 */
	public function frequencies( array $terms, string $lang, int $kind = Kind::PRODUCT ): array {
		$out = array();
		foreach ( Kind::CONTENT === $kind ? array( Kind::CONTENT ) : array( Kind::PRODUCT, Kind::TERM ) as $counted ) {
			foreach ( $this->postings( $terms, $lang, $counted ) as $term => $byDoc ) {
				$out[ $term ] = ( $out[ $term ] ?? 0 ) + count( $byDoc );
			}
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 */
	public function productCount( array $terms, string $lang, int $kind = Kind::PRODUCT ): int {
		$set = array();
		foreach ( $this->postings( $terms, $lang, $kind ) as $byDoc ) {
			$set += $byDoc;
		}
		return count( $set );
	}

	/**
	 * How many rankings were asked for.
	 *
	 * @var int
	 */
	public int $ranks = 0;

	/**
	 * {@inheritDoc}
	 */
	public function rank( RankPlan $plan ): array {
		++$this->ranks;
		$this->plans[] = $plan;
		return ( new Ranker() )->rank(
			$plan,
			$this->postings( array_merge( $plan->terms(), $plan->excluded ), $plan->lang, $plan->kind ),
			fn( array $ids, string $key ): array => $this->documents( $ids, $key )
		);
	}
}
