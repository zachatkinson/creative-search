<?php
/**
 * Uninstall tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Install\Uninstaller;
use CreativeBranding\Search\Support\Options;
use CreativeBranding\Search\Support\Tables;
use CreativeBranding\Search\Tests\Support\IntegrationTestCase;
use CreativeBranding\Search\Updates\HttpManifestSource;

/**
 * Uninstall leaves nothing: no table, option, transient, meta, scheduled action or cache entry.
 */
final class UninstallTest extends IntegrationTestCase {

	public function test_uninstall_leaves_nothing(): void {
		global $wpdb;

		// Make the plugin's footprint as big as it ever gets.
		update_option( Options::INDEX_STATE, array( 'live' => 3 ) );
		update_option( Options::STOPWORDS_PREFIX . 'en', array( 'the' ) );
		update_option( Options::STOPWORDS_PREFIX . 'fr', array( 'les' ) );
		set_transient( Options::TRANSIENT_PREFIX . 'notice_1', 'x', HOUR_IN_SECONDS );
		set_transient( Options::TRANSIENT_PREFIX . 'reindex', 'running' );
		set_site_transient( HttpManifestSource::CACHE, array( 'data' => null ), HOUR_IN_SECONDS );
		$product = new \WC_Product_Simple();
		$product->set_name( 'Deck' );
		$product->set_regular_price( '10' );
		$product->save();
		update_post_meta( $product->get_id(), Options::META_BOOST, 5 );
		update_post_meta( $product->get_id(), Options::META_EXCLUDE, 'yes' );
		update_user_meta( self::factory()->user->create(), Options::USER_DISMISSED, array( 'missing' => 1 ) );
		wp_cache_set( 'suggest:abc', array( 1 ), Options::CACHE_GROUP );
		$wpdb->insert( Tables::terms(), array( 'term' => 'deck', 'df' => 1 ) );
		$actionId = as_schedule_single_action( time() + DAY_IN_SECONDS, 'csearch_job_index_product', array( $product->get_id() ), Options::QUEUE_GROUP );
		$doneId   = as_enqueue_async_action( 'csearch_job_index_product', array( 1 ), Options::QUEUE_GROUP );
		\ActionScheduler::store()->mark_complete( $doneId );
		self::assertGreaterThan( 0, $actionId );

		$this->withRealTables( static fn() => Uninstaller::run() );

		foreach ( Tables::all() as $table ) {
			self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Tables::name( $table ) ) ), Tables::name( $table ) . ' still exists' );
		}
		$options = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s', $wpdb->options, '%' . $wpdb->esc_like( 'csearch' ) . '%', $wpdb->esc_like( '_transient_csearch_' ) . '%', $wpdb->esc_like( '_transient_timeout_csearch_' ) . '%' ) );
		self::assertSame( array(), $options, 'options or transients left behind' );
		self::assertFalse( get_site_transient( HttpManifestSource::CACHE ), 'the update manifest cache left behind' );
		$meta = $wpdb->get_col( $wpdb->prepare( 'SELECT meta_key FROM %i WHERE meta_key LIKE %s', $wpdb->postmeta, $wpdb->esc_like( '_csearch_' ) . '%' ) );
		self::assertSame( array(), $meta, 'product meta left behind' );
		self::assertSame( array(), $wpdb->get_col( $wpdb->prepare( 'SELECT umeta_id FROM %i WHERE meta_key = %s', $wpdb->usermeta, Options::USER_DISMISSED ) ), 'user meta left behind' );
		self::assertSame( array(), as_get_scheduled_actions( array( 'group' => Options::QUEUE_GROUP, 'status' => '', 'per_page' => 5 ), 'ids' ), 'actions of any status left behind' );
		self::assertFalse( wp_cache_get( 'suggest:abc', Options::CACHE_GROUP ), 'cache group not flushed' );
		self::assertNotEmpty( get_post( $product->get_id() ), 'products are never touched' );
	}

	public function test_a_deleted_network_site_drops_the_plugin_tables(): void {
		$tables = apply_filters( 'wpmu_drop_tables', array( 'wp_posts' ), 1 );
		foreach ( Tables::all() as $table ) {
			self::assertContains( Tables::name( $table ), $tables );
		}
	}

	public function test_uninstall_php_fallback_lists_the_same_tables_options_and_meta(): void {
		$source = (string) file_get_contents( CSEARCH_PATH . 'uninstall.php' );
		foreach ( Tables::all() as $table ) {
			self::assertStringContainsString( "'{$table}'", $source, "uninstall.php fallback misses table {$table}" );
		}
		foreach ( Options::names() as $option ) {
			self::assertStringContainsString( "'{$option}'", $source, "uninstall.php fallback misses option {$option}" );
		}
		foreach ( Options::metaKeys() as $key ) {
			self::assertStringContainsString( "'{$key}'", $source, "uninstall.php fallback misses meta {$key}" );
		}
		self::assertStringContainsString( "'" . Options::STOPWORDS_PREFIX . "'", $source );
		self::assertStringContainsString( "'" . Options::USER_DISMISSED . "'", $source );
		self::assertStringContainsString( "'" . Options::QUEUE_GROUP . "'", $source );
		self::assertStringContainsString( "wp_cache_flush_group( '" . Options::CACHE_GROUP . "' )", $source );
		self::assertStringContainsString( "delete_site_transient( '" . HttpManifestSource::CACHE . "' )", $source );
	}

	public function test_uninstall_is_idempotent(): void {
		$this->withRealTables( static fn() => Uninstaller::run() );
		$this->withRealTables( static fn() => Uninstaller::run() );
		self::assertTrue( true, 'a second run on an empty site raises nothing' );
	}
}
