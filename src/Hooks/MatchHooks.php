<?php
/**
 * The results page's cards: the variation a query named, and what matched.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Hooks;

use CreativeBranding\Search\Hookable;
use CreativeBranding\Search\Results\MatchDecorations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks the card decorations of a ranked results page (D-104, D-105): WooCommerce's product image and link, the
 * classic loop after the title, and the block theme's post title.
 */
final class MatchHooks implements Hookable {

	/**
	 * Constructor.
	 *
	 * @param MatchDecorations $cards The decorations.
	 */
	public function __construct( private MatchDecorations $cards ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_filter( 'woocommerce_product_get_image_id', array( $this->cards, 'imageId' ), 10, 2 );
		add_filter( 'post_type_link', array( $this->cards, 'link' ), 10, 2 );
		add_action( 'woocommerce_after_shop_loop_item_title', array( $this->cards, 'printLine' ), 4 );
		add_filter( 'render_block_core/post-title', array( $this->cards, 'blockLine' ), 10, 3 );
	}
}
