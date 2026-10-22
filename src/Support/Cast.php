<?php
/**
 * Typing values that arrive as mixed.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hook arguments, stored options and filter results arrive untyped. These turn them into the type the code
 * needs, with a safe value for anything else, so a plugin passing the wrong thing to a hook cannot break a
 * request.
 */
final class Cast {

	/**
	 * An integer; anything not numeric is the fallback.
	 *
	 * @param mixed $value    Value.
	 * @param int   $fallback Fallback.
	 */
	public static function int( mixed $value, int $fallback = 0 ): int {
		return is_numeric( $value ) ? (int) $value : $fallback;
	}

	/**
	 * A string; arrays, objects and null are the fallback.
	 *
	 * @param mixed  $value    Value.
	 * @param string $fallback Fallback.
	 */
	public static function str( mixed $value, string $fallback = '' ): string {
		return is_scalar( $value ) ? (string) $value : $fallback;
	}

	/**
	 * A list of integers from an array (non-numeric members dropped); anything else is empty.
	 *
	 * @param mixed $value Value.
	 * @return int[]
	 */
	public static function ints( mixed $value ): array {
		$out = array();
		foreach ( is_array( $value ) ? $value : array() as $item ) {
			if ( is_numeric( $item ) ) {
				$out[] = (int) $item;
			}
		}
		return $out;
	}

	/**
	 * A list of strings from an array (non-scalar members dropped); anything else is empty.
	 *
	 * @param mixed $value Value.
	 * @return string[]
	 */
	public static function strings( mixed $value ): array {
		$out = array();
		foreach ( is_array( $value ) ? $value : array() as $item ) {
			if ( is_scalar( $item ) ) {
				$out[] = (string) $item;
			}
		}
		return $out;
	}

	/**
	 * Field texts as the analyzer takes them: integer keys, a string or a list of strings each.
	 *
	 * @param mixed $value Value.
	 * @return array<int, string|string[]>
	 */
	public static function fields( mixed $value ): array {
		$out = array();
		foreach ( is_array( $value ) ? $value : array() as $field => $text ) {
			if ( ! is_numeric( $field ) ) {
				continue;
			}
			$out[ (int) $field ] = is_array( $text ) ? self::strings( $text ) : self::str( $text );
		}
		return $out;
	}
}
