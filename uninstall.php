<?php
/**
 * Uninstall: removes the seven tables, every option and transient, the product, post and page meta, the scheduled actions
 * and the object-cache group, on every site of a network. Deactivation leaves all of it in place.
 *
 * The work is in Install\Uninstaller so the integration suite can prove it leaves nothing; this file only
 * reaches it, and repeats the list inline when the autoloader is missing.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

if ( class_exists( \CreativeBranding\Search\Install\Uninstaller::class ) ) {
	\CreativeBranding\Search\Install\Uninstaller::run();
	return;
}

/**
 * Fallback without the autoloader: the same list, by hand. Kept in step with Install\Uninstaller by
 * tests/Integration/UninstallTest.php.
 */
function csearch_uninstall_site(): void {
	global $wpdb;
	foreach ( array( 'log', 'synonyms', 'term_stats', 'trigrams', 'postings', 'terms', 'documents' ) as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'csearch_' . $table ) );
	}
	foreach ( array( 'csearch_settings', 'csearch_db_version', 'csearch_index_state', 'csearch_upgrade_lock', 'csearch_reindex_cancel', 'csearch_rebuild_owed', 'csearch_analysis_version' ) as $option ) {
		delete_option( $option );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'csearch_stopwords_' ) . '%', $wpdb->esc_like( '_transient_csearch_' ) . '%', $wpdb->esc_like( '_transient_timeout_csearch_' ) . '%' ) );
	delete_post_meta_by_key( '_csearch_boost' );
	delete_post_meta_by_key( '_csearch_exclude' );
	delete_metadata( 'user', 0, 'csearch_dismissed_notices', '', true );
	if ( class_exists( 'ActionScheduler' ) && ActionScheduler::is_initialized() ) {
		$csearch_store = ActionScheduler::store();
		do {
			$csearch_ids = $csearch_store->query_actions(
				array(
					'group'    => 'csearch',
					'status'   => '',
					'per_page' => 500,
				)
			);
			foreach ( (array) $csearch_ids as $csearch_id ) {
				$csearch_store->delete_action( (string) $csearch_id );
			}
			$csearch_more = count( (array) $csearch_ids ) === 500;
		} while ( $csearch_more );
	}
	wp_cache_flush_group( 'csearch' );
}

if ( is_multisite() ) {
	$csearch_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		) 
	);
	foreach ( $csearch_site_ids as $csearch_site_id ) {
		switch_to_blog( (int) $csearch_site_id ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- uninstall walks every site once; only options, meta and the plugin's tables are touched.
		csearch_uninstall_site();
		restore_current_blog();
	}
} else {
	csearch_uninstall_site();
}
