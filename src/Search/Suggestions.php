<?php
/**
 * The suggestion panel's answer.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Search;

use CreativeBranding\Search\Domain\Document;
use CreativeBranding\Search\Domain\Hit;
use CreativeBranding\Search\Domain\SearchResult;
use CreativeBranding\Search\Results\CategoryScope;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Cast;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `{ queries, products, scopes, terms, content, total, query, corrected, lang }` for the panel (spec: The search API →
 * /suggest), built from the documents table alone: name, URL, image, price, stock and whether it can go straight
 * into the cart are on the row, so no WooCommerce product object is loaded on the search path. Suggested searches
 * (D-112) and the categories to search within (D-113) come with them (1.3).
 */
final class Suggestions {

	/**
	 * CSS classes a suggestion row carries at most.
	 */
	public const CLASSES = 8;

	/**
	 * Constructor.
	 *
	 * @param SearchService    $search  The search service.
	 * @param Cache            $cache   Answers.
	 * @param MatchDetails     $matches The variation a query named and why each product matched (D-104, D-105).
	 * @param QuerySuggestions $queries Suggested searches.
	 * @param CategoryScopes   $scopes  The categories to search within.
	 * @param Destination      $lands   Where the search lands, for the "See all" count (D-145).
	 */
	public function __construct( private SearchService $search, private Cache $cache, private MatchDetails $matches, private QuerySuggestions $queries, private CategoryScopes $scopes, private Destination $lands ) {
	}

	/**
	 * The answer for a query, cached, logged.
	 *
	 * @param string       $raw   Query as received.
	 * @param string|null  $lang  Language asked for.
	 * @param int          $limit Products, 1 to 10.
	 * @param WP_Term|null $within Products of this category and its subcategories only (the API's `cat`, D-117).
	 * @param WP_Term|null $here   The category the shopper is in, offered first among the scopes (D-130).
	 * @return array<mixed> `queries` (1.3), `products`, `scopes` (1.3), `terms`, `content` (posts and pages, 1.1),
	 *                      `total`, `query`, `corrected`, `lang`.
	 */
	public function answer( string $raw, ?string $lang, int $limit, ?WP_Term $within = null, ?WP_Term $here = null ): array {
		$scope = CategoryScope::ttIds( $within );
		$query = SearchService::clean( $raw );
		$lang  = $this->search->language( $lang );
		$limit = max( Settings::LIMIT_MIN, min( Settings::LIMIT_MAX, $limit ) );
		$empty = array(
			'queries'   => array(),
			'products'  => array(),
			'scopes'    => array(),
			'terms'     => array(),
			'content'   => array(),
			'total'     => 0,
			'query'     => $query,
			'corrected' => null,
			'lang'      => $lang,
		);
		if ( '' === $query ) {
			return $empty;
		}
		$terms  = Settings::isOn( 'show_terms' ) ? Settings::clamp( Settings::int( 'suggest_terms' ), 0, Settings::TERMS_MAX ) : 0;
		$panel  = array( Settings::get( 'suggest_queries' ), Settings::get( 'suggest_scopes' ), Settings::get( 'suggest_cart' ) );
		$key    = Cache::key( $this->search->index()->generation(), 'suggest', array( $query, $lang, $limit, $terms, $scope, null === $here ? 0 : $here->term_id, $this->search->settingsKey(), $panel, Settings::get( 'category_redirect' ), Settings::get( 'filter_attributes' ), get_locale() ) );
		$answer = $this->cache->get( $key );
		if ( null === $answer ) {
			$result = $this->search->run( $query, $lang, SearchService::MAX_IDS, $terms, true, false, $scope );
			// A panel limited to a category leaves out the pins: one may sit outside it (D-110, D-117).
			$pinned   = array() === $scope ? $this->search->pinnedDocuments( $query, $lang, $result->lang ) : array();
			$products = $this->pinnedFirst( $result->products, $pinned );
			$shown    = array();
			foreach ( $result->terms as $hit ) {
				if ( Document::CATEGORY === $hit->document->type ) {
					$shown[] = $hit->document->objectId;
				}
			}
			$answer = array(
				'queries'   => $this->queries->for( $query, $lang ),
				'products'  => $this->products( array_slice( $products, 0, $limit ), $result, $pinned ),
				// A panel already limited to a category offers no other category to search within (D-113, D-117).
				'scopes'    => array() === $scope ? $this->scopes->for( $query, $result->productIds(), $shown, $here ) : array(),
				'terms'     => $this->terms( $result->terms ),
				'content'   => $this->search->content( $query, $lang, 'suggest' ),
				'total'     => $this->total( $query, $lang, count( $products ), $within ),
				'query'     => $query,
				'corrected' => $result->corrected,
				'lang'      => $lang,
			);
			$this->cache->set( $key, $answer );
		}
		$this->search->log()->record( $query, $lang, Cast::int( $answer['total'] ?? 0 ), 'suggest' );
		return $answer;
	}

	/**
	 * The number "See all" shows: what the page it opens lists. A size or other value the shop filters by becomes
	 * that filter on the way to the page (D-101, D-102), so the page lists fewer than the panel's search found and
	 * the count is the page's (D-145); any other query lists what the panel found.
	 *
	 * @param string       $query  Clean query.
	 * @param string       $lang   Checked language.
	 * @param int          $found  The products the panel's search found.
	 * @param WP_Term|null $within The category the search is kept to, or null.
	 */
	private function total( string $query, string $lang, int $found, ?WP_Term $within ): int {
		return $this->lands->filteredCount( $query, $lang, $within ) ?? $found;
	}

	/**
	 * The ranked products' documents with the owner's pinned products first, in the pins' order (D-110): a pinned
	 * product that is ranked moves up, one that is not is added.
	 *
	 * @param Hit[]                $hits   Ranked products.
	 * @param array<int, Document> $pinned Pinned products' documents, by product id ({@see SearchService::pinnedDocuments()}).
	 * @return Document[]
	 */
	private function pinnedFirst( array $hits, array $pinned ): array {
		$documents = array_map( static fn( Hit $h ): Document => $h->document, $hits );
		if ( array() === $pinned ) {
			return $documents;
		}
		$rest = array_filter( $documents, static fn( Document $d ): bool => ! isset( $pinned[ $d->objectId ] ) );
		return array_merge( array_values( $pinned ), array_values( $rest ) );
	}

	/**
	 * Product rows: a variation the query named shows its own image and opens with it chosen (D-104), and a match
	 * the row does not show says what it was (D-105).
	 *
	 * @param Document[]           $documents Products, in order.
	 * @param SearchResult         $result    The search they came from.
	 * @param array<int, Document> $pinned    The owner's pinned products, by product id: never `described`, so they keep their place (D-143).
	 * @return array<int, array<string, mixed>>
	 */
	private function products( array $documents, SearchResult $result, array $pinned = array() ): array {
		$ids     = array_map( static fn( Document $d ): int => $d->id, $documents );
		$rows    = $this->search->index()->rows( $ids );
		$details = $this->matches->of( $ids, $result->tokens, $result->key, $result->lang );
		$cart    = Settings::isOn( 'suggest_cart' );
		$only    = array();
		foreach ( $result->products as $hit ) {
			if ( $hit->score->descriptionOnly && ! isset( $pinned[ $hit->document->objectId ] ) ) {
				$only[ $hit->document->id ] = true;
			}
		}
		$out = array();
		foreach ( $documents as $document ) {
			$row = $rows[ $document->id ] ?? null;
			if ( null === $row ) {
				continue;
			}
			$detail     = $details[ $document->id ] ?? null;
			$described  = isset( $only[ $document->id ] );
			$imageId    = $detail->imageId ?? Cast::int( $row['image_id'] );
			$suggestion = array(
				'id'         => $document->objectId,
				'name'       => $document->title,
				'url'        => null === $detail ? Cast::str( $row['url'] ) : MatchDetails::url( Cast::str( $row['url'] ), $detail ),
				'image'      => self::image( $imageId ),
				'price_html' => self::price( $row['price_min'], $row['price_max'] ),
				// The lowest price as a number, for an analytics tag's add-to-cart value (D-116); null when there is none.
				'price'      => is_numeric( $row['price_min'] ) ? (float) $row['price_min'] : null,
				'in_stock'   => $document->inStock,
				'type'       => 'product',
				'matched'    => null === $detail ? '' : MatchDetails::line( $detail ),
				// Some searched word reached it only in its description: the panel puts the page asked for before it (D-143).
				'described'  => $described,
				// Simple, purchasable, in stock and needing no choice: one click puts it in the cart (D-115).
				'cart'       => $cart && 1 === Cast::int( $row['cart'] ?? 0 ),
			);
			/**
			 * CSS classes for a product's suggestion row, as Mature Content adds one to blur flagged artwork
			 * (Skatehouse handoff item 3). Each is sanitised with `sanitize_html_class()`; the answer is cached,
			 * so a plugin whose answer changes calls `do_action( 'csearch_flush_cache' )`.
			 *
			 * @since 1.1.0
			 *
			 * @param string[]             $classes   None by default.
			 * @param int                  $productId The product.
			 * @param int|null             $imageId   The image the row shows (since 1.3.0 a variation's the query named), or null.
			 * @param array<string, mixed> $row       The suggestion as the panel receives it.
			 */
			$classes               = apply_filters( 'csearch_suggestion_classes', array(), $document->objectId, $imageId > 0 ? $imageId : null, $suggestion );
			$suggestion['classes'] = self::classes( $classes );
			$out[]                 = $suggestion;
		}
		return $out;
	}

	/**
	 * Term rows.
	 *
	 * @param Hit[] $hits Ranked categories, tags and brands.
	 * @return array<int, array<string, mixed>>
	 */
	private function terms( array $hits ): array {
		$rows = $this->search->index()->rows( array_map( static fn( Hit $h ): int => $h->document->id, $hits ) );
		$out  = array();
		foreach ( $hits as $hit ) {
			$out[] = array(
				'id'       => $hit->document->objectId,
				'taxonomy' => $hit->document->type,
				'name'     => $hit->document->title,
				'count'    => $hit->document->totalSales,
				'url'      => Cast::str( $rows[ $hit->document->id ]['url'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * A filter's CSS classes, sanitised, without repeats, eight at most.
	 *
	 * @param mixed $classes What the filter returned.
	 * @return string[]
	 */
	public static function classes( mixed $classes ): array {
		$out = array();
		foreach ( is_array( $classes ) ? $classes : array() as $class ) {
			$class = is_string( $class ) ? sanitize_html_class( $class ) : '';
			if ( '' !== $class && ! in_array( $class, $out, true ) && count( $out ) < self::CLASSES ) {
				$out[] = $class;
			}
		}
		return $out;
	}

	/**
	 * The thumbnail with its srcset, or null.
	 *
	 * @param int $imageId Attachment id.
	 * @return array{src: string, srcset: string, width: int, height: int, alt: string}|null
	 */
	public static function image( int $imageId ): ?array {
		if ( $imageId <= 0 ) {
			return null;
		}
		$src = wp_get_attachment_image_src( $imageId, 'woocommerce_thumbnail' );
		if ( ! is_array( $src ) ) {
			return null;
		}
		$srcset = wp_get_attachment_image_srcset( $imageId, 'woocommerce_thumbnail' );
		return array(
			'src'    => (string) $src[0],
			'srcset' => is_string( $srcset ) ? $srcset : '',
			'width'  => (int) $src[1],
			'height' => (int) $src[2],
			'alt'    => Cast::str( get_post_meta( $imageId, '_wp_attachment_image_alt', true ) ),
		);
	}

	/**
	 * The price through WooCommerce's formatting (a range for a variable product), or an empty string.
	 *
	 * @param string|null $min Lowest price.
	 * @param string|null $max Highest price.
	 */
	public static function price( ?string $min, ?string $max ): string {
		if ( null === $min || ! is_numeric( $min ) || ! Settings::isOn( 'show_prices' ) ) {
			return '';
		}
		if ( null !== $max && is_numeric( $max ) && (float) $max > (float) $min ) {
			return wc_format_price_range( $min, $max );
		}
		return wc_price( (float) $min );
	}
}
