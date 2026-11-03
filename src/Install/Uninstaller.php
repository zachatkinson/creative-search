<?php
/**
 * Uninstall.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Install;

use CreativeBranding\Search\Support\Options;
use CreativeBranding\Search\Support\Tables;
use CreativeBranding\Search\Updates\HttpManifestSource;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes everything the plugin made: the eight tables, every option and transient, the product meta, the
 * scheduled actions, the object-cache group and the network's cached update manifest. Per site on a network. Never touches products or orders.
 */
final class Uninstaller {

	/**
	 * Uninstall every site.
	 */
	public static function run(): void {
		// The update manifest is cached once for the whole network (D-140).
		delete_site_transient( HttpManifestSource::CACHE );
		if ( is_multisite() ) {
			/** @var int[] $siteIds */
			$siteIds = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $siteIds as $siteId ) {
				switch_to_blog( (int) $siteId ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- uninstall walks every site once; only options, meta and the plugin's tables are touched.
				self::site();
				restore_current_blog();
			}
			return;
		}
		self::site();
	}

	/**
	 * Uninstall the current site.
	 */
	public static function site(): void {
		global $wpdb;

		// Children first: postings and trigrams reference terms and documents.
		foreach ( array_reverse( Tables::all() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', Tables::name( $table ) ) );
		}

		foreach ( Options::names() as $option ) {
			delete_option( $option );
		}
		// Per-language stop words and every transient, by prefix: their names depend on the languages the site indexed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( Options::STOPWORDS_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_' . Options::TRANSIENT_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . Options::TRANSIENT_PREFIX ) . '%'
			)
		);

		foreach ( Options::metaKeys() as $key ) {
			delete_post_meta_by_key( $key );
		}
		delete_metadata( 'user', 0, Options::USER_DISMISSED, '', true );

		self::deleteJobs();

		wp_cache_flush_group( Options::CACHE_GROUP );
	}

	/**
	 * Delete every Action Scheduler action in the plugin's group, whatever its status. Unscheduling alone only
	 * marks pending actions cancelled and leaves their rows and logs.
	 */
	public static function deleteJobs(): void {
		if ( ! class_exists( 'ActionScheduler' ) || ! \ActionScheduler::is_initialized() ) {
			return;
		}
		$store = \ActionScheduler::store();
		do {
			$ids = $store->query_actions(
				array(
					'group'    => Options::QUEUE_GROUP,
					'status'   => '',
					'per_page' => 500,
				)
			);
			$ids = is_array( $ids ) ? $ids : array();
			foreach ( $ids as $id ) {
				$store->delete_action( is_scalar( $id ) ? (string) $id : '' );
			}
			$more = count( $ids ) === 500;
		} while ( $more );
	}

	/**
	 * The plugin's tables, for `wpmu_drop_tables` when a network site is deleted.
	 *
	 * @param mixed $tables Tables WordPress will drop.
	 * @return mixed
	 */
	public static function siteTables( $tables ) {
		if ( ! is_array( $tables ) ) {
			return $tables;
		}
		foreach ( Tables::all() as $table ) {
			$tables[] = Tables::name( $table );
		}
		return $tables;
	}
}
