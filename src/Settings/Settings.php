<?php
/**
 * Typed access to the plugin's one settings option.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Settings;

use CreativeBranding\Search\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads, writes and sanitises `csearch_settings`. Every key is documented in docs/SETTINGS.md; every
 * default is the one the spec names, or docs/DECISIONS.md D-018 says why.
 */
final class Settings {

	/**
	 * The longest wording kept for a no-results link (D-096).
	 */
	public const LINK_TEXT_MAX = 120;

	public const RETENTION_MIN = 7;
	public const RETENTION_MAX = 365;
	public const LIMIT_MIN     = 1;
	public const LIMIT_MAX     = 10;
	public const TERMS_MAX     = 3;
	public const MIN_CHARS_MIN = 1;
	public const MIN_CHARS_MAX = 5;
	public const BOOST_MIN     = -10;
	public const BOOST_MAX     = 10;

	public const LIMIT_DEFAULT     = 6;
	public const TERMS_DEFAULT     = 3;
	public const MIN_CHARS_DEFAULT = 2;
	public const RETENTION_DEFAULT = 90;

	public const PANEL_AUTO  = 'auto';
	public const PANEL_BELOW = 'below';
	public const PANEL_ABOVE = 'above';

	/**
	 * The field names the index knows, in the order of their field ids (name 1 … attribute 8).
	 *
	 * @var string[]
	 */
	public const FIELDS = array( 'name', 'sku', 'short', 'long', 'category', 'tag', 'brand', 'attribute' );

	/**
	 * Request-level cache of the option.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Default values for every setting.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			// What to index.
			'index_long_description'       => 'yes',
			'index_tags'                   => 'yes',
			'index_variation_skus'         => 'yes',
			'index_posts'                  => 'no',
			'index_pages'                  => 'no',
			'fields'                       => self::defaultFieldWeights(),
			// Languages: empty means the site's language only; the csearch_languages filter adds more.
			'languages'                    => array(),
			'fallback_to_default_language' => 'yes',
			// Matching.
			'hidden_by_sku'                => 'no',
			'boost_rules'                  => array(),
			'fallback_products'            => array(),
			// The no-results page's two links (D-096): a page the owner picks, and its wording ('' reads the default).
			'help_page'                    => 0,
			'help_text'                    => '',
			'guide_page'                   => 0,
			'guide_text'                   => '',
			// The results page (D-101, D-102): attributes whose values a query turns into the shop's filter, and
			// whether a query that names a category opens it.
			'filter_attributes'            => array(),
			'category_redirect'            => 'yes',
			// The suggestion panel's defaults (a block instance can override each).
			'suggest_limit'                => self::LIMIT_DEFAULT,
			'suggest_terms'                => self::TERMS_DEFAULT,
			'min_chars'                    => self::MIN_CHARS_DEFAULT,
			'show_prices'                  => 'yes',
			'show_stock'                   => 'yes',
			'show_terms'                   => 'yes',
			'label_visible'                => 'yes',
			'placeholder'                  => '',
			'panel_position'               => self::PANEL_AUTO,
			'recent_searches'              => 'no',
			'theme_form_takeover'          => 'no',
			// Analytics.
			'retention_days'               => self::RETENTION_DEFAULT,
			'stats'                        => array(),
		);
	}

	/**
	 * The spec's field weights: name 10, SKU 10, brand 6, category 5, attribute 5, tag 3, short 2, long 1.
	 *
	 * @return array<string, int>
	 */
	public static function defaultFieldWeights(): array {
		return array(
			'name'      => 10,
			'sku'       => 10,
			'brand'     => 6,
			'category'  => 5,
			'attribute' => 5,
			'tag'       => 3,
			'short'     => 2,
			'long'      => 1,
		);
	}

	/**
	 * The merged option.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( Options::SETTINGS, array() );
			self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( string $key ) {
		return self::all()[ $key ] ?? ( self::defaults()[ $key ] ?? null );
	}

	/**
	 * Write one setting, sanitised.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Value.
	 */
	public static function set( string $key, $value ): void {
		self::update( array( $key => $value ) );
	}

	/**
	 * Write some settings: merged into the current ones, the whole sanitised (so a screen saving one tab never
	 * resets another, and no value reaches the option unchecked).
	 *
	 * @param array<string, mixed> $changes Setting keys to new values.
	 * @return array<string, mixed> The settings as stored.
	 */
	public static function update( array $changes ): array {
		$all = self::sanitize( array_merge( self::all(), $changes ) );
		// Autoloaded: the block and the routes read it on every request. Short scalars and small arrays.
		update_option( Options::SETTINGS, $all, true );
		self::$cache = $all;
		return $all;
	}

	/**
	 * Drop the request cache (after a save through the settings screen, and in tests).
	 */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Whether a yes/no setting is on.
	 *
	 * @param string $key Setting key.
	 */
	public static function isOn( string $key ): bool {
		return 'yes' === self::get( $key );
	}

	/**
	 * Retention of the search log in days, within the spec's 7 to 365.
	 */
	public static function retentionDays(): int {
		return self::clamp( self::int( 'retention_days' ), self::RETENTION_MIN, self::RETENTION_MAX );
	}

	/**
	 * Field weights, the setting over the defaults, each a non-negative integer.
	 *
	 * @return array<string, int>
	 */
	public static function fieldWeights(): array {
		$stored = self::get( 'fields' );
		return self::sanitizeFieldWeights( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Sanitise a full settings array (the settings screen, WP-CLI and tests all go through here).
	 *
	 * Unknown keys are dropped; missing keys take their default; every value is coerced to its type and range.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$out = self::defaults();

		foreach ( array( 'index_long_description', 'index_tags', 'index_variation_skus', 'index_posts', 'index_pages', 'fallback_to_default_language', 'hidden_by_sku', 'show_prices', 'show_stock', 'show_terms', 'label_visible', 'recent_searches', 'theme_form_takeover', 'category_redirect' ) as $flag ) {
			if ( array_key_exists( $flag, $input ) ) {
				$out[ $flag ] = self::yesNo( $input[ $flag ] );
			}
		}

		if ( isset( $input['fields'] ) && is_array( $input['fields'] ) ) {
			$out['fields'] = self::sanitizeFieldWeights( $input['fields'] );
		}
		if ( isset( $input['languages'] ) && is_array( $input['languages'] ) ) {
			$out['languages'] = self::sanitizeLanguages( $input['languages'] );
		}
		if ( isset( $input['boost_rules'] ) && is_array( $input['boost_rules'] ) ) {
			$out['boost_rules'] = self::sanitizeBoostRules( $input['boost_rules'] );
		}
		if ( isset( $input['fallback_products'] ) && is_array( $input['fallback_products'] ) ) {
			$out['fallback_products'] = self::sanitizeIds( $input['fallback_products'] );
		}
		if ( isset( $input['filter_attributes'] ) && is_array( $input['filter_attributes'] ) ) {
			$out['filter_attributes'] = self::sanitizeTaxonomies( $input['filter_attributes'] );
		}
		foreach ( array( 'help', 'guide' ) as $link ) {
			if ( isset( $input[ $link . '_page' ] ) ) {
				$out[ $link . '_page' ] = max( 0, self::toInt( $input[ $link . '_page' ], 0 ) );
			}
			if ( isset( $input[ $link . '_text' ] ) ) {
				$out[ $link . '_text' ] = mb_substr( trim( sanitize_text_field( self::toString( $input[ $link . '_text' ] ) ) ), 0, self::LINK_TEXT_MAX, 'UTF-8' );
			}
		}
		if ( isset( $input['suggest_limit'] ) ) {
			$out['suggest_limit'] = self::clamp( self::toInt( $input['suggest_limit'], self::LIMIT_DEFAULT ), self::LIMIT_MIN, self::LIMIT_MAX );
		}
		if ( isset( $input['suggest_terms'] ) ) {
			$out['suggest_terms'] = self::clamp( self::toInt( $input['suggest_terms'], self::TERMS_DEFAULT ), 0, self::TERMS_MAX );
		}
		if ( isset( $input['min_chars'] ) ) {
			$out['min_chars'] = self::clamp( self::toInt( $input['min_chars'], self::MIN_CHARS_DEFAULT ), self::MIN_CHARS_MIN, self::MIN_CHARS_MAX );
		}
		if ( isset( $input['placeholder'] ) ) {
			$out['placeholder'] = trim( sanitize_text_field( self::toString( $input['placeholder'] ) ) );
		}
		if ( isset( $input['panel_position'] ) && in_array( $input['panel_position'], array( self::PANEL_AUTO, self::PANEL_BELOW, self::PANEL_ABOVE ), true ) ) {
			$out['panel_position'] = (string) $input['panel_position'];
		}
		if ( isset( $input['retention_days'] ) ) {
			$out['retention_days'] = self::clamp( self::toInt( $input['retention_days'], self::RETENTION_DEFAULT ), self::RETENTION_MIN, self::RETENTION_MAX );
		}
		if ( isset( $input['stats'] ) && is_array( $input['stats'] ) ) {
			$out['stats'] = self::sanitizeStats( $input['stats'] );
		}

		return $out;
	}

	/**
	 * Field weights: known fields only, integers 0 to 100, defaults for the rest.
	 *
	 * @param array<mixed, mixed> $input Raw weights.
	 * @return array<string, int>
	 */
	public static function sanitizeFieldWeights( array $input ): array {
		$out = self::defaultFieldWeights();
		foreach ( self::FIELDS as $field ) {
			if ( array_key_exists( $field, $input ) && is_numeric( $input[ $field ] ) ) {
				$out[ $field ] = self::clamp( (int) $input[ $field ], 0, 100 );
			}
		}
		return $out;
	}

	/**
	 * Language codes: lower-case letters, digits, hyphen or underscore, 2 to 8 characters, unique.
	 *
	 * @param array<mixed, mixed> $input Raw codes.
	 * @return string[]
	 */
	public static function sanitizeLanguages( array $input ): array {
		$out = array();
		foreach ( $input as $code ) {
			if ( ! is_scalar( $code ) ) {
				continue;
			}
			$code = strtolower( trim( (string) $code ) );
			if ( 1 === preg_match( '/^[a-z][a-z0-9_-]{1,7}$/', $code ) && ! in_array( $code, $out, true ) ) {
				$out[] = $code;
			}
		}
		return $out;
	}

	/**
	 * Boost rules: each a taxonomy, a term id and a boost within -10 to 10.
	 *
	 * @param array<mixed, mixed> $input Raw rules.
	 * @return array<int, array{taxonomy: string, term_id: int, boost: int}>
	 */
	public static function sanitizeBoostRules( array $input ): array {
		$out = array();
		foreach ( $input as $rule ) {
			if ( ! is_array( $rule ) || ! isset( $rule['taxonomy'], $rule['term_id'], $rule['boost'] ) ) {
				continue;
			}
			$taxonomy = sanitize_key( (string) $rule['taxonomy'] );
			$termId   = (int) $rule['term_id'];
			if ( '' === $taxonomy || $termId <= 0 ) {
				continue;
			}
			$out[] = array(
				'taxonomy' => $taxonomy,
				'term_id'  => $termId,
				'boost'    => self::clamp( (int) $rule['boost'], self::BOOST_MIN, self::BOOST_MAX ),
			);
		}
		return $out;
	}

	/**
	 * Attribute taxonomy names (`pa_width`): unique, in the order given; anything else dropped.
	 *
	 * @param array<mixed, mixed> $input Raw names.
	 * @return string[]
	 */
	public static function sanitizeTaxonomies( array $input ): array {
		$out = array();
		foreach ( $input as $name ) {
			$name = is_string( $name ) ? sanitize_key( $name ) : '';
			if ( 1 === preg_match( '/^pa_[a-z0-9_-]{1,29}$/', $name ) && ! in_array( $name, $out, true ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * A list of positive integer ids, unique, in the order given.
	 *
	 * @param array<mixed, mixed> $input Raw ids.
	 * @return int[]
	 */
	public static function sanitizeIds( array $input ): array {
		$out = array();
		foreach ( $input as $id ) {
			if ( ! is_scalar( $id ) || ! is_numeric( $id ) ) {
				continue;
			}
			$id = (int) $id;
			if ( $id > 0 && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Daily roll-ups: `Y-m-d` keys, each an array of non-negative integer counts.
	 *
	 * @param array<mixed, mixed> $input Raw stats.
	 * @return array<string, array<string, int>>
	 */
	public static function sanitizeStats( array $input ): array {
		$out = array();
		foreach ( $input as $day => $counts ) {
			if ( ! is_string( $day ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || ! is_array( $counts ) ) {
				continue;
			}
			$clean = array();
			foreach ( $counts as $name => $count ) {
				if ( is_string( $name ) && 1 === preg_match( '/^[a-z_]{1,32}$/', $name ) && is_numeric( $count ) ) {
					$clean[ $name ] = max( 0, (int) $count );
				}
			}
			$out[ $day ] = $clean;
		}
		return $out;
	}

	/**
	 * Normalise a checkbox-ish value to yes/no.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function yesNo( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		return in_array( $value, array( 'yes', '1', 1, 'on', 'true' ), true ) ? 'yes' : 'no';
	}

	/**
	 * An integer setting, its default when the stored value is not numeric.
	 *
	 * @param string $key Setting key.
	 */
	public static function int( string $key ): int {
		$value = self::get( $key );
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}
		return self::toInt( self::defaults()[ $key ] ?? 0, 0 );
	}

	/**
	 * Coerce a raw value to an integer.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $fallback Used when the value is not numeric.
	 */
	public static function toInt( $value, int $fallback ): int {
		return is_numeric( $value ) ? (int) $value : $fallback;
	}

	/**
	 * Coerce a raw value to a string; arrays and objects become empty.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function toString( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Clamp an integer to a range.
	 *
	 * @param int $value Value.
	 * @param int $min   Lowest allowed.
	 * @param int $max   Highest allowed.
	 */
	public static function clamp( int $value, int $min, int $max ): int {
		return max( $min, min( $max, $value ) );
	}
}
