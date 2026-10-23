<?php
/**
 * The results page.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Hooks;

use CreativeBranding\Search\Hookable;
use CreativeBranding\Search\Index\AttributeTerms;
use CreativeBranding\Search\Results\ProductSearch;
use CreativeBranding\Search\Results\QueryRouter;
use CreativeBranding\Search\Results\ResultsView;
use CreativeBranding\Search\Search\QueryRoutes;
use CreativeBranding\Search\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ranks the product search and prints the plugin's lines in classic templates (WooCommerce's shop loop hooks)
 * and block templates (the product collection's result count and no-results blocks).
 */
final class ResultsHooks implements Hookable {

	/**
	 * Whether the count line has been printed on this page (a block template reaches it by more than one of
	 * WooCommerce's paths).
	 *
	 * @var bool
	 */
	private bool $summaryShown = false;

	/**
	 * Whether the no-results state has been printed on this page.
	 *
	 * @var bool
	 */
	private bool $emptyShown = false;

	/**
	 * Whether the posts and pages have been printed after the products on this page.
	 *
	 * @var bool
	 */
	private bool $contentShown = false;

	/**
	 * Whether the line on the page a query was sent to has been printed.
	 *
	 * @var bool
	 */
	private bool $routedShown = false;

	/**
	 * Constructor.
	 *
	 * @param ProductSearch  $products   The ranked search.
	 * @param ResultsView    $view       The lines.
	 * @param QueryRouter    $router     Sends a query that names a category or a filter there.
	 * @param AttributeTerms $attributes The values a query may filter by, rebuilt when one changes.
	 */
	public function __construct( private ProductSearch $products, private ResultsView $view, private QueryRouter $router, private AttributeTerms $attributes ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'pre_get_posts', array( $this->router, 'route' ), 4 );
		add_action( 'template_redirect', array( $this->router, 'redirect' ), 5 );
		foreach ( array( 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
			add_action( $hook, array( $this->attributes, 'termChanged' ), 10, 3 );
			add_action( $hook, array( QueryRoutes::class, 'forget' ), 10, 3 );
		}
		add_action( 'csearch_index_switched', array( QueryRoutes::class, 'forget' ), 10, 0 );
		add_action( 'update_option_' . Options::SETTINGS, array( $this->attributes, 'settingsChanged' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this->products, 'apply' ), 5 );
		add_action( 'pre_get_posts', array( $this->products, 'enforce' ), 20 );
		add_action( 'pre_get_posts', array( $this->products, 'order' ), 1000 );
		add_filter( 'posts_search', array( $this->products, 'searchClause' ), 1000, 2 );
		add_filter( 'woocommerce_get_catalog_ordering_args', array( $this->products, 'ordering' ), 20, 2 );
		add_filter( 'woocommerce_product_query_tax_query', array( $this->products, 'visibility' ), 20 );
		add_filter( 'woocommerce_get_filtered_term_product_counts_query', array( $this->products, 'filterCounts' ) );
		add_filter( 'woocommerce_price_filter_sql', array( $this->products, 'priceRange' ) );
		add_action( 'woocommerce_before_shop_loop', array( $this, 'printSummary' ), 5 );
		add_action( 'woocommerce_no_products_found', array( $this, 'printRouted' ), 4 );
		add_action( 'woocommerce_no_products_found', array( $this, 'leadWithContent' ), 5 );
		add_action( 'woocommerce_no_products_found', array( $this, 'printNoResults' ), 20 );
		add_action( 'woocommerce_after_shop_loop', array( $this, 'printContentAfter' ), 5 );
		add_filter( 'render_block_woocommerce/product-template', array( $this, 'blockContentAfter' ) );
		add_filter( 'woocommerce_redirect_single_search_result', array( $this, 'singleRedirect' ) );
		add_filter( 'render_block_woocommerce/product-results-count', array( $this, 'blockSummary' ) );
		add_filter( 'render_block_woocommerce/product-collection-no-results', array( $this, 'blockNoResults' ) );
	}

	/**
	 * The count and corrected-query line above a classic shop loop.
	 */
	public function printSummary(): void {
		echo $this->routedOnce() . $this->summaryOnce(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built, in ResultsView.
	}

	/**
	 * On the page a query was sent to, the line saying so, above WooCommerce's "No products were found".
	 */
	public function printRouted(): void {
		echo $this->routedOnce(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built, in ResultsView.
	}

	/**
	 * The no-results state below WooCommerce's own notice.
	 */
	public function printNoResults(): void {
		echo $this->noResultsOnce(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built, in ResultsView.
	}

	/**
	 * The count line before a block theme's result count.
	 *
	 * @param string|mixed $content The block's HTML.
	 * @return string|mixed
	 */
	public function blockSummary( $content ) {
		if ( ! is_string( $content ) ) {
			return $content;
		}
		// With nothing to show, the no-results block carries the line (inside the no-results state).
		return $this->routedOnce() . ( 0 === $this->products->shown() ? '' : $this->summaryOnce() ) . $content;
	}

	/**
	 * The no-results state after a block theme's no-results block.
	 *
	 * @param string|mixed $content The block's HTML.
	 * @return string|mixed
	 */
	public function blockNoResults( $content ) {
		if ( ! is_string( $content ) ) {
			return $content;
		}
		// When a page matches, it leads: the block's bare "No products were found" goes.
		return $this->routedOnce() . ( $this->view->contentLeads() ? $this->noResultsOnce() : $content . $this->noResultsOnce() );
	}

	/**
	 * Before WooCommerce's own "No products were found": when a post or page matches, that notice goes and the
	 * no-results state leads with the page (Skatehouse handoff item 7).
	 */
	public function leadWithContent(): void {
		if ( $this->view->contentLeads() ) {
			remove_action( 'woocommerce_no_products_found', 'wc_no_products_found' );
		}
	}

	/**
	 * The posts and pages after a classic shop loop, when a product is named exactly as the query reads.
	 */
	public function printContentAfter(): void {
		echo $this->contentAfterOnce(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built, in ResultsView.
	}

	/**
	 * The same after a block theme's product template.
	 *
	 * @param string|mixed $content The block's HTML.
	 * @return string|mixed
	 */
	public function blockContentAfter( $content ) {
		return is_string( $content ) ? $content . $this->contentAfterOnce() : $content;
	}

	/**
	 * WooCommerce opens the product when a search finds exactly one; not when a post or page was found too, nor
	 * on the page a query was sent to, where the shopper is to see the filter it applied (D-101).
	 *
	 * @param bool|mixed $redirect WooCommerce's choice.
	 * @return bool|mixed
	 */
	public function singleRedirect( $redirect ) {
		return array() !== $this->view->content() || QueryRouter::arrived() ? false : $redirect;
	}

	/**
	 * The posts and pages after the products, the first time they are asked for on a page.
	 */
	private function contentAfterOnce(): string {
		if ( $this->contentShown ) {
			return '';
		}
		$html               = $this->view->contentAfter();
		$this->contentShown = '' !== $html;
		return $html;
	}

	/**
	 * The line on the page a query was sent to, the first time it is asked for on a page.
	 */
	private function routedOnce(): string {
		if ( $this->routedShown ) {
			return '';
		}
		$html              = $this->view->routedLine();
		$this->routedShown = '' !== $html;
		return $html;
	}

	/**
	 * The count line, the first time it is asked for on a page.
	 */
	private function summaryOnce(): string {
		if ( $this->summaryShown ) {
			return '';
		}
		$html               = $this->view->summary();
		$this->summaryShown = '' !== $html;
		return $html;
	}

	/**
	 * The no-results state (which carries the count line), the first time it is asked for on a page.
	 */
	private function noResultsOnce(): string {
		if ( $this->emptyShown ) {
			return '';
		}
		$html = $this->view->noResults();
		if ( '' !== $html ) {
			$this->emptyShown   = true;
			$this->summaryShown = true;
		}
		return $html;
	}

	/**
	 * Forget that the lines were printed (tests render several pages in one process).
	 */
	public function reset(): void {
		$this->summaryShown = false;
		$this->emptyShown   = false;
		$this->contentShown = false;
		$this->routedShown  = false;
		$this->view->reset();
		$this->router->reset();
		add_action( 'woocommerce_no_products_found', 'wc_no_products_found' );
	}
}
