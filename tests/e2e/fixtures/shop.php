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
		wp_mkdir_p( dirname( $csearch_file ) );
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

// Button mode (D-099): the same block as an icon that opens a dialog.
if ( ! get_page_by_path( 'search-button' ) ) {
	wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => 'search-button',
			'post_title'   => 'Search button',
			'post_content' => '<!-- wp:paragraph --><p>Above the button.</p><!-- /wp:paragraph --><!-- wp:creative-search/search {"mode":"button"} /-->',
		)
	);
}

// Pages and posts in the search (D-093, Skatehouse handoff item 7), and the no-results links (D-096).
$csearch_content_new = false;
foreach (
	array(
		array( 'page', 'size-guide', 'Size guide', 'Deck widths from 7.5″ to 9″, and which width suits which shoe size.' ),
		array( 'page', 'shipping-and-returns', 'Shipping and returns', 'Orders ship the next business day. Returns within 30 days.' ),
		array( 'post', 'choosing-a-deck', 'Choosing your first deck', 'Width matters more than the brand when you choose a deck.' ),
	) as $csearch_content
) {
	if ( get_page_by_path( $csearch_content[1], OBJECT, $csearch_content[0] ) ) {
		continue;
	}
	wp_insert_post(
		array(
			'post_type'    => $csearch_content[0],
			'post_status'  => 'publish',
			'post_name'    => $csearch_content[1],
			'post_title'   => $csearch_content[2],
			'post_content' => '<!-- wp:paragraph --><p>' . $csearch_content[3] . '</p><!-- /wp:paragraph -->',
		)
	);
	$csearch_content_new = true;
}
if ( $csearch_content_new ) {
	Settings::set( 'index_pages', 'yes' );
	Settings::set( 'index_posts', 'yes' );
	$csearch_help = get_page_by_path( 'shipping-and-returns' );
	Settings::set( 'help_page', $csearch_help instanceof WP_Post ? $csearch_help->ID : 0 );
}

// The French run (tests/e2e/french.spec.ts): products answer in French too (the must-use plugin in
// tests/e2e/fixtures/french.php reads this option on every request; this process adds its filter itself).
$csearch_french_new = 'yes' !== get_option( 'csearch_e2e_french' );
update_option( 'csearch_e2e_french', 'yes', false );
add_filter( 'csearch_languages', array( WooCommerceCatalogue::class, 'bilingual' ) );
Plugin::container()->get( \CreativeBranding\Search\Index\Languages::class )->flush();
Plugin::container()->get( \CreativeBranding\Search\Index\AnalyzerFactory::class )->flush();

// A size in a query becomes the shop's Width filter (D-101).
if ( array( 'pa_width' ) !== Settings::get( 'filter_attributes' ) ) {
	Settings::set( 'filter_attributes', array( 'pa_width' ) );
}

if ( wc_get_product_id_by_sku( 'KAZ-DEA-00001' ) ) {
	Plugin::container()->get( \CreativeBranding\Search\Index\AttributeTerms::class )->rebuild();
	if ( $csearch_french_new || $csearch_content_new ) {
		Plugin::container()->get( Reindex::class )->runInline();
		echo "present, reindexed\n";
		return;
	}
	echo "present\n";
	return;
}
$loader = new WooCommerceCatalogue();
$ids    = array();
foreach ( array( 0, 1, 2, 4, 6, 7, 15, 16, 21, 22 ) as $i ) {
	$ids[ $i ] = $loader->product( WooCommerceCatalogue::spec( $i ) );
}
Settings::set( 'fallback_products', array( $ids[15], $ids[6] ) );
Plugin::container()->get( \CreativeBranding\Search\Index\AttributeTerms::class )->rebuild();
Plugin::container()->get( Reindex::class )->runInline();
echo 'loaded ' . count( $ids ) . "\n";
