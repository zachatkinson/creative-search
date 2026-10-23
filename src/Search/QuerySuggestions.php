<?php
/**
 * Suggested searches for the panel.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Search;

use CreativeBranding\Search\Domain\Completions;
use CreativeBranding\Search\Domain\QueryParser;
use CreativeBranding\Search\Index\AnalyzerFactory;
use CreativeBranding\Search\Repository\IndexRepository;
use CreativeBranding\Search\Repository\LogRepository;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Clock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Up to three searches other shoppers made that start with what is typed (Baymard: keyword query suggestions;
 * D-112): from the search log of the retention window, in the panel's language, each made at least three times
 * and found something, each of its words still in the index. Two lookups, both on a key: the log by its
 * `normalised` prefix, then the dictionary for the candidates' words. Switched off by `suggest_queries`.
 */
final class QuerySuggestions {

	/**
	 * Searches a query needs before it is suggested to anyone.
	 */
	public const MINIMUM = 3;

	/**
	 * Characters typed before any is suggested: a shorter prefix reads too much of the log for too little.
	 */
	public const MIN_CHARS = 3;

	/**
	 * Candidates read from the log, before the ones the index cannot answer are left out.
	 */
	public const SCAN = 12;

	/**
	 * Constructor.
	 *
	 * @param LogRepository   $log       The search log.
	 * @param IndexRepository $index     The index, for the dictionary.
	 * @param AnalyzerFactory $analyzers The tokenizer and the stemmers.
	 */
	public function __construct( private LogRepository $log, private IndexRepository $index, private AnalyzerFactory $analyzers ) {
	}

	/**
	 * The searches to suggest for what is typed.
	 *
	 * @param string $query Clean query.
	 * @param string $lang  Checked language.
	 * @return string[]
	 */
	public function for( string $query, string $lang ): array {
		$typed = SearchLog::normalise( $query );
		if ( ! Settings::isOn( 'suggest_queries' ) || mb_strlen( $typed, 'UTF-8' ) < self::MIN_CHARS ) {
			return array();
		}
		$since      = Clock::format( Clock::timestamp() - Settings::retentionDays() * DAY_IN_SECONDS );
		$candidates = $this->log->completions( $typed, $lang, $since, self::MINIMUM, self::SCAN );
		if ( array() === $candidates ) {
			return array();
		}
		$completions = new Completions( new QueryParser( $this->analyzers->tokenizer() ), $this->analyzers->analyzer() );
		$known       = $this->index->frequencies( $completions->terms( $candidates, $lang ), $lang );
		$picked      = $completions->pick( $typed, $candidates, $known, $lang );
		/**
		 * The searches the panel suggests above the products, as an owner might keep a word off it.
		 *
		 * @since 1.3.0
		 *
		 * @param string[] $picked Up to three past searches, the most searched first, lower-cased.
		 * @param string   $query  What the shopper typed.
		 * @param string   $lang   Language code.
		 */
		$filtered = apply_filters( 'csearch_suggested_searches', $picked, $query, $lang );
		// Searches are words: a number or anything else a filter returns is not one.
		$searches = array_filter( is_array( $filtered ) ? $filtered : array(), static fn( mixed $q ): bool => is_string( $q ) && '' !== trim( $q ) );
		return array_slice( array_values( $searches ), 0, Completions::MOST );
	}
}
