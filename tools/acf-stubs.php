<?php
/**
 * The one Advanced Custom Fields function the plugin calls, for PHPStan only (never loaded at runtime). The
 * plugin checks `function_exists( 'get_field_object' )` before calling it (D-119).
 *
 * @package CreativeBranding\Search
 */

// phpcs:ignoreFile

/**
 * A field's settings and value.
 *
 * @param string $selector     Field name or key.
 * @param mixed  $post_id      Post id.
 * @param bool   $format_value Whether to format the value.
 * @param bool   $load_value   Whether to load the value.
 * @param bool   $escape_html  Whether to escape the value.
 * @return array<string, mixed>|false
 */
function get_field_object( $selector, $post_id = false, $format_value = true, $load_value = true, $escape_html = false ) {
	return false;
}
