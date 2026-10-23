<?php
/**
 * The results page's query.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Results;

use CreativeBranding\Search\Search\SearchService;
use CreativeBranding\Search\Support\Cast;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Puts the plugin's ranking into WooCommerce's product search (spec: Matching → The results page): the ranked
 * ids, capped at 500, become `post__in`; WordPress's own search clause and search terms are removed for that
 * query so nothing else filters by the raw words (WooCommerce's classic filter widgets count the ranked set
 * instead); the "Relevance" sort (WooCommerce's default on a search) becomes `post__in` order, and any other
 * sort keeps the match set and WooCommerce's ordering. Empty searches, feeds and searches that are not for
 * products are left to WordPress and WooCommerce.
 */
final class ProductSearch {

	public const EXACT_ARG = 'csearch_exact';

	/**
	 * The answer for the main query, once it has been searched.
	 *
	 * @var array{ids: int[], corrected: string|null, dropped: bool, query: string, lang: string}|null
	 */
	private ?array $answer = null;

	/**
	 * Constructor.
	 *
	 * @param SearchService $search The search service.
	 */
	public function __construct( private SearchService $search ) {
	}

	/**
	 * Whether a query is the shop's product search.
	 *
	 * @param WP_Query $query The query.
	 */
	public static function isProductSearch( WP_Query $query ): bool {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() || $query->is_feed() ) {
			return false;
		}
		$type = $query->get( 'post_type' );
		$ours = 'product' === $type || array( 'product' ) === $type;
		/**
		 * Whether the plugin ranks this search.
		 *
		 * @since 0.6.0
		 *
		 * @param bool     $ours  True for the main query's product search.
		 * @param WP_Query $query The query.
		 */
		return (bool) apply_filters( 'csearch_rank_query', $ours, $query );
	}

	/**
	 * The search words of a query, as the shopper typed them: unslashed (WordPress slashes `$_GET` and strips
	 * the slashes only later, in `parse_search`), and decoded on a pretty `/search/…` URL as core does.
	 *
	 * @param WP_Query $query The query.
	 */
	public static function words( WP_Query $query ): string {
		$raw = wp_unslash( Cast::str( $query->get( 's' ) ) );
		if ( ! isset( $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public, read-only search parameter.
			$raw = urldecode( $raw );
		}
		return SearchService::clean( $raw );
	}

	/**
	 * The language the shopper asked for (`lang`), else the request's locale.
	 */
	public static function lang(): string {
		return isset( $_GET['lang'] ) && is_string( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : get_locale(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public, read-only search parameter.
	}

	/**
	 * Rank the main product search: `pre_get_posts` at priority 5, before WooCommerce's own pass (which reads
	 * the answer for its visibility clause and overwrites `post__in`; {@see ProductSearch::enforce()} sets it
	 * again after).
	 *
	 * @param WP_Query $query The query.
	 */
	public function apply( WP_Query $query ): void {
		if ( ! self::isProductSearch( $query ) ) {
			return;
		}
		$words = self::words( $query );
		if ( '' === $words ) {
			return; // An empty search lists the catalogue, as WooCommerce does.
		}
		if ( true === $query->get( 'csearch_routed' ) ) {
			return; // The shopper is sent to a category or a filter ({@see QueryRouter}).
		}
		$lang  = self::lang();
		$exact = isset( $_GET[ self::EXACT_ARG ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public, read-only search parameter.
		// A second page, another sort, or the page a redirect landed on is the same search, not a new one for the log.
		$repeat       = Cast::int( $query->get( 'paged' ) ) > 1 || isset( $_GET['orderby'] ) || QueryRouter::arrived(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only parameters.
		$this->answer = $this->search->rankedIds( $words, $lang, $repeat ? '' : 'page', $exact );
		$query->set( 'post__in', array() === $this->answer['ids'] ? array( 0 ) : $this->answer['ids'] );
		$query->set( 'csearch_ranked', true );
	}

	/**
	 * Set the ranked ids again after WooCommerce's own `pre_get_posts` pass, which resets `post__in` from its
	 * `loop_shop_post_in` filter (priority 20).
	 *
	 * @param WP_Query $query The query.
	 */
	public function enforce( WP_Query $query ): void {
		if ( null === $this->answer || true !== $query->get( 'csearch_ranked' ) ) {
			return;
		}
		$query->set( 'post__in', array() === $this->answer['ids'] ? array( 0 ) : $this->answer['ids'] );
	}

	/**
	 * Remove WordPress's search clause and search terms from the ranked query (the match set is `post__in`) and from
	 * a query sent on to a category or a filter (nothing is fetched before the redirect).
	 *
	 * @param string|mixed $search The clause.
	 * @param mixed        $query  The query.
	 * @return string|mixed
	 */
	public function searchClause( $search, $query = null ) {
		if ( ! $query instanceof WP_Query || ( true !== $query->get( 'csearch_ranked' ) && true !== $query->get( 'csearch_routed' ) ) ) {
			return $search;
		}
		$query->set( 'search_terms', array() );
		return '';
	}

	/**
	 * WooCommerce hides products marked "hidden from search" on every search. A product the ranking returned
	 * is already allowed (hidden products only come back for an exact SKU match when `hidden_by_sku` is on), so
	 * that one exclusion is lifted for the ranked query; "hide out of stock" and the rest stay.
	 *
	 * @param array<int|string, mixed>|mixed $taxQuery WooCommerce's tax query.
	 * @return array<int|string, mixed>|mixed
	 */
	public function visibility( $taxQuery ) {
		if ( null === $this->answer || ! is_array( $taxQuery ) ) {
			return $taxQuery;
		}
		$hidden = get_term_by( 'slug', 'exclude-from-search', 'product_visibility' );
		if ( ! $hidden instanceof \WP_Term ) {
			return $taxQuery;
		}
		foreach ( $taxQuery as $key => $clause ) {
			if ( ! is_array( $clause ) || 'product_visibility' !== ( $clause['taxonomy'] ?? '' ) || 'NOT IN' !== ( $clause['operator'] ?? '' ) ) {
				continue;
			}
			$terms = array_values( array_diff( Cast::ints( $clause['terms'] ?? null ), array( $hidden->term_taxonomy_id, $hidden->term_id ) ) );
			if ( array() === $terms ) {
				unset( $taxQuery[ $key ] );
				continue;
			}
			$clause['terms']  = $terms;
			$taxQuery[ $key ] = $clause;
		}
		return $taxQuery;
	}

	/**
	 * WooCommerce's classic layered-nav and attribute filter counts, over the ranked set.
	 *
	 * @param array<string, string>|mixed $query The count query's fragments.
	 * @return array<string, string>|mixed
	 */
	public function filterCounts( $query ) {
		if ( null === $this->answer || ! is_array( $query ) || ! isset( $query['where'] ) || ! is_string( $query['where'] ) ) {
			return $query;
		}
		$query['where'] .= ' AND ' . $this->idClause();
		return $query;
	}

	/**
	 * WooCommerce's classic price filter range, over the ranked set.
	 *
	 * @param string|mixed $sql The range query.
	 * @return string|mixed
	 */
	public function priceRange( $sql ) {
		if ( null === $this->answer || ! is_string( $sql ) ) {
			return $sql;
		}
		$trimmed = rtrim( $sql );
		if ( ! str_ends_with( $trimmed, ')' ) ) {
			return $sql;
		}
		return substr( $trimmed, 0, -1 ) . ' AND ' . $this->idClause() . ' )';
	}

	/**
	 * `wp_posts.ID IN (…)` for the ranked ids, built from integers only.
	 */
	private function idClause(): string {
		global $wpdb;
		$ids = array() === ( $this->answer['ids'] ?? array() ) ? array( 0 ) : ( $this->answer['ids'] ?? array( 0 ) );
		return $wpdb->posts . '.ID IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')';
	}

	/**
	 * The "Relevance" sort of a ranked search is the plugin's order.
	 *
	 * @param array<string, mixed>|mixed $args    WooCommerce's ordering arguments.
	 * @param mixed                      $orderby The sort asked for.
	 * @return array<string, mixed>|mixed
	 */
	public function ordering( $args, $orderby = '' ) {
		if ( null === $this->answer || ! is_array( $args ) || 'relevance' !== ( $args['orderby'] ?? $orderby ) ) {
			return $args;
		}
		$args['orderby'] = 'post__in';
		$args['order']   = 'ASC';
		return $args;
	}

	/**
	 * Keep `post__in` order when WooCommerce has already set "relevance" on the query.
	 *
	 * @param WP_Query $query The query.
	 */
	public function order( WP_Query $query ): void {
		if ( true === $query->get( 'csearch_ranked' ) && in_array( $query->get( 'orderby' ), array( 'relevance', '', null ), true ) ) {
			$query->set( 'orderby', 'post__in' );
			$query->set( 'order', 'ASC' );
		}
	}

	/**
	 * The answer for this page, or null when the page is not a ranked search.
	 *
	 * @return array{ids: int[], corrected: string|null, dropped: bool, query: string, lang: string}|null
	 */
	public function answer(): ?array {
		return $this->answer;
	}

	/**
	 * How many products the page shows in all, after WooCommerce's own filters and visibility: the main
	 * query's `found_posts`, or the ranked count before the query has run.
	 */
	public function shown(): int {
		global $wp_query;
		if ( null === $this->answer ) {
			return 0;
		}
		if ( $wp_query instanceof WP_Query && true === $wp_query->get( 'csearch_ranked' ) && null !== $wp_query->posts ) {
			return (int) $wp_query->found_posts;
		}
		return count( $this->answer['ids'] );
	}

	/**
	 * Forget the answer (tests run several pages in one process).
	 */
	public function reset(): void {
		$this->answer = null;
	}
}
