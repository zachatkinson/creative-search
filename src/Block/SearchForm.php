<?php
/**
 * The search form's markup.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Block;

use CreativeBranding\Search\Results\QueryRouter;
use CreativeBranding\Search\Results\ResultsView;
use CreativeBranding\Search\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one place the form's markup lives. The block, the `[creative_search]` shortcode, the widget and the
 * theme-form takeover all render through here, so the four look and behave the same and a change is made
 * once. The markup is a complete search form with no script (it submits `s` and `post_type=product` to
 * the shop's front page) that the Interactivity API turns into the APG combobox the spec describes.
 */
final class SearchForm {

	/**
	 * The Interactivity API store namespace.
	 */
	public const STORE = 'creative-search/search';

	/**
	 * Rows the panel can hold at most: ten in all on a desktop (D-092), plus room for the counts to stay right
	 * if a theme widens the budget.
	 */
	private const MAX_SUGGESTIONS = 15;

	/**
	 * Recent searches kept, at most.
	 */
	private const MAX_RECENT = 5;

	/**
	 * Constructor.
	 *
	 * @param BlockAssets $assets The stylesheet and the script module.
	 */
	public function __construct( private readonly BlockAssets $assets ) {
	}

	/**
	 * Render one form.
	 *
	 * @param FormOptions $options The resolved options.
	 * @param string      $wrapper The wrapper's attributes, already escaped (`get_block_wrapper_attributes()`
	 *                             for a block); empty for the block's class alone.
	 */
	public function render( FormOptions $options, string $wrapper = '' ): string {
		$this->assets->enqueue();

		$id      = wp_unique_id( 'csearch-' );
		$input   = $id . '-input';
		$listbox = $id . '-listbox';
		$label   = __( 'Search products', 'creative-search' );

		$placeholder = '' !== $options->placeholder
			? $options->placeholder
			: _x( 'Search products…', 'the search field placeholder', 'creative-search' );

		if ( '' === $wrapper ) {
			$wrapper = 'class="wp-block-creative-search-search"';
		}

		$labelClass = $options->labelVisible ? 'csearch-label' : 'csearch-label csearch-sr';
		$current    = $this->current();

		$dialog = FormOptions::BUTTON === $options->mode;
		$html   = '<div ' . $wrapper . ' data-wp-interactive="' . esc_attr( self::STORE ) . '" ' . wp_interactivity_data_wp_context( $this->context( $id, $options, $current ) ) . ( $dialog ? ' data-csearch-mode="button"' : ' data-wp-class--is-dimmed="context.panel"' ) . ' data-wp-on-document--pointerdown="actions.outside">';
		if ( $dialog ) {
			$html .= $this->dialogStart( $id );
		}
		$html .= '<form role="search" class="csearch-form" method="get" action="' . esc_url( home_url( '/' ) ) . '" data-wp-on--submit="actions.submit">';
		$html .= '<label class="' . esc_attr( $labelClass ) . '" for="' . esc_attr( $input ) . '">' . esc_html( $label ) . '</label>';
		$html .= '<div class="csearch-field">';
		$html .= '<input id="' . esc_attr( $input ) . '" class="csearch-input" type="text" name="s" value="' . esc_attr( $current ) . '"'
			. ' placeholder="' . esc_attr( $placeholder ) . '"'
			. ' role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="' . esc_attr( $listbox ) . '"'
			. ' autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="search" inputmode="search"'
			. ' data-wp-bind--value="context.query" data-wp-bind--aria-expanded="context.open" data-wp-bind--aria-activedescendant="context.activeId"'
			. ' data-wp-on--input="actions.input" data-wp-on--keydown="actions.keydown" data-wp-on--focus="actions.focus">';
		$html .= '<input type="hidden" name="post_type" value="product">';
		$html .= '<button type="button" class="csearch-clear"' . ( '' === $current ? ' hidden' : '' ) . ' data-wp-bind--hidden="!context.query" data-wp-on--click="actions.clear">'
			. '<span class="csearch-sr">' . esc_html__( 'Clear search', 'creative-search' ) . '</span>'
			. '<svg class="csearch-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="24" height="24"><path d="M6.3 5 5 6.3 10.7 12 5 17.7 6.3 19l5.7-5.7 5.7 5.7 1.3-1.3-5.7-5.7L19 6.3 17.7 5 12 10.7Z"/></svg>'
			. '</button>';
		$html .= '<button type="submit" class="csearch-submit">'
			. '<span class="csearch-sr">' . esc_html_x( 'Search', 'the submit button', 'creative-search' ) . '</span>'
			. '<svg class="csearch-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="24" height="24"><path d="M10 3a7 7 0 1 0 4.2 12.6l5.6 5.6 1.4-1.4-5.6-5.6A7 7 0 0 0 10 3Zm0 2a5 5 0 1 1 0 10 5 5 0 0 1 0-10Z"/></svg>'
			. '</button>';
		$html .= '</div>';
		$html .= '<div class="csearch-live csearch-sr" aria-live="polite" aria-atomic="true" data-wp-text="context.live"></div>';
		$html .= '<div class="csearch-panel" hidden data-wp-bind--hidden="!context.panel" data-wp-class--is-above="context.above">';
		$html .= '<p class="csearch-status" hidden data-wp-bind--hidden="!context.searching">' . esc_html__( 'Searching…', 'creative-search' ) . '</p>';
		$html .= '<p class="csearch-status" hidden data-wp-bind--hidden="!context.empty">' . esc_html__( 'No suggestions', 'creative-search' ) . '</p>';
		$html .= '<p class="csearch-status csearch-corrected" hidden data-wp-bind--hidden="!context.corrected" data-wp-text="context.corrected"></p>';
		$html .= '<ul id="' . esc_attr( $listbox ) . '" class="csearch-list" role="listbox" aria-label="' . esc_attr__( 'Suggestions', 'creative-search' ) . '">';
		$html .= $this->rowTemplate();
		$html .= '</ul>';
		$html .= '</div>';
		$html .= '</form>';
		if ( $dialog ) {
			$html .= '</dialog>';
		}
		/**
		 * Whether the page dims behind the open suggestions (Baymard: give the panel visual depth; D-097). Off
		 * for a theme whose header makes the backdrop sit in the wrong place.
		 *
		 * @since 1.1.0
		 *
		 * @param bool $dim Default true.
		 */
		if ( ! $dialog && (bool) apply_filters( 'csearch_dim_page', true ) ) {
			$html .= '<div class="csearch-backdrop" aria-hidden="true" hidden data-wp-bind--hidden="!context.panel" data-wp-on--pointerdown="actions.dismiss"></div>';
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * In button mode (D-099): the icon button and the start of the modal dialog that holds the form. The dialog
	 * opens with the field focused, closes on Escape, its close button, a click on the page behind it or Back,
	 * and hands focus back to the button. With no script, the stylesheet shows the form in place
	 * (`@media (scripting: none)`), so the search still works and nothing shifts.
	 *
	 * @param string $id The instance id.
	 */
	private function dialogStart( string $id ): string {
		$search = '<svg class="csearch-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="24" height="24"><path d="M10 3a7 7 0 1 0 4.2 12.6l5.6 5.6 1.4-1.4-5.6-5.6A7 7 0 0 0 10 3Zm0 2a5 5 0 1 1 0 10 5 5 0 0 1 0-10Z"/></svg>';
		$close  = '<svg class="csearch-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="24" height="24"><path d="M6.3 5 5 6.3 10.7 12 5 17.7 6.3 19l5.7-5.7 5.7 5.7 1.3-1.3-5.7-5.7L19 6.3 17.7 5 12 10.7Z"/></svg>';
		return '<button type="button" id="' . esc_attr( $id . '-open' ) . '" class="csearch-open" aria-haspopup="dialog" aria-controls="' . esc_attr( $id . '-dialog' ) . '" data-wp-on--click="actions.openDialog">'
			. $search . '<span class="csearch-sr">' . esc_html_x( 'Search', 'the button that opens the search', 'creative-search' ) . '</span></button>'
			. '<dialog id="' . esc_attr( $id . '-dialog' ) . '" class="csearch-dialog" aria-label="' . esc_attr_x( 'Search', 'the search dialog', 'creative-search' ) . '"'
			. ' data-wp-on--close="actions.dialogClosed" data-wp-on--click="actions.dialogClick" data-wp-on-window--popstate="actions.dialogBack">'
			. '<button type="button" class="csearch-close" data-wp-on--click="actions.closeDialog">' . $close . '<span class="csearch-sr">' . esc_html__( 'Close search', 'creative-search' ) . '</span></button>';
	}

	/**
	 * The row the Interactivity API stamps out for every suggestion. Only the script fills it, so the
	 * item-scoped `state` getters are safe here (the server leaves a template alone).
	 */
	private function rowTemplate(): string {
		return '<template data-wp-each--row="context.rows" data-wp-each-key="context.row.key">'
			. '<li class="csearch-option" role="option" data-wp-bind--id="context.row.id" data-wp-bind--aria-selected="state.isActive"'
			. ' data-wp-class--is-active="state.isActive" data-wp-class--csearch-option-product="context.row.product" data-wp-class--csearch-option-term="context.row.term" data-wp-class--csearch-option-all="context.row.all" data-wp-class--csearch-option-recent="context.row.recent"'
			. ' data-wp-class--csearch-option-content="context.row.content" data-wp-class--has-heading="context.row.heading" data-wp-watch="callbacks.classes"'
			. ' data-wp-on--pointerdown="actions.hold" data-wp-on--pointermove="actions.hover" data-wp-on--click="actions.choose">'
			// The group's heading ("Pages") over its first row: shown, not read, since every row names its own type.
			. '<span class="csearch-group" aria-hidden="true" hidden data-wp-bind--hidden="!context.row.heading" data-wp-text="context.row.heading"></span>'
			. '<span class="csearch-thumb" aria-hidden="true" hidden data-wp-bind--hidden="!context.row.src">'
			. '<img alt="" loading="lazy" decoding="async" sizes="48px" data-wp-bind--src="context.row.src" data-wp-bind--srcset="context.row.srcset" data-wp-bind--width="context.row.width" data-wp-bind--height="context.row.height">'
			. '</span>'
			. '<span class="csearch-text">'
			. '<span class="csearch-name" data-wp-watch="callbacks.name"></span>'
			. '<span class="csearch-meta" hidden data-wp-bind--hidden="!context.row.meta">'
			. '<span class="csearch-price" hidden data-wp-bind--hidden="!context.row.priceHtml">' . $this->separator() . '<span data-wp-init="callbacks.price"></span></span>'
			. '<span class="csearch-stock" hidden data-wp-bind--hidden="!context.row.stock" data-wp-class--is-out="context.row.out">' . $this->separator() . '<span data-wp-text="context.row.stock"></span></span>'
			. '<span class="csearch-kind" hidden data-wp-bind--hidden="!context.row.kind">' . $this->separator() . '<span data-wp-text="context.row.kind"></span></span>'
			. '<span class="csearch-sr" hidden data-wp-bind--hidden="!context.row.type">' . $this->separator() . '<span data-wp-text="context.row.type"></span></span>'
			. '</span>'
			// What matched when the row does not show it ("Matches SKU KZ-825", D-105), on its own line.
			. '<span class="csearch-matched" hidden data-wp-bind--hidden="!context.row.matched">' . $this->separator() . '<span data-wp-text="context.row.matched"></span></span>'
			. '</span>'
			. '</li>'
			. '</template>';
	}

	/**
	 * A visually hidden separator, so an option's accessible name is its visible text read as a sentence
	 * ("KAZUMI Skate Deck, Dealer, $84.99, In stock") with no `aria-label` to drift from what is shown.
	 */
	private function separator(): string {
		/* translators: used between the parts of a suggestion's accessible name (name, price, stock); there is a space after the comma */
		return '<span class="csearch-sr">' . esc_html_x( ', ', 'between the parts of a suggestion name', 'creative-search' ) . '</span>';
	}

	/**
	 * The block's initial context: the request parameters, the options the script applies, every word the
	 * script shows (translated here, where `_n()` and the text domain are), and the empty state.
	 *
	 * @param string      $id      The instance id the row ids build on.
	 * @param FormOptions $options The resolved options.
	 * @param string      $current The query the page answers, kept in the field.
	 * @return array<string, mixed>
	 */
	private function context( string $id, FormOptions $options, string $current ): array {
		$counts = array();
		for ( $n = 0; $n <= self::MAX_SUGGESTIONS; $n++ ) {
			$counts[] = 0 === $n
				? __( 'No suggestions', 'creative-search' )
				/* translators: %s: number of suggestions in the panel */
				: sprintf( _n( '%s suggestion', '%s suggestions', $n, 'creative-search' ), number_format_i18n( $n ) );
		}
		$recent = array();
		for ( $n = 0; $n <= self::MAX_RECENT; $n++ ) {
			$recent[] = 0 === $n
				? __( 'No recent searches', 'creative-search' )
				/* translators: %s: number of recent searches shown */
				: sprintf( _n( '%s recent search', '%s recent searches', $n, 'creative-search' ), number_format_i18n( $n ) );
		}

		return array(
			'id'        => $id,
			'suggest'   => rest_url( 'csearch/v1/suggest' ),
			'click'     => rest_url( 'csearch/v1/click' ),
			'lang'      => $this->lang(),
			'min'       => $options->minChars,
			'limit'     => $options->limit,
			'prices'    => $options->showPrices,
			'stock'     => $options->showStock,
			'terms'     => $options->showTerms,
			'recent'    => $options->recentSearches,
			'position'  => $options->panelPosition,
			'i18n'      => array(
				'counts'     => $counts,
				'recents'    => $recent,
				// One result, then many: the two forms English and French need (D-077).
				'seeAll'     => array(
					/* translators: 1: number of results, 2: the query typed */
					_n( 'See %1$s result for %2$s', 'See all %1$s results for %2$s', 1, 'creative-search' ),
					/* translators: 1: number of results, 2: the query typed */
					_n( 'See %1$s result for %2$s', 'See all %1$s results for %2$s', 2, 'creative-search' ),
				),
				'inStock'    => __( 'In stock', 'creative-search' ),
				'outOfStock' => __( 'Out of stock', 'creative-search' ),
				/* translators: %1$s: the query the suggestions are for, after a word that matched nothing was left out */
				'corrected'  => __( 'Showing results for %1$s', 'creative-search' ),
				/* translators: used between the parts of a suggestion's accessible name (name, price, stock); there is a space after the comma */
				'separator'  => _x( ', ', 'between the parts of a suggestion name', 'creative-search' ),
				/* translators: 1: a category, tag or brand name, 2: "Category", "Tag" or "Brand", 3: the number of products in it */
				'termLabel'  => __( '%1$s, %2$s (%3$s)', 'creative-search' ),
				'taxonomies' => array(
					'category' => _x( 'Category', 'a suggestion row', 'creative-search' ),
					'tag'      => _x( 'Tag', 'a suggestion row', 'creative-search' ),
					'brand'    => _x( 'Brand', 'a suggestion row', 'creative-search' ),
				),
				'content'    => ResultsView::contentHeading(),
				'types'      => array(
					'post' => _x( 'Post', 'a suggestion row', 'creative-search' ),
					'page' => _x( 'Page', 'a suggestion row', 'creative-search' ),
				),
			),
			'query'     => $current,
			'typed'     => $current,
			'corrected' => '',
			'open'      => false,
			'panel'     => false,
			'activeId'  => null,
			'active'    => -1,
			'rows'      => array(),
			'live'      => '',
			'searching' => false,
			'empty'     => false,
			'answered'  => '',
			'above'     => Settings::PANEL_ABOVE === $options->panelPosition,
			'dialog'    => FormOptions::BUTTON === $options->mode,
		);
	}

	/**
	 * On a search page, the query it answers: the shopper sees what they searched for and can refine it (Baymard,
	 * "persist search queries"; D-094); on the page a query was sent to (a category, a filter), the query as typed
	 * (D-101, D-102). Empty everywhere else.
	 */
	private function current(): string {
		$from = QueryRouter::from();
		if ( '' !== $from ) {
			return $from;
		}
		return is_search() ? get_search_query( false ) : '';
	}

	/**
	 * The page's language as a 2-to-8 character code, for the `lang` parameter. The script prefers the
	 * document's `lang` attribute when it has one; this is the fallback, from the locale WordPress chose.
	 */
	private function lang(): string {
		$lang = strtolower( determine_locale() );
		$lang = preg_replace( '/[^a-z0-9_-]/', '', $lang ) ?? '';
		if ( strlen( $lang ) < 2 ) {
			return 'en';
		}
		return substr( $lang, 0, 8 );
	}
}
