<?php
/**
 * Schema upgrades, off the shopper's request.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Install;

use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Plugin;
use CreativeBranding\Search\Support\Clock;
use CreativeBranding\Search\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs `Schema::install()` when the stored version is behind: on `admin_init`, before a `wp csearch` command,
 * before the indexer writes, and as a job a storefront request queues (D-090); never inline on a shopper's
 * search (M1 review item 2), which reads as schema 1 until the upgrade is done. An index built before schema 2 has its postings migrated in place
 * (`Migration`, D-087) and is then rebuilt, so every document gets its name key (D-086). A lock row, added with `add_option()` (an INSERT
 * that fails when the row exists), keeps two admin requests from running `dbDelta()` at once; a lock older
 * than five minutes is taken over, so a crashed upgrade does not block the next.
 */
final class Upgrader {

	public const LOCK     = Options::UPGRADE_LOCK;
	public const LOCK_TTL = 300;

	/**
	 * Whether the stored schema version is behind the code's.
	 *
	 * @phpstan-impure An upgrade in between changes the answer.
	 */
	public static function needed(): bool {
		return version_compare( self::stored(), Schema::DB_VERSION, '<' );
	}

	/**
	 * Upgrade when needed and when no other request is upgrading.
	 *
	 * @return bool Whether this call ran the upgrade.
	 */
	public static function maybeUpgrade(): bool {
		if ( ! self::needed() ) {
			return false;
		}
		// The version this request loaded may be stale: another request may have upgraded since, before the lock
		// was taken or while this one waited for it. Read it again from the database each time (D-090).
		self::refresh();
		if ( ! self::needed() || ! self::lock() ) {
			return false;
		}
		self::refresh();
		if ( ! self::needed() ) {
			delete_option( self::LOCK );
			return false;
		}
		$stored = self::stored();
		try {
			Schema::install();
		} finally {
			delete_option( self::LOCK );
		}
		self::rebuildAfter( $stored );
		return true;
	}

	/**
	 * Whether the stored schema is older than a version: the searcher reads as schema 1 while it is below 2
	 * (D-090), and a product's SKUs and attribute values are read only from 3 (D-104).
	 *
	 * @param string $version A schema version.
	 */
	public static function below( string $version ): bool {
		return version_compare( self::stored(), $version, '<' );
	}

	/**
	 * The stored schema version, '0' when none.
	 */
	public static function stored(): string {
		$stored = get_option( Options::DB_VERSION, '0' );
		return is_scalar( $stored ) ? (string) $stored : '0';
	}

	/**
	 * Owe a rebuild when an index built before the current schema was just upgraded, so every document gets its
	 * name key (schema 2, D-086) and its SKUs, attribute values and cart flag (schema 3, D-104, D-106), and queue
	 * it. Only a generation begun after the upgrade that goes live settles the debt (`Reindex::owe()`), so neither
	 * a reindex already running nor one cancelled or abandoned loses it (D-090).
	 *
	 * @param string $stored The version stored before the upgrade.
	 */
	public static function rebuildAfter( string $stored ): void {
		if ( version_compare( $stored, '1', '>=' ) && version_compare( $stored, Schema::DB_VERSION, '<' ) && ! self::needed() ) {
			Plugin::container()->get( Reindex::class )->owe();
		}
	}

	/**
	 * Keep the lock fresh during a long upgrade, so another request does not take it over as stale.
	 */
	public static function heartbeat(): void {
		if ( false !== get_option( self::LOCK, false ) ) {
			update_option( self::LOCK, Clock::timestamp(), false );
		}
	}

	/**
	 * Forget this request's copy of the stored version, so the next read comes from the database.
	 */
	private static function refresh(): void {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( Options::DB_VERSION, 'options' );
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
