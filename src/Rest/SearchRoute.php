<?php
/**
 * GET /csearch/v1/search.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Rest;

use CreativeBranding\Search\Search\SearchLog;
use CreativeBranding\Search\Search\SearchService;
use CreativeBranding\Search\Support\Cast;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ranked product ids a page of results needs, for headless shops and tests; the results page calls the
 * service directly (spec: The search API).
 */
final class SearchRoute implements Route {

	public const PER_PAGE_MAX = 100;

	/**
	 * Constructor.
	 *
	 * @param SearchService $search    The search service.
	 * @param Responses     $responses Shared response helpers.
	 */
	public function __construct( private SearchService $search, private Responses $responses ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/search',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle' ),
					'permission_callback' => '__return_true',
					'args'                => Args::query() + Args::lang() + array(
						'page'     => array(
							'description' => __( 'Page of results, from 1.', 'creative-search' ),
							'type'        => 'integer',
							'minimum'     => 1,
							'default'     => 1,
						),
						'per_page' => array(
							'description' => __( 'Ids per page, 1 to 100.', 'creative-search' ),
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => self::PER_PAGE_MAX,
							'default'     => 20,
						),
						'orderby'  => array(
							'description' => __( 'Order: relevance only; other sorts are the shop\'s.', 'creative-search' ),
							'type'        => 'string',
							'enum'        => array( 'relevance' ),
							'default'     => 'relevance',
						),
					),
				),
				'schema' => array( Schemas::class, 'search' ),
			)
		);
	}

	/**
	 * Answer.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The request.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$limited = $this->responses->limited( 'search' );
		if ( null !== $limited ) {
			return $limited;
		}
		$query   = SearchService::clean( Cast::str( $request->get_param( 'q' ) ) );
		$lang    = $this->search->language( Args::langOf( $request ) );
		$page    = max( 1, Cast::int( $request->get_param( 'page' ), 1 ) );
		$perPage = max( 1, min( self::PER_PAGE_MAX, Cast::int( $request->get_param( 'per_page' ), 20 ) ) );
		return Responses::cacheable(
			$request,
			$this->search->index()->generation(),
			function () use ( $query, $lang, $page, $perPage ): array {
				$ranked = $this->search->rankedIds( $query, $lang, 'page' );
				$total  = count( $ranked['ids'] );
				return array(
					'ids'       => array_slice( $ranked['ids'], ( $page - 1 ) * $perPage, $perPage ),
					'total'     => $total,
					'page'      => $page,
					'pages'     => (int) ceil( $total / $perPage ),
					'corrected' => $ranked['corrected'],
					'query'     => $ranked['query'],
					'lang'      => $ranked['lang'],
				);
			}
		);
	}
}
