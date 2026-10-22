<?php
/**
 * Builds the analyzer with the owner's words.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Index;

use CreativeBranding\Search\Domain\Analyzer;
use CreativeBranding\Search\Domain\Stemmer\Stemmers;
use CreativeBranding\Search\Domain\StopWords;
use CreativeBranding\Search\Domain\Token;
use CreativeBranding\Search\Domain\Tokenizer;
use CreativeBranding\Search\Support\Cast;
use CreativeBranding\Search\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one analyzer the indexer, the query parser, WP-CLI and the results hook share (spec: DRY): the built-in
 * stop words plus the owner's (`csearch_stopwords_{lang}`) plus the `csearch_stop_words` filter, and the
 * `csearch_tokens` filter over every field's tokens. Built once per request.
 */
final class AnalyzerFactory {

	/**
	 * The built analyzer.
	 *
	 * @var Analyzer|null
	 */
	private ?Analyzer $analyzer = null;

	/**
	 * The built tokenizer.
	 *
	 * @var Tokenizer|null
	 */
	private ?Tokenizer $tokenizer = null;

	/**
	 * Constructor.
	 *
	 * @param Languages $languages The languages to index.
	 */
	public function __construct( private Languages $languages ) {
	}

	/**
	 * The analyzer.
	 */
	public function analyzer(): Analyzer {
		if ( null === $this->analyzer ) {
			$this->analyzer = new Analyzer(
				$this->tokenizer(),
				new Stemmers(),
				static function ( array $tokens, int $field, string $lang ): array {
					/**
					 * The tokens cut from one field of a document.
					 *
					 * @since 0.4.0
					 *
					 * @param Token[] $tokens The tokens, folded, with positions.
					 * @param int     $field  Domain\Fields id.
					 * @param string  $lang   Language code.
					 */
					$filtered = apply_filters( 'csearch_tokens', $tokens, $field, $lang );
					return is_array( $filtered ) ? array_values( array_filter( $filtered, static fn( $t ): bool => $t instanceof Token ) ) : $tokens;
				}
			);
		}
		return $this->analyzer;
	}

	/**
	 * The tokenizer with the owner's stop words.
	 */
	public function tokenizer(): Tokenizer {
		if ( null === $this->tokenizer ) {
			$this->tokenizer = new Tokenizer( $this->stopWords() );
		}
		return $this->tokenizer;
	}

	/**
	 * Stop words per language: built in, the owner's additions, the filter.
	 */
	public function stopWords(): StopWords {
		$words = new StopWords();
		foreach ( array_unique( array_merge( $words->languages(), $this->languages->codes() ) ) as $lang ) {
			$own  = get_option( Options::STOPWORDS_PREFIX . $lang, array() );
			$list = array_merge( $words->listFor( $lang ), Cast::strings( $own ) );
			/**
			 * The stop words of a language.
			 *
			 * @since 0.4.0
			 *
			 * @param string[] $list The built-in list with the owner's additions.
			 * @param string   $lang Language code.
			 */
			$filtered = apply_filters( 'csearch_stop_words', $list, $lang );
			$words    = $words->with( $lang, is_array( $filtered ) ? Cast::strings( $filtered ) : $list );
		}
		return $words;
	}

	/**
	 * Forget the built analyzer (tests change options and filters between cases).
	 */
	public function flush(): void {
		$this->analyzer  = null;
		$this->tokenizer = null;
		$this->languages->flush();
	}
}
