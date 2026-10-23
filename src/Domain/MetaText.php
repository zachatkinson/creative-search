<?php
/**
 * The text of a custom field.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Turns stored meta values into the texts the index reads (D-119): a string or a number as it is, a list (an ACF
 * checkbox, a serialised array) flattened to its values three levels deep, `true`, `false`, `null` and objects
 * dropped; then the texts capped together, so a product's custom fields never outweigh its description.
 */
final class MetaText {

	public const DEPTH = 3;

	/**
	 * The texts of one value, trimmed, empty ones dropped, in order.
	 *
	 * @param mixed $value A meta value, unserialised.
	 * @param int   $depth Levels of nesting still read.
	 * @return string[]
	 */
	public static function values( mixed $value, int $depth = self::DEPTH ): array {
		if ( is_string( $value ) || is_int( $value ) || is_float( $value ) ) {
			$text = trim( (string) $value );
			return '' === $text ? array() : array( $text );
		}
		if ( ! is_array( $value ) || $depth < 1 ) {
			return array();
		}
		$out = array();
		foreach ( $value as $item ) {
			array_push( $out, ...self::values( $item, $depth - 1 ) );
		}
		return $out;
	}

	/**
	 * Texts kept in order until they reach a number of characters together; the one that crosses it is cut.
	 *
	 * @param string[] $texts Texts.
	 * @param int      $max   Characters in all.
	 * @return string[]
	 */
	public static function cap( array $texts, int $max ): array {
		$out  = array();
		$left = $max;
		foreach ( $texts as $text ) {
			if ( $left <= 0 ) {
				return $out;
			}
			$kept  = mb_substr( $text, 0, $left, 'UTF-8' );
			$out[] = $kept;
			$left -= mb_strlen( $kept, 'UTF-8' );
		}
		return $out;
	}
}
