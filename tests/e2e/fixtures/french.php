<?php
/**
 * Browser-test fixture, loaded as a must-use plugin: a bilingual shop the way skatehouse-core makes one, with no
 * multilingual plugin. Products answer in French from their French meta (`csearch_languages`), and a visitor with
 * the `csearch_lang=fr` cookie gets the site in Canadian French: the locale, and WooCommerce's prices as
 * `84,99 $`. Off unless the shop fixture turned it on, so the integration tests never see it.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

use CreativeBranding\Search\Tests\Support\WooCommerceCatalogue;

if ( 'yes' !== get_option( 'csearch_e2e_french' ) ) {
	return;
}

add_filter(
	'csearch_languages',
	static fn( $languages ) => is_array( $languages ) && class_exists( WooCommerceCatalogue::class ) ? WooCommerceCatalogue::bilingual( $languages ) : $languages
);

if ( isset( $_COOKIE['csearch_lang'] ) && 'fr' === $_COOKIE['csearch_lang'] ) { // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- a test fixture.
	$csearch_fr = static fn(): string => 'fr_CA';
	add_filter( 'locale', $csearch_fr );
	add_filter( 'determine_locale', $csearch_fr );
	add_filter( 'wc_get_price_decimal_separator', static fn(): string => ',' );
	add_filter( 'wc_get_price_thousand_separator', static fn(): string => ' ' );
	add_filter( 'woocommerce_price_format', static fn(): string => '%2$s&nbsp;%1$s' );
}
