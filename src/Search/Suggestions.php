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
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Cast;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `{ products, terms, content, total, query, corrected, lang }` for the panel (spec: The search API → /suggest), built
 * from the documents table alone: name, URL, image, price and stock are on the row, so no WooCommerce product
 * object is loaded on the search path.
 */
final class Suggestions {

	/**
	 * CSS classes a suggestion row carries at most.
	 */
	public const CLASSES = 8;

	/**
	 * Constructor.
	 *
	 * @param SearchService $search  The search service.
	 * @param Cache         $cache   Answers.
	 * @param MatchDetails  $matches The variation a query named and why each product matched (D-104, D-105).
	 */
	public function __construct( private SearchService $search, private Cache $cache, private MatchDetails $matches ) {
	}

	/**
	 * The answer for a query, cached, logged.
	 *
	 * @param string      $raw   Query as received.
	 * @param string|null $lang  Language asked for.
	 * @param int         $limit Products, 1 to 10.
	 * @param int[]       $scope Products of these term-taxonomy ids only: the category the form searches (D-117).
	 * @return array<mixed> `products`, `terms`, `content` (posts and pages, 1.1), `total`, `query`, `corrected`, `lang`.
	 */
	public function answer( string $raw, ?string $lang, int $limit, array $scope = array() ): array {
		$query = SearchService::clean( $raw );
		$lang  = $this->search->language( $lang );
		$limit = max( Settings::LIMIT_MIN, min( Settings::LIMIT_MAX, $limit ) );
		$empty = array(
			'products'  => array(),
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
		$key    = Cache::key( $this->search->index()->generation(), 'suggest', array( $query, $lang, $limit, $terms, $scope, $this->search->settingsKey(), get_locale() ) );
		$answer = $this->cache->get( $key );
		if ( null === $answer ) {
			$result = $this->search->run( $query, $lang, SearchService::MAX_IDS, $terms, true, false, $scope );
			// A panel limited to a category leaves out the pins: one may sit outside it (D-110, D-117).
			$products = $this->pinnedFirst( $result->products, array() === $scope ? $this->search->rules()->pins( $query, $lang ) : array(), $result->lang );
			$answer   = array(
				'products'  => $this->products( array_slice( $products, 0, $limit ), $result ),
				'terms'     => $this->terms( $result->terms ),
				'content'   => $this->search->content( $query, $lang, 'suggest' ),
				'total'     => count( $products ),
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
	 * The ranked products' documents with the owner's pinned products first, in the pins' order (D-110): a pinned
	 * product that is ranked moves up, one that is not is added, one hidden from search or not in the index in the
	 * answer's language is left out.
	 *
	 * @param Hit[]  $hits Ranked products.
	 * @param int[]  $pins Pinned product ids.
	 * @param string $lang The language whose documents answered.
	 * @return Document[]
	 */
	private function pinnedFirst( array $hits, array $pins, string $lang ): array {
		$documents = array_map( static fn( Hit $h ): Document => $h->document, $hits );
		if ( array() === $pins ) {
			return $documents;
		}
		$pinned = array();
		foreach ( $this->search->index()->productDocuments( $lang, $pins ) as $document ) {
			if ( $document->visible ) {
				$pinned[ $document->objectId ] = $document;
			}
		}
		$rest = array_filter( $documents, static fn( Document $d ): bool => ! isset( $pinned[ $d->objectId ] ) );
		return array_merge( array_values( $pinned ), array_values( $rest ) );
	}

	/**
	 * Product rows: a variation the query named shows its own image and opens with it chosen (D-104), and a match
	 * the row does not show says what it was (D-105).
	 *
	 * @param Document[]   $documents Products, in order.
	 * @param SearchResult $result    The search they came from.
	 * @return array<int, array<string, mixed>>
	 */
	private function products( array $documents, SearchResult $result ): array {
		$ids     = array_map( static fn( Document $d ): int => $d->id, $documents );
		$rows    = $this->search->index()->rows( $ids );
		$details = $this->matches->of( $ids, $result->tokens, $result->key, $result->lang );
		$out     = array();
		foreach ( $documents as $document ) {
			$row = $rows[ $document->id ] ?? null;
			if ( null === $row ) {
				continue;
			}
			$detail     = $details[ $document->id ] ?? null;
			$imageId    = $detail->imageId ?? Cast::int( $row['image_id'] );
			$suggestion = array(
				'id'         => $document->objectId,
				'name'       => $document->title,
				'url'        => null === $detail ? Cast::str( $row['url'] ) : MatchDetails::url( Cast::str( $row['url'] ), $detail ),
				'image'      => self::image( $imageId ),
				'price_html' => self::price( $row['price_min'], $row['price_max'] ),
				'in_stock'   => $document->inStock,
				'type'       => 'product',
				'matched'    => null === $detail ? '' : MatchDetails::line( $detail ),
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
