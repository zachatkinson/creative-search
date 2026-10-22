<?php
/**
 * The Boosts and exclusions tab.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Admin;

use CreativeBranding\Search\Index\TermReader;
use CreativeBranding\Search\Repository\ProductIdRepository;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Cast;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The owner's hand on the ranking (spec: Admin → Boosts and exclusions): rules that add a number to every product
 * in a category, brand or tag; the products kept out of search, each of which can be let back in; the products
 * shown when nothing matches; and whether a hidden product appears for its exact SKU.
 */
final class BoostsTab {

	public const EXCLUDED_SHOWN = 100;

	/**
	 * Constructor.
	 *
	 * @param ProductIdRepository $products Product ids.
	 */
	public function __construct( private ProductIdRepository $products ) {
	}

	/**
	 * Print the tab.
	 */
	public function render(): void {
		$rules = Cast::arrays( Settings::get( 'boost_rules' ) );
		echo AdminPage::formStart( Actions::BOOSTS_SAVE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.

		echo '<h2>' . esc_html__( 'Boost rules', 'creative-search' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: lowest boost, 2: highest boost */
				__( 'A rule adds a number from %1$d to %2$d to every product in a category, brand or tag, so they rank higher (or lower, below zero) among equally good matches. A product’s own boost is on its edit screen.', 'creative-search' ),
				Settings::BOOST_MIN,
				Settings::BOOST_MAX
			)
		) . '</p>';
		echo '<table class="widefat striped csearch-rules"><thead><tr><th scope="col">' . esc_html__( 'Products in', 'creative-search' ) . '</th><th scope="col">' . esc_html__( 'Boost', 'creative-search' ) . '</th><th scope="col">' . esc_html__( 'Remove', 'creative-search' ) . '</th></tr></thead><tbody>';
		if ( array() === $rules ) {
			echo '<tr><td colspan="3">' . esc_html__( 'No rules yet.', 'creative-search' ) . '</td></tr>';
		}
		foreach ( array_values( $rules ) as $i => $rule ) {
			$taxonomy = Cast::str( $rule['taxonomy'] ?? '' );
			$termId   = Cast::int( $rule['term_id'] ?? 0 );
			$term     = get_term( $termId, $taxonomy );
			/* translators: 1: Category, Tag or Brand, 2: its name */
			$name = sprintf( __( '%1$s: %2$s', 'creative-search' ), self::taxonomyName( $taxonomy ), $term instanceof WP_Term ? $term->name : '#' . $termId );
			$id   = 'csearch-rule-' . $i;
			echo '<tr><th scope="row" id="' . esc_attr( $id ) . '-name">' . esc_html( $name ) . '<input type="hidden" name="rules[' . esc_attr( (string) $i ) . '][ref]" value="' . esc_attr( $taxonomy . ':' . $termId ) . '"></th>';
			/* translators: %s: a category, tag or brand */
			echo '<td><label class="screen-reader-text" for="' . esc_attr( $id ) . '-boost">' . esc_html( sprintf( __( 'Boost for %s', 'creative-search' ), $name ) ) . '</label><input type="number" id="' . esc_attr( $id ) . '-boost" name="rules[' . esc_attr( (string) $i ) . '][boost]" min="' . esc_attr( (string) Settings::BOOST_MIN ) . '" max="' . esc_attr( (string) Settings::BOOST_MAX ) . '" step="1" value="' . esc_attr( (string) Cast::int( $rule['boost'] ?? 0 ) ) . '" class="small-text"></td>';
			/* translators: %s: a category, tag or brand */
			echo '<td><label for="' . esc_attr( $id ) . '-remove"><input type="checkbox" id="' . esc_attr( $id ) . '-remove" name="rules[' . esc_attr( (string) $i ) . '][remove]" value="1"> <span class="screen-reader-text">' . esc_html( sprintf( __( 'Remove the rule for %s', 'creative-search' ), $name ) ) . '</span></label></td></tr>';
		}
		echo '</tbody></table>';
		echo '<h3>' . esc_html__( 'Add a rule', 'creative-search' ) . '</h3><p class="csearch-new-rule"><label for="csearch-new-ref">' . esc_html__( 'Products in', 'creative-search' ) . '</label> <select id="csearch-new-ref" name="new_ref"><option value="">' . esc_html__( 'Choose a category, brand or tag', 'creative-search' ) . '</option>';
		foreach ( array_keys( TermReader::TYPES ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => 500,
					'orderby'    => 'name',
				)
			);
			if ( ! is_array( $terms ) || array() === $terms ) {
				continue;
			}
			echo '<optgroup label="' . esc_attr( self::taxonomyName( $taxonomy ) ) . '">';
			foreach ( $terms as $term ) {
				if ( $term instanceof WP_Term ) {
					echo '<option value="' . esc_attr( $taxonomy . ':' . $term->term_id ) . '">' . esc_html( $term->name ) . '</option>';
				}
			}
			echo '</optgroup>';
		}
		echo '</select> <label for="csearch-new-boost">' . esc_html__( 'Boost', 'creative-search' ) . '</label> <input type="number" id="csearch-new-boost" name="new_boost" min="' . esc_attr( (string) Settings::BOOST_MIN ) . '" max="' . esc_attr( (string) Settings::BOOST_MAX ) . '" step="1" value="2" class="small-text"></p>';

		echo '<h2>' . esc_html__( 'When nothing matches', 'creative-search' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row">' . esc_html__( 'Fallback products', 'creative-search' ) . '</th><td>';
		$fallback = Cast::ints( Settings::get( 'fallback_products' ) );
		_prime_post_caches( $fallback );
		if ( array() === $fallback ) {
			echo '<p>' . esc_html__( 'None chosen.', 'creative-search' ) . '</p>';
		} else {
			echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Fallback products', 'creative-search' ) . '</legend><ul class="csearch-fallback">';
			foreach ( $fallback as $productId ) {
				$product = wc_get_product( $productId );
				$name    = $product instanceof \WC_Product ? wp_strip_all_tags( $product->get_formatted_name() ) : '#' . $productId;
				/* translators: %s: a product */
				echo '<li><input type="hidden" name="fallback_products[]" value="' . esc_attr( (string) $productId ) . '">' . esc_html( $name ) . ' <label for="csearch-fallback-remove-' . esc_attr( (string) $productId ) . '"><input type="checkbox" id="csearch-fallback-remove-' . esc_attr( (string) $productId ) . '" name="fallback_remove[]" value="' . esc_attr( (string) $productId ) . '"> ' . esc_html__( 'Remove', 'creative-search' ) . '<span class="screen-reader-text"> ' . esc_html( $name ) . '</span></label></li>';
			}
			echo '</ul></fieldset>';
		}
		echo '<p><label for="csearch-fallback-add">' . esc_html__( 'Add products by SKU or ID', 'creative-search' ) . '</label><br><input type="text" id="csearch-fallback-add" name="fallback_add" class="regular-text" aria-describedby="csearch-fallback-help"></p>';
		echo '<p class="description" id="csearch-fallback-help">' . esc_html__( 'Separate several with commas. Up to four are shown on the results page when a search finds nothing.', 'creative-search' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Hidden products', 'creative-search' ) . '</th><td><input type="hidden" name="hidden_by_sku" value="no"><label for="csearch-hidden-sku"><input type="checkbox" id="csearch-hidden-sku" name="hidden_by_sku" value="yes"' . checked( 'yes', Settings::get( 'hidden_by_sku' ), false ) . '> ' . esc_html__( 'Show a hidden product when a search is exactly its SKU', 'creative-search' ) . '</label></td></tr></tbody></table>';

		echo '<h2>' . esc_html__( 'Excluded products', 'creative-search' ) . '</h2>';
		echo '<p>' . esc_html__( 'Products kept out of search from their edit screen (Search panel → Exclude from search). Tick one to let it back in.', 'creative-search' ) . '</p>';
		$excluded = $this->products->excluded( self::EXCLUDED_SHOWN );
		_prime_post_caches( $excluded );
		if ( array() === $excluded ) {
			echo '<p>' . esc_html__( 'No product is excluded.', 'creative-search' ) . '</p>';
		} else {
			echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Let back into search', 'creative-search' ) . '</legend><ul class="csearch-excluded">';
			foreach ( $excluded as $productId ) {
				$product = wc_get_product( $productId );
				$name    = $product instanceof \WC_Product ? wp_strip_all_tags( $product->get_formatted_name() ) : '#' . $productId;
				$edit    = get_edit_post_link( $productId, 'url' );
				/* translators: %s: a product */
				echo '<li><label for="csearch-include-' . esc_attr( (string) $productId ) . '"><input type="checkbox" id="csearch-include-' . esc_attr( (string) $productId ) . '" name="include[]" value="' . esc_attr( (string) $productId ) . '"> ' . esc_html( sprintf( __( 'Let %s back in', 'creative-search' ), $name ) ) . '</label>';
				if ( is_string( $edit ) ) {
					/* translators: %s: a product */
					echo ' <a href="' . esc_url( $edit ) . '" aria-label="' . esc_attr( sprintf( __( 'Edit %s', 'creative-search' ), $name ) ) . '">' . esc_html__( 'Edit', 'creative-search' ) . '</a>';
				}
				echo '</li>';
			}
			echo '</ul></fieldset>';
		}
		submit_button( __( 'Save boosts and exclusions', 'creative-search' ) );
		echo '</form>';
	}

	/**
	 * A taxonomy's name for the owner.
	 *
	 * @param string $taxonomy The taxonomy.
	 */
	private static function taxonomyName( string $taxonomy ): string {
		$names = array(
			'product_cat'   => __( 'Category', 'creative-search' ),
			'product_tag'   => __( 'Tag', 'creative-search' ),
			'product_brand' => __( 'Brand', 'creative-search' ),
		);
		return $names[ $taxonomy ] ?? $taxonomy;
	}
}
