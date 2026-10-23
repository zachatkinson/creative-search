<?php
/**
 * The Tools tab.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Admin;

use CreativeBranding\Search\Domain\StopWords;
use CreativeBranding\Search\Index\IndexHealth;
use CreativeBranding\Search\Index\Languages;
use CreativeBranding\Search\Search\Explanation;
use CreativeBranding\Search\Search\SearchService;
use CreativeBranding\Search\Repository\MetaKeyRepository;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Cast;
use CreativeBranding\Search\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The index and the settings (spec: Admin → Tools): the index's status, Rebuild with its progress and Cancel,
 * a test search with the ranked answer and why, stop words per language, retention, what to index and the
 * search block's defaults.
 */
final class ToolsTab {

	/**
	 * The meta keys offered as custom fields at most (D-119).
	 */
	public const META_KEYS_SHOWN = 30;

	public const TEST_LIMIT = 10;

	/**
	 * Constructor.
	 *
	 * @param IndexHealth       $health    The index's status.
	 * @param SearchService     $search    The search, for the test box.
	 * @param Languages         $languages The indexed languages.
	 * @param MetaKeyRepository $metaKeys  The meta keys products carry, offered as custom fields (D-119).
	 */
	public function __construct( private IndexHealth $health, private SearchService $search, private Languages $languages, private MetaKeyRepository $metaKeys ) {
	}

	/**
	 * Print the tab.
	 */
	public function render(): void {
		$this->status();
		$this->testSearch();
		$this->settings();
	}

	/**
	 * The index's status, Rebuild and Cancel.
	 */
	private function status(): void {
		$status   = $this->health->status();
		$building = null !== $status['building'];
		echo '<h2>' . esc_html__( 'Index', 'creative-search' ) . '</h2>';
		echo '<table class="widefat striped csearch-status"><tbody>';
		$last = null === $status['last_reindex'] ? false : strtotime( $status['last_reindex'] . ' UTC' );
		foreach (
			array(
				array( __( 'Live generation', 'creative-search' ), number_format_i18n( $status['generation'] ) ),
				/* translators: 1: products in the index, 2: published products that should be */
				array( __( 'Products', 'creative-search' ), sprintf( __( '%1$s of %2$s', 'creative-search' ), number_format_i18n( $status['products'] ), number_format_i18n( $status['expected'] ) ) ),
				array( __( 'Documents (products, categories, tags and brands, per language)', 'creative-search' ), number_format_i18n( $status['documents'] ) ),
				array( __( 'Terms', 'creative-search' ), number_format_i18n( $status['terms'] ) ),
				array(
					__( 'Last rebuild', 'creative-search' ),
					false === $last
						? __( 'Never', 'creative-search' )
						/* translators: %s: how long ago, such as "2 hours" */
						: sprintf( __( '%s ago', 'creative-search' ), human_time_diff( $last ) ),
				),
				array( __( 'Jobs waiting', 'creative-search' ), number_format_i18n( $status['backlog'] ) ),
			) as $row
		) {
			echo '<tr><th scope="row">' . esc_html( $row[0] ) . '</th><td>' . esc_html( $row[1] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$progress = $status['progress'];
		echo '<div id="csearch-progress" class="csearch-progress"' . ( $building ? '' : ' hidden' ) . ' data-building="' . ( $building ? '1' : '0' ) . '">';
		echo '<label for="csearch-progress-bar">' . esc_html__( 'Rebuilding', 'creative-search' ) . '</label> <progress id="csearch-progress-bar" max="' . esc_attr( (string) max( 1, $progress['total'] ) ) . '" value="' . esc_attr( (string) $progress['offset'] ) . '"></progress> ';
		echo '<span id="csearch-progress-text" role="status">' . esc_html( self::progressText( $progress['offset'], $progress['total'], $progress['cancelled'] ) ) . '</span></div>';

		echo '<p class="csearch-index-actions">';
		echo AdminPage::formStart( Actions::REINDEX, 'csearch-inline' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		submit_button( __( 'Rebuild index', 'creative-search' ), 'primary', 'submit', false, array_merge( array( 'id' => 'csearch-rebuild' ), $building ? array( 'disabled' => 'disabled' ) : array() ) );
		echo '</form> ';
		if ( $building ) {
			echo AdminPage::formStart( Actions::REINDEX_CANCEL, 'csearch-inline' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			submit_button( __( 'Cancel the rebuild', 'creative-search' ), 'secondary', 'submit', false, array( 'id' => 'csearch-cancel' ) );
			echo '</form>';
		}
		echo '</p><p class="description">' . esc_html__( 'The shop keeps searching the current index while a new one is built, and switches when it is complete.', 'creative-search' ) . '</p>';

		if ( array() !== $status['errors'] ) {
			echo '<h3>' . esc_html__( 'Errors in the last rebuild', 'creative-search' ) . '</h3><ul class="ul-disc">';
			foreach ( $status['errors'] as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul>';
		}
	}

	/**
	 * The progress line.
	 *
	 * @param int  $done      Products walked.
	 * @param int  $total     Products to walk.
	 * @param bool $cancelled Whether it is stopping.
	 */
	public static function progressText( int $done, int $total, bool $cancelled ): string {
		return $cancelled
			/* translators: 1: products done, 2: products in all */
			? sprintf( _n( '%1$s of %2$s product (stopping)', '%1$s of %2$s products (stopping)', $total, 'creative-search' ), number_format_i18n( $done ), number_format_i18n( $total ) )
			/* translators: 1: products done, 2: products in all */
			: sprintf( _n( '%1$s of %2$s product', '%1$s of %2$s products', $total, 'creative-search' ), number_format_i18n( $done ), number_format_i18n( $total ) );
	}

	/**
	 * The test search: the ranked answer and its explanation, as `wp csearch explain` gives it.
	 */
	private function testSearch(): void {
		$codes = $this->languages->codes();
		$query = isset( $_GET['test'] ) && is_string( $_GET['test'] ) ? SearchService::clean( sanitize_text_field( wp_unslash( $_GET['test'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only test, not logged.
		$lang  = $this->search->language( isset( $_GET['lang'] ) && is_string( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : null ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only test.
		echo '<h2 id="csearch-test">' . esc_html__( 'Test search', 'creative-search' ) . '</h2>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '#csearch-test" class="csearch-inline"><input type="hidden" name="page" value="' . esc_attr( AdminPage::SLUG ) . '"><input type="hidden" name="tab" value="tools">';
		echo '<label for="csearch-test-query">' . esc_html__( 'Query', 'creative-search' ) . '</label> <input type="search" id="csearch-test-query" name="test" class="regular-text" value="' . esc_attr( $query ) . '"> ';
		if ( count( $codes ) > 1 ) {
			echo '<label for="csearch-test-lang">' . esc_html__( 'Language', 'creative-search' ) . '</label> <select id="csearch-test-lang" name="lang">';
			foreach ( $codes as $code ) {
				echo '<option value="' . esc_attr( $code ) . '"' . selected( $code, $lang, false ) . '>' . esc_html( $code ) . '</option>';
			}
			echo '</select> ';
		}
		submit_button( __( 'Search', 'creative-search' ), 'secondary', '', false, array( 'id' => 'csearch-test-submit' ) );
		echo '</form><p class="description">' . esc_html__( 'Not logged, and not counted in Analytics.', 'creative-search' ) . '</p>';
		if ( '' === $query ) {
			return;
		}
		$result = $this->search->run( $query, $lang, self::TEST_LIMIT, Settings::TERMS_MAX, true, true );
		$hits   = array_merge( Explanation::rows( $result->products ), Explanation::rows( $result->terms ) );
		echo '<p role="status">';
		if ( null !== $result->corrected ) {
			/* translators: 1: the query, 2: the query the results are for */
			echo esc_html( sprintf( __( 'Nothing matched every word of “%1$s”; showing results for “%2$s”.', 'creative-search' ), $query, $result->corrected ) ) . ' ';
		}
		echo esc_html(
			sprintf(
				/* translators: 1: number of products, 2: number of categories, tags and brands */
				__( 'Products: %1$s. Categories, tags and brands: %2$s.', 'creative-search' ),
				number_format_i18n( count( $result->products ) ),
				number_format_i18n( count( $result->terms ) )
			)
		) . '</p>';
		if ( array() === $hits ) {
			return;
		}
		echo '<table class="widefat striped csearch-explain"><thead><tr><th scope="col">' . esc_html__( 'Rank', 'creative-search' ) . '</th><th scope="col">' . esc_html__( 'Result', 'creative-search' ) . '</th><th scope="col">' . esc_html__( 'Score', 'creative-search' ) . '</th><th scope="col">' . esc_html__( 'Why', 'creative-search' ) . '</th></tr></thead><tbody>';
		foreach ( $hits as $hit ) {
			$bonuses = sprintf(
				/* translators: 1: phrase bonus, 2: exact-match bonus, 3: stock bonus, 4: owner's boost */
				__( 'phrase %1$s, exact %2$s, stock %3$s, owner %4$s', 'creative-search' ),
				number_format_i18n( Cast::float( $hit['phrase'] ), 2 ),
				number_format_i18n( Cast::float( $hit['exact'] ), 2 ),
				number_format_i18n( Cast::float( $hit['stock'] ), 2 ),
				number_format_i18n( Cast::float( $hit['owner'] ), 2 )
			);
			echo '<tr><td>' . esc_html( Cast::str( $hit['rank'] ) ) . '</td><td>' . esc_html( Cast::str( $hit['title'] ) ) . ' <span class="csearch-muted">' . esc_html(
				sprintf(
					/* translators: 1: Product, Category, Tag or Brand, 2: its id */
					__( '(%1$s #%2$s)', 'creative-search' ),
					AnalyticsTab::type( Cast::str( $hit['type'] ) ),
					Cast::str( $hit['id'] )
				)
			) . '</span></td><td>' . esc_html( number_format_i18n( Cast::float( $hit['total'] ), 2 ) ) . '</td><td><ul class="csearch-why">';
			foreach ( array_filter( explode( '; ', Cast::str( $hit['tokens'] ) ) ) as $line ) {
				echo '<li><code>' . esc_html( $line ) . '</code></li>';
			}
			echo '<li>' . esc_html( $bonuses ) . '</li></ul></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Stop words, retention, what to index and the block's defaults.
	 */
	private function settings(): void {
		$settings = Settings::all();
		echo '<h2>' . esc_html__( 'Settings', 'creative-search' ) . '</h2>';
		echo AdminPage::formStart( Actions::TOOLS_SAVE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.

		echo '<h3>' . esc_html__( 'What to index', 'creative-search' ) . '</h3><table class="form-table" role="presentation"><tbody>';
		self::flag( 'index_long_description', __( 'Long descriptions', 'creative-search' ), __( 'Search the long description (its first 2,000 characters).', 'creative-search' ), $settings );
		self::flag( 'index_tags', __( 'Tags', 'creative-search' ), __( 'Search product tags, and suggest tags.', 'creative-search' ), $settings );
		self::flag( 'index_variation_skus', __( 'Variation SKUs', 'creative-search' ), __( 'Find a variable product by any of its variations’ SKUs.', 'creative-search' ), $settings );
		self::flag( 'index_posts', __( 'Posts', 'creative-search' ), __( 'Search the blog’s posts too, shown apart from the products.', 'creative-search' ), $settings );
		self::flag( 'index_pages', __( 'Pages', 'creative-search' ), __( 'Search the site’s pages too, shown apart from the products.', 'creative-search' ), $settings );
		$this->customFields( $settings );
		echo '</tbody></table><p class="description">' . esc_html__( 'A change to the product settings applies to products as they are next indexed; rebuild the index to apply it to all of them. Posts and pages are added or removed in the background as soon as you save.', 'creative-search' ) . '</p>';

		echo '<h3>' . esc_html__( 'Results page', 'creative-search' ) . '</h3><table class="form-table" role="presentation"><tbody>';
		self::flag( 'category_redirect', __( 'Categories', 'creative-search' ), __( 'Open a category when a search names it, such as “wheels”.', 'creative-search' ), $settings );
		$attributes = function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : array();
		if ( array() !== $attributes ) {
			$chosen = Cast::strings( $settings['filter_attributes'] ?? array() );
			echo '<tr><th scope="row">' . esc_html__( 'Filters', 'creative-search' ) . '</th><td><fieldset aria-describedby="csearch-filter-attributes-help"><legend class="screen-reader-text">' . esc_html__( 'Filters', 'creative-search' ) . '</legend><input type="hidden" name="filter_attributes[]" value="">';
			foreach ( $attributes as $attribute ) {
				$taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );
				$id       = 'csearch-filter-' . $attribute->attribute_name;
				echo '<label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="filter_attributes[]" value="' . esc_attr( $taxonomy ) . '"' . checked( in_array( $taxonomy, $chosen, true ), true, false ) . '> ' . esc_html( $attribute->attribute_label ) . '</label><br>';
			}
			echo '<p class="description" id="csearch-filter-attributes-help">' . esc_html__( 'A search that names one of these attributes’ values, such as “deck 8.25”, shows the products with that filter applied, so the shopper can see it and remove it.', 'creative-search' ) . '</p></fieldset></td></tr>';
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Stop words', 'creative-search' ) . '</h3><p>' . esc_html__( 'Words a search ignores unless they are all it has, besides the built-in list. One per line or separated by commas.', 'creative-search' ) . '</p><table class="form-table" role="presentation"><tbody>';
		$builtIn = new StopWords();
		foreach ( $this->languages->codes() as $code ) {
			$own = Cast::strings( get_option( Options::STOPWORDS_PREFIX . $code, array() ) );
			/* translators: %s: language code */
			echo '<tr><th scope="row"><label for="csearch-stop-' . esc_attr( $code ) . '">' . esc_html( sprintf( __( 'Stop words (%s)', 'creative-search' ), $code ) ) . '</label></th><td><textarea id="csearch-stop-' . esc_attr( $code ) . '" name="stopwords[' . esc_attr( $code ) . ']" rows="3" class="large-text" aria-describedby="csearch-stop-' . esc_attr( $code ) . '-help">' . esc_textarea( implode( "\n", $own ) ) . '</textarea>';
			/* translators: %s: number of words */
			echo '<p class="description" id="csearch-stop-' . esc_attr( $code ) . '-help">' . esc_html( sprintf( _n( 'The built-in list has %s word.', 'The built-in list has %s words.', count( $builtIn->listFor( $code ) ), 'creative-search' ), number_format_i18n( count( $builtIn->listFor( $code ) ) ) ) ) . '</p></td></tr>';
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Search log', 'creative-search' ) . '</h3><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="csearch-retention">' . esc_html__( 'Keep searches for (days)', 'creative-search' ) . '</label></th><td><input type="number" id="csearch-retention" name="retention_days" min="' . esc_attr( (string) Settings::RETENTION_MIN ) . '" max="' . esc_attr( (string) Settings::RETENTION_MAX ) . '" step="1" class="small-text" value="' . esc_attr( Cast::str( $settings['retention_days'] ) ) . '" aria-describedby="csearch-retention-help">';
		echo '<p class="description" id="csearch-retention-help">' . esc_html__( 'Searches are anonymous; older ones are deleted each night. The daily counts are kept for the charts.', 'creative-search' ) . '</p></td></tr></tbody></table>';

		echo '<h3>' . esc_html__( 'Search bar defaults', 'creative-search' ) . '</h3><p>' . esc_html__( 'For the shortcode, the widget, the theme’s own search form and new search blocks; a block can change each.', 'creative-search' ) . '</p><table class="form-table" role="presentation"><tbody>';
		self::number( 'suggest_limit', __( 'Products suggested', 'creative-search' ), Settings::LIMIT_MIN, Settings::LIMIT_MAX, $settings );
		self::number( 'suggest_terms', __( 'Categories, tags and brands suggested', 'creative-search' ), 0, Settings::TERMS_MAX, $settings );
		self::number( 'min_chars', __( 'Characters before suggesting', 'creative-search' ), Settings::MIN_CHARS_MIN, Settings::MIN_CHARS_MAX, $settings );
		self::flag( 'show_prices', __( 'Prices', 'creative-search' ), __( 'Show each suggestion’s price.', 'creative-search' ), $settings );
		self::flag( 'show_stock', __( 'Stock', 'creative-search' ), __( 'Show whether each suggestion is in stock.', 'creative-search' ), $settings );
		self::flag( 'show_terms', __( 'Categories, tags and brands', 'creative-search' ), __( 'Suggest categories, tags and brands after the products.', 'creative-search' ), $settings );
		self::flag( 'label_visible', __( 'Label', 'creative-search' ), __( 'Show the search field’s label (it is always there for screen readers).', 'creative-search' ), $settings );
		echo '<tr><th scope="row"><label for="csearch-placeholder">' . esc_html__( 'Placeholder', 'creative-search' ) . '</label></th><td><input type="text" id="csearch-placeholder" name="placeholder" class="regular-text" value="' . esc_attr( Cast::str( $settings['placeholder'] ) ) . '" aria-describedby="csearch-placeholder-help"><p class="description" id="csearch-placeholder-help">' . esc_html__( 'Empty uses “Search products…” in the shopper’s language.', 'creative-search' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="csearch-panel-position">' . esc_html__( 'Suggestions open', 'creative-search' ) . '</label></th><td><select id="csearch-panel-position" name="panel_position">';
		foreach (
			array(
				Settings::PANEL_AUTO  => __( 'Below, or above when a phone’s keyboard would cover them', 'creative-search' ),
				Settings::PANEL_BELOW => __( 'Below the field', 'creative-search' ),
				Settings::PANEL_ABOVE => __( 'Above the field', 'creative-search' ),
			) as $value => $label
		) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $value, $settings['panel_position'], false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';
		self::flag( 'recent_searches', __( 'Recent searches', 'creative-search' ), __( 'Offer the shopper’s own last searches, kept in their browser only.', 'creative-search' ), $settings );
		self::flag( 'theme_form_takeover', __( 'Theme search form', 'creative-search' ), __( 'Replace the theme’s own search form with this search bar.', 'creative-search' ), $settings );
		echo '</tbody></table>';
		submit_button( __( 'Save settings', 'creative-search' ) );
		echo '</form>';
	}

	/**
	 * The custom fields row (D-119): a box for each meta key the products use (and each one already chosen), and
	 * a field for any other key, ACF field names included.
	 *
	 * @param array<string, mixed> $settings The settings.
	 */
	private function customFields( array $settings ): void {
		$chosen = Cast::strings( $settings['custom_fields'] ?? array() );
		$keys   = array_values( array_unique( array_merge( $chosen, $this->metaKeys->productKeys( self::META_KEYS_SHOWN ) ) ) );
		echo '<tr><th scope="row">' . esc_html__( 'Custom fields', 'creative-search' ) . '</th><td><fieldset aria-describedby="csearch-custom-fields-help"><legend class="screen-reader-text">' . esc_html__( 'Custom fields', 'creative-search' ) . '</legend><input type="hidden" name="custom_fields[]" value="">';
		foreach ( $keys as $i => $key ) {
			$id = 'csearch-custom-field-' . $i;
			echo '<label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="custom_fields[]" value="' . esc_attr( $key ) . '"' . checked( in_array( $key, $chosen, true ), true, false ) . '> <code>' . esc_html( $key ) . '</code></label><br>';
		}
		echo '<label for="csearch-custom-fields-other">' . esc_html__( 'Other fields', 'creative-search' ) . '</label> <input type="text" id="csearch-custom-fields-other" name="custom_fields_other" class="regular-text" value="">';
		echo '<p class="description" id="csearch-custom-fields-help">' . esc_html(
			sprintf(
				/* translators: %s: the most custom fields that can be searched */
				__( 'Search these product fields too, such as an Advanced Custom Fields field by its name. Separate other names with commas; up to %s fields. Rebuild the index after a change.', 'creative-search' ),
				number_format_i18n( Settings::CUSTOM_FIELDS_MAX )
			)
		) . '</p></fieldset></td></tr>';
	}

	/**
	 * A yes/no setting row: a hidden "no" before the checkbox, so an unticked box saves "no".
	 *
	 * @param string               $key      Setting key.
	 * @param string               $title    Row title.
	 * @param string               $label    Checkbox label.
	 * @param array<string, mixed> $settings The settings.
	 */
	private static function flag( string $key, string $title, string $label, array $settings ): void {
		$id = 'csearch-' . str_replace( '_', '-', $key );
		echo '<tr><th scope="row">' . esc_html( $title ) . '</th><td><input type="hidden" name="' . esc_attr( $key ) . '" value="no"><label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="yes"' . checked( 'yes', $settings[ $key ] ?? 'no', false ) . '> ' . esc_html( $label ) . '</label></td></tr>';
	}

	/**
	 * A number setting row.
	 *
	 * @param string               $key      Setting key.
	 * @param string               $label    Label.
	 * @param int                  $min      Lowest.
	 * @param int                  $max      Highest.
	 * @param array<string, mixed> $settings The settings.
	 */
	private static function number( string $key, string $label, int $min, int $max, array $settings ): void {
		$id = 'csearch-' . str_replace( '_', '-', $key );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td><input type="number" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '" step="1" class="small-text" value="' . esc_attr( Cast::str( $settings[ $key ] ?? '' ) ) . '"></td></tr>';
	}
}
