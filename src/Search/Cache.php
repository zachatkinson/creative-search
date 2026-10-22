<?php
/**
 * The answer cache.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Search;

use CreativeBranding\Search\Support\Cast;
use CreativeBranding\Search\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers by index generation and normalised query, in the object cache for 60 seconds (spec: The search API →
 * Behaviour). The generation is in the key, so a reindex makes every old answer unreachable without a scan;
 * a single product's change is at most one TTL stale. Without a persistent object cache the cache lives for
 * the request only (D-051).
 */
final class Cache {

	public const TTL = 60;

	/**
	 * A cached answer, or null.
	 *
	 * @param string $key Key from {@see Cache::key()}.
	 * @return array<mixed>|null
	 */
	public function get( string $key ): ?array {
		$found  = false;
		$cached = wp_cache_get( $key, Options::CACHE_GROUP, false, $found );
		return $found && is_array( $cached ) ? $cached : null;
	}

	/**
	 * Keep an answer.
	 *
	 * @param string       $key    Key.
	 * @param array<mixed> $answer The answer.
	 */
	public function set( string $key, array $answer ): void {
		/**
		 * Seconds an answer stays in the object cache; 0 turns the cache off.
		 *
		 * @since 0.4.0
		 *
		 * @param int $ttl Seconds, default 60.
		 */
		$ttl = Cast::int( apply_filters( 'csearch_cache_ttl', self::TTL ) );
		if ( $ttl > 0 ) {
			wp_cache_set( $key, $answer, Options::CACHE_GROUP, $ttl ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- the spec's 60 seconds, filterable through csearch_cache_ttl.
		}
	}

	/**
	 * The key of an answer.
	 *
	 * @param int          $generation Live generation.
	 * @param string       $kind       suggest or search.
	 * @param array<mixed> $params     Everything the answer depends on.
	 */
	public static function key( int $generation, string $kind, array $params ): string {
		return sprintf( 'g%d:%s:%s:%s', $generation, self::salt(), $kind, md5( (string) wp_json_encode( $params ) ) );
	}

	/**
	 * Drop every answer without scanning: a new salt makes old keys unreachable (a synonym or a setting changed).
	 */
	public static function bump(): void {
		wp_cache_set( 'salt', wp_generate_password( 8, false ), Options::CACHE_GROUP );
	}

	/**
	 * The current salt.
	 */
	private static function salt(): string {
		$salt = wp_cache_get( 'salt', Options::CACHE_GROUP );
		if ( ! is_string( $salt ) || '' === $salt ) {
			$salt = '0';
		}
		return $salt;
	}
}
