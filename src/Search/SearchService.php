<?php
/**
 * The search service.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Search;

use CreativeBranding\Search\Domain\Document;
use CreativeBranding\Search\Domain\Hit;
use CreativeBranding\Search\Domain\Kind;
use CreativeBranding\Search\Domain\QueryParser;
use CreativeBranding\Search\Domain\SearchOptions;
use CreativeBranding\Search\Domain\SearchResult;
use CreativeBranding\Search\Domain\Searcher;
use CreativeBranding\Search\Domain\Synonym;
use CreativeBranding\Search\Domain\Synonyms;
use CreativeBranding\Search\Domain\TitleMatch;
use CreativeBranding\Search\Index\AnalyzerFactory;
use CreativeBranding\Search\Index\ContentReader;
use CreativeBranding\Search\Index\Languages;
use CreativeBranding\Search\Index\TermReader;
use CreativeBranding\Search\Repository\IndexRepository;
use CreativeBranding\Search\Repository\SynonymRepository;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Cast;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one place a query string becomes a ranked answer (spec: Matching and ranking), shared by the REST routes,
 * the results page and WP-CLI: the query trimmed and capped, the language checked against the indexed ones,
 * the owner's synonyms, settings and boost rules applied, the Domain searcher run against the live index.
 * Answers are cached by generation; the log is written after the response.
 */
final class SearchService {

	public const MAX_QUERY = 200;
	public const MAX_IDS   = 500;
	public const MAX_TERMS = 10;

	/**
	 * Posts and pages at most: in the panel, and on the results page (D-093).
	 */
	public const CONTENT_PANEL   = 2;
	public const CONTENT_RESULTS = 3;
	public const CONTENT_MAX     = 10;

	/**
	 * The ranked products looked at for an exact name: the exact-name bonus puts it among the first.
	 */
	public const EXACT_SCAN = 10;

	/**
	 * Constructor.
	 *
	 * @param IndexRepository   $index     The index.
	 * @param AnalyzerFactory   $analyzers The analyzer.
	 * @param SynonymRepository $synonyms  The owner's synonyms.
	 * @param Languages         $languages The indexed languages.
	 * @param Cache             $cache     Answers.
	 * @param SearchLog         $log       The log writer.
	 * @param OwnerRules        $rules     The owner's pinned products (D-110).
	 */
	public function __construct(
		private IndexRepository $index,
		private AnalyzerFactory $analyzers,
		private SynonymRepository $synonyms,
		private Languages $languages,
		private Cache $cache,
		private SearchLog $log,
		private OwnerRules $rules
	) {
	}

	/**
	 * A query string trimmed, with control characters removed, capped at 200 characters.
	 *
	 * @param string $raw As received.
	 */
	public static function clean( string $raw ): string {
		$text = (string) preg_replace( '/[\p{Cc}\p{Cf}]+/u', ' ', $raw );
		return trim( mb_substr( trim( $text ), 0, self::MAX_QUERY, 'UTF-8' ) );
	}

	/**
	 * The language to search: the one asked for when it is indexed, else the site's.
	 *
	 * @param string|null $lang Asked for.
	 */
	public function language( ?string $lang ): string {
		$codes = $this->languages->codes();
		$code  = null === $lang ? '' : \CreativeBranding\Search\Domain\StopWords::code( $lang );
		return in_array( $code, $codes, true ) ? $code : ( in_array( Languages::siteCode(), $codes, true ) ? Languages::siteCode() : $codes[0] );
	}

	/**
	 * Run a search, uncached and unlogged (explain, tests).
	 *
	 * @param string $query    Clean query.
	 * @param string $lang     Checked language.
	 * @param int    $limit    Products at most.
	 * @param int    $termLimit Terms at most.
	 * @param bool   $retry     Retry without the least-matched token when nothing matches.
	 * @param bool   $explain   Rank in PHP, with every part of each score (D-086): the same order, more memory.
	 * @param int[]  $scope     Products of these term-taxonomy ids only (D-117).
	 */
	public function run( string $query, string $lang, int $limit = self::MAX_IDS, int $termLimit = 0, bool $retry = true, bool $explain = false, array $scope = array() ): SearchResult {
		return $this->searchWith( $query, $lang, $this->options( $limit, $termLimit, $retry, $scope ), $explain );
	}

	/**
	 * Run a search of the posts and pages, uncached and unlogged: no boosts, no hidden products, no terms (D-093).
	 *
	 * @param string $query   Clean query.
	 * @param string $lang    Checked language.
	 * @param int    $limit   Posts and pages at most.
	 * @param bool   $explain Rank in PHP, with every part of each score.
	 */
	public function runContent( string $query, string $lang, int $limit, bool $explain = false ): SearchResult {
		return $this->searchWith( $query, $lang, $this->options( 1, 0 )->forContent( max( 1, min( self::CONTENT_MAX, $limit ) ) ), $explain );
	}

	/**
	 * The posts and pages a query finds, as rows (`id`, `type`, `name`, `parent`: a child page's parent's title,
	 * `url`, `image`), cached; none, with no query run, while posts and pages are both switched off.
	 *
	 * @param string $query   Clean query.
	 * @param string $lang    Checked language.
	 * @param string $context `suggest` (the panel) or `page` (the results page).
	 * @return array<int, array{id: int, type: string, name: string, parent: string, url: string, image: array{src: string, srcset: string, width: int, height: int, alt: string}|null}>
	 */
	public function content( string $query, string $lang, string $context ): array {
		if ( '' === $query || array() === ContentReader::enabled() ) {
			return array();
		}
		$default = 'suggest' === $context ? self::CONTENT_PANEL : self::CONTENT_RESULTS;
		/**
		 * How many posts and pages to show beside the products.
		 *
		 * @since 1.1.0
		 *
		 * @param int    $limit   2 in the panel, 3 on the results page; 0 shows none. The panel never shows more
		 *                        than 2 (D-136), so there it can only be lowered.
		 * @param string $context `suggest` or `page`.
		 */
		$limit = max( 0, min( 'suggest' === $context ? self::CONTENT_PANEL : self::CONTENT_MAX, Cast::int( apply_filters( 'csearch_content_limit', $default, $context ) ) ) );
		if ( 0 === $limit ) {
			return array();
		}
		$key    = Cache::key( $this->index->generation(), 'content', array( $query, $lang, $limit, $context, $this->settingsKey() ) );
		$cached = $this->cache->get( $key );
		if ( null === $cached ) {
			$hits   = 'suggest' === $context ? $this->byTitle( $this->runContent( $query, $lang, self::CONTENT_MAX ), $limit ) : $this->runContent( $query, $lang, $limit )->products;
			$cached = array( 'ids' => array_map( static fn( Hit $hit ): int => $hit->document->id, $hits ) );
			$this->cache->set( $key, $cached );
		}
		$ids   = Cast::ints( $cached['ids'] ?? array() );
		$rows  = $this->index->rows( $ids );
		$posts = array_map( static fn( array $row ): int => Cast::int( $row['object_id'] ), $rows );
		// The posts, then their parents, each in one query: a row's parent title costs nothing more.
		_prime_post_caches( $posts, false, false );
		_prime_post_caches( array_map( static fn( int $id ): int => (int) wp_get_post_parent_id( $id ), $posts ), false, false );
		$out = array();
		foreach ( $ids as $id ) {
			$row  = $rows[ $id ] ?? null;
			$post = null === $row ? null : get_post( Cast::int( $row['object_id'] ) );
			if ( null === $row || ! $post instanceof \WP_Post ) {
				continue;
			}
			$out[] = array(
				'id'     => Cast::int( $row['object_id'] ),
				'type'   => Cast::str( $row['type'] ),
				'name'   => Cast::str( $row['title'] ),
				'parent' => ContentReader::parentTitle( $post ),
				'url'    => Cast::str( $row['url'] ),
				'image'  => Suggestions::image( Cast::int( $row['image_id'] ) ),
			);
		}
		return $out;
	}

	/**
	 * The posts and pages whose titles the query matches, in rank order (D-137): the panel suggests a page by its
	 * title, never for a word somewhere in its content. A search that had to drop a word suggests none.
	 *
	 * @param SearchResult $result The content search, ranked over the first ten.
	 * @param int          $limit  How many to keep.
	 * @return Hit[]
	 */
	private function byTitle( SearchResult $result, int $limit ): array {
		if ( $result->dropped || array() === $result->products ) {
			return array();
		}
		$ids    = array_map( static fn( Hit $hit ): int => $hit->document->id, $result->products );
		$terms  = array_values( array_unique( array_merge( ...array_values( $result->tokens ) ) ) );
		$fields = $this->index->fieldsOf( $ids, $terms, $result->lang, Kind::CONTENT );
		$kept   = array_filter( $result->products, static fn( Hit $hit ): bool => TitleMatch::covers( $result->tokens, $fields[ $hit->document->id ] ?? array() ) );
		return array_slice( array_values( $kept ), 0, $limit );
	}

	/**
	 * Whether one of a query's first ranked products is named exactly as the query reads: then a page never
	 * shows above it (Skatehouse handoff item 7), and a category query does not leave the search (item 10).
	 *
	 * @param string $query Clean query.
	 * @param string $lang  Checked language.
	 * @param int[]  $ids   The ranked product ids.
	 */
	public function namesProduct( string $query, string $lang, array $ids ): bool {
		return $this->index->anyNamed( $lang, array_slice( $ids, 0, self::EXACT_SCAN ), mb_substr( $this->analyzers->tokenizer()->key( $query, $lang ), 0, 255, 'UTF-8' ) );
	}

	/**
	 * The owner's synonyms in some languages, read once and kept with the answers (a synonym's change drops them).
	 *
	 * @param string[] $langs Language codes.
	 */
	public function synonymsFor( array $langs ): Synonyms {
		$key  = Cache::key( 0, 'synonyms', $langs );
		$rows = $this->cache->get( $key );
		if ( null === $rows ) {
			$rows = array_map( static fn( Synonym $s ): array => array( $s->lang, $s->word, $s->means, $s->bothWays ), $this->synonyms->forLanguages( $langs ) );
			$this->cache->set( $key, $rows );
		}
		$list = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && 4 === count( $row ) ) {
				$list[] = new Synonym( Cast::str( $row[0] ), Cast::str( $row[1] ), Cast::str( $row[2] ), true === $row[3] );
			}
		}
		return new Synonyms( $list, $this->analyzers->tokenizer() );
	}

	/**
	 * Run a search with some options against the live index.
	 *
	 * @param string        $query   Clean query.
	 * @param string        $lang    Checked language.
	 * @param SearchOptions $options Options.
	 * @param bool          $explain Rank in PHP, with every part of each score (D-086): the same order, more memory.
	 */
	private function searchWith( string $query, string $lang, SearchOptions $options, bool $explain ): SearchResult {
		$store    = $this->index->at( $this->index->generation() )->withTermRefs( array() !== $options->boostRules );
		$store    = $explain ? $store->explaining() : $store;
		$langs    = array_values( array_unique( array_filter( array( $lang, $options->fallbackLang ) ) ) );
		$parser   = new QueryParser( $this->analyzers->tokenizer(), $this->synonymsFor( $langs ) );
		$searcher = new Searcher( $store, $this->analyzers->analyzer() );
		return $searcher->search( $parser->parse( $query, $lang ), $options );
	}

	/**
	 * The owner's pinned products for a query (D-110), in the pins' order, as documents of the language that
	 * answered: one hidden from search, or not in the index in that language, is left out. The panel and the
	 * results page both put them first.
	 *
	 * @param string $query  The query, cleaned.
	 * @param string $lang   Its language.
	 * @param string $answer The language whose documents answered.
	 * @return array<int, Document> Product id to document.
	 */
	public function pinnedDocuments( string $query, string $lang, string $answer ): array {
		$pins = $this->rules->pins( $query, $lang );
		if ( array() === $pins ) {
			return array();
		}
		$pinned = array();
		foreach ( $this->index->productDocuments( $answer, $pins ) as $document ) {
			if ( $document->visible ) {
				$pinned[ $document->objectId ] = $document;
			}
		}
		return $pinned;
	}

	/**
	 * The ranked product ids for a results page, cached, logged, with what a page needs to say which variation
	 * the query named and why each product matched (D-104, D-105): each product's document, the terms each token
	 * reached, the query's key and the language whose documents answered.
	 *
	 * @param string      $raw    Query as received.
	 * @param string|null $lang   Language asked for.
	 * @param string      $source page or suggest; empty for a repeat of a search already logged.
	 * @param bool        $exact  The shopper's exact words: no token dropped ("search instead for").
	 * @param int[]       $scope  Products of these term-taxonomy ids only: a category and its subcategories (D-117).
	 * @param bool        $strong The strong matches only, when any (D-118): a sort other than Relevance.
	 * @return array{ids: int[], corrected: string|null, dropped: bool, query: string, lang: string, docs: array<int, int>, tokens: array<int, string[]>, key: string, from: string}
	 */
	public function rankedIds( string $raw, ?string $lang, string $source = 'page', bool $exact = false, array $scope = array(), bool $strong = false ): array {
		$query = self::clean( $raw );
		$lang  = $this->language( $lang );
		if ( '' === $query ) {
			return array(
				'ids'       => array(),
				'corrected' => null,
				'dropped'   => false,
				'query'     => '',
				'lang'      => $lang,
				'docs'      => array(),
				'tokens'    => array(),
				'key'       => '',
				'from'      => $lang,
			);
		}
		$key    = Cache::key( $this->index->generation(), 'ids', array( $query, $lang, $exact, $scope, $strong, $this->settingsKey() ) );
		$cached = $this->cache->get( $key );
		if ( null === $cached ) {
			$result = $strong ? $this->searchWith( $query, $lang, $this->options( self::MAX_IDS, 0, false, $scope, true ), false ) : null;
			if ( null === $result || array() === $result->products ) {
				// Nothing matches every word as typed: the sort keeps every match rather than show nothing (D-118).
				$result = $this->searchWith( $query, $lang, $this->options( self::MAX_IDS, 0, ! $exact, $scope ), false );
			}
			$docs = array();
			foreach ( $result->products as $hit ) {
				$docs[ $hit->document->objectId ] = $hit->document->id;
			}
			// The owner's pinned products first, in their order (D-110). One hidden from search is left out here: the
			// ranked query lifts WooCommerce's search exclusion (a hidden product's exact SKU may be ranked), and
			// WooCommerce's query still leaves out one that is not published.
			$pins   = array_keys( $this->pinnedDocuments( $query, $lang, $result->lang ) );
			$cached = array(
				'ids'       => array_slice( array_values( array_unique( array_merge( $pins, $result->productIds() ) ) ), 0, self::MAX_IDS ),
				'corrected' => $result->corrected,
				'dropped'   => $result->dropped,
				'docs'      => $docs,
				'tokens'    => $result->tokens,
				'key'       => $result->key,
				'from'      => $result->lang,
			);
			$this->cache->set( $key, $cached );
		}
		$ids = Cast::ints( $cached['ids'] ?? array() );
		if ( '' !== $source ) {
			$this->log->record( $query, $lang, count( $ids ), $source );
		}
		return array(
			'ids'       => $ids,
			'corrected' => isset( $cached['corrected'] ) && is_string( $cached['corrected'] ) ? $cached['corrected'] : null,
			'dropped'   => true === ( $cached['dropped'] ?? false ),
			'query'     => $query,
			'lang'      => $lang,
			'docs'      => self::documentsOf( $cached['docs'] ?? null ),
			'tokens'    => array_map( array( Cast::class, 'strings' ), is_array( $cached['tokens'] ?? null ) ? $cached['tokens'] : array() ),
			'key'       => Cast::str( $cached['key'] ?? null ),
			'from'      => Cast::str( $cached['from'] ?? null, $lang ),
		);
	}

	/**
	 * Product id to document id, from a cached answer (an answer cached before 1.3 has none).
	 *
	 * @param mixed $docs The cached map.
	 * @return array<int, int>
	 */
	private static function documentsOf( mixed $docs ): array {
		$out = array();
		foreach ( is_array( $docs ) ? $docs : array() as $product => $document ) {
			$out[ (int) $product ] = Cast::int( $document );
		}
		return $out;
	}

	/**
	 * The search options from the settings.
	 *
	 * @param int   $limit     Products at most.
	 * @param int   $termLimit Terms at most.
	 * @param bool  $retry     Retry without the least-matched token.
	 * @param int[] $scope     Products of these term-taxonomy ids only (D-117).
	 * @param bool  $strict    Strong matches only (D-118).
	 */
	public function options( int $limit, int $termLimit, bool $retry = true, array $scope = array(), bool $strict = false ): SearchOptions {
		$weights = Settings::fieldWeights();
		/**
		 * The field weights the score uses, by field name (name, sku, short, long, category, tag, brand, attribute).
		 *
		 * @since 0.4.0
		 *
		 * @param array<string, int> $weights The `fields` setting.
		 */
		$filtered = apply_filters( 'csearch_fields', $weights );
		$out      = array();
		foreach ( is_array( $filtered ) ? $filtered : $weights as $name => $weight ) {
			$out[ (string) $name ] = max( 0, min( 100, Cast::int( $weight ) ) );
		}
		return new SearchOptions(
			$out,
			Settings::isOn( 'hidden_by_sku' ),
			Settings::isOn( 'fallback_to_default_language' ) ? Languages::siteCode() : null,
			self::boostRules( Settings::get( 'boost_rules' ) ),
			max( 1, min( self::MAX_IDS, $limit ) ),
			max( 0, min( self::MAX_TERMS, $termLimit ) ),
			$retry,
			Kind::PRODUCT,
			$strict,
			array_values( array_unique( array_map( 'intval', $scope ) ) )
		);
	}

	/**
	 * The owner's boost rules as the scorer reads them: `category:12` to a boost.
	 *
	 * @param mixed $rules The `boost_rules` setting.
	 * @return array<string, int>
	 */
	public static function boostRules( mixed $rules ): array {
		$types = TermReader::TYPES;
		$out   = array();
		foreach ( is_array( $rules ) ? $rules : array() as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$taxonomy = Cast::str( $rule['taxonomy'] ?? '' );
			$type     = $types[ $taxonomy ] ?? ( in_array( $taxonomy, $types, true ) ? $taxonomy : null );
			$termId   = Cast::int( $rule['term_id'] ?? 0 );
			if ( null === $type || $termId <= 0 ) {
				continue;
			}
			$out[ $type . ':' . $termId ] = max( Settings::BOOST_MIN, min( Settings::BOOST_MAX, Cast::int( $rule['boost'] ?? 0 ) ) );
		}
		return $out;
	}

	/**
	 * What in the settings changes an answer, for the cache key.
	 *
	 * @return array<int, mixed>
	 */
	public function settingsKey(): array {
		return array( Settings::get( 'fields' ), Settings::get( 'hidden_by_sku' ), Settings::get( 'fallback_to_default_language' ), Settings::get( 'boost_rules' ), Settings::get( 'show_prices' ), Settings::get( 'index_posts' ), Settings::get( 'index_pages' ), Settings::get( OwnerRules::PINS ) );
	}

	/**
	 * The owner's rules for single queries.
	 */
	public function rules(): OwnerRules {
		return $this->rules;
	}

	/**
	 * The log writer (the suggest route records through it).
	 */
	public function log(): SearchLog {
		return $this->log;
	}

	/**
	 * The index (the suggest route reads the rows a suggestion renders from).
	 */
	public function index(): IndexRepository {
		return $this->index;
	}
}
