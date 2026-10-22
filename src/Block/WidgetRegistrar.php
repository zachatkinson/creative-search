<?php
/**
 * Registers the classic widget.
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
 * Registers `Widget` on `widgets_init`. A `WP_Widget` translates its title in its constructor, so it
 * cannot be built with the container on `plugins_loaded` without loading the text domain before `init`.
 */
final class WidgetRegistrar implements Hookable {

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		add_action( 'widgets_init', array( $this, 'registerWidget' ) );
	}

	/**
	 * `widgets_init` callback.
	 */
	public function registerWidget(): void {
		register_widget( Widget::class );
	}
}
