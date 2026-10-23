<?php
/**
 * Sends a results-page query where it belongs.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Results;

use CreativeBranding\Search\Index\TermReader;
use CreativeBranding\Search\Search\QueryRoutes;
use CreativeBranding\Search\Search\SearchLog;
use CreativeBranding\Search\Search\SearchService;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Cast;
use WP_Query;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A product search that names a category opens the category (D-102: `wheels` is the Wheels page, `8.25 deck` is
 * Decks filtered to 8.25), and one that names a value of an attribute the shop filters by becomes that filter on
 * the search for the other words (D-101: `deck 8.25` is `deck` with Width 8.25), so the shopper sees the filter
 * and can remove it. The target is worked out in `pre_get_posts` (priority 4, before the ranking, which then
 * stands aside) and the shopper is sent there on `template_redirect` (priority 5, before WooCommerce's own
 * redirect to a single result).
 *
 * The owner's redirect for the query's words comes first (D-108), and a query the owner pinned products to is
 * never sent to a category or filter (D-110).
 *
 * The query is left alone when the shopper asked for every product (`csearch_all`), arrived from a redirect
 * (`csearch_from`), asked for their exact words, already filters or sorts, or is past the first page.
 */
final class QueryRouter {

	public const ALL_ARG  = 'csearch_all';
	public const FROM_ARG = 'csearch_from';
	public const SIG_ARG  = 'csearch_sig';

	/**
	 * Arguments that mean the shopper is refining a search they already have.
	 */
	private const REFINING = array( self::ALL_ARG, self::FROM_ARG, self::SIG_ARG, ProductSearch::EXACT_ARG, 'orderby', 'min_price', 'max_price', 'rating_filter' );

	/**
	 * The page to send the shopper to, once worked out.
	 *
	 * @var string|null
	 */
	private ?string $target = null;

	/**
	 * The categories the query named, when none was opened (the results page lists them).
	 *
	 * @var int[]
	 */
	private array $categories = array();

	/**
	 * Constructor.
	 *
	 * @param QueryRoutes   $routes The reading of the query.
	 * @param SearchService $search The search, for a product named exactly as the query reads.
	 * @param SearchLog     $log    The log, for the query the shopper typed.
	 */
	public function __construct( private QueryRoutes $routes, private SearchService $search, private SearchLog $log ) {
	}

	/**
	 * Work out where the main product search belongs (`pre_get_posts`, priority 4).
	 *
	 * @param WP_Query $query The query.
	 */
	public function route( WP_Query $query ): void {
		if ( ! ProductSearch::isProductSearch( $query ) || self::refining( $query ) ) {
			return;
		}
		$words = ProductSearch::words( $query );
		if ( '' === $words ) {
			return;
		}
		$lang     = $this->search->language( ProductSearch::lang() );
		$redirect = $this->search->rules()->redirect( $words, $lang );
		if ( null !== $redirect ) {
			// The owner sends these words to a page (D-108): before any category or filter.
			$this->leave( $query, $redirect, $words, $lang, count( $this->search->rankedIds( $words, $lang, '' )['ids'] ) );
			return;
		}
		if ( array() !== $this->search->rules()->pins( $words, $lang ) ) {
			return; // The owner pinned products to these words (D-110): they are shown, not a category.
		}
		$route = $this->routes->route( $words, $lang );
		$scope = CategoryScope::requested();
		if ( null !== $scope ) {
			$this->scoped( $query, $words, $lang, $route, $scope );
			return;
		}
		$term = $this->category( $route['categories'], $words, $lang );
		if ( null === $term && count( $route['categories'] ) > 1 ) {
			// Two categories of one name: the shopper chooses, on the results page for every word they typed.
			$this->categories = $route['categories'];
			return;
		}
		if ( null === $term && array() === $route['filters'] ) {
			return;
		}
		$args = self::filterArgs( $route['filters'] ) + self::fromArgs( $words );
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
		if ( ! is_string( $base ) || '' === $base ) {
			return;
		}
		$this->leave( $query, add_query_arg( $args, $base ), $words, $lang, self::count( $term, $route['filters'], $ids ) );
	}

	/**
	 * Send the query on: the target kept for `template_redirect`, the query the shopper typed logged once with what
	 * the page they land on lists (that page does not log it again), and the page left: no ranking, no search
	 * clause, nothing fetched.
	 *
	 * @param WP_Query $query  The query.
	 * @param string   $target Where to.
	 * @param string   $words  The query's words.
	 * @param string   $lang   Its language.
	 * @param int      $count  What the page lists.
	 */
	private function leave( WP_Query $query, string $target, string $words, string $lang, int $count ): void {
		$this->target = $target;
		$this->log->record( $words, $lang, $count, 'page' );
		$query->set( 'csearch_routed', true );
		$query->set( 'post__in', array( 0 ) );
	}

	/**
	 * A search kept to a category (D-117) stays in it: no other category is opened, and a size it names becomes the
	 * filter on the search for its other words in the same category, or on the category itself when nothing else
	 * is left.
	 *
	 * @param WP_Query                                                                 $query The query.
	 * @param string                                                                   $words The query's words.
	 * @param string                                                                   $lang  Its language.
	 * @param array{filters: array<string, string[]>, rest: string, categories: int[]} $route The reading of the query.
	 * @param WP_Term                                                                  $scope The category.
	 */
	private function scoped( WP_Query $query, string $words, string $lang, array $route, WP_Term $scope ): void {
		if ( array() === $route['filters'] ) {
			return;
		}
		$args = self::filterArgs( $route['filters'] ) + self::fromArgs( $words );
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
		if ( ! is_string( $base ) || '' === $base ) {
			return;
		}
		$this->leave( $query, add_query_arg( $args, $base ), $words, $lang, self::count( $term, $route['filters'], $ids ) );
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
				'tax_query'           => $tax, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- once, on the request a query is sent on from.
			)
		);
		return $found->found_posts;
	}


	/**
	 * Send the shopper on (`template_redirect`, priority 5).
	 */
	public function redirect(): void {
		if ( null !== $this->target && wp_safe_redirect( $this->target, 302, 'Creative Search' ) ) {
			exit;
		}
	}

	/**
	 * The arguments that tell the page landed on which query sent the shopper there: the query, and a signature so
	 * a link made elsewhere cannot put words on the shop's pages.
	 *
	 * @param string $words The query.
	 * @return array<string, string>
	 */
	public static function fromArgs( string $words ): array {
		return array(
			self::FROM_ARG => rawurlencode( $words ),
			self::SIG_ARG  => self::signature( $words ),
		);
	}

	/**
	 * The query that sent the shopper to this page, cleaned, when its signature holds; an empty string on any
	 * other page.
	 */
	public static function from(): string {
		$from = isset( $_GET[ self::FROM_ARG ] ) && is_string( $_GET[ self::FROM_ARG ] ) ? SearchService::clean( wp_unslash( $_GET[ self::FROM_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a public, read-only parameter, cleaned by SearchService::clean().
		$sig  = isset( $_GET[ self::SIG_ARG ] ) && is_string( $_GET[ self::SIG_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::SIG_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public, read-only parameter, checked against its signature.
		return '' !== $from && hash_equals( self::signature( $from ), $sig ) ? $from : '';
	}

	/**
	 * A query's signature: the site's hash of it, cut short. The same for every visitor, so the page can be cached.
	 *
	 * @param string $words The query.
	 */
	private static function signature( string $words ): string {
		return substr( wp_hash( 'csearch_from|' . $words ), 0, 12 );
	}

	/**
	 * Whether the shopper was sent to this page by a query.
	 */
	public static function arrived(): bool {
		return '' !== self::from();
	}

	/**
	 * The page the shopper is sent to, or null.
	 */
	public function target(): ?string {
		return $this->target;
	}

	/**
	 * The categories the query named when it opened none: two or more.
	 *
	 * @return int[]
	 */
	public function categories(): array {
		return $this->categories;
	}

	/**
	 * Forget this request's route (tests run several pages in one process).
	 */
	public function reset(): void {
		$this->target     = null;
		$this->categories = array();
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

	/**
	 * WooCommerce's own filter arguments: `filter_width=8-25`, and `query_type_width=or` for several values.
	 *
	 * @param array<string, string[]> $filters Attribute taxonomy to slugs.
	 * @return array<string, string>
	 */
	public static function filterArgs( array $filters ): array {
		$args = array();
		foreach ( $filters as $taxonomy => $slugs ) {
			$name                      = wc_attribute_taxonomy_slug( $taxonomy );
			$args[ 'filter_' . $name ] = implode( ',', array_map( 'rawurlencode', $slugs ) );
			if ( count( $slugs ) > 1 ) {
				$args[ 'query_type_' . $name ] = 'or';
			}
		}
		return $args;
	}

	/**
	 * Whether the shopper is refining a search: past the first page, or with a filter, sort or flag of their own.
	 *
	 * @param WP_Query $query The query.
	 */
	private static function refining( WP_Query $query ): bool {
		if ( Cast::int( $query->get( 'paged' ) ) > 1 ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only parameters, only their names are read.
		foreach ( array_keys( $_GET ) as $name ) {
			$name = (string) $name;
			if ( in_array( $name, self::REFINING, true ) || str_starts_with( $name, 'filter_' ) || str_starts_with( $name, 'query_type_' ) ) {
				return true;
			}
		}
		return false;
	}
}
