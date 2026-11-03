<?php
/**
 * The document fields the index knows.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Field ids as stored in `csearch_postings.field` (spec: Data model), their names, and the spec's default
 * weights. The settings store and the `csearch_fields` filter speak in names; the index speaks in ids.
 */
final class Fields {

	public const NAME      = 1;
	public const SKU       = 2;
	public const SHORT     = 3;
	public const LONG      = 4;
	public const CATEGORY  = 5;
	public const TAG       = 6;
	public const BRAND     = 7;
	public const ATTRIBUTE = 8;
	public const CUSTOM    = 9;

	/**
	 * The description fields: a product found by a searched word only here "only mentions" it (D-142).
	 *
	 * @var int[]
	 */
	public const DESCRIPTIONS = array( self::SHORT, self::LONG );

	/**
	 * Field names by id, in id order.
	 *
	 * @var array<int, string>
	 */
	public const NAMES = array(
		self::NAME      => 'name',
		self::SKU       => 'sku',
		self::SHORT     => 'short',
		self::LONG      => 'long',
		self::CATEGORY  => 'category',
		self::TAG       => 'tag',
		self::BRAND     => 'brand',
		self::ATTRIBUTE => 'attribute',
		self::CUSTOM    => 'custom',
	);

	/**
	 * The spec's weights: name 10, SKU 10, brand 6, category 5, attribute 5, tag 3, short 2, long 1; and the owner's
	 * custom fields 4 (1.3, D-119), between an attribute and a tag.
	 *
	 * @var array<string, int>
	 */
	public const DEFAULT_WEIGHTS = array(
		'name'      => 10,
		'sku'       => 10,
		'brand'     => 6,
		'category'  => 5,
		'attribute' => 5,
		'tag'       => 3,
		'short'     => 2,
		'long'      => 1,
		'custom'    => 4,
	);

	/**
	 * A field's id from its name.
	 *
	 * @param string $name Field name.
	 * @return int The id, or 0 for an unknown name.
	 */
	public static function id( string $name ): int {
		$id = array_search( $name, self::NAMES, true );
		return false === $id ? 0 : $id;
	}

	/**
	 * A field's name from its id.
	 *
	 * @param int $id Field id.
	 * @return string The name, or an empty string for an unknown id.
	 */
	public static function name( int $id ): string {
		return self::NAMES[ $id ] ?? '';
	}

	/**
	 * Weights keyed by field id, from weights keyed by name; unknown names are ignored, missing fields take the default.
	 *
	 * @param array<string, int> $byName Weights by field name.
	 * @return array<int, int>
	 */
	public static function weightsById( array $byName ): array {
		$out = array();
		foreach ( self::NAMES as $id => $name ) {
			$out[ $id ] = $byName[ $name ] ?? self::DEFAULT_WEIGHTS[ $name ];
		}
		return $out;
	}
}
