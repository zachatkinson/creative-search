<?php
/**
 * GET /csearch/v1/status.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Rest;

use CreativeBranding\Search\Index\IndexHealth;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The index's state for the Tools screen and monitoring; `manage_woocommerce` only.
 */
final class StatusRoute implements Route {

	/**
	 * Constructor.
	 *
	 * @param IndexHealth $health The index's status.
	 */
	public function __construct( private IndexHealth $health ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle' ),
					'permission_callback' => static fn(): bool => current_user_can( 'manage_woocommerce' ),
				),
				'schema' => array( Schemas::class, 'status' ),
			)
		);
	}

	/**
	 * Answer.
	 */
	public function handle(): WP_REST_Response {
		$status = $this->health->status();
		unset( $status['expected'] );
		$response = new WP_REST_Response( $status, 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
