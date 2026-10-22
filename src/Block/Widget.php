<?php
/**
 * The classic widget.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Block;

use CreativeBranding\Search\Plugin;
use CreativeBranding\Search\Support\Cast;
use CreativeBranding\Search\Settings\Settings;
use WP_Widget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A `WP_Widget` for classic sidebars that renders the same form as the block. The instance holds the
 * same keys as the settings (`label_visible`, `placeholder`, `suggest_limit`, `show_prices`, `show_stock`,
 * `show_terms`, `recent_searches`, `panel_position`); an empty instance follows the owner's settings.
 * `WidgetRegistrar` registers the class on `widgets_init`, so WordPress builds it after `init` and the
 * translated title loads no text domain early; the form comes from the container when it renders.
 */
final class Widget extends WP_Widget {

	/**
	 * The widget id base.
	 */
	public const ID = 'creative_search';

	/**
	 * The yes/no fields the form shows, with their labels.
	 *
	 * @return array<string, string>
	 */
	private static function flags(): array {
		return array(
			'label_visible'   => __( 'Show the label', 'creative-search' ),
			'show_prices'     => __( 'Show prices', 'creative-search' ),
			'show_stock'      => __( 'Show stock', 'creative-search' ),
			'show_terms'      => __( 'Show categories, tags and brands', 'creative-search' ),
			'recent_searches' => __( 'Offer recent searches (stored in the shopper\'s browser)', 'creative-search' ),
		);
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			self::ID,
			__( 'Creative Search', 'creative-search' ),
			array(
				'description'                 => __( 'The product search bar with live suggestions.', 'creative-search' ),
				'customize_selective_refresh' => true,
				'show_instance_in_rest'       => true,
			)
		);
	}

	/**
	 * Front end.
	 *
	 * @param array<string, mixed> $args     Sidebar markup.
	 * @param array<string, mixed> $instance Saved instance.
	 */
	public function widget( $args, $instance ): void {
		$form = Plugin::container()->get( SearchForm::class );
		// Sidebar wrappers are the theme's and are filtered by it; the form is escaped where it is built.
		echo wp_kses_post( Cast::str( $args['before_widget'] ?? '' ) );
		$title = apply_filters( 'widget_title', Cast::str( $instance['title'] ?? '' ), $instance, $this->id_base );
		if ( is_string( $title ) && '' !== $title ) {
			echo wp_kses_post( Cast::str( $args['before_title'] ?? '' ) ) . esc_html( $title ) . wp_kses_post( Cast::str( $args['after_title'] ?? '' ) );
		}
		echo $form->render( FormOptions::fromSettingKeys( $instance ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in SearchForm.
		echo wp_kses_post( Cast::str( $args['after_widget'] ?? '' ) );
	}

	/**
	 * Sanitise a saved instance.
	 *
	 * @param array<string, mixed> $new_instance Submitted values.
	 * @param array<string, mixed> $old_instance Previous values.
	 * @return array<string, mixed>
	 */
	public function update( $new_instance, $old_instance ): array {
		$out = array(
			'title' => sanitize_text_field( Settings::toString( $new_instance['title'] ?? '' ) ),
		);
		foreach ( array_keys( self::flags() ) as $flag ) {
			$out[ $flag ] = Settings::yesNo( $new_instance[ $flag ] ?? 'no' );
		}
		$out['placeholder']    = sanitize_text_field( Settings::toString( $new_instance['placeholder'] ?? '' ) );
		$out['suggest_limit']  = Settings::clamp( Settings::toInt( $new_instance['suggest_limit'] ?? null, Settings::LIMIT_DEFAULT ), Settings::LIMIT_MIN, Settings::LIMIT_MAX );
		$position              = Settings::toString( $new_instance['panel_position'] ?? '' );
		$out['panel_position'] = in_array( $position, array( Settings::PANEL_AUTO, Settings::PANEL_BELOW, Settings::PANEL_ABOVE ), true ) ? $position : Settings::PANEL_AUTO;
		return $out;
	}

	/**
	 * The admin form.
	 *
	 * @param array<string, mixed> $instance Saved instance.
	 * @return string
	 */
	public function form( $instance ): string {
		$options = FormOptions::fromSettingKeys( $instance );
		$title   = Settings::toString( $instance['title'] ?? '' );

		$this->field( 'title', __( 'Title', 'creative-search' ), 'text', $title );
		$this->field( 'placeholder', __( 'Placeholder', 'creative-search' ), 'text', $options->placeholder );
		$this->field( 'suggest_limit', __( 'Suggestions (1 to 10)', 'creative-search' ), 'number', (string) $options->limit );

		$positions = array(
			Settings::PANEL_AUTO  => _x( 'Automatic', 'panel position', 'creative-search' ),
			Settings::PANEL_BELOW => _x( 'Below the field', 'panel position', 'creative-search' ),
			Settings::PANEL_ABOVE => _x( 'Above the field', 'panel position', 'creative-search' ),
		);
		printf(
			'<p><label for="%1$s">%2$s</label> <select class="widefat" id="%1$s" name="%3$s">',
			esc_attr( $this->get_field_id( 'panel_position' ) ),
			esc_html__( 'Panel position', 'creative-search' ),
			esc_attr( $this->get_field_name( 'panel_position' ) )
		);
		foreach ( $positions as $value => $text ) {
			printf( '<option value="%1$s"%3$s>%2$s</option>', esc_attr( $value ), esc_html( $text ), selected( $options->panelPosition, $value, false ) );
		}
		echo '</select></p>';

		$on = array(
			'label_visible'   => $options->labelVisible,
			'show_prices'     => $options->showPrices,
			'show_stock'      => $options->showStock,
			'show_terms'      => $options->showTerms,
			'recent_searches' => $options->recentSearches,
		);
		foreach ( self::flags() as $flag => $text ) {
			printf(
				'<p><input type="checkbox" class="checkbox" id="%1$s" name="%2$s" value="yes"%4$s> <label for="%1$s">%3$s</label></p>',
				esc_attr( $this->get_field_id( $flag ) ),
				esc_attr( $this->get_field_name( $flag ) ),
				esc_html( $text ),
				checked( $on[ $flag ], true, false )
			);
		}
		return 'noform';
	}

	/**
	 * One labelled text or number field.
	 *
	 * @param string $key   Instance key.
	 * @param string $label Label.
	 * @param string $type  text or number.
	 * @param string $value Current value.
	 */
	private function field( string $key, string $label, string $type, string $value ): void {
		$extra = 'number' === $type ? sprintf( ' min="%d" max="%d" step="1"', Settings::LIMIT_MIN, Settings::LIMIT_MAX ) : '';
		printf(
			'<p><label for="%1$s">%2$s</label> <input class="widefat" id="%1$s" name="%3$s" type="%4$s" value="%5$s"%6$s></p>',
			esc_attr( $this->get_field_id( $key ) ),
			esc_html( $label ),
			esc_attr( $this->get_field_name( $key ) ),
			esc_attr( $type ),
			esc_attr( $value ),
			$extra // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integer constants formatted with %d.
		);
	}
}
