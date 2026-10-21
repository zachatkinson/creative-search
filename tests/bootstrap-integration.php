<?php
/**
 * Integration test bootstrap: the WordPress test suite inside wp-env, WooCommerce loaded, the plugin activated.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

$csearch_plugin_dir = dirname( __DIR__ );

require_once $csearch_plugin_dir . '/vendor/autoload.php';

$csearch_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( false === $csearch_tests_dir || '' === $csearch_tests_dir ) {
	$csearch_tests_dir = '/wordpress-phpunit';
}
if ( ! file_exists( $csearch_tests_dir . '/includes/functions.php' ) ) {
	$csearch_tests_dir = $csearch_plugin_dir . '/vendor/wp-phpunit/wp-phpunit';
}
if ( ! file_exists( $csearch_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test suite not found. Run through `composer test:integration` with wp-env started.\n" );
	exit( 1 );
}

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $csearch_plugin_dir . '/vendor/yoast/phpunit-polyfills' );
}

require_once $csearch_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $csearch_plugin_dir ): void {
		$wc = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		if ( ! file_exists( $wc ) ) {
			fwrite( STDERR, "WooCommerce not found at {$wc}.\n" );
			exit( 1 );
		}
		require $wc;
		require $csearch_plugin_dir . '/creative-search.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function (): void {
		// WooCommerce installs its tables on this hook in its own test suite.
		define( 'WP_UNINSTALL_PLUGIN', true );
		define( 'WC_REMOVE_ALL_DATA', true );
		include WP_PLUGIN_DIR . '/woocommerce/uninstall.php';
		WC_Install::install();
		if ( class_exists( '\Automattic\WooCommerce\Internal\Admin\Install' ) ) {
			\Automattic\WooCommerce\Internal\Admin\Install::create_tables();
			\Automattic\WooCommerce\Internal\Admin\Install::create_events();
		}
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_roles();
		\CreativeBranding\Search\Install\Activator::activate();
	}
);

require $csearch_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/Support/IntegrationTestCase.php';
