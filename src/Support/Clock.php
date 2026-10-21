<?php
/**
 * UTC clock.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every time the plugin stores or compares goes through here, in UTC; never MySQL's NOW() and never
 * `date()`. Tests freeze it with {@see Clock::freeze()}.
 */
final class Clock {

	/**
	 * Frozen timestamp, tests only.
	 *
	 * @var int|null
	 */
	private static ?int $frozen = null;

	/**
	 * Current UTC unix timestamp.
	 */
	public static function timestamp(): int {
		return self::$frozen ?? time();
	}

	/**
	 * Current UTC time as a MySQL DATETIME string.
	 */
	public static function now(): string {
		return self::format( self::timestamp() );
	}

	/**
	 * Format a unix timestamp as MySQL DATETIME in UTC.
	 *
	 * @param int $timestamp Unix timestamp.
	 */
	public static function format( int $timestamp ): string {
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Freeze the clock (tests).
	 *
	 * @param int|null $timestamp Unix timestamp, or null to unfreeze.
	 */
	public static function freeze( ?int $timestamp ): void {
		self::$frozen = $timestamp;
	}
}
