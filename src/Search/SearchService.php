<?php
/**
 * The search service.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Search;

use CreativeBranding\Search\Domain\QueryParser;
use CreativeBranding\Search\Domain\SearchOptions;
use CreativeBranding\Search\Domain\SearchResult;
use CreativeBranding\Search\Domain\Searcher;
use CreativeBranding\Search\Domain\Synonyms;
use CreativeBranding\Search\Index\AnalyzerFactory;
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

	/**
	 * Constructor.
	 *
	 * @param IndexRepository   $index     The index.
	 * @param AnalyzerFactory   $analyzers The analyzer.
	 * @param SynonymRepository $synonyms  The owner's synonyms.
	 * @param Languages         $languages The indexed languages.
	 * @param Cache             $cache     Answers.
	 * @param SearchLog         $log       The log writer.
	 */
	public function __construct(
		private IndexRepository $index,
		private AnalyzerFactory $analyzers,
		private SynonymRepository $synonyms,
		private Languages $languages,
		private Cache $cache,
		private SearchLog $log
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
	 */
	public function run( string $query, string $lang, int $limit = self::MAX_IDS, int $termLimit = 0 ): SearchResult {
		$options  = $this->options( $limit, $termLimit );
		$store    = $this->index->at( $this->index->generation() )->withTermRefs( array() !== $options->boostRules );
		$langs    = array_values( array_unique( array_filter( array( $lang, $options->fallbackLang ) ) ) );
		$parser   = new QueryParser( $this->analyzers->tokenizer(), new Synonyms( $this->synonyms->forLanguages( $langs ), $this->analyzers->tokenizer() ) );
		$searcher = new Searcher( $store, $this->analyzers->analyzer() );
		return $searcher->search( $parser->parse( $query, $lang ), $options );
	}

	/**
	 * The ranked product ids for a results page, cached, logged.
	 *
	 * @param string      $raw    Query as received.
	 * @param string|null $lang   Language asked for.
	 * @param string      $source page or suggest.
	 * @return array{ids: int[], corrected: string|null, query: string, lang: string}
	 */
	public function rankedIds( string $raw, ?string $lang, string $source = 'page' ): array {
		$query = self::clean( $raw );
		$lang  = $this->language( $lang );
		if ( '' === $query ) {
			return array(
				'ids'       => array(),
				'corrected' => null,
				'query'     => '',
				'lang'      => $lang,
			);
		}
		$key    = Cache::key( $this->index->generation(), 'ids', array( $query, $lang, $this->settingsKey() ) );
		$cached = $this->cache->get( $key );
		if ( null === $cached ) {
			$result = $this->run( $query, $lang );
			$cached = array(
				'ids'       => $result->productIds(),
				'corrected' => $result->corrected,
			);
			$this->cache->set( $key, $cached );
		}
		$ids = Cast::ints( $cached['ids'] ?? array() );
		$this->log->record( $query, $lang, count( $ids ), $source );
		return array(
			'ids'       => $ids,
			'corrected' => isset( $cached['corrected'] ) && is_string( $cached['corrected'] ) ? $cached['corrected'] : null,
			'query'     => $query,
			'lang'      => $lang,
		);
	}

	/**
	 * The search options from the settings.
	 *
	 * @param int $limit     Products at most.
	 * @param int $termLimit Terms at most.
	 */
	public function options( int $limit, int $termLimit ): SearchOptions {
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
			max( 0, min( Settings::TERMS_MAX, $termLimit ) )
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
		return array( Settings::get( 'fields' ), Settings::get( 'hidden_by_sku' ), Settings::get( 'fallback_to_default_language' ), Settings::get( 'boost_rules' ) );
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
