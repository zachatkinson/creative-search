<?php
/**
 * The Redirects and pins tab.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Admin;

use CreativeBranding\Search\Index\Languages;
use CreativeBranding\Search\Search\OwnerRules;
use CreativeBranding\Search\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The owner's rules for single searches: a search sent straight to a page (D-108) and products shown first for a
 * search (D-110). One form; each list with a box to remove a rule, then a row to add one.
 */
final class RulesTab {

	/**
	 * Constructor.
	 *
	 * @param Languages $languages The indexed languages.
	 */
	public function __construct( private Languages $languages ) {
	}

	/**
	 * Print the tab.
	 */
	public function render(): void {
		echo AdminPage::formStart( Actions::RULES_SAVE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo '<p>' . esc_html__( 'A rule answers a search for exactly its words. Capitals, accents, punctuation and short words such as “the” do not matter; the order of the words does.', 'creative-search' ) . '</p>';

		echo '<h2>' . esc_html__( 'Redirects', 'creative-search' ) . '</h2>';
		echo '<p>' . esc_html__( 'Send a search straight to a page of this site, such as “size guide” to your size guides, instead of showing results.', 'creative-search' ) . '</p>';
		echo '<table class="widefat striped csearch-redirects"><thead><tr><th scope="col">' . esc_html__( 'Search', 'creative-search' ) . '</th><th scope="col">' . esc_html__( 'Language', 'creative-search' ) . '</th><th scope="col">' . esc_html__( 'Goes to', 'creative-search' ) . '</th><th scope="col">' . esc_html__( 'Remove', 'creative-search' ) . '</th></tr></thead><tbody>';
		$redirects = OwnerRules::redirects();
		if ( array() === $redirects ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No redirects yet.', 'creative-search' ) . '</td></tr>';
		}
		foreach ( $redirects as $i => $rule ) {
			echo '<tr><th scope="row">' . esc_html( $rule['query'] ) . '</th><td>' . esc_html( self::language( $rule['lang'] ) ) . '</td><td><a href="' . esc_url( $rule['url'] ) . '">' . esc_html( $rule['url'] ) . '</a></td>';
			/* translators: %s: a search */
			echo '<td>' . self::removeBox( 'redirect_remove', $i, sprintf( __( 'Remove the redirect for “%s”', 'creative-search' ), $rule['query'] ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
		echo '</tbody></table>';
		echo '<h3>' . esc_html__( 'Add a redirect', 'creative-search' ) . '</h3><p class="csearch-new-rule">';
		echo self::queryField( 'redirect' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo $this->languageField( 'redirect' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo '<label for="csearch-redirect-url">' . esc_html__( 'Goes to', 'creative-search' ) . '</label> <input type="text" id="csearch-redirect-url" name="redirect_url" class="regular-text" maxlength="' . esc_attr( (string) Settings::RULE_URL_MAX ) . '" placeholder="/size-guide/" aria-describedby="csearch-redirect-help"></p>';
		echo '<p class="description" id="csearch-redirect-help">' . esc_html__( 'An address on this site, or a path such as /size-guide/. Adding a search that already has a redirect changes it.', 'creative-search' ) . '</p>';

		echo '<h2>' . esc_html__( 'Pinned products', 'creative-search' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: the most products pinned to one search */
				__( 'Show up to %s products first for a search, in the order given, in the suggestions and on the results page. A product that is not published, or is hidden from search, is still left out.', 'creative-search' ),
				number_format_i18n( Settings::PINS_MAX )
			)
		) . '</p>';
		echo '<table class="widefat striped csearch-pins"><thead><tr><th scope="col">' . esc_html__( 'Search', 'creative-search' ) . '</th><th scope="col">' . esc_html__( 'Language', 'creative-search' ) . '</th><th scope="col">' . esc_html__( 'Products, in order', 'creative-search' ) . '</th><th scope="col">' . esc_html__( 'Remove', 'creative-search' ) . '</th></tr></thead><tbody>';
		$pins = OwnerRules::pinned();
		if ( array() === $pins ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No pinned products yet.', 'creative-search' ) . '</td></tr>';
		}
		_prime_post_caches( array_merge( array(), ...array_column( $pins, 'ids' ) ) );
		foreach ( $pins as $i => $rule ) {
			$names = array();
			foreach ( $rule['ids'] as $productId ) {
				$product = wc_get_product( $productId );
				$names[] = $product instanceof \WC_Product ? wp_strip_all_tags( $product->get_formatted_name() ) : '#' . $productId;
			}
			echo '<tr><th scope="row">' . esc_html( $rule['query'] ) . '</th><td>' . esc_html( self::language( $rule['lang'] ) ) . '</td><td><ol class="csearch-pinned">';
			foreach ( $names as $name ) {
				echo '<li>' . esc_html( $name ) . '</li>';
			}
			/* translators: %s: a search */
			echo '</ol></td><td>' . self::removeBox( 'pin_remove', $i, sprintf( __( 'Remove the products pinned to “%s”', 'creative-search' ), $rule['query'] ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
		echo '</tbody></table>';
		echo '<h3>' . esc_html__( 'Pin products', 'creative-search' ) . '</h3><p class="csearch-new-rule">';
		echo self::queryField( 'pin' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo $this->languageField( 'pin' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo '<label for="csearch-pin-products">' . esc_html__( 'Products by SKU or ID', 'creative-search' ) . '</label> <input type="text" id="csearch-pin-products" name="pin_products" class="regular-text" aria-describedby="csearch-pin-help"></p>';
		echo '<p class="description" id="csearch-pin-help">' . esc_html__( 'Separate several with commas, first first. Pinning products to a search that already has some replaces them.', 'creative-search' ) . '</p>';

		submit_button( __( 'Save redirects and pins', 'creative-search' ) );
		echo '</form>';
	}

	/**
	 * A rule's language for the owner: its code, or every language.
	 *
	 * @param string $code A code, or empty.
	 */
	private static function language( string $code ): string {
		return '' === $code ? __( 'Every language', 'creative-search' ) : $code;
	}

	/**
	 * The search field of a new rule.
	 *
	 * @param string $kind `redirect` or `pin`.
	 */
	private static function queryField( string $kind ): string {
		return '<label for="' . esc_attr( 'csearch-' . $kind . '-query' ) . '">' . esc_html__( 'Search', 'creative-search' ) . '</label> <input type="text" id="' . esc_attr( 'csearch-' . $kind . '-query' ) . '" name="' . esc_attr( $kind . '_query' ) . '" maxlength="' . esc_attr( (string) Settings::RULE_QUERY_MAX ) . '">';
	}

	/**
	 * The language of a new rule: every language, or one the shop indexes; nothing to choose with one language.
	 *
	 * @param string $kind `redirect` or `pin`.
	 */
	private function languageField( string $kind ): string {
		$codes = $this->languages->codes();
		if ( count( $codes ) < 2 ) {
			return '<input type="hidden" name="' . esc_attr( $kind . '_lang' ) . '" value="">';
		}
		$html = '<label for="' . esc_attr( 'csearch-' . $kind . '-lang' ) . '">' . esc_html__( 'Language', 'creative-search' ) . '</label> <select id="' . esc_attr( 'csearch-' . $kind . '-lang' ) . '" name="' . esc_attr( $kind . '_lang' ) . '"><option value="">' . esc_html__( 'Every language', 'creative-search' ) . '</option>';
		foreach ( $codes as $code ) {
			$html .= '<option value="' . esc_attr( $code ) . '">' . esc_html( $code ) . '</option>';
		}
		return $html . '</select>';
	}

	/**
	 * A rule's remove box.
	 *
	 * @param string $name  The field.
	 * @param int    $index The rule's place.
	 * @param string $label What it removes, for a screen reader.
	 */
	private static function removeBox( string $name, int $index, string $label ): string {
		$id = 'csearch-' . str_replace( '_', '-', $name ) . '-' . $index;
		return '<label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( (string) $index ) . '"> <span class="screen-reader-text">' . esc_html( $label ) . '</span></label>';
	}
}
