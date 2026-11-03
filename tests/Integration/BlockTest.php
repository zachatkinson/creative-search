<?php
/**
 * Search block tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Block\FormOptions;
use CreativeBranding\Search\Block\Shortcode;
use CreativeBranding\Search\Block\Widget;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Tests\Support\IntegrationTestCase;
use WP_Block_Type_Registry;

/**
 * The server-rendered form: a search that works with no script, the APG combobox markup, the context the
 * script reads, the owner's defaults and the block's overrides; the same form from the shortcode, the widget
 * and the theme-form takeover.
 */
final class BlockTest extends IntegrationTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'creative-search/search' ) ) {
			do_action( 'init' );
		}
	}

	/**
	 * The interactivity context of a rendered form.
	 *
	 * @param string $html Markup.
	 * @return array<string, mixed>
	 */
	private static function context( string $html ): array {
		self::assertSame( 1, preg_match( "/data-wp-context='([^']+)'/", $html, $m ), 'a context' );
		$context = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
		self::assertIsArray( $context );
		return $context;
	}

	public function test_the_block_is_registered_from_the_build(): void {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( 'creative-search/search' );
		self::assertNotNull( $type, 'npm run build makes build/block/block.json' );
		self::assertTrue( $type->supports['interactivity'] );
		self::assertSame( Settings::LIMIT_DEFAULT, $type->attributes['limit']['default'], 'the owner\'s setting is the default' );
		self::assertNotEmpty( $type->view_script_module_ids );
	}

	public function test_the_form_works_without_script_and_carries_the_combobox(): void {
		$html = do_blocks( '<!-- wp:creative-search/search /-->' );
		self::assertStringContainsString( '<form role="search"', $html );
		self::assertStringContainsString( 'action="' . esc_url( home_url( '/' ) ) . '"', $html );
		self::assertStringContainsString( 'name="s"', $html );
		self::assertStringContainsString( '<input type="hidden" name="post_type" value="product">', $html );
		self::assertMatchesRegularExpression( '/<label class="csearch-label" for="(csearch-\d+-input)">Search products<\/label>/', $html );
		foreach ( array( 'role="combobox"', 'aria-autocomplete="list"', 'aria-expanded="false"', 'autocomplete="off"', 'enterkeyhint="search"', 'inputmode="search"', 'role="listbox"', 'aria-live="polite"', 'type="submit"' ) as $needle ) {
			self::assertStringContainsString( $needle, $html );
		}
		self::assertMatchesRegularExpression( '/aria-controls="(csearch-\d+)-listbox"[^>]*>.*id="\1-listbox"/s', $html, 'the input controls its listbox' );
		self::assertStringContainsString( 'data-wp-interactive="creative-search/search"', $html );
	}

	public function test_the_context_carries_the_routes_the_words_and_the_options(): void {
		$context = self::context( do_blocks( '<!-- wp:creative-search/search {"limit":4,"showPrices":false,"labelVisible":false} /-->' ) );
		self::assertSame( rest_url( 'csearch/v1/suggest' ), $context['suggest'] );
		self::assertSame( rest_url( 'csearch/v1/click' ), $context['click'] );
		self::assertSame( 4, $context['limit'] );
		self::assertFalse( $context['prices'] );
		self::assertSame( 2, $context['min'] );
		self::assertSame( Settings::PANEL_MAX_DEFAULT, $context['max'], 'the whole panel\'s cap (D-136)' );
		self::assertSame( Settings::PANEL_MAX_PHONE_DEFAULT, $context['maxPhone'] );
		self::assertSame( '6 suggestions', $context['i18n']['counts'][6] );
		self::assertSame( '1 suggestion', $context['i18n']['counts'][1] );
		self::assertSame( 'No suggestions', $context['i18n']['counts'][0] );
		self::assertSame( array( 'See %1$s result for %2$s', 'See all %1$s results for %2$s' ), $context['i18n']['seeAll'], 'one result, then many (D-077)' );
		self::assertSame( array( 'category', 'tag', 'brand' ), array_keys( $context['i18n']['taxonomies'] ), 'the term types /suggest returns' );
		self::assertSame( '', $context['answered'] );
		self::assertStringContainsString( 'class="csearch-label csearch-sr"', do_blocks( '<!-- wp:creative-search/search {"labelVisible":false} /-->' ), 'a visually hidden label' );
	}

	public function test_a_block_or_shortcode_sets_its_own_caps_within_their_ranges(): void {
		Settings::update(
			array(
				'panel_max'       => 10,
				'panel_max_phone' => 6,
			)
		);
		$owner = self::context( do_shortcode( '[creative_search]' ) );
		self::assertSame( array( 10, 6 ), array( $owner['max'], $owner['maxPhone'] ), 'the owner\'s caps by default' );
		$block = self::context( do_blocks( '<!-- wp:creative-search/search {"panelMax":5,"panelMaxPhone":4} /-->' ) );
		self::assertSame( array( 5, 4 ), array( $block['max'], $block['maxPhone'] ) );
		$short = self::context( do_shortcode( '[creative_search panel_max="40" panel_max_phone="1"]' ) );
		self::assertSame( array( Settings::PANEL_MAX_MAX, Settings::PANEL_MAX_PHONE_MIN ), array( $short['max'], $short['maxPhone'] ), 'clamped to the ranges' );
		$type = WP_Block_Type_Registry::get_instance()->get_registered( 'creative-search/search' );
		self::assertSame( Settings::PANEL_MAX_DEFAULT, $type->attributes['panelMax']['default'] ?? null, 'the editor starts from the owner\'s cap, read when the block registers' );
		self::assertSame( Settings::PANEL_MAX_PHONE_DEFAULT, $type->attributes['panelMaxPhone']['default'] ?? null );
	}

	public function test_the_shortcode_widget_and_takeover_render_the_same_form(): void {
		$short = do_shortcode( '[' . Shortcode::TAG . ']' );
		self::assertStringContainsString( 'role="combobox"', $short );

		ob_start();
		the_widget( Widget::class, array( 'title' => 'Find a deck' ), array( 'before_title' => '<h2>', 'after_title' => '</h2>' ) );
		$widget = (string) ob_get_clean();
		self::assertStringContainsString( '<h2>Find a deck</h2>', $widget );
		self::assertStringContainsString( 'role="combobox"', $widget );

		self::assertStringNotContainsString( 'role="combobox"', get_search_form( array( 'echo' => false ) ), 'the theme\'s own form by default' );
		Settings::set( 'theme_form_takeover', 'yes' );
		self::assertStringContainsString( 'role="combobox"', get_search_form( array( 'echo' => false ) ) );
	}

	public function test_each_form_on_a_page_has_its_own_ids(): void {
		$html = do_blocks( '<!-- wp:creative-search/search /--><!-- wp:creative-search/search /-->' );
		preg_match_all( '/id="(csearch-\d+-input)"/', $html, $m );
		self::assertCount( 2, array_unique( $m[1] ) );
	}

	public function test_placeholder_is_escaped(): void {
		$html = do_blocks( '<!-- wp:creative-search/search {"placeholder":""><script>alert(1)</script>"} /-->' );
		self::assertStringNotContainsString( '<script>alert(1)', $html );
	}

	public function test_on_a_search_page_the_field_keeps_the_query_escaped(): void {
		$this->go_to( add_query_arg( array( 's' => 'deck "><script>alert(1)</script>', 'post_type' => 'product' ), home_url( '/' ) ) );
		$html = do_blocks( '<!-- wp:creative-search/search /-->' );
		self::assertStringNotContainsString( '<script>alert(1)', $html );
		self::assertStringContainsString( 'value="deck &quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $html, 'the query in the field (Baymard; handoff item 2)' );
		self::assertMatchesRegularExpression( '/<button type="button" class="csearch-clear" data-wp-bind/', $html, 'the clear button shown' );
		$context = self::context( $html );
		self::assertSame( 'deck "><script>alert(1)</script>', $context['query'] );
		self::assertSame( $context['query'], $context['typed'] );
		self::assertFalse( $context['panel'], 'the panel stays closed' );
		$this->go_to( home_url( '/' ) );
		$html = do_blocks( '<!-- wp:creative-search/search /-->' );
		self::assertStringContainsString( 'value=""', $html );
		self::assertStringContainsString( 'class="csearch-clear" hidden', $html );
	}

	public function test_button_mode_opens_the_same_form_in_a_dialog(): void {
		$html = do_blocks( '<!-- wp:creative-search/search {"mode":"button"} /-->' );
		self::assertMatchesRegularExpression( '/<button type="button" id="csearch-[^"]+-open" class="csearch-open" aria-haspopup="dialog" aria-controls="csearch-[^"]+-dialog" data-wp-on--click="actions.openDialog">/', $html );
		self::assertStringContainsString( '<span class="csearch-sr">Search</span></button>', $html, 'the button\'s name' );
		self::assertMatchesRegularExpression( '/<dialog id="csearch-[^"]+-dialog" class="csearch-dialog" aria-label="Search"/', $html );
		self::assertStringContainsString( 'data-wp-on-window--popstate="actions.dialogBack"', $html, 'Back closes it' );
		self::assertStringContainsString( 'Close search', $html );
		self::assertLessThan( strpos( $html, '</dialog>' ), strpos( $html, '<form role="search"' ), 'the form is inside the dialog' );
		self::assertStringContainsString( 'name="s"', $html, 'the same form, so it searches with no script' );
		self::assertStringNotContainsString( 'csearch-backdrop', $html, 'the dialog has its own backdrop' );
		self::assertTrue( self::context( $html )['dialog'] );
		self::assertFalse( self::context( do_blocks( '<!-- wp:creative-search/search /-->' ) )['dialog'] );
		self::assertStringContainsString( 'csearch-dialog', do_shortcode( '[creative_search mode="button"]' ), 'the shortcode too' );
		self::assertStringNotContainsString( 'csearch-dialog', do_blocks( '<!-- wp:creative-search/search {"mode":"other"} /-->' ) );
	}

	public function test_the_page_dims_unless_the_form_says_not_to(): void {
		// Field mode dims by default; the block and the shortcode can each turn it off, the filter has the last word (D-127).
		self::assertStringContainsString( 'csearch-backdrop', do_blocks( '<!-- wp:creative-search/search /-->' ) );
		self::assertStringContainsString( 'csearch-backdrop', do_blocks( '<!-- wp:creative-search/search {"dimPage":true} /-->' ) );
		self::assertStringNotContainsString( 'csearch-backdrop', do_blocks( '<!-- wp:creative-search/search {"dimPage":false} /-->' ) );
		self::assertStringNotContainsString( 'csearch-backdrop', do_shortcode( '[creative_search dim_page="no"]' ) );
		self::assertStringContainsString( 'csearch-backdrop', do_shortcode( '[creative_search]' ) );
		self::assertTrue( FormOptions::fromSettings()->dimPage );

		$seen = array();
		$spy  = static function ( bool $dim ) use ( &$seen ): bool {
			$seen[] = $dim;
			return true;
		};
		add_filter( 'csearch_dim_page', $spy );
		$forced = do_blocks( '<!-- wp:creative-search/search {"dimPage":false} /-->' );
		remove_filter( 'csearch_dim_page', $spy );
		self::assertSame( array( false ), $seen, 'the filter hears the block\'s choice' );
		self::assertStringContainsString( 'csearch-backdrop', $forced, 'and has the last word' );
	}

	public function test_the_panel_can_be_capped_by_the_script(): void {
		// The script caps the panel in a pinned header or on a touch screen (D-126); the server starts it uncapped.
		$html    = do_blocks( '<!-- wp:creative-search/search /-->' );
		$context = self::context( $html );
		self::assertFalse( $context['capped'] );
		self::assertSame( '', $context['panelMax'] );
		self::assertStringContainsString( 'data-wp-class--is-capped="context.capped" data-wp-style--max-height="context.panelMax"', $html );
	}

	public function test_the_render_is_the_same_for_every_visitor_and_holds_no_nonce(): void {
		// A page cached for days must still search (Skatehouse handoff item 4): nothing per user, nothing that expires.
		foreach ( array( '<!-- wp:creative-search/search /-->', '<!-- wp:creative-search/search {"mode":"button"} /-->' ) as $block ) {
			wp_set_current_user( 0 );
			$guest = preg_replace( '/csearch-\d+/', 'csearch-N', do_blocks( $block ) );
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
			$admin = preg_replace( '/csearch-\d+/', 'csearch-N', do_blocks( $block ) );
			wp_set_current_user( 0 );
			self::assertSame( $guest, $admin, $block );
			self::assertDoesNotMatchRegularExpression( '/nonce|_wpnonce|X-WP-Nonce/i', (string) $guest );
		}
	}
}
