<?php
/**
 * Base class for integration tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Support;

use CreativeBranding\Search\Container;
use CreativeBranding\Search\Install\Schema;
use CreativeBranding\Search\Plugin;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Clock;
use CreativeBranding\Search\Support\Options;
use CreativeBranding\Search\Support\Tables;
use WP_UnitTestCase;

/**
 * Makes sure the schema exists, truncates the plugin's tables before each test and resets request caches.
 */
abstract class IntegrationTestCase extends WP_UnitTestCase {

	/**
	 * Set up.
	 */
	public function set_up(): void {
		global $wpdb;
		// Before the test transaction opens: TRUNCATE and CREATE commit implicitly. A previous test may have
		// uninstalled, so the schema is put back first, as real tables (the WordPress suite rewrites CREATE TABLE to
		// CREATE TEMPORARY TABLE, which dbDelta cannot see on the next run).
		Schema::install();
		foreach ( Tables::all() as $table ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Tables::name( $table ) ); // phpcs:ignore
		}
		// WooCommerce's Action Scheduler tables are not among the core tables the suite reinstalls, so a cancelled
		// action a test leaves behind would survive into the next run. Clear the plugin's group here, outside the
		// transaction, so every test starts with an empty queue.
		if ( isset( $wpdb->actionscheduler_actions, $wpdb->actionscheduler_groups ) ) {
			$wpdb->query( $wpdb->prepare( 'DELETE a FROM %i a INNER JOIN %i g ON g.group_id = a.group_id WHERE g.slug = %s', $wpdb->actionscheduler_actions, $wpdb->actionscheduler_groups, Options::QUEUE_GROUP ) ); // phpcs:ignore
		}
		parent::set_up();
		wp_cache_flush();
		Settings::flush();
		Clock::freeze( null );
		update_option( Options::SETTINGS, Settings::defaults(), true );
		Settings::flush();
		// The spec: the rate limit is off in tests. The rate-limit test turns it back on. The suite restores hooks
		// after each test.
		add_filter( 'csearch_rate_limit', '__return_zero' );
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		Clock::freeze( null );
		Settings::flush();
		parent::tear_down();
	}

	/**
	 * Run a callback with the WordPress test suite's temporary-table rewrites off, so CREATE TABLE and DROP TABLE
	 * act on real tables (the plugin's schema and uninstall are what is under test). The suite attaches the
	 * rewrites as instance methods in set_up(); set_up() here runs the schema before parent::set_up(), so the
	 * tables are always real.
	 *
	 * @param callable $callback Work.
	 */
	protected function withRealTables( callable $callback ): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			$callback();
		} finally {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	/**
	 * The plugin's container.
	 */
	protected function container(): Container {
		return Plugin::container();
	}

	/**
	 * Resolve a service.
	 *
	 * @template T of object
	 * @param class-string<T> $id Class.
	 * @return T
	 */
	protected function service( string $id ) {
		return $this->container()->get( $id );
	}
}
