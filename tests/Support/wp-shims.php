<?php
/**
 * Minimal WordPress function shims for the unit suite (no WordPress loaded).
 *
 * Only what the classes under test reach for. src/Domain must need none of these: a shim added for a Domain
 * class is a WordPress import in the domain layer, which the spec forbids. Anything needing real WordPress
 * belongs in tests/Integration/.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

// phpcs:ignoreFile

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! function_exists( '_x' ) ) {
	function _x( string $text, string $context, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! function_exists( '_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		return 1 === $number ? $single : $plural;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		$str = strip_tags( $str );
		$str = (string) preg_replace( '/[\r\n\t ]+/', ' ', $str );
		return trim( $str );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}
if ( ! function_exists( 'get_option' ) ) {
	/**
	 * In-memory options for unit tests; reset with csearch_test_reset_options().
	 */
	function get_option( string $option, $default = false ) {
		return $GLOBALS['csearch_test_options'][ $option ] ?? $default;
	}
	function update_option( string $option, $value, $autoload = null ): bool {
		$GLOBALS['csearch_test_options'][ $option ] = $value;
		return true;
	}
	function add_option( string $option, $value = '', string $deprecated = '', $autoload = null ): bool {
		if ( isset( $GLOBALS['csearch_test_options'][ $option ] ) ) {
			return false;
		}
		$GLOBALS['csearch_test_options'][ $option ] = $value;
		return true;
	}
	function delete_option( string $option ): bool {
		unset( $GLOBALS['csearch_test_options'][ $option ] );
		return true;
	}
	function csearch_test_reset_options(): void {
		$GLOBALS['csearch_test_options'] = array();
	}
}
