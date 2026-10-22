<?php
/**
 * The suggestion panel's answer.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Search;

use CreativeBranding\Search\Domain\Hit;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Cast;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `{ products, terms, total, query, corrected, lang }` for the panel (spec: The search API → /suggest), built
 * from the documents table alone: name, URL, image, price and stock are on the row, so no WooCommerce product
 * object is loaded on the search path.
 */
final class Suggestions {

	/**
	 * Constructor.
	 *
	 * @param SearchService $search The search service.
	 * @param Cache         $cache  Answers.
	 */
	public function __construct( private SearchService $search, private Cache $cache ) {
	}

	/**
	 * The answer for a query, cached, logged.
	 *
	 * @param string      $raw   Query as received.
	 * @param string|null $lang  Language asked for.
	 * @param int         $limit Products, 1 to 10.
	 * @return array<mixed> `products`, `terms`, `total`, `query`, `corrected`, `lang`.
	 */
	public function answer( string $raw, ?string $lang, int $limit ): array {
		$query = SearchService::clean( $raw );
		$lang  = $this->search->language( $lang );
		$limit = max( Settings::LIMIT_MIN, min( Settings::LIMIT_MAX, $limit ) );
		$empty = array(
			'products'  => array(),
			'terms'     => array(),
			'total'     => 0,
			'query'     => $query,
			'corrected' => null,
			'lang'      => $lang,
		);
		if ( '' === $query ) {
			return $empty;
		}
		$terms  = Settings::isOn( 'show_terms' ) ? Settings::clamp( Settings::int( 'suggest_terms' ), 0, Settings::TERMS_MAX ) : 0;
		$key    = Cache::key( $this->search->index()->generation(), 'suggest', array( $query, $lang, $limit, $terms, $this->search->settingsKey(), get_locale() ) );
		$answer = $this->cache->get( $key );
		if ( null === $answer ) {
			$result = $this->search->run( $query, $lang, SearchService::MAX_IDS, $terms );
			$answer = array(
				'products'  => $this->products( array_slice( $result->products, 0, $limit ) ),
				'terms'     => $this->terms( $result->terms ),
				'total'     => count( $result->products ),
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
	 * Product rows.
	 *
	 * @param Hit[] $hits Ranked products.
	 * @return array<int, array<string, mixed>>
	 */
	private function products( array $hits ): array {
		$rows = $this->search->index()->rows( array_map( static fn( Hit $h ): int => $h->document->id, $hits ) );
		$out  = array();
		foreach ( $hits as $hit ) {
			$row = $rows[ $hit->document->id ] ?? null;
			if ( null === $row ) {
				continue;
			}
			$out[] = array(
				'id'         => $hit->document->objectId,
				'name'       => $hit->document->title,
				'url'        => Cast::str( $row['url'] ),
				'image'      => self::image( Cast::int( $row['image_id'] ) ),
				'price_html' => self::price( $row['price_min'], $row['price_max'] ),
				'in_stock'   => $hit->document->inStock,
				'type'       => 'product',
			);
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
