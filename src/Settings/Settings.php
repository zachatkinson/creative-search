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

	/**
	 * Rows the whole suggestion panel shows at most, "See all" aside (D-136): on a desktop, and on a phone.
	 */
	public const PANEL_MAX_MIN           = 4;
	public const PANEL_MAX_MAX           = 12;
	public const PANEL_MAX_DEFAULT       = 8;
	public const PANEL_MAX_PHONE_MIN     = 3;
	public const PANEL_MAX_PHONE_MAX     = 8;
	public const PANEL_MAX_PHONE_DEFAULT = 5;

	/**
	 * The owner's rules for single queries (D-108, D-110): rules of each kind at most, products pinned to one
	 * query at most, and the longest query a rule names (a query's own cap).
	 */
	public const QUERY_RULES_MAX = 200;
	public const PINS_MAX        = 5;
	public const RULE_QUERY_MAX  = 200;
	public const RULE_URL_MAX    = 2048;

	public const LIMIT_DEFAULT     = 6;
	public const TERMS_DEFAULT     = 3;
	public const MIN_CHARS_DEFAULT = 2;
	public const RETENTION_DEFAULT = 90;

	public const PANEL_AUTO  = 'auto';
	public const PANEL_BELOW = 'below';
	public const PANEL_ABOVE = 'above';

	/**
	 * The field names the index knows, in the order of their field ids (name 1 … attribute 8, custom fields 9).
	 *
	 * @var string[]
	 */
	public const FIELDS = array( 'name', 'sku', 'short', 'long', 'category', 'tag', 'brand', 'attribute', 'custom' );

	/**
	 * Custom fields indexed at most, and the characters kept of all of a product's custom fields together (D-119).
	 */
	public const CUSTOM_FIELDS_MAX  = 20;
	public const CUSTOM_FIELD_CHARS = 2000;

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
			// Product meta keys (ACF field names among them) searched as the "custom" field (D-119).
			'custom_fields'                => array(),
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
			// The owner's rules for single queries (D-108, D-110): a query sent to a page, products pinned to a query.
			'query_redirects'              => array(),
			'pinned_products'              => array(),
			// The suggestion panel's defaults (a block instance can override each).
			'suggest_limit'                => self::LIMIT_DEFAULT,
			'suggest_terms'                => self::TERMS_DEFAULT,
			'min_chars'                    => self::MIN_CHARS_DEFAULT,
			// The whole panel's rows at most, on a desktop and on a phone (D-136).
			'panel_max'                    => self::PANEL_MAX_DEFAULT,
			'panel_max_phone'              => self::PANEL_MAX_PHONE_DEFAULT,
			'show_prices'                  => 'yes',
			'show_stock'                   => 'yes',
			'show_terms'                   => 'yes',
			'label_visible'                => 'yes',
			'placeholder'                  => '',
			'panel_position'               => self::PANEL_AUTO,
			'recent_searches'              => 'no',
			'theme_form_takeover'          => 'no',
			// The panel's extras (1.3): suggested searches (D-112), categories to search within (D-113), add to cart
			// (D-115, off: a shop chooses it), and the browser events an analytics tag can listen to (D-116).
			'suggest_queries'              => 'yes',
			'suggest_scopes'               => 'yes',
			'suggest_cart'                 => 'no',
			'analytics_events'             => 'yes',
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
			'custom'    => 4,
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

		foreach ( array( 'index_long_description', 'index_tags', 'index_variation_skus', 'index_posts', 'index_pages', 'fallback_to_default_language', 'hidden_by_sku', 'show_prices', 'show_stock', 'show_terms', 'label_visible', 'recent_searches', 'theme_form_takeover', 'category_redirect', 'suggest_queries', 'suggest_scopes', 'suggest_cart', 'analytics_events' ) as $flag ) {
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
		if ( isset( $input['custom_fields'] ) && is_array( $input['custom_fields'] ) ) {
			$out['custom_fields'] = self::sanitizeMetaKeys( $input['custom_fields'] );
		}
		if ( isset( $input['filter_attributes'] ) && is_array( $input['filter_attributes'] ) ) {
			$out['filter_attributes'] = self::sanitizeTaxonomies( $input['filter_attributes'] );
		}
		if ( isset( $input['query_redirects'] ) && is_array( $input['query_redirects'] ) ) {
			$out['query_redirects'] = self::sanitizeRedirects( $input['query_redirects'] );
		}
		if ( isset( $input['pinned_products'] ) && is_array( $input['pinned_products'] ) ) {
			$out['pinned_products'] = self::sanitizePins( $input['pinned_products'] );
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
		if ( isset( $input['panel_max'] ) ) {
			$out['panel_max'] = self::clamp( self::toInt( $input['panel_max'], self::PANEL_MAX_DEFAULT ), self::PANEL_MAX_MIN, self::PANEL_MAX_MAX );
		}
		if ( isset( $input['panel_max_phone'] ) ) {
			$out['panel_max_phone'] = self::clamp( self::toInt( $input['panel_max_phone'], self::PANEL_MAX_PHONE_DEFAULT ), self::PANEL_MAX_PHONE_MIN, self::PANEL_MAX_PHONE_MAX );
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
	 * Query redirects (D-108): each a query, a language (empty for every one) and an address, 200 at most. Whether
	 * the address is one of the shop's own is checked where it is used ({@see \CreativeBranding\Search\Search\OwnerRules}).
	 *
	 * @param array<mixed, mixed> $input Raw rules.
	 * @return array<int, array{query: string, lang: string, url: string}>
	 */
	public static function sanitizeRedirects( array $input ): array {
		$out = array();
		foreach ( $input as $rule ) {
			$base = is_array( $rule ) ? self::ruleBase( $rule ) : null;
			$url  = is_array( $rule ) ? mb_substr( esc_url_raw( trim( self::toString( $rule['url'] ?? '' ) ) ), 0, self::RULE_URL_MAX, 'UTF-8' ) : '';
			if ( null === $base || '' === $url || count( $out ) === self::QUERY_RULES_MAX ) {
				continue;
			}
			$out[] = $base + array( 'url' => $url );
		}
		return $out;
	}

	/**
	 * Pinned products (D-110): each a query, a language (empty for every one) and one to five product ids in the
	 * owner's order, 200 at most.
	 *
	 * @param array<mixed, mixed> $input Raw rules.
	 * @return array<int, array{query: string, lang: string, ids: int[]}>
	 */
	public static function sanitizePins( array $input ): array {
		$out = array();
		foreach ( $input as $rule ) {
			$base = is_array( $rule ) ? self::ruleBase( $rule ) : null;
			$ids  = is_array( $rule ) && isset( $rule['ids'] ) && is_array( $rule['ids'] ) ? array_slice( self::sanitizeIds( $rule['ids'] ), 0, self::PINS_MAX ) : array();
			if ( null === $base || array() === $ids || count( $out ) === self::QUERY_RULES_MAX ) {
				continue;
			}
			$out[] = $base + array( 'ids' => $ids );
		}
		return $out;
	}

	/**
	 * A rule's query (plain text, 200 characters) and language (a code, or empty for every one); null without a
	 * query.
	 *
	 * @param array<mixed, mixed> $rule Raw rule.
	 * @return array{query: string, lang: string}|null
	 */
	private static function ruleBase( array $rule ): ?array {
		$query = mb_substr( trim( sanitize_text_field( self::toString( $rule['query'] ?? '' ) ) ), 0, self::RULE_QUERY_MAX, 'UTF-8' );
		if ( '' === $query ) {
			return null;
		}
		$lang = self::sanitizeLanguages( array( self::toString( $rule['lang'] ?? '' ) ) );
		return array(
			'query' => $query,
			'lang'  => $lang[0] ?? '',
		);
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
	 * Meta keys (D-119): letters, digits, underscores, hyphens, dots and colons, 1 to 64 characters, not one of
	 * WordPress's own `_wp_` or `_edit_` keys; unique, in the order given, 20 at most.
	 *
	 * @param array<mixed, mixed> $input Raw keys.
	 * @return string[]
	 */
	public static function sanitizeMetaKeys( array $input ): array {
		$out = array();
		foreach ( $input as $key ) {
			$key = is_string( $key ) ? trim( $key ) : '';
			if ( 1 === preg_match( '/^[A-Za-z0-9_][A-Za-z0-9_.:-]{0,63}$/', $key ) && 1 !== preg_match( '/^_(wp|edit)_/', $key ) && ! in_array( $key, $out, true ) && count( $out ) < self::CUSTOM_FIELDS_MAX ) {
				$out[] = $key;
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
