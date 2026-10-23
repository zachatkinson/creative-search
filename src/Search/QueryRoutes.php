<?php
/**
 * Where a results-page query belongs.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Search;

use CreativeBranding\Search\Domain\CategoryMatcher;
use CreativeBranding\Search\Domain\FeatureFilters;
use CreativeBranding\Search\Domain\QueryParser;
use CreativeBranding\Search\Domain\QueryToken;
use CreativeBranding\Search\Index\AnalyzerFactory;
use CreativeBranding\Search\Index\AttributeTerms;
use CreativeBranding\Search\Index\ProductReader;
use CreativeBranding\Search\Index\TermReader;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Cast;
use CreativeBranding\Search\Support\Options;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a results-page query for the two things it may name besides products (Skatehouse handoff items 9 and
 * 10): a value of an attribute the shop filters by (`8.25`, D-101), and a category, by every word of its name
 * (`wheels`, D-102). {@see \CreativeBranding\Search\Results\QueryRouter} decides where the shopper goes.
 */
final class QueryRoutes {

	/**
	 * Constructor.
	 *
	 * @param AnalyzerFactory $analyzers  The tokenizer and the stemmers.
	 * @param SearchService   $search     The owner's synonyms, read once with the search's own.
	 * @param AttributeTerms  $attributes The values of the attributes the shop filters by.
	 * @param TermReader      $terms      A category's name in each language.
	 */
	public function __construct(
		private AnalyzerFactory $analyzers,
		private SearchService $search,
		private AttributeTerms $attributes,
		private TermReader $terms
	) {
	}

	/**
	 * The filters a query names, the query without them, and the categories the rest names.
	 *
	 * @param string $query A clean query.
	 * @param string $lang  A checked language.
	 * @return array{filters: array<string, string[]>, rest: string, categories: int[]} The filters by attribute
	 *     taxonomy; the rest as a query (an empty string when the filters were all of it); the categories, with
	 *     products, whose names the rest reads as.
	 */
	public function route( string $query, string $lang ): array {
		$tokenizer = $this->analyzers->tokenizer();
		$parsed    = ( new QueryParser( $tokenizer, $this->search->synonymsFor( array( $lang ) ) ) )->parse( $query, $lang );
		$values    = $this->attributes->values();
		$split     = FeatureFilters::split( $parsed->tokens, $values );
		/**
		 * The shop filters a results-page query names (D-101): attribute taxonomy to term slugs. Return an empty
		 * array to search the words instead.
		 *
		 * @since 1.2.0
		 *
		 * @param array<string, string[]> $filters The filters.
		 * @param string                  $query   The query.
		 */
		$filters = self::filters( apply_filters( 'csearch_query_filters', $split['filters'], $query ) );
		// A word stays a word unless the filter it named is still applied.
		$rest       = array_values(
			array_filter(
				$parsed->tokens,
				static function ( QueryToken $token ) use ( $values, $filters ): bool {
					$named    = $values[ $token->text ] ?? array();
					$taxonomy = (string) array_key_first( $named );
					return 1 !== count( $named ) || 0 !== $token->phrase || ! in_array( $named[ $taxonomy ] ?? '', $filters[ $taxonomy ] ?? array(), true );
				}
			)
		);
		$categories = array();
		if ( Settings::isOn( 'category_redirect' ) && array() !== $rest && array() === $parsed->excluded && ! $parsed->allStopWords && array() === $parsed->phrases() ) {
			$categories = self::listing( CategoryMatcher::match( $this->wordStems( $rest, $lang ), $this->categoryStems( $lang ) ) );
		}
		return array(
			'filters'    => $filters,
			'rest'       => array() === $filters ? $query : self::text( $rest, $parsed->excluded ),
			'categories' => $categories,
		);
	}

	/**
	 * Each word's stem and its synonyms' stems; a number is its own stem.
	 *
	 * @param QueryToken[] $tokens The words.
	 * @param string       $lang   Language code.
	 * @return array<int, string[]>
	 */
	private function wordStems( array $tokens, string $lang ): array {
		$analyzer = $this->analyzers->analyzer();
		$out      = array();
		foreach ( $tokens as $token ) {
			$stems = array( $token->stemmable() ? $analyzer->stemOf( $token->source, $lang ) : $token->text );
			foreach ( $token->synonyms as $synonym ) {
				$stems[] = $analyzer->stemOf( $synonym, $lang );
			}
			$out[] = array_values( array_unique( $stems ) );
		}
		return $out;
	}

	/**
	 * The stems of each category name's words, in a language (not the default "Uncategorized"). Kept in one
	 * autoloaded option, so a search page reads nothing for it; forgotten when a category changes and when a
	 * rebuild goes live ({@see QueryRoutes::forget()}).
	 *
	 * @param string $lang Language code.
	 * @return array<int, string[]>
	 */
	private function categoryStems( string $lang ): array {
		$stored = get_option( Options::CATEGORY_STEMS );
		$stored = is_array( $stored ) ? $stored : array();
		if ( isset( $stored[ $lang ] ) && is_array( $stored[ $lang ] ) ) {
			return array_map( array( Cast::class, 'strings' ), $stored[ $lang ] );
		}
		$analyzer = $this->analyzers->analyzer();
		$skip     = Cast::int( get_option( 'default_product_cat', 0 ) );
		$terms    = get_terms(
			array(
				'taxonomy'   => ProductReader::CATEGORY_TAXONOMY,
				'hide_empty' => false,
			)
		);
		$out      = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( ! $term instanceof WP_Term || $term->term_id === $skip ) {
				continue;
			}
			$read  = $this->terms->read( $term );
			$name  = isset( $read[ $lang ] ) ? $read[ $lang ]->title : $term->name;
			$stems = array();
			foreach ( $this->analyzers->tokenizer()->tokenize( $name, $lang ) as $token ) {
				$stem                        = $analyzer->stem( $token, $lang );
				$stems[ $token->position ] ??= '' === $stem ? $token->text : $stem;
			}
			if ( array() !== $stems ) {
				$out[ $term->term_id ] = array_values( $stems );
			}
		}
		$stored[ $lang ] = $out;
		update_option( Options::CATEGORY_STEMS, $stored, true );
		return $out;
	}

	/**
	 * Forget the categories' stems: a category changed, or a rebuild went live (the rules that read names may have).
	 *
	 * @param int|mixed    $termId   Term id, when a term changed.
	 * @param int|mixed    $ttId     Term taxonomy id.
	 * @param string|mixed $taxonomy Taxonomy; any when not a term change.
	 */
	public static function forget( $termId = 0, $ttId = 0, $taxonomy = ProductReader::CATEGORY_TAXONOMY ): void {
		if ( ProductReader::CATEGORY_TAXONOMY === $taxonomy || ! is_string( $taxonomy ) ) {
			delete_option( Options::CATEGORY_STEMS );
		}
	}

	/**
	 * The categories, of those named, that list products: a category emptied since its name was read names nothing.
	 *
	 * @param int[] $ids Category ids.
	 * @return int[]
	 */
	private static function listing( array $ids ): array {
		return array_values(
			array_filter(
				$ids,
				static function ( int $id ): bool {
					$term = get_term( $id, ProductReader::CATEGORY_TAXONOMY );
					return $term instanceof WP_Term && TermReader::count( $term ) > 0;
				}
			)
		);
	}

	/**
	 * The words left as a query: a quoted phrase quoted again, a minus word with its minus.
	 *
	 * @param QueryToken[] $tokens   The words left.
	 * @param QueryToken[] $excluded The minus words.
	 */
	private static function text( array $tokens, array $excluded ): string {
		$parts  = array(); // Each word, or each phrase's words, in order.
		$quoted = array(); // Whether each part is a phrase.
		$at     = array(); // A phrase's place among the parts.
		foreach ( $tokens as $token ) {
			if ( 0 !== $token->phrase && isset( $at[ $token->phrase ] ) ) {
				$parts[ $at[ $token->phrase ] ][] = $token->source;
				continue;
			}
			if ( 0 !== $token->phrase ) {
				$at[ $token->phrase ] = count( $parts );
			}
			$parts[]  = array( $token->source );
			$quoted[] = 0 !== $token->phrase;
		}
		$words = array_map( static fn( array $part, bool $phrase ): string => $phrase ? '"' . implode( ' ', $part ) . '"' : implode( ' ', $part ), $parts, $quoted );
		foreach ( $excluded as $token ) {
			$words[] = '-' . $token->source;
		}
		return implode( ' ', $words );
	}

	/**
	 * Filters as returned by the hook: attribute taxonomies that exist, each with its slugs.
	 *
	 * @param mixed $filters The hook's answer.
	 * @return array<string, string[]>
	 */
	private static function filters( mixed $filters ): array {
		$out = array();
		foreach ( is_array( $filters ) ? $filters : array() as $taxonomy => $slugs ) {
			$slugs = array_values( array_unique( array_filter( array_map( 'sanitize_title', Cast::strings( $slugs ) ) ) ) );
			if ( is_string( $taxonomy ) && str_starts_with( $taxonomy, 'pa_' ) && taxonomy_exists( $taxonomy ) && array() !== $slugs ) {
				$out[ $taxonomy ] = $slugs;
			}
		}
		return $out;
	}
}
