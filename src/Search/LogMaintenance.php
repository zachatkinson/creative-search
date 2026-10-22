<?php
/**
 * Log retention and roll-ups.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Search;

use CreativeBranding\Search\Repository\LogRepository;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Clock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The nightly `csearch_purge_log` job (spec: Analytics and privacy → Retention): yesterday's counts rolled up
 * into `csearch_settings['stats']` so the charts survive the purge, then rows past the retention period deleted
 * 1,000 at a time.
 */
final class LogMaintenance {

	public const HOOK      = 'csearch_purge_log';
	public const BATCH     = 1000;
	public const MAX_DAYS  = 400;
	public const ROLL_BACK = 7;

	/**
	 * Constructor.
	 *
	 * @param LogRepository $log The log table.
	 */
	public function __construct( private LogRepository $log ) {
	}

	/**
	 * Roll up the last few days and purge one batch.
	 *
	 * @return bool Whether more rows remain to purge.
	 */
	public function run(): bool {
		$this->rollUp();
		return $this->purge() === self::BATCH;
	}

	/**
	 * Roll up the counts of the days before today that are not rolled up yet (up to a week back, so a missed
	 * night is caught up), and keep at most 400 days.
	 */
	public function rollUp(): void {
		$stats = Settings::get( 'stats' );
		$stats = is_array( $stats ) ? $stats : array();
		$today = Clock::timestamp();
		for ( $back = self::ROLL_BACK; $back >= 1; $back-- ) {
			$day = gmdate( 'Y-m-d', $today - $back * DAY_IN_SECONDS );
			if ( isset( $stats[ $day ] ) ) {
				continue;
			}
			$counts = $this->log->dayCounts( $day );
			if ( 0 === $counts['searches'] ) {
				continue;
			}
			$stats[ $day ] = $counts;
		}
		ksort( $stats );
		Settings::set( 'stats', array_slice( $stats, -self::MAX_DAYS, null, true ) );
	}

	/**
	 * Delete one batch of rows past the retention period.
	 *
	 * @return int Rows deleted.
	 */
	public function purge(): int {
		return $this->log->purge( Clock::format( Clock::timestamp() - Settings::retentionDays() * DAY_IN_SECONDS ), self::BATCH );
	}
}
