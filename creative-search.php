<?php
/**
 * Plugin Name:       Creative Search
 * Plugin URI:        https://creativebranding.ca
 * Description:       Search for WooCommerce: live suggestions as the shopper types, typo tolerance, SKU and attribute matching, an index of its own, search analytics and an accessible search bar.
 * Version:           1.3.0
 * Requires at least: 7.0
 * Requires PHP:      8.5
 * Requires Plugins:  woocommerce
 * Author:            Creative Branding
 * Author URI:        https://zachatkinson.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       creative-search
 * Domain Path:       /languages
 * WC requires at least: 10.0
 * WC tested up to:   11.2
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CSEARCH_VERSION', '1.3.0' );
define( 'CSEARCH_FILE', __FILE__ );
define( 'CSEARCH_PATH', plugin_dir_path( __FILE__ ) );
define( 'CSEARCH_URL', plugin_dir_url( __FILE__ ) );
define( 'CSEARCH_MIN_WC', '10.0' );

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			wp_admin_notice(
				esc_html__( 'Creative Search: run "composer install" before activating the plugin.', 'creative-search' ),
				array( 'type' => 'error' )
			);
		}
	);
	return;
}
require_once __DIR__ . '/vendor/autoload.php';

register_activation_hook( __FILE__, array( \CreativeBranding\Search\Install\Activator::class, 'activate' ) );

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( \CreativeBranding\Search\Plugin::class, 'boot' ), 5 );
