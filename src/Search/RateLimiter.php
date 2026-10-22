<?php
/**
 * The rate limiter.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Search;

use CreativeBranding\Search\Support\Cast;
use CreativeBranding\Search\Support\Clock;
use CreativeBranding\Search\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 30 suggest and 20 search requests per 10 seconds per client (spec: The search API → Rate limit), as a sliding
 * window approximated from two fixed windows: the current window's count plus the previous one's, weighted by
 * how much of it still overlaps. Counters live in the object cache under a hash of the client, never in the
 * database (spec: Analytics and privacy), so the limit holds only where a persistent object cache exists
 * (D-051). Off under WP-CLI.
 */
final class RateLimiter {

	public const WINDOW = 10;
	public const LIMITS = array(
		'suggest' => 30,
		'search'  => 20,
		'click'   => 30,
	);

	/**
	 * Whether a request may go ahead; counts it when it may.
	 *
	 * @param string $route  suggest, search or click.
	 * @param string $client A stable client key (see {@see RateLimiter::client()}).
	 * @return int 0 when allowed, else the seconds to wait.
	 */
	public function hit( string $route, string $client ): int {
		$limit = $this->limit( $route );
		if ( $limit <= 0 || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return 0;
		}
		$now      = Clock::timestamp();
		$window   = intdiv( $now, self::WINDOW );
		$elapsed  = ( $now % self::WINDOW ) / self::WINDOW;
		$key      = 'rl:' . $route . ':' . $client . ':';
		$current  = Cast::int( wp_cache_get( $key . $window, Options::CACHE_GROUP ) );
		$previous = Cast::int( wp_cache_get( $key . ( $window - 1 ), Options::CACHE_GROUP ) );
		if ( $current + $previous * ( 1 - $elapsed ) >= $limit ) {
			return max( 1, self::WINDOW - ( $now % self::WINDOW ) );
		}
		if ( false === wp_cache_incr( $key . $window, 1, Options::CACHE_GROUP ) ) {
			wp_cache_set( $key . $window, 1, Options::CACHE_GROUP, 20 ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined, WordPressVIPMinimum.Performance.LowExpiryCacheTime.LowCacheTime -- two windows of 10 seconds: a rate-limit counter must not outlive them.
		}
		return 0;
	}

	/**
	 * The limit for a route per window.
	 *
	 * @param string $route Route.
	 */
	public function limit( string $route ): int {
		/**
		 * Requests per 10 seconds per client for a route; 0 turns the limit off.
		 *
		 * @since 0.4.0
		 *
		 * @param int    $limit Default: suggest 30, search 20, click 30.
		 * @param string $route suggest, search or click.
		 */
		return Cast::int( apply_filters( 'csearch_rate_limit', self::LIMITS[ $route ] ?? 20, $route ) );
	}

	/**
	 * A key for the client: WooCommerce's session cookie when it has one, else the remote address; hashed, so
	 * neither is kept even in the cache.
	 *
	 * @param array<string, mixed> $server  `$_SERVER`.
	 * @param array<string, mixed> $cookies `$_COOKIE`.
	 */
	public static function client( array $server, array $cookies ): string {
		$session = '';
		foreach ( $cookies as $name => $value ) {
			if ( str_starts_with( (string) $name, 'wp_woocommerce_session_' ) && is_string( $value ) ) {
				$session = $value;
				break;
			}
		}
		$source = '' !== $session ? 's:' . $session : 'a:' . Cast::str( $server['REMOTE_ADDR'] ?? '' );
		return substr( hash( 'sha256', wp_salt( 'nonce' ) . $source ), 0, 32 );
	}
}
