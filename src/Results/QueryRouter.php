<?php
/**
 * Sends a results-page query where it belongs.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Results;

use CreativeBranding\Search\Search\Destination;
use CreativeBranding\Search\Search\SearchLog;
use CreativeBranding\Search\Search\SearchService;
use CreativeBranding\Search\Support\Cast;
use WP_Query;

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
	public const NONE_ARG = 'csearch_none';
	public const NEAR_ARG = 'csearch_near';

	/**
	 * Arguments that mean the shopper is refining a search they already have.
	 */
	private const REFINING = array( self::ALL_ARG, self::FROM_ARG, self::SIG_ARG, self::NONE_ARG, self::NEAR_ARG, ProductSearch::EXACT_ARG, 'orderby', 'min_price', 'max_price', 'rating_filter' );

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
	 * @param Destination   $destination Where the query lands and what that page lists (D-145).
	 * @param SearchService $search      The language.
	 * @param SearchLog     $log         The log, for the query the shopper typed.
	 */
	public function __construct( private Destination $destination, private SearchService $search, private SearchLog $log ) {
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
		$lang    = $this->search->language( ProductSearch::lang() );
		$landing = $this->destination->of( $words, $lang, CategoryScope::requested() );
		if ( null === $landing ) {
			return;
		}
		if ( is_array( $landing ) ) {
			// Two categories of one name: the shopper chooses, on the results page for every word they typed.
			$this->categories = $landing['categories'];
			return;
		}
		// The target is kept for `template_redirect`; the query the shopper typed is logged once with what the page
		// they land on lists (that page does not log it again), or as finding nothing when the value asked for was
		// left out (D-154); and the page is left: no ranking, no search clause, nothing fetched.
		$this->target = $landing->url;
		$this->log->record( $words, $lang, $landing->logged(), 'page' );
		$query->set( 'csearch_routed', true );
		$query->set( 'post__in', array( 0 ) );
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
	 * The arguments that tell the page landed on which query sent the shopper there: the query, the values it named
	 * that were left out for having nothing in stock there and the closest values in stock (D-154), and a signature
	 * of them all, so a link made elsewhere cannot put words on the shop's pages.
	 *
	 * @param string                  $words     The query.
	 * @param array<string, string[]> $unstocked Attribute taxonomy to the slugs left out.
	 * @param array<string, string[]> $closest   Attribute taxonomy to the closest slugs in stock.
	 * @return array<string, string>
	 */
	public static function fromArgs( string $words, array $unstocked = array(), array $closest = array() ): array {
		$none = self::values( $unstocked );
		$near = self::values( $closest );
		$args = array(
			self::FROM_ARG => rawurlencode( $words ),
			self::SIG_ARG  => self::signature( $words, $none, $near ),
		);
		if ( '' !== $none ) {
			$args[ self::NONE_ARG ] = rawurlencode( $none );
		}
		if ( '' !== $near ) {
			$args[ self::NEAR_ARG ] = rawurlencode( $near );
		}
		return $args;
	}

	/**
	 * The query that sent the shopper to this page, cleaned, when its signature holds; an empty string on any
	 * other page.
	 */
	public static function from(): string {
		$from = self::arg( self::FROM_ARG );
		$from = '' === $from ? '' : SearchService::clean( $from );
		$sig  = sanitize_key( self::arg( self::SIG_ARG ) );
		return '' !== $from && hash_equals( self::signature( $from, self::arg( self::NONE_ARG ), self::arg( self::NEAR_ARG ) ), $sig ) ? $from : '';
	}

	/**
	 * The values the query named that were left out of this page, nothing in them being in stock (D-154), when the
	 * signature holds: attribute taxonomy to slugs.
	 *
	 * @return array<string, string[]>
	 */
	public static function unstocked(): array {
		return '' === self::from() ? array() : self::parse( self::arg( self::NONE_ARG ) );
	}

	/**
	 * The closest values in stock to the one left out (D-154), when the signature holds: attribute taxonomy to slugs.
	 *
	 * @return array<string, string[]>
	 */
	public static function closest(): array {
		return '' === self::from() ? array() : self::parse( self::arg( self::NEAR_ARG ) );
	}

	/**
	 * A public, read-only argument of this request, unslashed, or an empty string.
	 *
	 * @param string $name The argument.
	 */
	private static function arg( string $name ): string {
		return isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? wp_unslash( $_GET[ $name ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- public and read-only; checked against its signature before use, and every reader cleans it.
	}

	/**
	 * Values as one argument: `pa_width:9-00,pa_width:9-5`.
	 *
	 * @param array<string, string[]> $values Attribute taxonomy to slugs.
	 */
	private static function values( array $values ): string {
		$out = array();
		foreach ( $values as $taxonomy => $slugs ) {
			foreach ( $slugs as $slug ) {
				$out[] = $taxonomy . ':' . $slug;
			}
		}
		return implode( ',', $out );
	}

	/**
	 * Values back from an argument: attribute taxonomies that exist, each with its slugs.
	 *
	 * @param string $arg The argument.
	 * @return array<string, string[]>
	 */
	private static function parse( string $arg ): array {
		$out = array();
		foreach ( '' === $arg ? array() : explode( ',', $arg ) as $value ) {
			$parts = explode( ':', $value, 2 );
			$slug  = sanitize_title( $parts[1] ?? '' );
			if ( '' !== $slug && str_starts_with( $parts[0], 'pa_' ) && taxonomy_exists( $parts[0] ) ) {
				$out[ $parts[0] ][] = $slug;
			}
		}
		return $out;
	}

	/**
	 * A query's signature: the site's hash of it and of the values left out, cut short. The same for every visitor,
	 * so the page can be cached; a query alone signs as it did before 1.6.5.
	 *
	 * @param string $words The query.
	 * @param string $none  The values left out, as one argument.
	 * @param string $near  The closest values, as one argument.
	 */
	private static function signature( string $words, string $none = '', string $near = '' ): string {
		$signed = '' === $none && '' === $near ? $words : $words . "\n" . $none . "\n" . $near;
		return substr( wp_hash( 'csearch_from|' . $signed ), 0, 12 );
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
