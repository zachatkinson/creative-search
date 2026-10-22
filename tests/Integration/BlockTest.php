<?php
/**
 * Search block tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

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
		self::assertSame( '6 suggestions', $context['i18n']['counts'][6] );
		self::assertSame( '1 suggestion', $context['i18n']['counts'][1] );
		self::assertSame( 'No suggestions', $context['i18n']['counts'][0] );
		self::assertSame( array( 'See %1$s result for %2$s', 'See all %1$s results for %2$s' ), $context['i18n']['seeAll'], 'one result, then many (D-077)' );
		self::assertSame( array( 'category', 'tag', 'brand' ), array_keys( $context['i18n']['taxonomies'] ), 'the term types /suggest returns' );
		self::assertSame( '', $context['answered'] );
		self::assertStringContainsString( 'class="csearch-label csearch-sr"', do_blocks( '<!-- wp:creative-search/search {"labelVisible":false} /-->' ), 'a visually hidden label' );
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
}
