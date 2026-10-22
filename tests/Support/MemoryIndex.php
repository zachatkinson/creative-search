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
		$this->counts[ $document->lang ]  = ( $this->counts[ $document->lang ] ?? 0 ) + 1;
		foreach ( $postings as $term => $byField ) {
			$term = (string) $term;
			if ( ! isset( $this->df[ $term ] ) ) {
				$this->df[ $term ] = 0;
				foreach ( Trigrams::of( $term ) as $trigram ) {
					$this->trigrams[ $trigram ][ $term ] = true;
				}
			}
			++$this->df[ $term ];
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
	public function documentCount( string $lang ): int {
		return $this->counts[ $lang ] ?? 0;
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
	public function termsWithPrefix( string $prefix, int $limit ): array {
		$out = array();
		foreach ( $this->df as $term => $df ) {
			if ( str_starts_with( (string) $term, $prefix ) ) {
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
		$shared = array_filter( $shared, static fn( int $n ): bool => $n >= $minimumShared );
		arsort( $shared ); // Most shared first; ties keep insertion order, as a SQL store might return them.
		return $shared;
	}

	/**
	 * {@inheritDoc}
	 */
	public function postings( array $terms, string $lang, bool $products ): array {
		$out = array();
		foreach ( $terms as $term ) {
			foreach ( $this->postings[ $term ] ?? array() as $doc => $byField ) {
				$document = $this->documents[ $doc ];
				if ( $document->lang === $lang && $document->isProduct() === $products ) {
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
	public function frequencies( array $terms, string $lang ): array {
		$out = array();
		foreach ( array( true, false ) as $products ) {
			foreach ( $this->postings( $terms, $lang, $products ) as $term => $byDoc ) {
				$out[ $term ] = ( $out[ $term ] ?? 0 ) + count( $byDoc );
			}
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 */
	public function productCount( array $terms, string $lang ): int {
		$set = array();
		foreach ( $this->postings( $terms, $lang, true ) as $byDoc ) {
			$set += $byDoc;
		}
		return count( $set );
	}

	/**
	 * {@inheritDoc}
	 */
	public function rank( RankPlan $plan ): array {
		$this->plans[] = $plan;
		return ( new Ranker() )->rank(
			$plan,
			$this->postings( array_merge( $plan->terms(), $plan->excluded ), $plan->lang, true ),
			fn( array $ids, string $key ): array => $this->documents( $ids, $key )
		);
	}
}
