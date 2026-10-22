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
	 * A key for the client: WooCommerce's session cookie when its signature checks out (a forged or rotated
	 * cookie would otherwise buy a fresh bucket on every request), else the remote address; hashed, so neither
	 * is kept even in the cache. Behind a proxy or CDN, `csearch_client_ip` supplies the shopper's address.
	 *
	 * @param array<string, mixed> $server  `$_SERVER`.
	 * @param array<string, mixed> $cookies `$_COOKIE`.
	 */
	public static function client( array $server, array $cookies ): string {
		$session = '';
		foreach ( $cookies as $name => $value ) {
			if ( str_starts_with( (string) $name, 'wp_woocommerce_session_' ) && is_string( $value ) && self::validSession( wp_unslash( $value ) ) ) {
				$session = wp_unslash( $value );
				break;
			}
		}
		/**
		 * The shopper's IP address for the rate limit. Default `REMOTE_ADDR`; a site behind a proxy or CDN
		 * returns the address its trusted proxy forwards.
		 *
		 * @since 0.6.0
		 *
		 * @param string               $ip     The remote address.
		 * @param array<string, mixed> $server `$_SERVER`.
		 */
		$ip     = Cast::str( apply_filters( 'csearch_client_ip', Cast::str( $server['REMOTE_ADDR'] ?? '' ), $server ) );
		$source = '' !== $session ? 's:' . $session : 'a:' . $ip;
		return substr( hash( 'sha256', wp_salt( 'nonce' ) . $source ), 0, 32 );
	}

	/**
	 * Whether a WooCommerce session cookie carries a valid signature (`customer||expiry||expiring||hash`, the
	 * hash an HMAC of `customer|expiry`, as `WC_Session_Handler` checks it) and has not expired.
	 *
	 * @param string $cookie The cookie's value.
	 */
	public static function validSession( string $cookie ): bool {
		$parts = explode( '||', $cookie );
		if ( 4 !== count( $parts ) || ! is_numeric( $parts[1] ) || (int) $parts[1] < Clock::timestamp() ) {
			return false;
		}
		$data = $parts[0] . '|' . $parts[1];
		return hash_equals( hash_hmac( 'md5', $data, wp_hash( $data ) ), $parts[3] );
	}
}
