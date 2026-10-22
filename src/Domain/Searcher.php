<?php
/**
 * The search.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a parsed query against an index (spec: Matching and ranking): each token widened in order (exact and
 * stem, and the prefix terms of the unfinished last token; then typo candidates when that finds nothing,
 * D-033); AND across tokens; minus words excluded; quoted phrases enforced; the least-matched token dropped once
 * when no product matches (D-034); hidden products only for their exact SKU; every product scored and sorted;
 * the categories, tags and brands any token names, scored by name; the fallback language when nothing is found.
 */
final class Searcher {

	public const PREFIX_TERMS = 20;
	public const TYPO_TERMS   = 10;

	/**
	 * Constructor.
	 *
	 * @param IndexStore $index    The index.
	 * @param Analyzer   $analyzer The analyzer, for stems.
	 * @param Scorer     $scorer   The scorer.
	 */
	public function __construct( private IndexStore $index, private Analyzer $analyzer = new Analyzer(), private Scorer $scorer = new Scorer() ) {
	}

	/**
	 * Search.
	 *
	 * @param Query         $query   A parsed query.
	 * @param SearchOptions $options Options.
	 */
	public function search( Query $query, SearchOptions $options = new SearchOptions() ): SearchResult {
		if ( $query->isEmpty() ) {
			return new SearchResult( array(), array(), $query->lang );
		}
		$result   = $this->run( $query, $query->lang, $options );
		$fallback = null === $options->fallbackLang ? null : StopWords::code( $options->fallbackLang );
		if ( $result->isEmpty() && null !== $fallback && $fallback !== $query->lang ) {
			$result = $this->run( $query, $fallback, $options );
		}
		return $result;
	}

	/**
	 * Search one language.
	 *
	 * @param Query         $query   The query.
	 * @param string        $lang    Language code.
	 * @param SearchOptions $options Options.
	 */
	private function run( Query $query, string $lang, SearchOptions $options ): SearchResult {
		$widenings = array();
		foreach ( $query->tokens as $i => $token ) {
			$widenings[ $i ] = $this->widen( $token, $lang );
		}
		$excluded = $this->excluded( $query, $lang );
		$n        = $this->index->documentCount( $lang );

		$matched   = $this->intersect( $query, $widenings, $excluded, $options );
		$corrected = null;
		$extra     = array();
		$reduced   = $query;
		$kept      = $widenings;
		if ( array() === $matched && $options->retry && count( $widenings ) >= 2 ) {
			$drop    = self::leastMatched( $widenings );
			$reduced = $query->without( $drop );
			$kept    = array_values( array_filter( $widenings, static fn( int $i ): bool => $i !== $drop, ARRAY_FILTER_USE_KEY ) );
			$extra   = $this->intersect( $reduced, $kept, $excluded, $options );
			if ( array() !== $extra ) {
				$corrected = $reduced->text;
			}
		}

		$products = array();
		foreach ( $matched as $document ) {
			$products[] = new Hit( $document, $this->scorer->score( $query, $document, $widenings, $n, $options ) );
		}
		usort( $products, array( Hit::class, 'compare' ) );
		$more = array();
		foreach ( $extra as $document ) {
			$more[] = new Hit( $document, $this->scorer->score( $reduced, $document, $kept, $n, $options ) );
		}
		usort( $more, array( Hit::class, 'compare' ) );
		$products = array_slice( array_merge( $products, $more ), 0, $options->limit );

		$terms = array();
		foreach ( $this->termDocuments( $widenings, $excluded ) as $document ) {
			$terms[] = new Hit( $document, $this->scorer->scoreTerm( $query, $document, $widenings ) );
		}
		usort( $terms, array( Hit::class, 'compareTerms' ) );
		$terms = array_slice( $terms, 0, $options->termLimit );

		return new SearchResult( $products, $terms, $lang, $corrected );
	}

	/**
	 * Widen one token: the exact term, its spellings, its stem and its synonyms, plus the terms it is a prefix
	 * of when it is the unfinished last token; typo candidates only when none of that finds anything.
	 *
	 * @param QueryToken $token A token.
	 * @param string     $lang  Language code.
	 */
	public function widen( QueryToken $token, string $lang ): TokenWidening {
		$matches = array( $token->text => new TermMatch( $token->text, TermMatch::EXACT ) );
		foreach ( $token->variants as $variant ) {
			$matches[ $variant ] = new TermMatch( $variant, TermMatch::EXACT );
		}
		if ( $token->stemmable() ) {
			$stem = $this->analyzer->stemOf( $token->source, $lang );
			if ( '' !== $stem ) {
				$matches[ $stem ] ??= new TermMatch( $stem, TermMatch::STEM );
			}
		}
		foreach ( $token->synonyms as $synonym ) {
			$matches[ $synonym ] ??= new TermMatch( $synonym, TermMatch::SYNONYM );
			$stem                  = $this->analyzer->stemOf( $synonym, $lang );
			if ( '' !== $stem ) {
				$matches[ $stem ] ??= new TermMatch( $stem, TermMatch::SYNONYM );
			}
		}
		if ( $token->prefix ) {
			foreach ( $this->index->termsWithPrefix( $token->text, self::PREFIX_TERMS ) as $term => $df ) {
				$matches[ $term ] ??= new TermMatch( (string) $term, TermMatch::PREFIX, $df );
			}
		}
		$level = $this->lookup( $matches, $lang );
		if ( ! $level->isEmpty() ) {
			return $level;
		}
		if ( $token->fuzzable() ) {
			$level = $this->lookup( $this->typoCandidates( $token->text ), $lang );
			if ( ! $level->isEmpty() ) {
				return $level;
			}
		}
		return new TokenWidening( array(), array(), array() );
	}

	/**
	 * The typo candidates of a token: the terms sharing at least half its trigrams, within the allowed
	 * Damerau–Levenshtein distance, the closest and most shared first, at most ten.
	 *
	 * @param string $text A folded token of four or more characters.
	 * @return array<string, TermMatch>
	 */
	private function typoCandidates( string $text ): array {
		$allowed    = Distance::allowedFor( $text );
		$candidates = array();
		foreach ( $this->index->termsSharingTrigrams( Trigrams::of( $text ), Trigrams::minimumShared( $text ) ) as $term => $shared ) {
			$term     = (string) $term;
			$distance = Distance::damerauLevenshtein( $text, $term, $allowed );
			if ( $distance <= $allowed ) {
				$candidates[] = array( $term, $distance, $shared );
			}
		}
		usort( $candidates, static fn( array $a, array $b ): int => array( $a[1], $b[2], $a[0] ) <=> array( $b[1], $a[2], $b[0] ) );
		$matches = array();
		foreach ( array_slice( $candidates, 0, self::TYPO_TERMS ) as [ $term, $distance ] ) {
			$matches[ $term ] = new TermMatch( $term, $distance <= 1 ? TermMatch::TYPO1 : TermMatch::TYPO2 );
		}
		return $matches;
	}

	/**
	 * Fetch the postings of a set of candidate terms and fill in their document frequencies.
	 *
	 * @param array<string, TermMatch> $matches Candidate terms.
	 * @param string                   $lang    Language code.
	 */
	private function lookup( array $matches, string $lang ): TokenWidening {
		$terms    = self::termsOf( $matches );
		$products = $this->index->postings( $terms, $lang, true );
		$others   = $this->index->postings( $terms, $lang, false );
		$found    = array();
		foreach ( $matches as $term => $match ) {
			// df is the documents of this language that carry the term, counted from the postings just read: the
			// spec's df, over the same set as N (D-050).
			$df = count( $products[ $term ] ?? array() ) + count( $others[ $term ] ?? array() );
			if ( $df > 0 ) {
				$found[ $term ] = $match->withDf( $df );
			}
		}
		return new TokenWidening( $found, $products, $others ); // Every term with postings has a df, so both maps hold found terms only.
	}

	/**
	 * The terms of some matches, as the store takes them.
	 *
	 * @param array<string, TermMatch> $matches Matches by term.
	 * @return string[]
	 */
	private static function termsOf( array $matches ): array {
		$terms = array();
		foreach ( $matches as $match ) {
			$terms[] = $match->term;
		}
		return $terms;
	}

	/**
	 * The documents the minus words exclude (their exact terms, spellings and stems), as a set.
	 *
	 * @param Query  $query The query.
	 * @param string $lang  Language code.
	 * @return array<int, mixed>
	 */
	private function excluded( Query $query, string $lang ): array {
		$set = array();
		foreach ( $query->excluded as $token ) {
			$terms = array_merge( array( $token->text ), $token->variants );
			if ( $token->stemmable() ) {
				$terms[] = $this->analyzer->stemOf( $token->source, $lang );
			}
			foreach ( array( true, false ) as $products ) {
				foreach ( $this->index->postings( $terms, $lang, $products ) as $byDoc ) {
					$set += $byDoc;
				}
			}
		}
		return $set;
	}

	/**
	 * The products every token reached, minus the excluded, phrases enforced, hidden ones only for their SKU.
	 *
	 * @param Query             $query     The query (for phrases and the SKU key).
	 * @param TokenWidening[]   $widenings One per token.
	 * @param array<int, mixed> $excluded Excluded document ids.
	 * @param SearchOptions     $options   Options.
	 * @return array<int, Document> By id.
	 */
	private function intersect( Query $query, array $widenings, array $excluded, SearchOptions $options ): array {
		$set = null;
		foreach ( $widenings as $widening ) {
			$docs = $widening->productDocs();
			$set  = null === $set ? $docs : array_intersect_key( $set, $docs );
		}
		$set = array_diff_key( $set ?? array(), $excluded );
		foreach ( $query->phrases() as $indexes ) {
			$set = array_filter( $set, fn( int $doc ): bool => self::hasPhrase( $widenings, $indexes, $doc ), ARRAY_FILTER_USE_KEY );
		}
		$documents = $this->index->documents( array_keys( $set ) );
		return array_filter(
			$documents,
			static fn( Document $d ): bool => $d->visible || ( $options->hiddenBySku && in_array( $query->key(), $d->skuKeys, true ) )
		);
	}

	/**
	 * Whether some field of a document holds the phrase's tokens adjacent and in order, by first positions.
	 *
	 * @param TokenWidening[] $widenings One per token.
	 * @param int[]           $indexes   The phrase's token indexes, in order.
	 * @param int             $doc       Document id.
	 */
	private static function hasPhrase( array $widenings, array $indexes, int $doc ): bool {
		$fields = array();
		foreach ( $indexes as $order => $i ) {
			foreach ( $widenings[ $i ]->postingsIn( $doc, true ) as $byField ) {
				foreach ( $byField as $field => [ , $position ] ) {
					$fields[ $field ][ $order ] = min( $fields[ $field ][ $order ] ?? PHP_INT_MAX, $position );
				}
			}
		}
		foreach ( $fields as $positions ) {
			if ( count( $positions ) !== count( $indexes ) ) {
				continue;
			}
			$adjacent = true;
			$previous = null;
			foreach ( $positions as $position ) {
				$adjacent = $adjacent && ( null === $previous || $position === $previous + 1 );
				$previous = $position;
			}
			if ( $adjacent ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The token that reached the fewest products; the later one on a tie.
	 *
	 * @param non-empty-array<int, TokenWidening> $widenings One per token.
	 */
	private static function leastMatched( array $widenings ): int {
		$counts = array_map( static fn( TokenWidening $w ): int => count( $w->productDocs() ), $widenings );
		arsort( $counts ); // Stable: equal counts keep their order, so the last key is the later token.
		return array_key_last( $counts );
	}

	/**
	 * The categories, tags and brands any token reached, minus the excluded.
	 *
	 * @param TokenWidening[]   $widenings One per token.
	 * @param array<int, mixed> $excluded  Excluded document ids.
	 * @return array<int, Document>
	 */
	private function termDocuments( array $widenings, array $excluded ): array {
		$set = array();
		foreach ( $widenings as $widening ) {
			$set += $widening->termDocs();
		}
		return $this->index->documents( array_keys( array_diff_key( $set, $excluded ) ) );
	}
}
