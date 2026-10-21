<?php
/**
 * Activation and boot tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Container;
use CreativeBranding\Search\Install\Activator;
use CreativeBranding\Search\Plugin;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Options;
use CreativeBranding\Search\Tests\Support\IntegrationTestCase;

/**
 * Activation creates the schema and the default settings and nothing else; the plugin boots once.
 */
final class ActivationTest extends IntegrationTestCase {

	public function test_activation_writes_default_settings_once(): void {
		delete_option( Options::SETTINGS );
		Activator::activate();
		self::assertSame( Settings::defaults(), get_option( Options::SETTINGS ) );

		update_option( Options::SETTINGS, array( 'retention_days' => 30 ) );
		Activator::activate();
		self::assertSame( array( 'retention_days' => 30 ), get_option( Options::SETTINGS ), 're-activation keeps the owner\'s settings' );
	}

	public function test_settings_are_autoloaded(): void {
		delete_option( Options::SETTINGS );
		Activator::activate();
		self::assertArrayHasKey( Options::SETTINGS, wp_load_alloptions( true ) );
	}

	public function test_activation_schedules_nothing_and_indexes_nothing(): void {
		global $wpdb;
		Activator::activate();
		self::assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'csearch_documents' ) ), 'the first build is the owner\'s' );
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			self::assertSame( array(), as_get_scheduled_actions( array( 'group' => Options::QUEUE_GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 5 ), 'ids' ), 'activation queued work' );
		}
	}

	public function test_plugin_booted_with_a_container_and_constants(): void {
		self::assertTrue( defined( 'CSEARCH_VERSION' ) );
		self::assertSame( '0.1.0', CSEARCH_VERSION );
		self::assertSame( '10.0', CSEARCH_MIN_WC );
		self::assertInstanceOf( Container::class, Plugin::container() );
		self::assertSame( Plugin::container(), Plugin::container() );
		self::assertSame( array(), Plugin::container()->hookables(), 'milestone 1 attaches no hooks' );
	}

	public function test_plugin_headers_match_the_spec(): void {
		$data = get_plugin_data( CSEARCH_FILE, false, false );
		self::assertSame( 'Creative Search', $data['Name'] );
		self::assertSame( 'creative-search', $data['TextDomain'] );
		self::assertSame( '/languages', $data['DomainPath'] );
		self::assertSame( '7.0', $data['RequiresWP'] );
		self::assertSame( '8.5', $data['RequiresPHP'] );
		self::assertSame( 'woocommerce', $data['RequiresPlugins'] );
		self::assertSame( CSEARCH_VERSION, $data['Version'] );
	}

	public function test_woocommerce_is_the_supported_version(): void {
		self::assertTrue( version_compare( (string) WC_VERSION, CSEARCH_MIN_WC, '>=' ) );
	}

	public function test_hpos_compatibility_is_declared(): void {
		$plugin = plugin_basename( CSEARCH_FILE );
		$compat = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_plugins_for_feature( 'custom_order_tables', false );
		self::assertContains( $plugin, $compat['compatible'] );
		$blocks = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_plugins_for_feature( 'cart_checkout_blocks', false );
		self::assertContains( $plugin, $blocks['compatible'] );
	}
}
