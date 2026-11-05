<?php
/**
 * Where a product search lands, and how many products that page lists.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Search;

use CreativeBranding\Search\Index\TermReader;
use CreativeBranding\Search\Results\CategoryScope;
use CreativeBranding\Search\Results\QueryRouter;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Cast;
use WP_Query;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The page a results-page query is sent to (D-101, D-102, D-108, D-117) and the number of products it lists, worked
 * out once for both the redirect ({@see QueryRouter}) and the panel's "See all" (D-145): `8.25 deck` lands on Decks
 * filtered to 8.25, so the panel counts the products that page shows, not every deck.
 */
final class Destination {

	/**
	 * Constructor.
	 *
	 * @param QueryRoutes   $routes The reading of the query.
	 * @param SearchService $search The search.
	 */
	public function __construct( private QueryRoutes $routes, private SearchService $search ) {
	}

	/**
	 * Where a query lands when it leaves the results page for its words.
	 *
	 * @param string       $words The query's words, cleaned.
	 * @param string       $lang  Its language, checked.
	 * @param WP_Term|null $scope The category the search is kept to (D-117), or null.
	 * @return array{url: string, count: int, filtered: bool}|array{categories: int[]}|null The page and what it
	 *     lists (`filtered` when a size or other value became the shop's filter); `categories` when two categories of
	 *     one name leave the choice to the shopper; null to show the results for the words.
	 */
	public function of( string $words, string $lang, ?WP_Term $scope = null ): ?array {
		return $this->land( $words, $lang, $scope, false );
	}

	/**
	 * How many products the page lists when a value in the query becomes the shop's filter on the way there, or null
	 * when none does (the panel's "See all", D-145). A query that names no filter costs only its parse: no search is
	 * run for it.
	 *
	 * @param string       $words The query's words, cleaned.
	 * @param string       $lang  Its language, checked.
	 * @param WP_Term|null $scope The category the search is kept to, or null.
	 */
	public function filteredCount( string $words, string $lang, ?WP_Term $scope = null ): ?int {
		$landing = $this->land( $words, $lang, $scope, true );
		return null !== $landing && isset( $landing['count'] ) && true === ( $landing['filtered'] ?? false ) ? $landing['count'] : null;
	}

	/**
	 * Where a query lands.
	 *
	 * @param string       $words    The query's words, cleaned.
	 * @param string       $lang     Its language, checked.
	 * @param WP_Term|null $scope    The category the search is kept to, or null.
	 * @param bool         $filtered Only a landing with a filter: null as soon as there can be none.
	 * @return array{url: string, count: int, filtered: bool}|array{categories: int[]}|null
	 */
	private function land( string $words, string $lang, ?WP_Term $scope, bool $filtered ): ?array {
		$redirect = $this->search->rules()->redirect( $words, $lang );
		if ( null !== $redirect && $filtered ) {
			return null; // The owner's page applies no filter.
		}
		if ( null !== $redirect ) {
			// The owner sends these words to a page (D-108): before any category or filter.
			return array(
				'url'      => $redirect,
				'count'    => count( $this->search->rankedIds( $words, $lang, '' )['ids'] ),
				'filtered' => false,
			);
		}
		if ( array() !== $this->search->rules()->pins( $words, $lang ) ) {
			return null; // The owner pinned products to these words (D-110): they are shown, not a category.
		}
		$route = $this->routes->route( $words, $lang );
		if ( $filtered && array() === $route['filters'] ) {
			return null;
		}
		if ( null !== $scope ) {
			return $this->scoped( $words, $lang, $route, $scope );
		}
		$term = $this->category( $route['categories'], $words, $lang );
		if ( null === $term && count( $route['categories'] ) > 1 ) {
			return array( 'categories' => $route['categories'] );
		}
		if ( null === $term && array() === $route['filters'] ) {
			return null;
		}
		$args = QueryRouter::filterArgs( $route['filters'] ) + QueryRouter::fromArgs( $words );
		$ids  = null;
		if ( null !== $term ) {
			$base = get_term_link( $term );
		} elseif ( '' !== $route['rest'] ) {
			$args = array(
				's'         => rawurlencode( $route['rest'] ),
				'post_type' => 'product',
			) + $args;
			$base = home_url( '/' );
			$ids  = $this->search->rankedIds( $route['rest'], $lang, '' )['ids'];
		} else {
			$base = wc_get_page_permalink( 'shop' );
		}
		return self::landing( $base, $args, $term, $route['filters'], $ids );
	}

	/**
	 * A search kept to a category stays in it: a size it names becomes the filter on the search for its other words
	 * in the same category, or on the category itself when nothing else is left.
	 *
	 * @param string                                                                   $words The query's words.
	 * @param string                                                                   $lang  Its language.
	 * @param array{filters: array<string, string[]>, rest: string, categories: int[]} $route The reading of the query.
	 * @param WP_Term                                                                  $scope The category.
	 * @return array{url: string, count: int, filtered: bool}|null
	 */
	private function scoped( string $words, string $lang, array $route, WP_Term $scope ): ?array {
		if ( array() === $route['filters'] ) {
			return null;
		}
		$args = QueryRouter::filterArgs( $route['filters'] ) + QueryRouter::fromArgs( $words );
		$ids  = null;
		$term = $scope;
		if ( '' !== $route['rest'] ) {
			$args = array(
				's'                => rawurlencode( $route['rest'] ),
				'post_type'        => 'product',
				CategoryScope::ARG => $scope->slug,
			) + $args;
			$base = home_url( '/' );
			$ids  = $this->search->rankedIds( $route['rest'], $lang, '', false, CategoryScope::ttIds( $scope ) )['ids'];
			$term = null;
		} else {
			$base = get_term_link( $scope );
		}
		return self::landing( $base, $args, $term, $route['filters'], $ids );
	}

	/**
	 * The page and its count, or null when the page has no address.
	 *
	 * @param mixed                   $base    The page's address, or an error.
	 * @param array<string, string>   $args    Its arguments.
	 * @param WP_Term|null            $term    The category it lists, or null.
	 * @param array<string, string[]> $filters Attribute taxonomy to slugs.
	 * @param int[]|null              $ids     The other words' products, or null when there were none.
	 * @return array{url: string, count: int, filtered: bool}|null
	 */
	private static function landing( mixed $base, array $args, ?WP_Term $term, array $filters, ?array $ids ): ?array {
		if ( ! is_string( $base ) || '' === $base ) {
			return null;
		}
		return array(
			'url'      => add_query_arg( $args, $base ),
			'count'    => self::count( $term, $filters, $ids ),
			'filtered' => array() !== $filters,
		);
	}

	/**
	 * How many products the page a query is sent to lists: a category, the filters, the other words' products.
	 *
	 * @param WP_Term|null            $term    The category, or null.
	 * @param array<string, string[]> $filters Attribute taxonomy to slugs.
	 * @param int[]|null              $ids     The other words' products, or null when there were none.
	 */
	private static function count( ?WP_Term $term, array $filters, ?array $ids ): int {
		if ( null !== $term && array() === $filters ) {
			return TermReader::count( $term );
		}
		if ( array() === $ids ) {
			return 0;
		}
		$visibility = wc_get_product_visibility_term_ids();
		$tax        = array(
			'relation' => 'AND',
			array(
				'taxonomy' => 'product_visibility',
				'field'    => 'term_taxonomy_id',
				'terms'    => array( Cast::int( $visibility[ null === $ids ? 'exclude-from-catalog' : 'exclude-from-search' ] ?? 0 ) ),
				'operator' => 'NOT IN',
			),
		);
		if ( null !== $term ) {
			$tax[] = array(
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => array( $term->term_id ),
			);
		}
		foreach ( $filters as $taxonomy => $slugs ) {
			$tax[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => $slugs,
			);
		}
		$found = new WP_Query(
			array(
				'post_type'           => 'product',
				'post_status'         => 'publish',
				'fields'              => 'ids',
				'posts_per_page'      => 1,
				'ignore_sticky_posts' => true,
				'post__in'            => $ids ?? array(),
				'tax_query'           => $tax, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- once per query sent on, and once per uncached panel answer that names a filter.
			)
		);
		return $found->found_posts;
	}

	/**
	 * The category to open: the one the query names, unless a product is named exactly as the query reads.
	 *
	 * @param int[]  $ids   The categories named.
	 * @param string $words The query.
	 * @param string $lang  Its language.
	 */
	private function category( array $ids, string $words, string $lang ): ?WP_Term {
		$term = null;
		if ( Settings::isOn( 'category_redirect' ) && 1 === count( $ids ) ) {
			$found = get_term( $ids[0], 'product_cat' );
			if ( $found instanceof WP_Term && ! $this->search->namesProduct( $words, $lang, $this->search->rankedIds( $words, $lang, '' )['ids'] ) ) {
				$term = $found;
			}
		}
		/**
		 * The category a results-page query opens (D-102), or null to show the results.
		 *
		 * @since 1.2.0
		 *
		 * @param WP_Term|null $term  The category the query names, when exactly one with products does.
		 * @param string       $words The query.
		 */
		$term = apply_filters( 'csearch_category_redirect', $term, $words );
		return $term instanceof WP_Term && 'product_cat' === $term->taxonomy ? $term : null;
	}
}
