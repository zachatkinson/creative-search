<?php
/**
 * Browser-test fixture for Kadence's own sticky header (1.4, D-125 to D-127): Kadence active, its header's main
 * row sticky on desktop and phone, and the search form in the header's HTML element on both (as the Skatehouse
 * header has it). The theme's settings are kept aside first and put back by `kadence-sticky.php restore`.
 * Run with `wp eval-file` after shop.php.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

$csearch_backup = 'csearch_e2e_kadence_mods';

if ( in_array( 'restore', $args ?? array(), true ) ) {
	$csearch_saved = get_option( $csearch_backup, null );
	if ( null !== $csearch_saved ) {
		if ( is_array( $csearch_saved ) && array() !== $csearch_saved ) {
			update_option( 'theme_mods_kadence', $csearch_saved );
		} else {
			delete_option( 'theme_mods_kadence' );
		}
		delete_option( $csearch_backup );
	}
	echo "restored\n";
	return;
}

if ( null === get_option( $csearch_backup, null ) ) {
	update_option( $csearch_backup, get_option( 'theme_mods_kadence', array() ), false );
}

switch_theme( 'kadence' );
set_theme_mod( 'header_sticky', 'main' );
set_theme_mod( 'mobile_header_sticky', 'main' );
set_theme_mod( 'header_html_content', '[creative_search]' );
set_theme_mod( 'mobile_html_content', '[creative_search]' );
set_theme_mod(
	'header_desktop_items',
	array(
		'top'    => array(
			'top_left'         => array(),
			'top_left_center'  => array(),
			'top_center'       => array(),
			'top_right_center' => array(),
			'top_right'        => array(),
		),
		'main'   => array(
			'main_left'         => array( 'logo' ),
			'main_left_center'  => array(),
			'main_center'       => array( 'html' ),
			'main_right_center' => array(),
			'main_right'        => array( 'navigation' ),
		),
		'bottom' => array(
			'bottom_left'         => array(),
			'bottom_left_center'  => array(),
			'bottom_center'       => array(),
			'bottom_right_center' => array(),
			'bottom_right'        => array(),
		),
	)
);
set_theme_mod(
	'header_mobile_items',
	array(
		'popup'  => array( 'popup_content' => array( 'mobile-navigation' ) ),
		'top'    => array(
			'top_left'   => array(),
			'top_center' => array(),
			'top_right'  => array(),
		),
		'main'   => array(
			'main_left'   => array( 'mobile-logo' ),
			'main_center' => array( 'mobile-html' ),
			'main_right'  => array( 'popup-toggle' ),
		),
		'bottom' => array(
			'bottom_left'   => array(),
			'bottom_center' => array(),
			'bottom_right'  => array(),
		),
	)
);

$csearch_body = '';
for ( $csearch_i = 1; $csearch_i <= 40; $csearch_i++ ) {
	$csearch_body .= '<!-- wp:paragraph --><p>Paragraph ' . $csearch_i . ' of the page under Kadence\'s header.</p><!-- /wp:paragraph -->';
}
$csearch_page = array(
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_name'    => 'kadence-sticky',
	'post_title'   => 'Kadence sticky header',
	'post_content' => $csearch_body,
);
$csearch_existing = get_page_by_path( 'kadence-sticky' );
if ( $csearch_existing instanceof WP_Post ) {
	$csearch_page['ID'] = $csearch_existing->ID;
	wp_update_post( wp_slash( $csearch_page ) );
} else {
	wp_insert_post( wp_slash( $csearch_page ) );
}
echo "kadence sticky\n";
