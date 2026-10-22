<?php
/**
 * Browser-test fixture: hero products from the catalogue, indexed, with fallback products set.
 * Run with `wp eval-file`; does nothing when the products are already there.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Plugin;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Tests\Support\WooCommerceCatalogue;

// A fresh WooCommerce shows a "coming soon" page to visitors until the store is launched.
update_option( 'woocommerce_coming_soon', 'no' );

// WooCommerce's placeholder image ships without its intermediate sizes in a fresh environment; WebKit asks
// for the 768 px one from the srcset and logs a 404.
$csearch_placeholder = (int) get_option( 'woocommerce_placeholder_image', 0 );
$csearch_file        = $csearch_placeholder > 0 ? get_attached_file( $csearch_placeholder ) : false;
if ( is_string( $csearch_file ) && ! file_exists( $csearch_file ) ) {
	$csearch_source = WC()->plugin_path() . '/assets/images/placeholder.webp';
	if ( file_exists( $csearch_source ) ) {
		copy( $csearch_source, $csearch_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- a test fixture restoring WooCommerce's own file.
	}
}
if ( is_string( $csearch_file ) && file_exists( $csearch_file ) ) {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $csearch_placeholder, wp_generate_attachment_metadata( $csearch_placeholder, $csearch_file ) );
}

// A page with the search block, for the block's browser tests.
if ( ! get_page_by_path( 'search-block' ) ) {
	wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => 'search-block',
			'post_title'   => 'Search block',
			'post_content' => '<!-- wp:creative-search/search /-->',
		)
	);
}

if ( wc_get_product_id_by_sku( 'KAZ-DEA-00001' ) ) {
	echo "present\n";
	return;
}
$loader = new WooCommerceCatalogue();
$ids    = array();
foreach ( array( 0, 1, 2, 4, 6, 7, 15, 16, 21, 22 ) as $i ) {
	$ids[ $i ] = $loader->product( WooCommerceCatalogue::spec( $i ) );
}
Settings::set( 'fallback_products', array( $ids[15], $ids[6] ) );
Plugin::container()->get( Reindex::class )->runInline();
echo 'loaded ' . count( $ids ) . "\n";
