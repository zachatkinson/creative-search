<?php
/**
 * Settings tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Settings;

use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Options;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every default is the spec's; every input is coerced to its type and range; unknown keys are dropped.
 */
final class SettingsTest extends TestCase {

	protected function setUp(): void {
		csearch_test_reset_options();
		Settings::flush();
	}

	public function test_defaults_match_the_spec(): void {
		$d = Settings::defaults();
		self::assertSame( 'no', $d['recent_searches'], 'off by default for privacy (D-004)' );
		self::assertSame( 90, $d['retention_days'] );
		self::assertSame( 6, $d['suggest_limit'] );
		self::assertSame( 3, $d['suggest_terms'] );
		self::assertSame( 2, $d['min_chars'] );
		self::assertSame( 8, $d['panel_max'], 'Baymard: at most 10 on a desktop (D-136)' );
		self::assertSame( 5, $d['panel_max_phone'], 'Baymard: 4 to 8 on a phone (D-136)' );
		self::assertSame( 'no', $d['log_staff_searches'], 'the team\'s searches stay out of Analytics (D-139)' );
		self::assertSame( 'no', $d['in_stock_first'], 'today\'s score until the shop chooses it (D-138)' );
		self::assertSame( 'no', $d['suggest_pages_phone'], 'a phone\'s rows go to products (D-137)' );
		self::assertSame( 'yes', $d['fallback_to_default_language'] );
		self::assertSame( 'no', $d['hidden_by_sku'], 'D-018' );
		self::assertSame( 'auto', $d['panel_position'] );
		self::assertSame( array(), $d['languages'] );
		self::assertSame( array(), $d['boost_rules'] );
		self::assertSame( array(), $d['fallback_products'] );
		self::assertSame( array(), $d['stats'] );
		self::assertSame( 'no', $d['theme_form_takeover'] );
		self::assertSame( '', $d['placeholder'] );
		self::assertSame( 0, $d['help_page'], 'no help link until the owner picks a page (D-096)' );
		self::assertSame( 0, $d['guide_page'] );
		self::assertSame( '', $d['help_text'], 'empty reads the translated default' );
		self::assertSame( '', $d['guide_text'] );
		self::assertSame( 'no', $d['index_posts'], 'posts and pages off by default (D-093)' );
		self::assertSame( 'no', $d['index_pages'] );
		foreach ( array( 'index_long_description', 'index_tags', 'index_variation_skus', 'show_prices', 'show_stock', 'show_terms', 'label_visible' ) as $on ) {
			self::assertSame( 'yes', $d[ $on ], $on );
		}
		self::assertSame( 'yes', $d['category_redirect'], 'a category name opens the category (D-102)' );
		self::assertSame( array(), $d['filter_attributes'], 'no attribute filters until the owner ticks one (D-101)' );
		self::assertSame( array(), $d['custom_fields'], 'no custom fields until the owner picks one (D-119)' );
		self::assertSame( array(), $d['query_redirects'], 'no redirect until the owner adds one (D-108)' );
		self::assertSame( array(), $d['pinned_products'], 'no pins until the owner adds some (D-110)' );
		self::assertSame( 'yes', $d['suggest_queries'], 'suggested searches on (D-112)' );
		self::assertSame( 'yes', $d['suggest_scopes'], 'searches within a category on (D-113)' );
		self::assertSame( 'no', $d['suggest_cart'], 'add to cart off until the shop chooses it (D-115)' );
		self::assertSame( 'yes', $d['analytics_events'], 'the browser events on; nothing leaves the page (D-116)' );
		self::assertCount( 42, $d, 'every key is documented in docs/SETTINGS.md' );
	}

	public function test_the_filter_attributes_are_attribute_taxonomies(): void {
		$out = Settings::sanitize(
			array(
				'filter_attributes' => array( 'pa_width', 'pa_width', 'PA_Size', 'product_cat', 'pa_', '', 7, array( 'pa_x' ), 'pa_' . str_repeat( 'a', 29 ), 'pa_' . str_repeat( 'a', 30 ) ),
				'category_redirect' => 'no',
			)
		);
		self::assertSame( array( 'pa_width', 'pa_size', 'pa_' . str_repeat( 'a', 29 ) ), $out['filter_attributes'], 'unique, lower-cased, pa_ and at most 32 characters' );
		self::assertSame( 'no', $out['category_redirect'] );
		self::assertSame( 'yes', Settings::sanitize( array( 'category_redirect' => 'yes' ) )['category_redirect'] );
		self::assertSame( array(), Settings::sanitize( array( 'filter_attributes' => 'pa_width' ) )['filter_attributes'], 'not a list' );
	}

	public function test_the_custom_fields_are_meta_keys(): void {
		$keys = array( 'material', 'material', ' grip_type ', 'acf:field.name-2', '_private', '_wp_attached_file', '_edit_lock', 'has space', 'bad/slash', '', 7, array( 'x' ), str_repeat( 'k', 64 ), str_repeat( 'k', 65 ) );
		self::assertSame( array( 'material', 'grip_type', 'acf:field.name-2', '_private', str_repeat( 'k', 64 ) ), Settings::sanitize( array( 'custom_fields' => $keys ) )['custom_fields'], 'unique, trimmed, a meta key of 64 characters at most, not WordPress\'s own' );
		$many = array_map( static fn( int $n ): string => 'field_' . $n, range( 1, 25 ) );
		self::assertSame( array_slice( $many, 0, Settings::CUSTOM_FIELDS_MAX ), Settings::sanitizeMetaKeys( $many ), '20 at most' );
		self::assertSame( array(), Settings::sanitize( array( 'custom_fields' => 'material' ) )['custom_fields'], 'not a list' );
	}

	public function test_the_no_results_links_keep_a_page_id_and_plain_wording(): void {
		$out = Settings::sanitize(
			array(
				'help_page'  => '42',
				'help_text'  => '  Questions? <b>Ask us</b>  ',
				'guide_page' => -3,
				'guide_text' => str_repeat( 'é', Settings::LINK_TEXT_MAX + 5 ),
			)
		);
		self::assertSame( 42, $out['help_page'] );
		self::assertSame( 'Questions? Ask us', $out['help_text'], 'plain text, trimmed' );
		self::assertSame( 0, $out['guide_page'], 'never negative' );
		self::assertSame( Settings::LINK_TEXT_MAX, mb_strlen( $out['guide_text'] ), 'capped in characters' );
		self::assertSame( 0, Settings::sanitize( array( 'help_page' => 'contact' ) )['help_page'] );
		self::assertSame( array( 'index_posts' => 'yes' ), array_intersect_key( Settings::sanitize( array( 'index_posts' => '1' ) ), array( 'index_posts' => 1 ) ) );
	}

	public function test_default_field_weights_are_the_spec_table(): void {
		self::assertSame(
			array(
				'name'      => 10,
				'sku'       => 10,
				'brand'     => 6,
				'category'  => 5,
				'attribute' => 5,
				'tag'       => 3,
				'short'     => 2,
				'long'      => 1,
				'custom'    => 4,
			),
			Settings::defaultFieldWeights()
		);
		self::assertSame( Settings::defaultFieldWeights(), Settings::defaults()['fields'] );
		self::assertSame( array( 'name', 'sku', 'short', 'long', 'category', 'tag', 'brand', 'attribute', 'custom' ), Settings::FIELDS, 'field ids 1 to 9 in the postings table' );
	}

	public function test_sanitize_of_an_empty_array_is_the_defaults(): void {
		self::assertSame( Settings::defaults(), Settings::sanitize( array() ) );
	}

	public function test_sanitize_drops_unknown_keys(): void {
		$out = Settings::sanitize( array( 'nonsense' => 1, 'retention_days' => 30 ) );
		self::assertArrayNotHasKey( 'nonsense', $out );
		self::assertSame( 30, $out['retention_days'] );
	}

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function yesNoCases(): array {
		return array(
			'true'      => array( true, 'yes' ),
			'false'     => array( false, 'no' ),
			'yes'       => array( 'yes', 'yes' ),
			'no'        => array( 'no', 'no' ),
			'1 string'  => array( '1', 'yes' ),
			'1 int'     => array( 1, 'yes' ),
			'on'        => array( 'on', 'yes' ),
			'true str'  => array( 'true', 'yes' ),
			'0'         => array( '0', 'no' ),
			'empty'     => array( '', 'no' ),
			'null'      => array( null, 'no' ),
			'array'     => array( array( 'yes' ), 'no' ),
			'YES upper' => array( 'YES', 'no' ),
			'2'         => array( 2, 'no' ),
		);
	}

	#[DataProvider( 'yesNoCases' )]
	public function test_yes_no( mixed $input, string $expected ): void {
		self::assertSame( $expected, Settings::yesNo( $input ) );
	}

	public function test_every_flag_goes_through_yes_no(): void {
		$flags = array( 'index_long_description', 'index_tags', 'index_variation_skus', 'fallback_to_default_language', 'hidden_by_sku', 'show_prices', 'show_stock', 'show_terms', 'label_visible', 'recent_searches', 'theme_form_takeover' );
		$out   = Settings::sanitize( array_fill_keys( $flags, 'definitely' ) );
		foreach ( $flags as $flag ) {
			self::assertSame( 'no', $out[ $flag ], $flag );
		}
		$out = Settings::sanitize( array_fill_keys( $flags, true ) );
		foreach ( $flags as $flag ) {
			self::assertSame( 'yes', $out[ $flag ], $flag );
		}
	}

	/**
	 * @return array<string, array{0: string, 1: mixed, 2: int}>
	 */
	public static function integerRanges(): array {
		return array(
			'retention low'     => array( 'retention_days', 1, 7 ),
			'retention floor'   => array( 'retention_days', 7, 7 ),
			'retention high'    => array( 'retention_days', 1000, 365 ),
			'retention ceiling' => array( 'retention_days', 365, 365 ),
			'retention string'  => array( 'retention_days', '45', 45 ),
			'retention junk'    => array( 'retention_days', 'soon', 90 ),
			'retention array'   => array( 'retention_days', array( 3 ), 90 ),
			'limit low'         => array( 'suggest_limit', 0, 1 ),
			'limit high'        => array( 'suggest_limit', 50, 10 ),
			'limit exact'       => array( 'suggest_limit', 8, 8 ),
			'limit junk'        => array( 'suggest_limit', 'many', 6 ),
			'terms low'         => array( 'suggest_terms', -1, 0 ),
			'terms high'        => array( 'suggest_terms', 9, 3 ),
			'terms junk'        => array( 'suggest_terms', null, 3 ),
			'min chars low'     => array( 'min_chars', 0, 1 ),
			'min chars high'    => array( 'min_chars', 12, 5 ),
			'min chars exact'   => array( 'min_chars', 3, 3 ),
			'min chars junk'    => array( 'min_chars', 'two', 2 ),
			'panel low'         => array( 'panel_max', 3, 4 ),
			'panel high'        => array( 'panel_max', 13, 12 ),
			'panel exact'       => array( 'panel_max', 10, 10 ),
			'panel junk'        => array( 'panel_max', 'lots', 8 ),
			'phone low'         => array( 'panel_max_phone', 2, 3 ),
			'phone high'        => array( 'panel_max_phone', 9, 8 ),
			'phone exact'       => array( 'panel_max_phone', 4, 4 ),
			'phone junk'        => array( 'panel_max_phone', array(), 5 ),
		);
	}

	#[DataProvider( 'integerRanges' )]
	public function test_integers_are_clamped( string $key, mixed $input, int $expected ): void {
		self::assertSame( $expected, Settings::sanitize( array( $key => $input ) )[ $key ] );
	}

	public function test_placeholder_is_text(): void {
		self::assertSame( 'Search the shop', Settings::sanitize( array( 'placeholder' => "  <b>Search</b> the\nshop " ) )['placeholder'] );
		self::assertSame( '', Settings::sanitize( array( 'placeholder' => array( 'x' ) ) )['placeholder'] );
	}

	public function test_panel_position_is_an_allow_list(): void {
		self::assertSame( 'above', Settings::sanitize( array( 'panel_position' => 'above' ) )['panel_position'] );
		self::assertSame( 'below', Settings::sanitize( array( 'panel_position' => 'below' ) )['panel_position'] );
		self::assertSame( 'auto', Settings::sanitize( array( 'panel_position' => 'left' ) )['panel_position'] );
	}

	public function test_field_weights_are_known_fields_clamped_to_0_100(): void {
		$out = Settings::sanitizeFieldWeights(
			array(
				'name'  => 500,
				'sku'   => -3,
				'brand' => '7',
				'tag'   => 'heavy',
				'bogus' => 9,
				'long'  => 0,
			)
		);
		self::assertSame( 100, $out['name'] );
		self::assertSame( 0, $out['sku'] );
		self::assertSame( 7, $out['brand'] );
		self::assertSame( 3, $out['tag'], 'non-numeric keeps the default' );
		self::assertSame( 0, $out['long'] );
		self::assertArrayNotHasKey( 'bogus', $out );
		self::assertSame( array_keys( Settings::defaultFieldWeights() ), array_keys( $out ), 'the order of the defaults is kept' );
		self::assertSame( Settings::defaultFieldWeights(), Settings::sanitize( array( 'fields' => 'nope' ) )['fields'] );
	}

	public function test_field_weights_read_from_the_option(): void {
		update_option( Options::SETTINGS, array( 'fields' => array( 'name' => 20 ) ) );
		Settings::flush();
		$weights = Settings::fieldWeights();
		self::assertSame( 20, $weights['name'] );
		self::assertSame( 10, $weights['sku'] );
		update_option( Options::SETTINGS, array( 'fields' => 'corrupt' ) );
		Settings::flush();
		self::assertSame( Settings::defaultFieldWeights(), Settings::fieldWeights() );
	}

	public function test_languages_are_lower_case_codes_unique(): void {
		self::assertSame( array( 'en', 'fr_ca', 'fr-ca', 'pt-br2' ), Settings::sanitizeLanguages( array( 'EN', ' fr_CA ', 'fr-ca', 'en', 'x', '-bad', 'toolongcode9', 'pt-br2', 7, array( 'fr' ), null ) ) );
		self::assertSame( array(), Settings::sanitize( array( 'languages' => 'fr' ) )['languages'], 'a string is not a list' );
	}

	public function test_boost_rules_need_taxonomy_term_and_boost_within_range(): void {
		$out = Settings::sanitizeBoostRules(
			array(
				array( 'taxonomy' => 'product_cat', 'term_id' => '12', 'boost' => 50 ),
				array( 'taxonomy' => 'Product Brand!', 'term_id' => 3, 'boost' => -40 ),
				array( 'taxonomy' => '', 'term_id' => 3, 'boost' => 1 ),
				array( 'taxonomy' => 'product_tag', 'term_id' => 0, 'boost' => 1 ),
				array( 'taxonomy' => 'product_tag', 'term_id' => 5 ),
				'junk',
				array( 'taxonomy' => 'product_tag', 'term_id' => 9, 'boost' => '2' ),
			)
		);
		self::assertSame(
			array(
				array( 'taxonomy' => 'product_cat', 'term_id' => 12, 'boost' => 10 ),
				array( 'taxonomy' => 'productbrand', 'term_id' => 3, 'boost' => -10 ),
				array( 'taxonomy' => 'product_tag', 'term_id' => 9, 'boost' => 2 ),
			),
			$out
		);
		self::assertSame( $out, Settings::sanitize( array( 'boost_rules' => array( array( 'taxonomy' => 'product_cat', 'term_id' => '12', 'boost' => 50 ), array( 'taxonomy' => 'Product Brand!', 'term_id' => 3, 'boost' => -40 ), array( 'taxonomy' => 'product_tag', 'term_id' => 9, 'boost' => '2' ) ) ) )['boost_rules'] );
	}

	public function test_redirects_need_a_query_and_an_address(): void {
		$out = Settings::sanitizeRedirects(
			array(
				array( 'query' => ' Size <b>guide</b> ', 'lang' => 'EN', 'url' => ' https://shop.test/size-guide/ ' ),
				array( 'query' => 'returns', 'url' => '/returns/' ),
				array( 'query' => '', 'url' => '/x/' ),
				array( 'query' => 'bad', 'url' => 'javascript:alert(1)' ),
				array( 'query' => 'none' ),
				array( 'query' => 'odd lang', 'lang' => '-x', 'url' => '/y/' ),
				'junk',
				array( 'query' => str_repeat( 'a', 250 ), 'lang' => array( 'fr' ), 'url' => '/' . str_repeat( 'b', 3000 ) ),
			)
		);
		self::assertSame( array( 'query' => 'Size guide', 'lang' => 'en', 'url' => 'https://shop.test/size-guide/' ), $out[0] );
		self::assertSame( array( 'query' => 'returns', 'lang' => '', 'url' => '/returns/' ), $out[1], 'no language is every language' );
		self::assertSame( array( 'query' => 'odd lang', 'lang' => '', 'url' => '/y/' ), $out[2] );
		self::assertSame( 200, mb_strlen( $out[3]['query'] ) );
		self::assertSame( '', $out[3]['lang'] );
		self::assertSame( Settings::RULE_URL_MAX, strlen( $out[3]['url'] ) );
		self::assertCount( 4, $out );
		$many = array_fill( 0, 205, array( 'query' => 'q', 'url' => '/q/' ) );
		self::assertCount( Settings::QUERY_RULES_MAX, Settings::sanitizeRedirects( $many ), 'two hundred at most' );
		self::assertSame( array( $out[1] ), Settings::sanitize( array( 'query_redirects' => array( array( 'query' => 'returns', 'url' => '/returns/' ) ) ) )['query_redirects'] );
		self::assertSame( array(), Settings::sanitize( array( 'query_redirects' => 'x' ) )['query_redirects'] );
		self::assertSame( array(), Settings::defaults()['query_redirects'] );
	}

	public function test_pins_need_a_query_and_up_to_five_products(): void {
		$out = Settings::sanitizePins(
			array(
				array( 'query' => 'grip tape', 'lang' => 'fr', 'ids' => array( '9', 3, 9, 0, 4, 5, 6, 7, 8 ) ),
				array( 'query' => 'none', 'ids' => array() ),
				array( 'query' => 'no list', 'ids' => '5' ),
				array( 'query' => '  ', 'ids' => array( 1 ) ),
				array( 'ids' => array( 1 ) ),
				'junk',
			)
		);
		self::assertSame( array( array( 'query' => 'grip tape', 'lang' => 'fr', 'ids' => array( 9, 3, 4, 5, 6 ) ) ), $out, 'in order, without repeats, five at most' );
		$many = array_fill( 0, 201, array( 'query' => 'q', 'ids' => array( 1 ) ) );
		self::assertCount( Settings::QUERY_RULES_MAX, Settings::sanitizePins( $many ) );
		self::assertSame( $out, Settings::sanitize( array( 'pinned_products' => array( array( 'query' => 'grip tape', 'lang' => 'fr', 'ids' => array( 9, 3, 4, 5, 6 ) ) ) ) )['pinned_products'] );
		self::assertSame( array(), Settings::sanitize( array( 'pinned_products' => 'x' ) )['pinned_products'] );
		self::assertSame( array(), Settings::defaults()['pinned_products'] );
	}

	public function test_ids_are_positive_unique_integers_in_order(): void {
		self::assertSame( array( 5, 7, 2 ), Settings::sanitizeIds( array( '5', 7, 5, 0, -1, 'x', 2, array( 9 ), null, 2.9 ) ) );
		self::assertSame( array( 5 ), Settings::sanitize( array( 'fallback_products' => array( 5 ) ) )['fallback_products'] );
	}

	public function test_stats_are_days_of_named_counts(): void {
		$out = Settings::sanitizeStats(
			array(
				'2026-10-21' => array( 'searches' => '12', 'zero' => -4, 'Bad Name' => 3, 'x' => 'many' ),
				'yesterday'  => array( 'searches' => 1 ),
				'2026-10-20' => 'nope',
				5            => array( 'searches' => 1 ),
			)
		);
		self::assertSame( array( '2026-10-21' => array( 'searches' => 12, 'zero' => 0 ) ), $out );
		self::assertSame( $out, Settings::sanitize( array( 'stats' => array( '2026-10-21' => array( 'searches' => '12', 'zero' => -4 ) ) ) )['stats'] );
	}

	public function test_get_set_and_flush(): void {
		self::assertSame( 90, Settings::get( 'retention_days' ) );
		self::assertNull( Settings::get( 'nothing' ) );
		Settings::set( 'retention_days', 30 );
		self::assertSame( 30, Settings::get( 'retention_days' ) );
		self::assertSame( 30, get_option( Options::SETTINGS )['retention_days'] );
		self::assertSame( 'yes', get_option( Options::SETTINGS )['show_prices'], 'set() writes the merged array' );
		Settings::flush();
		self::assertSame( 30, Settings::get( 'retention_days' ), 're-read from the option' );
		update_option( Options::SETTINGS, 'corrupt' );
		Settings::flush();
		self::assertSame( Settings::defaults(), Settings::all(), 'a corrupt option reads as the defaults' );
	}

	public function test_is_on_and_int(): void {
		self::assertTrue( Settings::isOn( 'show_prices' ) );
		self::assertFalse( Settings::isOn( 'recent_searches' ) );
		self::assertFalse( Settings::isOn( 'nothing' ) );
		self::assertSame( 6, Settings::int( 'suggest_limit' ) );
		Settings::set( 'suggest_limit', 'eight' );
		self::assertSame( 6, Settings::int( 'suggest_limit' ), 'the default when the stored value is not numeric' );
		Settings::set( 'suggest_limit', '8' );
		self::assertSame( 8, Settings::int( 'suggest_limit' ) );
		self::assertSame( 0, Settings::int( 'placeholder' ), 'a non-numeric default reads as 0' );
		self::assertSame( 0, Settings::int( 'nothing' ) );
	}

	public function test_retention_days_is_clamped_on_read(): void {
		Settings::set( 'retention_days', 2 );
		self::assertSame( 7, Settings::retentionDays() );
		Settings::set( 'retention_days', 900 );
		self::assertSame( 365, Settings::retentionDays() );
		Settings::set( 'retention_days', 'forever' );
		self::assertSame( 90, Settings::retentionDays() );
	}

	public function test_coercions(): void {
		self::assertSame( 4, Settings::toInt( '4', 9 ) );
		self::assertSame( 4, Settings::toInt( 4.7, 9 ) );
		self::assertSame( 9, Settings::toInt( 'four', 9 ) );
		self::assertSame( 9, Settings::toInt( null, 9 ) );
		self::assertSame( 9, Settings::toInt( array( 4 ), 9 ) );
		self::assertSame( '4', Settings::toString( 4 ) );
		self::assertSame( '1', Settings::toString( true ) );
		self::assertSame( '', Settings::toString( array( 'a' ) ) );
		self::assertSame( '', Settings::toString( null ) );
		self::assertSame( 5, Settings::clamp( 5, 1, 10 ) );
		self::assertSame( 1, Settings::clamp( -5, 1, 10 ) );
		self::assertSame( 10, Settings::clamp( 50, 1, 10 ) );
	}
}
