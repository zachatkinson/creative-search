<?php
/**
 * The block's front-end assets, for the paths that render the form outside a block.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Block;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the stylesheet and the view script module that block.json registered. A block render enqueues
 * them on its own (`WP_Block::render()`); the shortcode, the widget and the theme-form takeover do not, so
 * `SearchForm` calls this every time, and WordPress ignores the repeats.
 */
final class BlockAssets {

	/**
	 * The block name, as block.json declares it.
	 */
	public const BLOCK = 'creative-search/search';

	/**
	 * Enqueue the stylesheet and the script module (printed late when the form renders in the body).
	 */
	public function enqueue(): void {
		wp_enqueue_style( generate_block_asset_handle( self::BLOCK, 'style' ) );
		wp_enqueue_script_module( generate_block_asset_handle( self::BLOCK, 'viewScriptModule' ) );
	}
}
