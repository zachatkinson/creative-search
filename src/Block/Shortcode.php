<?php
/**
 * The `[creative_search]` shortcode.
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
 * `[creative_search]` renders the same form as the block, for themes without a block header. Attributes
 * use the settings' keys: `[creative_search limit="8" show_prices="no" label_visible="no"]`.
 */
final class Shortcode implements Hookable {

	/**
	 * The shortcode tag.
	 */
	public const TAG = 'creative_search';

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
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes (an empty string when there are none).
	 */
	public function render( $atts ): string {
		return $this->form->render( FormOptions::fromSettingKeys( is_array( $atts ) ? $atts : array() ) );
	}
}
