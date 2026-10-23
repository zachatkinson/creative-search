<?php
/**
 * What the results page says.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Results;

use CreativeBranding\Search\Domain\Document;
use CreativeBranding\Search\Index\ContentReader;
use CreativeBranding\Search\Search\Cache;
use CreativeBranding\Search\Search\SearchService;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Cast;
use WC_Query;
use WC_Shortcode_Products;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The lines the plugin adds to WooCommerce's unchanged results template (spec: The front end → The results
 * page): the count ("23 results for kazumi"), the corrected query ("Showing results for kazumi deck; search
 * instead for kazumi deck 9.5"), the posts and pages the query finds (1.1, above the products unless a product
 * is named exactly as the query reads), and, when nothing matches, the matching pages, the owner's two links,
 * each word alone, the three nearest categories and the owner's fallback products. Every string is escaped where
 * it is built.
 */
final class ResultsView {

	public const FALLBACK_LIMIT = 4;

	/**
	 * The posts and pages found, once a request.
	 *
	 * @var array<int, array{id: int, type: string, name: string, parent: string, url: string, image: array{src: string, srcset: string, width: int, height: int, alt: string}|null}>|null
	 */
	private ?array $content = null;

	/**
	 * Whether a product is named exactly as the query reads, once a request.
	 *
	 * @var bool|null
	 */
	private ?bool $pagesBelow = null;

	/**
	 * Words of a query that found nothing offered alone at most.
	 */
	private const ALTERNATIVES = 3;

	/**
	 * Constructor.
	 *
	 * @param ProductSearch $products The ranked search.
	 * @param SearchService $search   The search service, for the nearest categories.
	 * @param Cache         $cache    Answers, for the nearest categories.
	 * @param QueryRouter   $router   The categories a query named.
	 */
	public function __construct( private ProductSearch $products, private SearchService $search, private Cache $cache, private QueryRouter $router ) {
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
		$scope = CategoryScope::requested();
		$line  = null === $scope
			? sprintf(
				/* translators: 1: number of products found, 2: the search query */
				_n( '%1$s result for “%2$s”', '%1$s results for “%2$s”', $count, 'creative-search' ),
				number_format_i18n( $count ),
				$answer['query']
			)
			: sprintf(
				/* translators: 1: number of products found, 2: the search query, 3: the category searched */
				_n( '%1$s result for “%2$s” in %3$s', '%1$s results for “%2$s” in %3$s', $count, 'creative-search' ),
				number_format_i18n( $count ),
				$answer['query'],
				$scope->name
			);
		$html = $this->categoriesLine() . '<p class="csearch-results-summary" role="status">' . esc_html( $line ) . '</p>';
		if ( null !== $scope ) {
			// The same search in every category, the page's filters and sort with it, not its page, and not sent on to a
			// category it names: the shopper asked for every product (D-117).
			$all   = add_query_arg( QueryRouter::ALL_ARG, '1', remove_query_arg( array( CategoryScope::ARG, 'paged', 'product-page' ) ) );
			$html .= '<p class="csearch-results-scope"><a href="' . esc_url( $all ) . '">' . esc_html(
				sprintf(
					/* translators: %s: the query the shopper typed */
					__( 'Search all products for “%s”.', 'creative-search' ),
					$answer['query']
				)
			) . '</a></p>';
		}
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
		if ( $count > 0 && ! $this->pagesBelow() ) {
			$html .= $this->contentSection();
		}
		return $html;
	}

	/**
	 * The categories a query named when two or more did, so none was opened (D-102): "Categories: Decks,
	 * Fingerboard decks", each a link.
	 */
	public function categoriesLine(): string {
		$terms = array();
		$urls  = array();
		foreach ( $this->router->categories() as $id ) {
			$term = get_term( $id, 'product_cat' );
			$url  = $term instanceof WP_Term ? get_term_link( $term ) : '';
			if ( $term instanceof WP_Term && is_string( $url ) ) {
				$terms[]                = $term;
				$urls[ $term->term_id ] = $url;
			}
		}
		if ( count( $terms ) < 2 ) {
			return '';
		}
		// Two links of one text would not say where each goes: a shared name carries its parent.
		$counts = array_count_values( wp_list_pluck( $terms, 'name' ) );
		$links  = array();
		foreach ( $terms as $term ) {
			$name   = $term->name;
			$parent = $counts[ $term->name ] > 1 && $term->parent > 0 ? get_term( $term->parent, 'product_cat' ) : null;
			if ( $parent instanceof WP_Term ) {
				/* translators: 1: a category, 2: the category it is in */
				$name = sprintf( _x( '%1$s (%2$s)', 'a category and its parent', 'creative-search' ), $term->name, $parent->name );
			}
			$links[] = '<a href="' . esc_url( $urls[ $term->term_id ] ) . '">' . esc_html( $name ) . '</a>';
		}
		return '<p class="csearch-results-categories">' . sprintf(
			/* translators: %s: a list of links to categories */
			esc_html__( 'Categories: %s', 'creative-search' ),
			wp_sprintf( '%l', $links )
		) . '</p>';
	}

	/**
	 * On the page a query was sent to (D-101, D-102), what it shows and the way back to every product: "Showing
	 * Wheels. Search all products for “wheels”." Empty on any other page.
	 */
	public function routedLine(): string {
		$from = QueryRouter::from();
		if ( '' === $from ) {
			return '';
		}
		$filters  = self::chosenFilters();
		$category = is_tax( 'product_cat' ) ? get_queried_object() : null;
		if ( $category instanceof WP_Term ) {
			$showing = '' === $filters
				/* translators: %s: a category */
				? sprintf( __( 'Showing %s.', 'creative-search' ), $category->name )
				/* translators: 1: a category, 2: the filters applied, such as "Width 8.25" */
				: sprintf( __( 'Showing %1$s with %2$s.', 'creative-search' ), $category->name, $filters );
		} elseif ( '' !== $filters ) {
			/* translators: %s: the filters applied, such as "Width 8.25" */
			$showing = sprintf( __( 'Showing products with %s.', 'creative-search' ), $filters );
		} else {
			return '';
		}
		$all = add_query_arg(
			array(
				's'                  => rawurlencode( $from ),
				'post_type'          => 'product',
				QueryRouter::ALL_ARG => '1',
			),
			home_url( '/' )
		);
		return '<p class="csearch-results-routed">' . esc_html( $showing ) . ' <a href="' . esc_url( $all ) . '">' . esc_html(
			sprintf(
				/* translators: %s: the query the shopper typed */
				__( 'Search all products for “%s”.', 'creative-search' ),
				$from
			)
		) . '</a></p>';
	}

	/**
	 * The attribute filters on the page, as WooCommerce reads them: "Width 8.25 and Wheel size 54mm".
	 */
	private static function chosenFilters(): string {
		$parts = array();
		foreach ( class_exists( WC_Query::class ) ? WC_Query::get_layered_nav_chosen_attributes() : array() as $taxonomy => $chosen ) {
			$names = array();
			foreach ( Cast::strings( $chosen['terms'] ?? array() ) as $slug ) {
				$term    = get_term_by( 'slug', $slug, (string) $taxonomy );
				$names[] = $term instanceof WP_Term ? $term->name : $slug;
			}
			if ( array() !== $names ) {
				/* translators: 1: an attribute, such as Width, 2: its values, such as 8.25 */
				$parts[] = sprintf( _x( '%1$s %2$s', 'an attribute and its values, such as Width 8.25', 'creative-search' ), wc_attribute_label( (string) $taxonomy ), wp_sprintf( '%l', $names ) );
			}
		}
		return wp_sprintf( '%l', $parts );
	}

	/**
	 * Forget what this request found (tests render several pages in one process).
	 */
	public function reset(): void {
		$this->content    = null;
		$this->pagesBelow = null;
	}

	/**
	 * The posts and pages after the products, when a product is named exactly as the query reads (a page never
	 * shows above it); else an empty string, the section having gone above them.
	 */
	public function contentAfter(): string {
		return $this->products->shown() > 0 && $this->pagesBelow() ? $this->contentSection() : '';
	}

	/**
	 * Whether the search found no product but found a post or page: then the page leads, and WooCommerce's bare
	 * "No products were found" is left out (Skatehouse handoff item 7).
	 */
	public function contentLeads(): bool {
		$answer = $this->products->answer();
		return null !== $answer && '' !== $answer['query'] && 0 === $this->products->shown() && array() !== $this->content();
	}

	/**
	 * The posts and pages the query finds on the results page's first page (D-093), fetched once a request.
	 *
	 * @return array<int, array{id: int, type: string, name: string, parent: string, url: string, image: array{src: string, srcset: string, width: int, height: int, alt: string}|null}>
	 */
	public function content(): array {
		$answer = $this->products->answer();
		if ( null === $answer || '' === $answer['query'] || Cast::int( get_query_var( 'paged' ) ) > 1 ) {
			return array();
		}
		$this->content ??= $this->search->content( $answer['query'], $answer['lang'], 'page' );
		return $this->content;
	}

	/**
	 * The heading over the posts and pages, by what is searched.
	 */
	public static function contentHeading(): string {
		$types = ContentReader::enabled();
		if ( array( 'page' ) === $types ) {
			return __( 'Pages', 'creative-search' );
		}
		return array( 'post' ) === $types ? __( 'Posts', 'creative-search' ) : __( 'Pages and posts', 'creative-search' );
	}

	/**
	 * The default wording of a no-results link (D-096).
	 *
	 * @param string $link `help` or `guide`.
	 */
	public static function defaultLinkText( string $link ): string {
		return 'help' === $link ? __( 'Can’t find what you need? Contact us', 'creative-search' ) : __( 'Need help choosing? Read our guides', 'creative-search' );
	}

	/**
	 * The posts and pages section, or an empty string.
	 */
	private function contentSection(): string {
		$rows = $this->content();
		if ( array() === $rows ) {
			return '';
		}
		$html = '<section class="csearch-content" aria-labelledby="csearch-content-title"><h2 id="csearch-content-title" class="csearch-content-title">' . esc_html( self::contentHeading() ) . '</h2><ul class="csearch-content-list">';
		foreach ( $rows as $row ) {
			$html .= '<li><a href="' . esc_url( $row['url'] ) . '">' . esc_html( $row['name'] ) . '</a>';
			if ( '' !== $row['parent'] ) {
				$html .= ' <span class="csearch-content-parent">' . esc_html( $row['parent'] ) . '</span>';
			}
			$html .= '</li>';
		}
		return $html . '</ul></section>';
	}

	/**
	 * Whether a product is named exactly as the query reads, so the pages go after the products.
	 */
	private function pagesBelow(): bool {
		$answer = $this->products->answer();
		if ( null === $answer || array() === $this->content() ) {
			return false;
		}
		$this->pagesBelow ??= $this->search->namesProduct( $answer['query'], $answer['lang'], $answer['ids'] );
		return $this->pagesBelow;
	}

	/**
	 * The owner's two no-results links (D-096): a help page and a second page, each with its wording.
	 *
	 * @param string $query The query that found nothing.
	 */
	private function links( string $query ): string {
		$links = array();
		foreach ( array( 'help', 'guide' ) as $link ) {
			$page = Cast::int( Settings::get( $link . '_page' ) );
			$url  = $page > 0 && 'page' === get_post_type( $page ) && 'publish' === get_post_status( $page ) ? get_permalink( $page ) : false;
			if ( is_string( $url ) ) {
				$text    = Cast::str( Settings::get( $link . '_text' ) );
				$links[] = array(
					'url'  => $url,
					'text' => '' !== $text ? $text : self::defaultLinkText( $link ),
					'kind' => $link,
				);
			}
		}
		/**
		 * The links the no-results page offers after the matching pages (D-096): the owner's help page and
		 * second page by default.
		 *
		 * @since 1.1.0
		 *
		 * @param array<int, array{url: string, text: string, kind: string}> $links The links.
		 * @param string                                                      $query The query that found nothing.
		 */
		$links = apply_filters( 'csearch_no_results_links', $links, $query );
		$html  = '';
		foreach ( is_array( $links ) ? $links : array() as $link ) {
			if ( is_array( $link ) && '' !== Cast::str( $link['url'] ?? '' ) && '' !== Cast::str( $link['text'] ?? '' ) ) {
				$html .= '<p class="csearch-no-results-link csearch-no-results-' . esc_attr( sanitize_html_class( Cast::str( $link['kind'] ?? '' ) ) ) . '"><a href="' . esc_url( Cast::str( $link['url'] ) ) . '">' . esc_html( Cast::str( $link['text'] ) ) . '</a></p>';
			}
		}
		return $html;
	}

	/**
	 * The no-results state (D-058, D-096): the summary, or the line that leads with the matching pages; the
	 * pages; the owner's two links; each word alone; the nearest categories; the fallback products.
	 */
	public function noResults(): string {
		$answer = $this->products->answer();
		if ( null === $answer || '' === $answer['query'] || $this->products->shown() > 0 ) {
			return '';
		}
		$html = '<div class="csearch-no-results">';
		if ( $this->contentLeads() ) {
			$html .= '<p class="csearch-results-summary" role="status">' . esc_html(
				sprintf(
					/* translators: %s: the search query */
					__( 'No products match “%s”, but these do:', 'creative-search' ),
					$answer['query']
				)
			) . '</p>' . $this->contentSection();
		} else {
			$html .= $this->summary();
		}
		$html .= $this->links( $answer['query'] );
		$html .= $this->alternatives( $answer['query'], $answer['lang'] );
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
	 * Each word of a query that found nothing, searched alone, when it finds something (Baymard: offer
	 * alternative searches on the no-results page; D-096). Up to three words, each a link with its count.
	 * The searches are the results page's own, cached, and not logged.
	 *
	 * @param string $query The query.
	 * @param string $lang  Its language.
	 */
	public function alternatives( string $query, string $lang ): string {
		$split = preg_split( '/\s+/u', $query );
		$words = array_values( array_unique( array_filter( false === $split ? array() : $split, static fn( string $w ): bool => mb_strlen( $w ) >= 2 ) ) );
		if ( count( $words ) < 2 ) {
			return ''; // One word is the query itself.
		}
		$found = array();
		foreach ( array_slice( $words, 0, self::ALTERNATIVES ) as $word ) {
			$count = count( $this->search->rankedIds( $word, $lang, '', true )['ids'] );
			if ( $count > 0 ) {
				$found[ $word ] = $count;
			}
		}
		/**
		 * The words offered on the no-results page, each with its result count.
		 *
		 * @since 1.1.0
		 *
		 * @param array<string, int> $found The words and their counts.
		 * @param string             $query The query that found nothing.
		 */
		$found = apply_filters( 'csearch_alternative_queries', $found, $query );
		if ( ! is_array( $found ) || array() === $found ) {
			return '';
		}
		$html = '<p class="csearch-no-results-alternatives">' . esc_html__( 'Try one word:', 'creative-search' ) . '</p><ul class="csearch-no-results-alternatives-list">';
		foreach ( $found as $word => $count ) {
			$url   = add_query_arg(
				array(
					's'         => rawurlencode( (string) $word ),
					'post_type' => 'product',
				),
				home_url( '/' )
			);
			$html .= '<li><a href="' . esc_url( $url ) . '">' . esc_html(
				sprintf(
					/* translators: 1: a word of the shopper's query, 2: how many products it finds alone */
					_n( '%1$s (%2$s result)', '%1$s (%2$s results)', Cast::int( $count ), 'creative-search' ),
					(string) $word,
					number_format_i18n( Cast::int( $count ) )
				)
			) . '</a></li>';
		}
		return $html . '</ul>';
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
