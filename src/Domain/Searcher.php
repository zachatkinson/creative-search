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
 * the query's other forms from the owner's phrase synonyms ranked too and merged, each product at its best (D-109);
 * the categories, tags and brands any token names, scored by name; the fallback language when nothing is found.
 * A search of the posts and pages (1.1) runs the same way over their own postings and counts (D-093).
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
		$kind      = $options->kind;
		$widenings = $this->widenAll( $query, $lang, $kind, $options->strict );
		$excluded  = $this->excludedTerms( $query, $lang );
		$n         = $this->index->documentCount( $lang, $kind );

		$products = $this->index->rank( RankPlan::of( $query, $widenings, $lang, $n, $excluded, $options ) );
		$forms    = array( array( $query, $widenings ) );
		foreach ( $query->alternatives as $alternative ) {
			$widened  = $this->widenAll( $alternative, $lang, $kind, $options->strict );
			$forms[]  = array( $alternative, $widened );
			$products = self::merge( $products, $this->index->rank( RankPlan::of( $alternative, $widened, $lang, $n, $excluded, $options ) ), $options->limit );
		}
		$corrected = null;
		$answered  = $query;
		$used      = $widenings;
		if ( array() === $products && $options->retry && count( $widenings ) >= 2 ) {
			$drop     = $this->leastMatched( $widenings, $lang, $kind );
			$reduced  = $query->without( $drop );
			$kept     = array_values( array_filter( $widenings, static fn( int $i ): bool => $i !== $drop, ARRAY_FILTER_USE_KEY ) );
			$products = $this->index->rank( RankPlan::of( $reduced, $kept, $lang, $n, $excluded, $options ) );
			if ( array() !== $products ) {
				$corrected = $reduced->text;
				$answered  = $reduced;
				$used      = $kept;
			}
		}

		$terms = array();
		if ( Kind::CONTENT !== $kind ) {
			$others = $this->excludedOthers( $excluded, $lang );
			foreach ( $forms as [ $form, $widened ] ) {
				foreach ( $this->termDocuments( $widened, $others ) as $document ) {
					$terms[] = new Hit( $document, $this->scorer->scoreTerm( $form, $document, $widened ) );
				}
			}
		}
		$terms = array_slice( self::best( $terms, 'compareTerms' ), 0, $options->termLimit );

		return new SearchResult( $products, $terms, $lang, $corrected, array_map( static fn( TokenWidening $w ): array => self::termsOf( $w->matches ), $used ), $answered->key() );
	}

	/**
	 * Widen every token of a query.
	 *
	 * @param Query  $query  The query.
	 * @param string $lang   Language code.
	 * @param int    $kind   What is searched ({@see Kind}).
	 * @param bool   $strict Strong matches only (D-118).
	 * @return TokenWidening[] Keyed as the query's tokens are.
	 */
	private function widenAll( Query $query, string $lang, int $kind, bool $strict ): array {
		$widenings = array();
		foreach ( $query->tokens as $i => $token ) {
			$widenings[ $i ] = $this->widen( $token, $lang, $kind, $strict );
		}
		return $widenings;
	}

	/**
	 * Two rankings as one (a query and one of its other forms, D-109): each product once, at its better score,
	 * in the products' order, within the limit.
	 *
	 * @param Hit[] $ranked One ranking.
	 * @param Hit[] $more   Another.
	 * @param int   $limit  At most this many.
	 * @return Hit[]
	 */
	private static function merge( array $ranked, array $more, int $limit ): array {
		return array_slice( self::best( array_merge( $ranked, $more ), 'compare' ), 0, $limit );
	}

	/**
	 * Hits in order, each document once, where it ranks best; keyed by document id (the callers' array_slice() renumbers them).
	 *
	 * @param Hit[]                    $hits  Hits, a document perhaps more than once.
	 * @param 'compare'|'compareTerms' $order {@see Hit::compare()} or {@see Hit::compareTerms()}.
	 * @return Hit[]
	 */
	private static function best( array $hits, string $order ): array {
		usort( $hits, array( Hit::class, $order ) );
		$best = array();
		foreach ( $hits as $hit ) {
			$best[ $hit->document->id ] ??= $hit;
		}
		return $best;
	}

	/**
	 * Widen one token: the exact term, its spellings, its stem and its synonyms, plus the terms it is a prefix
	 * of when it is the unfinished last token; typo candidates only when none of that finds anything.
	 *
	 * @param QueryToken $token  A token.
	 * @param string     $lang   Language code.
	 * @param int        $kind   What is searched ({@see Kind}): its terms alone are kept.
	 * @param bool       $strict Strong matches only (D-118): no prefix terms, no typo candidates.
	 */
	public function widen( QueryToken $token, string $lang, int $kind = Kind::PRODUCT, bool $strict = false ): TokenWidening {
		// A word a phrase synonym put in scores as a synonym, its stem too (D-109).
		$exact   = $token->synonym ? TermMatch::SYNONYM : TermMatch::EXACT;
		$matches = array( $token->text => new TermMatch( $token->text, $exact ) );
		foreach ( $token->variants as $variant ) {
			$matches[ $variant ] = new TermMatch( $variant, $exact );
		}
		if ( $token->stemmable() ) {
			$stem = $this->analyzer->stemOf( $token->source, $lang );
			if ( '' !== $stem ) {
				$matches[ $stem ] ??= new TermMatch( $stem, $token->synonym ? TermMatch::SYNONYM : TermMatch::STEM );
			}
		}
		foreach ( $token->synonyms as $synonym ) {
			$matches[ $synonym ] ??= new TermMatch( $synonym, TermMatch::SYNONYM );
			$stem                  = $this->analyzer->stemOf( $synonym, $lang );
			if ( '' !== $stem ) {
				$matches[ $stem ] ??= new TermMatch( $stem, TermMatch::SYNONYM );
			}
		}
		if ( $token->prefix && ! $strict ) {
			foreach ( $this->index->termsWithPrefix( $token->text, self::PREFIX_TERMS, $kind, $lang ) as $term => $df ) {
				$matches[ $term ] ??= new TermMatch( (string) $term, TermMatch::PREFIX, $df );
			}
		}
		$level = $this->lookup( $matches, $lang, $kind );
		if ( ! $level->isEmpty() ) {
			return $level;
		}
		if ( $token->fuzzable() && ! $strict ) {
			$level = $this->lookup( $this->typoCandidates( $token->text ), $lang, $kind, self::TYPO_TERMS );
			if ( ! $level->isEmpty() ) {
				return $level;
			}
		}
		return new TokenWidening( array(), array(), array() );
	}

	/**
	 * The typo candidates of a token: the terms sharing at least half its trigrams, within the allowed
	 * Damerau–Levenshtein distance, the closest and most shared first. The ten kept are the first the search's
	 * documents carry ({@see lookup()}), so a word only the posts carry never takes a product's place.
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
		foreach ( $candidates as [ $term, $distance ] ) {
			$matches[ $term ] = new TermMatch( $term, $distance <= 1 ? TermMatch::TYPO1 : TermMatch::TYPO2 );
		}
		return $matches;
	}

	/**
	 * Fill in the document frequencies of a set of candidate terms, keep the ones some document of the search's
	 * kind carries (the first so many, in order, when capped), and fetch their postings in the categories, tags
	 * and brands (few; the products and the posts are ranked by the store).
	 *
	 * @param array<string, TermMatch> $matches Candidate terms.
	 * @param string                   $lang    Language code.
	 * @param int                      $kind    What is searched ({@see Kind}).
	 * @param int                      $cap     At most this many kept; 0 for all.
	 */
	private function lookup( array $matches, string $lang, int $kind, int $cap = 0 ): TokenWidening {
		$terms = self::termsOf( $matches );
		$dfs   = $this->index->frequencies( $terms, $lang, $kind );
		$found = array();
		foreach ( $matches as $term => $match ) {
			// df is the documents of this language that carry the term: the spec's df, over the same set as N (D-050).
			if ( isset( $dfs[ $term ] ) && ( 0 === $cap || count( $found ) < $cap ) ) {
				$found[ $term ] = $match->withDf( $dfs[ $term ] );
			}
		}
		$others = Kind::CONTENT === $kind ? array() : $this->index->postings( self::termsOf( $found ), $lang, Kind::TERM );
		return new TokenWidening( $found, array(), $others );
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
	 * The terms the minus words exclude: their exact terms, spellings and stems (a repeat or an empty stem
	 * matches nothing more).
	 *
	 * @param Query  $query The query.
	 * @param string $lang  Language code.
	 * @return string[]
	 */
	private function excludedTerms( Query $query, string $lang ): array {
		$terms = array();
		foreach ( $query->excluded as $token ) {
			array_push( $terms, $token->text, ...$token->variants );
			if ( $token->stemmable() ) {
				$terms[] = $this->analyzer->stemOf( $token->source, $lang );
			}
		}
		return $terms;
	}

	/**
	 * The categories, tags and brands the minus words exclude, as a set.
	 *
	 * @param string[] $terms The excluded terms.
	 * @param string   $lang  Language code.
	 * @return array<int, mixed>
	 */
	private function excludedOthers( array $terms, string $lang ): array {
		$set = array();
		foreach ( $this->index->postings( $terms, $lang, Kind::TERM ) as $byDoc ) {
			$set += $byDoc;
		}
		return $set;
	}

	/**
	 * The token that reached the fewest documents of the search's kind; the later one on a tie.
	 *
	 * @param non-empty-array<int, TokenWidening> $widenings One per token.
	 * @param string                              $lang      Language code.
	 * @param int                                 $kind      What is searched ({@see Kind}).
	 */
	private function leastMatched( array $widenings, string $lang, int $kind ): int {
		$counts = array_map( fn( TokenWidening $w ): int => $this->index->productCount( self::termsOf( $w->matches ), $lang, $kind ), $widenings );
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
