<?php
/**
 * The search block.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Block;

use CreativeBranding\Search\Hookable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers `creative-search/search` from the built block.json and renders it through `SearchForm`.
 * block.json declares the view script module, the stylesheet and the editor script; WordPress enqueues
 * them when the block renders, so nothing is enqueued here.
 */
final class SearchBlock implements Hookable {

	/**
	 * Constructor.
	 *
	 * @param SearchForm $form The markup.
	 */
	public function __construct( private readonly SearchForm $form ) {
	}

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'registerBlock' ) );
	}

	/**
	 * Register the block type with the owner's settings as the attribute defaults.
	 */
	public function registerBlock(): void {
		$dir = CSEARCH_PATH . 'build/block';
		if ( ! is_readable( $dir . '/block.json' ) ) {
			return;
		}
		$metadata = wp_json_file_decode( $dir . '/block.json', array( 'associative' => true ) );
		$schema   = is_array( $metadata ) && isset( $metadata['attributes'] ) && is_array( $metadata['attributes'] ) ? $metadata['attributes'] : array();
		/** @var array<string, array<string, mixed>> $schema */
		register_block_type_from_metadata(
			$dir,
			array(
				'attributes'      => FormOptions::attributeSchema( $schema ),
				'render_callback' => array( $this, 'render' ),
			)
		);
	}

	/**
	 * Render callback.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render( array $attributes ): string {
		return $this->form->render( FormOptions::fromAttributes( $attributes ), get_block_wrapper_attributes() );
	}
}
