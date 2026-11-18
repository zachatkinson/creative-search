<?php
/**
 * Activation and boot tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Container;
use CreativeBranding\Search\Index\Reindex;
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

	public function test_activation_queues_exactly_one_build_and_indexes_nothing(): void {
		global $wpdb;
		delete_option( Options::INDEX_STATE );
		Activator::activate();
		self::assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'csearch_documents' ) ), 'nothing indexed on the request' );
		$pending = as_get_scheduled_actions( array( 'group' => Options::QUEUE_GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 5 ) );
		self::assertSame( array( Reindex::START ), array_values( array_map( static fn( $a ): string => $a->get_hook(), $pending ) ), 'one job: the first build' );
		Activator::activate();
		self::assertCount( 1, as_get_scheduled_actions( array( 'group' => Options::QUEUE_GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 5 ), 'ids' ), 're-activation queues nothing more' );
	}

	public function test_plugin_booted_with_a_container_and_constants(): void {
		self::assertTrue( defined( 'CSEARCH_VERSION' ) );
		self::assertSame( get_plugin_data( CSEARCH_FILE, false, false )['Version'], CSEARCH_VERSION, 'the constant and the header agree' );
		self::assertSame( '10.0', CSEARCH_MIN_WC );
		self::assertInstanceOf( Container::class, Plugin::container() );
		self::assertSame( Plugin::container(), Plugin::container() );
		self::assertCount( 14, Plugin::container()->hookables(), 'index, jobs, CLI, install, REST, log, results, matches, the API, the four block entry points and the admin' );
		self::assertNotFalse( has_action( 'woocommerce_update_product' ), 'the index hooks are attached' );
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
		self::assertLessThan( 140, mb_strlen( $data['Description'], 'UTF-8' ), 'under the Plugin Handbook limit, as WordPress reads it (D-135)' );
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

	public function test_the_bundled_canadian_french_loads_from_the_domain_path(): void {
		// For an active plugin, wp-settings.php registers the `Domain Path` header's folder and WordPress loads
		// `languages/` just in time (D-076). The test suite loads the plugin from its bootstrap, not as an active
		// plugin, so the test registers the folder the way wp-settings.php does; the French browser run covers a
		// real site.
		$path     = WP_PLUGIN_DIR . '/creative-search/creative-search.php';
		$data     = get_plugin_data( $path, false, false );
		$registry = $GLOBALS['wp_textdomain_registry'];
		$paths    = new \ReflectionProperty( $registry, 'custom_paths' );
		$before   = $paths->getValue( $registry );
		$registry->set_custom_path( $data['TextDomain'], dirname( $path ) . $data['DomainPath'] );
		// switch_to_locale() needs WordPress's own fr_CA files, which the test site lacks; the locale filter does not.
		$locale = static fn(): string => 'fr_CA';
		add_filter( 'locale', $locale );
		add_filter( 'determine_locale', $locale );
		unload_textdomain( 'creative-search' );
		$french = __( 'Search products', 'creative-search' );
		// The scripts' strings: WordPress finds each JSON file by the script's path inside the plugin (D-084).
		$scripts = array();
		foreach ( array( 'build/block/editor.js', 'assets/admin/tools.js' ) as $file ) {
			wp_register_script( 'csearch-i18n-test-' . md5( $file ), plugins_url( $file, CSEARCH_FILE ), array(), CSEARCH_VERSION, true );
			$json             = load_script_textdomain( 'csearch-i18n-test-' . md5( $file ), 'creative-search', CSEARCH_PATH . 'languages' );
			$scripts[ $file ] = is_string( $json ) ? json_decode( $json, true )['locale_data']['messages'] ?? array() : array();
			wp_deregister_script( 'csearch-i18n-test-' . md5( $file ) );
		}
		remove_filter( 'locale', $locale );
		remove_filter( 'determine_locale', $locale );
		unload_textdomain( 'creative-search' );
		$paths->setValue( $registry, $before );
		self::assertSame( 'Rechercher des produits', $french );
		self::assertSame( 'Search products', __( 'Search products', 'creative-search' ), 'English again' );
		self::assertSame( array( 'Texte indicatif' ), $scripts['build/block/editor.js']['Placeholder'] ?? null, 'the editor script in French' );
		self::assertArrayHasKey( '%1$s of %2$s product', $scripts['assets/admin/tools.js'], 'the Tools script in French' );
	}

	public function test_the_matrix_leg_is_the_one_asked_for(): void {
		// tools/integration.sh's switches (D-083) must change what they say, or a leg proves nothing.
		$hpos = getenv( 'CSEARCH_HPOS' );
		if ( 'off' === $hpos || 'on' === $hpos ) {
			self::assertSame( 'on' === $hpos, \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() );
		}
		self::assertSame( 'persistent' === getenv( 'CSEARCH_OBJECT_CACHE' ), (bool) wp_using_ext_object_cache() );
		$host = getenv( 'WORDPRESS_DB_HOST' );
		self::assertSame( false === $host ? DB_HOST : $host, DB_HOST );
	}
}
