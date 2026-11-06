<?php
/**
 * Where a product search lands, and how many products that page lists.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Search;

use CreativeBranding\Search\Index\TermReader;
use CreativeBranding\Search\Repository\ProductIdRepository;
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
 *
 * A value filters only when the page's products carry its attribute at all (D-156), and a value that would leave
 * the page listing nothing, where the same page without it lists something, is left out
 * and named on the page with the closest values in stock (D-154): `9 deck` opens Decks, not an empty Decks in 9.00.
 */
final class Destination {

	/**
	 * Constructor.
	 *
	 * @param QueryRoutes         $routes   The reading of the query.
	 * @param SearchService       $search   The search.
	 * @param ProductIdRepository $products Product ids, for WooCommerce's sold-out variations (D-151).
	 */
	public function __construct( private QueryRoutes $routes, private SearchService $search, private ProductIdRepository $products ) {
	}

	/**
	 * Where a query lands when it leaves the results page for its words.
	 *
	 * @param string       $words The query's words, cleaned.
	 * @param string       $lang  Its language, checked.
	 * @param WP_Term|null $scope The category the search is kept to (D-117), or null.
	 * @return Landing|array{categories: int[]}|null The page and what it lists; `categories` when two categories of
	 *     one name leave the choice to the shopper; null to show the results for the words.
	 */
	public function of( string $words, string $lang, ?WP_Term $scope = null ): Landing|array|null {
		return $this->land( $words, $lang, $scope, false );
	}

	/**
	 * The page a query lands on when it names a value the shop filters by, for the panel (D-145), or null when it
	 * names none. A query that names no filter costs only its parse: no search is run for it.
	 *
	 * @param string       $words The query's words, cleaned.
	 * @param string       $lang  Its language, checked.
	 * @param WP_Term|null $scope The category the search is kept to, or null.
	 */
	public function filtered( string $words, string $lang, ?WP_Term $scope = null ): ?Landing {
		$landing = $this->land( $words, $lang, $scope, true );
		return $landing instanceof Landing && $landing->named ? $landing : null;
	}

	/**
	 * Which of some products a landing lists, in their order (D-155): the panel suggests what Enter opens.
	 *
	 * @param Landing $landing  The page.
	 * @param int[]   $products Product ids.
	 * @return int[]
	 */
	public function lists( Landing $landing, array $products ): array {
		if ( array() === $products ) {
			return array();
		}
		$ids    = null === $landing->ids ? $products : array_values( array_intersect( $products, $landing->ids ) );
		$listed = $this->query( $landing->term, $landing->filters, $ids, -1 );
		$listed = array_flip( null === $listed ? array() : Cast::ints( $listed->posts ) );
		return array_values( array_filter( $products, static fn( int $id ): bool => isset( $listed[ $id ] ) ) );
	}

	/**
	 * Where a query lands.
	 *
	 * @param string       $words The query's words, cleaned.
	 * @param string       $lang  Its language, checked.
	 * @param WP_Term|null $scope The category the search is kept to, or null.
	 * @param bool         $panel For the panel: only a landing with a filter (null as soon as there can be none), and
	 *                            no closest values.
	 * @return Landing|array{categories: int[]}|null
	 */
	private function land( string $words, string $lang, ?WP_Term $scope, bool $panel ): Landing|array|null {
		$redirect = $this->search->rules()->redirect( $words, $lang );
		if ( null !== $redirect && $panel ) {
			return null; // The owner's page applies no filter.
		}
		if ( null !== $redirect ) {
			// The owner sends these words to a page (D-108): before any category or filter.
			return new Landing( $redirect, count( $this->search->rankedIds( $words, $lang, '' )['ids'] ) );
		}
		if ( array() !== $this->search->rules()->pins( $words, $lang ) ) {
			return null; // The owner pinned products to these words (D-110): they are shown, not a category.
		}
		$kept = array(); // Attributes whose values stay words.
		do {
			$route = $this->routes->route( $words, $lang, $kept );
			if ( $panel && array() === $route['filters'] ) {
				return null;
			}
			$base = $this->base( $words, $lang, $route, $scope );
			if ( null === $base || isset( $base['categories'] ) ) {
				return $base;
			}
			// A value filters only products that carry its attribute (D-156): "9 hoodie" is no width of a hoodie, so the
			// 9 stays a word, and the query is read again without it as a filter (the rest may name a category then).
			$missing = array_values( array_filter( array_keys( $route['filters'] ), fn( string $taxonomy ): bool => ! $this->products->carries( $taxonomy, $base['ids'], CategoryScope::ttIds( $base['term'] ) ) ) );
			$kept    = array_merge( $kept, $missing );
		} while ( array() !== $missing );
		return $this->landing( $words, $base, $route['filters'], $panel );
	}

	/**
	 * The page before its filters: a category, the search for the other words (kept to a category or not), or the
	 * shop.
	 *
	 * @param string                                                                   $words The query's words.
	 * @param string                                                                   $lang  Its language.
	 * @param array{filters: array<string, string[]>, rest: string, categories: int[]} $route The reading of the query.
	 * @param WP_Term|null                                                             $scope The category the search is kept to, or null.
	 * @return array{base: string, args: array<string, string>, term: WP_Term|null, ids: int[]|null}|array{categories: int[]}|null
	 */
	private function base( string $words, string $lang, array $route, ?WP_Term $scope ): ?array {
		if ( null !== $scope ) {
			return $this->scoped( $lang, $route, $scope );
		}
		$term = $this->category( $route['categories'], $words, $lang );
		if ( null === $term && count( $route['categories'] ) > 1 ) {
			return array( 'categories' => $route['categories'] );
		}
		if ( null === $term && array() === $route['filters'] ) {
			return null;
		}
		$args = array();
		$ids  = null;
		if ( null !== $term ) {
			$base = get_term_link( $term );
		} elseif ( '' !== $route['rest'] ) {
			$args = array(
				's'         => rawurlencode( $route['rest'] ),
				'post_type' => 'product',
			);
			$base = home_url( '/' );
			$ids  = $this->search->rankedIds( $route['rest'], $lang, '' )['ids'];
		} else {
			$base = wc_get_page_permalink( 'shop' );
		}
		if ( ! is_string( $base ) || '' === $base ) {
			return null;
		}
		return array(
			'base' => $base,
			'args' => $args,
			'term' => $term,
			'ids'  => $ids,
		);
	}

	/**
	 * A search kept to a category stays in it: a size it names becomes the filter on the search for its other words
	 * in the same category, or on the category itself when nothing else is left.
	 *
	 * @param string                                                                   $lang  Its language.
	 * @param array{filters: array<string, string[]>, rest: string, categories: int[]} $route The reading of the query.
	 * @param WP_Term                                                                  $scope The category.
	 * @return array{base: string, args: array<string, string>, term: WP_Term|null, ids: int[]|null}|null
	 */
	private function scoped( string $lang, array $route, WP_Term $scope ): ?array {
		if ( array() === $route['filters'] ) {
			return null;
		}
		$args = array();
		$ids  = null;
		$term = $scope;
		if ( '' !== $route['rest'] ) {
			$args = array(
				's'                => rawurlencode( $route['rest'] ),
				'post_type'        => 'product',
				CategoryScope::ARG => $scope->slug,
			);
			$base = home_url( '/' );
			$ids  = $this->search->rankedIds( $route['rest'], $lang, '', false, CategoryScope::ttIds( $scope ) )['ids'];
			$term = null;
		} else {
			$base = get_term_link( $scope );
		}
		if ( ! is_string( $base ) || '' === $base ) {
			return null;
		}
		return array(
			'base' => $base,
			'args' => $args,
			'term' => $term,
			'ids'  => $ids,
		);
	}

	/**
	 * The page with its filters and its count. A value that leaves it listing nothing, where the page without it
	 * lists something, is left out (D-154): with several, only those that alone list nothing, and every one when what
	 * is left still lists nothing. One value left out brings the closest values in stock either side of it, for the
	 * page's line.
	 *
	 * @param string                                                                                $words   The query.
	 * @param array{base: string, args: array<string, string>, term: WP_Term|null, ids: int[]|null} $base    The page before its filters.
	 * @param array<string, string[]>                                                               $filters Attribute taxonomy to slugs.
	 * @param bool                                                                                  $panel   For the panel: no closest values.
	 */
	private function landing( string $words, array $base, array $filters, bool $panel ): Landing {
		$term      = $base['term'];
		$ids       = $base['ids'];
		$count     = $this->count( $term, $filters, $ids );
		$applied   = $filters;
		$unstocked = array();
		$closest   = array();
		if ( 0 === $count && array() !== $filters ) {
			// What the page lists without the filters, as WooCommerce lists it (sold-out products hidden or not).
			$without = $this->query( $term, array(), $ids, 1 )->found_posts ?? 0;
			if ( $without > 0 ) {
				[ $applied, $unstocked ] = $this->leaveOut( $term, $filters, $ids );
				$count                   = array() === $applied ? $without : $this->count( $term, $applied, $ids );
				$taxonomy                = (string) array_key_first( $unstocked );
				// One value left out, none of its attribute kept: the closest in stock replace it on the page's links.
				if ( ! $panel && 1 === count( $unstocked, COUNT_RECURSIVE ) - count( $unstocked ) && ! isset( $applied[ $taxonomy ] ) ) {
					$closest = $this->closest( $term, $applied, $ids, $taxonomy, $unstocked[ $taxonomy ][0] );
				}
			}
		}
		$args = $base['args'] + QueryRouter::filterArgs( $applied ) + QueryRouter::fromArgs( $words, $unstocked, $closest );
		return new Landing( add_query_arg( $args, $base['base'] ), $count, array() !== $filters, $applied, $unstocked, $term, $ids );
	}

	/**
	 * The values to leave out of a page that lists nothing with all of them (D-154).
	 *
	 * @param WP_Term|null            $term    The category, or null.
	 * @param array<string, string[]> $filters Attribute taxonomy to slugs.
	 * @param int[]|null              $ids     The other words' products, or null.
	 * @return array{0: array<string, string[]>, 1: array<string, string[]>} The values kept, and those left out.
	 */
	private function leaveOut( ?WP_Term $term, array $filters, ?array $ids ): array {
		if ( 1 === count( $filters, COUNT_RECURSIVE ) - count( $filters ) ) {
			return array( array(), $filters ); // One value: the page already lists nothing with it.
		}
		$kept = array();
		$out  = array();
		foreach ( $filters as $taxonomy => $slugs ) {
			foreach ( $slugs as $slug ) {
				if ( 0 === $this->count( $term, array( $taxonomy => array( $slug ) ), $ids ) ) {
					$out[ $taxonomy ][] = $slug;
				} else {
					$kept[ $taxonomy ][] = $slug;
				}
			}
		}
		if ( array() !== $kept && 0 === $this->count( $term, $kept, $ids ) ) {
			return array( array(), $filters ); // What is left still lists nothing: every value goes.
		}
		return array( $kept, $out );
	}

	/**
	 * The closest values in stock either side of one left out, in the attribute's own order (WooCommerce's
	 * `get_terms()` defaults follow it): at most one below and one above, each one the page would list something in.
	 *
	 * @param WP_Term|null            $term     The category, or null.
	 * @param array<string, string[]> $filters  The values the page applies.
	 * @param int[]|null              $ids      The other words' products, or null.
	 * @param string                  $taxonomy The attribute left out.
	 * @param string                  $slug     Its value.
	 * @return array<string, string[]> The attribute to its closest values, lower first; empty when there are none.
	 */
	private function closest( ?WP_Term $term, array $filters, ?array $ids, string $taxonomy, string $slug ): array {
		$listed = null;
		if ( null !== $term || null !== $ids || array() !== $filters ) {
			$found  = $this->query( $term, $filters, $ids, -1 );
			$listed = null === $found ? array() : Cast::ints( $found->posts );
		}
		$stocked = array_flip( $this->hidesSoldOut() ? $this->products->termsInStock( $taxonomy, $listed ) : $this->products->termsOf( $taxonomy, $listed ) );
		$values  = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		$values  = is_array( $values ) ? array_values( array_filter( $values, static fn( $value ): bool => $value instanceof WP_Term ) ) : array();
		$at      = array_search( $slug, wp_list_pluck( $values, 'slug' ), true );
		if ( ! is_int( $at ) ) {
			return array();
		}
		$out = array();
		for ( $i = $at - 1; $i >= 0; $i-- ) {
			if ( isset( $stocked[ $values[ $i ]->term_id ] ) ) {
				$out[] = $values[ $i ]->slug;
				break;
			}
		}
		$count = count( $values );
		for ( $i = $at + 1; $i < $count; $i++ ) {
			if ( isset( $stocked[ $values[ $i ]->term_id ] ) ) {
				$out[] = $values[ $i ]->slug;
				break;
			}
		}
		return array() === $out ? array() : array( $taxonomy => $out );
	}

	/**
	 * How many products the page a query is sent to lists: a category, the filters, the other words' products.
	 *
	 * @param WP_Term|null            $term    The category, or null.
	 * @param array<string, string[]> $filters Attribute taxonomy to slugs.
	 * @param int[]|null              $ids     The other words' products, or null when there were none.
	 */
	private function count( ?WP_Term $term, array $filters, ?array $ids ): int {
		if ( null !== $term && array() === $filters ) {
			return TermReader::count( $term );
		}
		return $this->query( $term, $filters, $ids, 1 )->found_posts ?? 0;
	}

	/**
	 * The page's listing as WooCommerce writes it, or null when it can list nothing.
	 *
	 * @param WP_Term|null            $term    The category, or null.
	 * @param array<string, string[]> $filters Attribute taxonomy to slugs.
	 * @param int[]|null              $ids     The other words' products, or null when there were none.
	 * @param int                     $limit   Products fetched (their ids), -1 for all; `found_posts` counts them all.
	 */
	private function query( ?WP_Term $term, array $filters, ?array $ids, int $limit ): ?WP_Query {
		$listed = $this->inStock( $filters, $ids );
		if ( array() === $listed ) {
			return null;
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
		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
			// WooCommerce's own listing leaves sold-out products out then, so the count does too.
			$tax[] = array(
				'taxonomy' => 'product_visibility',
				'field'    => 'term_taxonomy_id',
				'terms'    => array( Cast::int( $visibility['outofstock'] ?? 0 ) ),
				'operator' => 'NOT IN',
			);
		}
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
		return new WP_Query(
			array(
				'post_type'           => 'product',
				'post_status'         => 'publish',
				'fields'              => 'ids',
				'posts_per_page'      => $limit,
				'no_found_rows'       => -1 === $limit,
				'ignore_sticky_posts' => true,
				'post__in'            => $listed ?? array(),
				'tax_query'           => $tax, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- once per query sent on, and once per uncached panel answer that names a filter.
			)
		);
	}

	/**
	 * Whether WooCommerce filters by attribute through its lookup table and hides what is sold out there (D-151).
	 */
	private function hidesSoldOut(): bool {
		/** This filter is documented in woocommerce/src/Internal/ProductAttributesLookup/Filterer.php */
		$hide = (bool) apply_filters( 'woocommerce_product_attributes_filterer_hide_out_of_stock', 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, read as its listing reads it.
		return $hide && 'yes' === get_option( 'woocommerce_attribute_lookup_enabled' );
	}

	/**
	 * The products the filtered page can list, when WooCommerce filters by attribute through its lookup table and
	 * hides what is sold out (D-151): a variable product whose variation in the size is sold out is not listed, even
	 * though the product itself is in stock. Otherwise the ids are passed on as they are.
	 *
	 * @param array<string, string[]> $filters Attribute taxonomy to slugs.
	 * @param int[]|null              $ids     The other words' products, or null when there were none.
	 * @return int[]|null
	 */
	private function inStock( array $filters, ?array $ids ): ?array {
		if ( array() === $filters || ! $this->hidesSoldOut() ) {
			return $ids;
		}
		foreach ( $filters as $taxonomy => $slugs ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'slug'       => $slugs,
					'fields'     => 'ids',
					'hide_empty' => false,
				)
			);
			$found = $this->products->inStockWithTerms( is_array( $terms ) ? array_map( Cast::int( ... ), $terms ) : array() );
			$ids   = null === $ids ? $found : array_values( array_intersect( $ids, $found ) );
		}
		return $ids;
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
