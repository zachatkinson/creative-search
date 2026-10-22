<?php
/**
 * The resolved options of one search form.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Block;

use CreativeBranding\Search\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One form's settings, resolved once: the panel defaults from `Settings` (docs/SETTINGS.md), overridden by
 * whatever the caller carries (block attributes in camelCase, shortcode attributes and widget instances in
 * the settings' own keys). The block, the shortcode, the widget and the theme-form takeover all build one
 * of these and hand it to `SearchForm`, so no caller reads a setting on its own.
 */
final class FormOptions {

	/**
	 * Block attribute name to setting key.
	 *
	 * @var array<string, string>
	 */
	public const ATTRIBUTES = array(
		'labelVisible'   => 'label_visible',
		'placeholder'    => 'placeholder',
		'limit'          => 'suggest_limit',
		'showPrices'     => 'show_prices',
		'showStock'      => 'show_stock',
		'showTerms'      => 'show_terms',
		'recentSearches' => 'recent_searches',
		'panelPosition'  => 'panel_position',
	);

	/**
	 * Constructor.
	 *
	 * @param bool   $labelVisible   A visible label, or a visually hidden one.
	 * @param string $placeholder    The input's placeholder; empty for the shop's default.
	 * @param int    $limit          Products in the panel, 1 to 10.
	 * @param bool   $showPrices     Price in each row.
	 * @param bool   $showStock      Stock in each row.
	 * @param bool   $showTerms      Category, tag and brand rows.
	 * @param bool   $recentSearches The shopper's last five searches, in their browser only.
	 * @param string $panelPosition  auto, below or above.
	 * @param int    $minChars       Characters before the first request, after `csearch_min_chars`.
	 */
	public function __construct(
		public readonly bool $labelVisible,
		public readonly string $placeholder,
		public readonly int $limit,
		public readonly bool $showPrices,
		public readonly bool $showStock,
		public readonly bool $showTerms,
		public readonly bool $recentSearches,
		public readonly string $panelPosition,
		public readonly int $minChars
	) {
	}

	/**
	 * The owner's defaults, nothing overridden (the theme-form takeover, a widget with an empty instance).
	 */
	public static function fromSettings(): self {
		return self::fromSettingKeys( array() );
	}

	/**
	 * From block attributes (camelCase, booleans and integers as block.json types them).
	 *
	 * @param array<string, mixed> $attributes Block attributes as the render callback receives them.
	 */
	public static function fromAttributes( array $attributes ): self {
		$values = array();
		foreach ( self::ATTRIBUTES as $attribute => $key ) {
			if ( array_key_exists( $attribute, $attributes ) && null !== $attributes[ $attribute ] ) {
				$values[ $key ] = $attributes[ $attribute ];
			}
		}
		return self::fromSettingKeys( $values );
	}

	/**
	 * From values keyed like the settings (shortcode attributes, a widget instance). Unknown keys are
	 * ignored, each value is coerced to its type and range, a missing key takes the owner's setting.
	 *
	 * @param array<string, mixed> $values Overrides.
	 */
	public static function fromSettingKeys( array $values ): self {
		$flag = static function ( string $key ) use ( $values ): bool {
			if ( array_key_exists( $key, $values ) ) {
				return 'yes' === Settings::yesNo( $values[ $key ] );
			}
			return Settings::isOn( $key );
		};

		$limit = array_key_exists( 'suggest_limit', $values )
			? Settings::toInt( $values['suggest_limit'], Settings::LIMIT_DEFAULT )
			: Settings::int( 'suggest_limit' );

		$placeholder = array_key_exists( 'placeholder', $values )
			? Settings::toString( $values['placeholder'] )
			: Settings::toString( Settings::get( 'placeholder' ) );

		$position = array_key_exists( 'panel_position', $values )
			? Settings::toString( $values['panel_position'] )
			: Settings::toString( Settings::get( 'panel_position' ) );
		if ( ! in_array( $position, array( Settings::PANEL_AUTO, Settings::PANEL_BELOW, Settings::PANEL_ABOVE ), true ) ) {
			$position = Settings::PANEL_AUTO;
		}

		/**
		 * Filters the number of characters typed before the block sends its first request.
		 *
		 * @since 0.6.0
		 *
		 * @param int $min_chars The `min_chars` setting, 1 to 5.
		 */
		$minChars = apply_filters( 'csearch_min_chars', Settings::int( 'min_chars' ) );

		return new self(
			$flag( 'label_visible' ),
			trim( sanitize_text_field( $placeholder ) ),
			Settings::clamp( $limit, Settings::LIMIT_MIN, Settings::LIMIT_MAX ),
			$flag( 'show_prices' ),
			$flag( 'show_stock' ),
			$flag( 'show_terms' ),
			$flag( 'recent_searches' ),
			$position,
			Settings::clamp( Settings::toInt( $minChars, Settings::MIN_CHARS_DEFAULT ), Settings::MIN_CHARS_MIN, Settings::MIN_CHARS_MAX )
		);
	}

	/**
	 * The block's attribute schema with the owner's settings as the defaults, so a block that sets nothing
	 * follows the settings screen and the editor's controls start from the owner's values.
	 *
	 * @param array<string, array<string, mixed>> $schema The attributes block.json declares (types, enums).
	 * @return array<string, array<string, mixed>>
	 */
	public static function attributeSchema( array $schema ): array {
		$defaults = self::fromSettings();
		$values   = array(
			'labelVisible'   => $defaults->labelVisible,
			'placeholder'    => $defaults->placeholder,
			'limit'          => $defaults->limit,
			'showPrices'     => $defaults->showPrices,
			'showStock'      => $defaults->showStock,
			'showTerms'      => $defaults->showTerms,
			'recentSearches' => $defaults->recentSearches,
			'panelPosition'  => $defaults->panelPosition,
		);
		foreach ( $values as $attribute => $value ) {
			if ( isset( $schema[ $attribute ] ) ) {
				$schema[ $attribute ]['default'] = $value;
			}
		}
		return $schema;
	}
}
