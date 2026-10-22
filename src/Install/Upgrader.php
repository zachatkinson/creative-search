<?php
/**
 * Schema upgrades, off the shopper's request.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Install;

use CreativeBranding\Search\Support\Clock;
use CreativeBranding\Search\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs `Schema::install()` when the stored version is behind, on `admin_init` and before a `wp csearch`
 * command, never on a front-end request (M1 review item 2). A lock row, added with `add_option()` (an INSERT
 * that fails when the row exists), keeps two admin requests from running `dbDelta()` at once; a lock older
 * than five minutes is taken over, so a crashed upgrade does not block the next.
 */
final class Upgrader {

	public const LOCK     = Options::UPGRADE_LOCK;
	public const LOCK_TTL = 300;

	/**
	 * Whether the stored schema version is behind the code's.
	 */
	public static function needed(): bool {
		$stored = get_option( Options::DB_VERSION, '0' );
		return version_compare( is_scalar( $stored ) ? (string) $stored : '0', Schema::DB_VERSION, '<' );
	}

	/**
	 * Upgrade when needed and when no other request is upgrading.
	 *
	 * @return bool Whether this call ran the upgrade.
	 */
	public static function maybeUpgrade(): bool {
		if ( ! self::needed() || ! self::lock() ) {
			return false;
		}
		try {
			Schema::install();
		} finally {
			delete_option( self::LOCK );
		}
		return true;
	}

	/**
	 * Take the lock.
	 */
	private static function lock(): bool {
		$now = Clock::timestamp();
		if ( add_option( self::LOCK, $now, '', false ) ) {
			return true;
		}
		$held = get_option( self::LOCK, 0 );
		if ( is_numeric( $held ) && $now - (int) $held > self::LOCK_TTL ) {
			update_option( self::LOCK, $now, false );
			return true;
		}
		return false;
	}
}
