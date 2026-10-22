<?php
/**
 * What the results page says.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Results;

use CreativeBranding\Search\Domain\Document;
use CreativeBranding\Search\Search\Cache;
use CreativeBranding\Search\Search\SearchService;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Cast;
use WC_Shortcode_Products;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The lines the plugin adds to WooCommerce's unchanged results template (spec: The front end → The results
 * page): the count ("23 results for kazumi"), the corrected query ("Showing results for kazumi deck; search
 * instead for kazumi deck 9.5"), and, when nothing matches, the three nearest categories and the owner's
 * fallback products. Every string is escaped where it is built.
 */
final class ResultsView {

	public const FALLBACK_LIMIT = 4;

	/**
	 * Constructor.
	 *
	 * @param ProductSearch $products The ranked search.
	 * @param SearchService $search   The search service, for the nearest categories.
	 * @param Cache         $cache    Answers, for the nearest categories.
	 */
	public function __construct( private ProductSearch $products, private SearchService $search, private Cache $cache ) {
	}

	/**
	 * The count and the corrected-query line, or an empty string when the page is not a ranked search.
	 */
	public function summary(): string {
		$answer = $this->products->answer();
		if ( null === $answer || '' === $answer['query'] ) {
			return '';
		}
		$count = $this->products->shown();
		$html  = '<p class="csearch-results-summary" role="status">' . esc_html(
			sprintf(
				/* translators: 1: number of products found, 2: the search query */
				_n( '%1$s result for “%2$s”', '%1$s results for “%2$s”', $count, 'creative-search' ),
				number_format_i18n( $count ),
				$answer['query']
			)
		) . '</p>';
		if ( null !== $answer['corrected'] ) {
			// The page the shopper is on, with its language and filters, asked for the exact words.
			$exact = add_query_arg( ProductSearch::EXACT_ARG, '1', remove_query_arg( array( 'paged', 'product-page' ) ) );
			$html .= '<p class="csearch-results-corrected">' . sprintf(
				/* translators: 1: the query the results are for, 2: a link to search the shopper's words exactly */
				esc_html__( 'Showing results for %1$s; search instead for %2$s', 'creative-search' ),
				'<em>' . esc_html( $answer['corrected'] ) . '</em>',
				'<a href="' . esc_url( $exact ) . '">' . esc_html( $answer['query'] ) . '</a>'
			) . '</p>';
		}
		return $html;
	}

	/**
	 * The no-results state: the summary, the nearest categories and the fallback products.
	 */
	public function noResults(): string {
		$answer = $this->products->answer();
		if ( null === $answer || '' === $answer['query'] || $this->products->shown() > 0 ) {
			return '';
		}
		$html  = '<div class="csearch-no-results">';
		$html .= $this->summary();
		$cats  = $this->nearestCategories( $answer['query'], $answer['lang'] );
		if ( array() !== $cats ) {
			$html .= '<p class="csearch-no-results-categories">' . esc_html__( 'Try one of these categories:', 'creative-search' ) . '</p><ul class="csearch-no-results-categories-list">';
			foreach ( $cats as $cat ) {
				$link  = get_term_link( $cat );
				$html .= '<li><a href="' . esc_url( is_string( $link ) ? $link : '' ) . '">' . esc_html( $cat->name ) . '</a></li>';
			}
			$html .= '</ul>';
		}
		$fallback = Cast::ints( Settings::get( 'fallback_products' ) );
		if ( array() !== $fallback && class_exists( WC_Shortcode_Products::class ) ) {
			$shortcode = new WC_Shortcode_Products(
				array(
					'ids'     => implode( ',', array_slice( $fallback, 0, self::FALLBACK_LIMIT ) ),
					'limit'   => self::FALLBACK_LIMIT,
					'columns' => self::FALLBACK_LIMIT,
					'orderby' => 'post__in',
				),
				'products'
			);
			$html     .= '<h2 class="csearch-no-results-fallback-title">' . esc_html__( 'You might like', 'creative-search' ) . '</h2>' . wp_kses_post( $shortcode->get_content() );
		}
		return $html . '</div>';
	}

	/**
	 * The three categories nearest the query: those the query's words point to, else the largest.
	 *
	 * @param string $query The query.
	 * @param string $lang  Its language.
	 * @return WP_Term[]
	 */
	public function nearestCategories( string $query, string $lang ): array {
		$key = Cache::key( $this->search->index()->generation(), 'nearest', array( $query, $lang ) );
		$ids = $this->cache->get( $key );
		if ( null === $ids ) {
			$ids = array();
			foreach ( $this->search->run( $query, $lang, 1, SearchService::MAX_TERMS )->terms as $hit ) {
				if ( Document::CATEGORY === $hit->document->type && count( $ids ) < Settings::TERMS_MAX ) {
					$ids[] = $hit->document->objectId;
				}
			}
			$this->cache->set( $key, $ids );
		}
		$ids   = Cast::ints( $ids );
		$terms = array();
		foreach ( $ids as $id ) {
			$term = get_term( $id, 'product_cat' );
			if ( $term instanceof WP_Term ) {
				$terms[] = $term;
			}
		}
		if ( array() !== $terms ) {
			return $terms;
		}
		$uncategorised = Cast::int( get_option( 'default_product_cat', 0 ) );
		$largest       = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => Settings::TERMS_MAX + 1,
				'hide_empty' => true,
			)
		);
		$largest       = is_array( $largest ) ? array_filter( $largest, static fn( $t ): bool => $t instanceof WP_Term && $t->term_id !== $uncategorised ) : array();
		return array_slice( array_values( $largest ), 0, Settings::TERMS_MAX );
	}
}
