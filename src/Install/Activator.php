<?php
/**
 * Activation.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Install;

use CreativeBranding\Search\Domain\Tokenizer;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Plugin;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates the tables and the default settings and queues the first index build as one Action Scheduler job
 * (spec: Indexing → The first build); nothing is indexed on the activating request. On a network activation
 * every site gets the same, the way `Uninstaller::run()` walks them (M1 review item 3).
 */
final class Activator {

	/**
	 * Activation hook.
	 *
	 * @param bool|mixed $networkWide Whether the plugin is being network-activated.
	 */
	public static function activate( $networkWide = false ): void {
		if ( is_multisite() && true === $networkWide ) {
			/** @var int[] $siteIds */
			$siteIds = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $siteIds as $siteId ) {
				switch_to_blog( (int) $siteId ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- activation walks every site once; only the plugin's tables and options are written.
				self::site();
				restore_current_blog();
			}
			return;
		}
		self::site();
	}

	/**
	 * Activate the current site.
	 */
	public static function site(): void {
		// Behind the upgrader's lock when an upgrade is due, so a reactivation never migrates beside a job that
		// is already migrating (D-090); when the schema is current, dbDelta() only puts back anything missing.
		if ( Upgrader::needed() ) {
			Upgrader::maybeUpgrade();
		} else {
			Schema::install();
		}
		if ( false === get_option( Options::SETTINGS, false ) ) {
			add_option( Options::SETTINGS, Settings::defaults(), '', true );
			// A first install builds under today's rules: no rebuild is owed for them.
			add_option( Options::ANALYSIS_VERSION, Tokenizer::VERSION, '', true );
		}
		Settings::flush();
		Plugin::container()->get( Reindex::class )->queueFirstBuild();
	}
}
