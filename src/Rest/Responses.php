<?php
/**
 * Shared response helpers.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Rest;

use CreativeBranding\Search\Search\RateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The rate limit, the cache headers and the conditional GET the public routes share.
 */
final class Responses {

	/**
	 * Constructor.
	 *
	 * @param RateLimiter $limiter The rate limiter.
	 */
	public function __construct( private RateLimiter $limiter ) {
	}

	/**
	 * A 429 with `Retry-After` when the client is over its limit, else null.
	 *
	 * @param string $route suggest, search or click.
	 */
	public function limited( string $route ): ?WP_REST_Response {
		$wait = $this->limiter->hit( $route, RateLimiter::client( $_SERVER, $_COOKIE ) ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- read only to hash a session id for the rate limit; never stored.
		if ( 0 === $wait ) {
			return null;
		}
		$response = rest_convert_error_to_response(
			new WP_Error(
				'csearch_rate_limited',
				__( 'Too many searches in a short time. Please wait a few seconds and try again.', 'creative-search' ),
				array(
					'status'      => 429,
					'retry_after' => $wait,
				)
			)
		);
		$response->header( 'Retry-After', (string) $wait );
		return $response;
	}

	/**
	 * A cacheable answer: `Cache-Control: public, max-age=60` and an ETag of the generation and the answer
	 * itself, so a product's change, a setting or a synonym that changes what the shopper would see changes the
	 * ETag; a 304 when the client already holds it. The answer is computed either way (from the cache when
	 * warm), which also logs the search.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request    The request.
	 * @param int                                   $generation The live generation.
	 * @param callable(): array<mixed>              $answer     Builds the answer.
	 */
	public static function cacheable( WP_REST_Request $request, int $generation, callable $answer ): WP_REST_Response {
		$data     = $answer();
		$etag     = '"' . $generation . '-' . md5( (string) wp_json_encode( $data ) ) . '"';
		$response = $etag === $request->get_header( 'if_none_match' ) ? new WP_REST_Response( null, 304 ) : new WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'public, max-age=60' );
		$response->header( 'ETag', $etag );
		return $response;
	}
}
