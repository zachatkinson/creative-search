<?php
/**
 * POST /csearch/v1/click.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Rest;

use CreativeBranding\Search\Search\Clicks;
use CreativeBranding\Search\Search\SearchService;
use CreativeBranding\Search\Support\Cast;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The block's beacon when a suggestion is chosen: which query, which language, what was clicked. Public (a
 * shopper is anonymous), rate-limited, writes one column pair on an existing log row and nothing else (D-052).
 */
final class ClickRoute implements Route {

	/**
	 * Constructor.
	 *
	 * @param Clicks        $clicks    The click recorder.
	 * @param SearchService $search    The search service, for the language check.
	 * @param Responses     $responses Shared response helpers.
	 */
	public function __construct( private Clicks $clicks, private SearchService $search, private Responses $responses ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/click',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
				'args'                => Args::query() + Args::lang() + array(
					'type' => array(
						'description' => __( 'What was clicked.', 'creative-search' ),
						'type'        => 'string',
						'enum'        => Clicks::TYPES,
						'required'    => true,
					),
					'id'   => array(
						'description' => __( 'The product or term id.', 'creative-search' ),
						'type'        => 'integer',
						'minimum'     => 1,
						'required'    => true,
					),
				),
			)
		);
	}

	/**
	 * Record.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The request.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$limited = $this->responses->limited( 'click' );
		if ( null !== $limited ) {
			return $limited;
		}
		$recorded = $this->clicks->record(
			Cast::str( $request->get_param( 'q' ) ),
			$this->search->language( Args::langOf( $request ) ),
			Cast::str( $request->get_param( 'type' ) ),
			Cast::int( $request->get_param( 'id' ) )
		);
		$response = new WP_REST_Response( array( 'recorded' => $recorded ), 202 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
