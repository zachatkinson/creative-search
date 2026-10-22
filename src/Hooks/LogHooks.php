<?php
/**
 * The log's nightly job.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Hooks;

use CreativeBranding\Search\Hookable;
use CreativeBranding\Search\Index\IndexQueue;
use CreativeBranding\Search\Search\LogMaintenance;
use CreativeBranding\Search\Support\Clock;
use CreativeBranding\Search\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedules `csearch_purge_log` daily (on admin requests, so a shopper's request never checks) and runs it;
 * a purge that left rows re-queues itself at once.
 */
final class LogHooks implements Hookable {

	/**
	 * Constructor.
	 *
	 * @param LogMaintenance $maintenance The purge and roll-up.
	 */
	public function __construct( private LogMaintenance $maintenance ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( LogMaintenance::HOOK, array( $this, 'run' ) );
		add_action( 'admin_init', array( $this, 'schedule' ) );
	}

	/**
	 * Run the job.
	 */
	public function run(): void {
		if ( $this->maintenance->run() ) {
			IndexQueue::schedule( LogMaintenance::HOOK );
		}
	}

	/**
	 * Schedule the nightly run once.
	 */
	public function schedule(): void {
		if ( ! IndexQueue::available() || false !== as_next_scheduled_action( LogMaintenance::HOOK, array(), Options::QUEUE_GROUP ) ) {
			return;
		}
		$tomorrow = ( intdiv( Clock::timestamp(), DAY_IN_SECONDS ) + 1 ) * DAY_IN_SECONDS + 3 * HOUR_IN_SECONDS;
		as_schedule_recurring_action( $tomorrow, DAY_IN_SECONDS, LogMaintenance::HOOK, array(), Options::QUEUE_GROUP, true );
	}
}
