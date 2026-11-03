<?php
/**
 * `wp csearch log` and `wp csearch cache`.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Cli;

use CreativeBranding\Search\Admin\Analytics;
use CreativeBranding\Search\Repository\LogRepository;
use CreativeBranding\Search\Search\Cache;
use CreativeBranding\Search\Search\LogMaintenance;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Cast;
use CreativeBranding\Search\Support\Clock;
use CreativeBranding\Search\Support\Options;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Purges the search log (after rolling up the days it holds, so the charts keep them) and drops the cached
 * answers (spec: Admin → WP-CLI).
 */
final class MaintenanceCommand {

	/**
	 * Constructor.
	 *
	 * @param LogRepository  $log         The log table.
	 * @param LogMaintenance $maintenance The nightly roll-up, run first so the charts keep the purged days.
	 * @param Analytics      $analytics   Clear analytics (D-139).
	 */
	public function __construct( private LogRepository $log, private LogMaintenance $maintenance, private Analytics $analytics ) {
	}

	/**
	 * Delete every search-log row and every daily roll-up: the Analytics tab starts from nothing (D-139).
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Clear without asking.
	 *
	 * [--format=<format>]
	 * : `json` for `{"searches": n, "days": n}`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp csearch analytics clear --yes
	 *
	 * @param string[]             $args  Positional arguments (none).
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function clear( array $args, array $assoc ): void {
		unset( $args );
		$held = $this->analytics->held();
		WP_CLI::confirm(
			sprintf(
				/* translators: %s: number of searches */
				_n( 'Delete %s logged search and every daily count? Analytics and the suggested searches start again from nothing.', 'Delete %s logged searches and every daily count? Analytics and the suggested searches start again from nothing.', $held['searches'], 'creative-search' ),
				number_format_i18n( $held['searches'] )
			),
			$assoc
		);
		$done = $this->analytics->clear();
		if ( 'json' === ( $assoc['format'] ?? '' ) ) {
			WP_CLI::line( (string) wp_json_encode( $done ) );
			return;
		}
		WP_CLI::success(
			sprintf(
				/* translators: %s: number of searches */
				_n( 'Analytics cleared: %s search deleted, with the daily counts.', 'Analytics cleared: %s searches deleted, with the daily counts.', $done['searches'], 'creative-search' ),
				number_format_i18n( $done['searches'] )
			)
		);
	}

	/**
	 * Delete search-log rows older than the retention period, or than --days.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Keep this many days (0 deletes every row); the retention setting by default.
	 *
	 * [--format=<format>]
	 * : text or json.
	 * ---
	 * default: text
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp csearch log purge
	 *     wp csearch log purge --days=30
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function purge( array $args, array $assoc ): void {
		unset( $args );
		if ( isset( $assoc['days'] ) && ( ! is_numeric( $assoc['days'] ) || (int) $assoc['days'] < 0 ) ) {
			WP_CLI::error( __( '--days takes a number of days, 0 or more.', 'creative-search' ) );
		}
		$days   = isset( $assoc['days'] ) ? Cast::int( $assoc['days'] ) : Settings::retentionDays();
		$before = 0 === $days ? Clock::format( Clock::timestamp() + MINUTE_IN_SECONDS ) : Clock::format( Clock::timestamp() - $days * DAY_IN_SECONDS );
		$this->maintenance->rollUp();
		$deleted = 0;
		do {
			$batch    = $this->log->purge( $before, LogMaintenance::BATCH );
			$deleted += $batch;
		} while ( LogMaintenance::BATCH === $batch );
		if ( 'json' === ( $assoc['format'] ?? '' ) ) {
			WP_CLI::line( (string) wp_json_encode( array( 'deleted' => $deleted ) ) );
			return;
		}
		/* translators: %d: rows deleted */
		WP_CLI::success( sprintf( _n( '%d search deleted from the log.', '%d searches deleted from the log.', $deleted, 'creative-search' ), $deleted ) );
	}

	/**
	 * Drop every cached search answer.
	 *
	 * ## EXAMPLES
	 *
	 *     wp csearch cache flush
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function flush( array $args, array $assoc ): void {
		unset( $args );
		Cache::bump();
		wp_cache_flush_group( Options::CACHE_GROUP );
		if ( 'json' === ( $assoc['format'] ?? '' ) ) {
			WP_CLI::line( (string) wp_json_encode( array( 'flushed' => true ) ) );
			return;
		}
		WP_CLI::success( __( 'Search answers dropped; the next searches are answered fresh.', 'creative-search' ) );
	}
}
