<?php
/**
 * GET /csearch/v1/suggest.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Rest;

use CreativeBranding\Search\Search\SearchLog;
use CreativeBranding\Search\Search\SearchService;
use CreativeBranding\Search\Results\CategoryScope;
use CreativeBranding\Search\Search\Suggestions;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Cast;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The suggestion panel's route: public, read-only, rate-limited, cacheable.
 */
final class SuggestRoute implements Route {

	/**
	 * Constructor.
	 *
	 * @param Suggestions   $suggestions The answer builder.
	 * @param SearchService $search      The search service.
	 * @param Responses     $responses   Shared response helpers.
	 */
	public function __construct( private Suggestions $suggestions, private SearchService $search, private Responses $responses ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/suggest',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle' ),
					'permission_callback' => '__return_true',
					'args'                => Args::query() + Args::lang() + array(
						'limit' => array(
							'description' => __( 'Products to return, 1 to 10.', 'creative-search' ),
							'type'        => 'integer',
							'minimum'     => Settings::LIMIT_MIN,
							'maximum'     => Settings::LIMIT_MAX,
							'default'     => Settings::LIMIT_DEFAULT,
						),
						'cat'   => array(
							'description' => __( 'A product category’s slug: suggest only its products and its subcategories’ (1.3).', 'creative-search' ),
							'type'        => 'string',
							'pattern'     => '^[^\\s/?#]{1,200}$',
							'required'    => false,
						),
						'here'  => array(
							'description' => __( 'The product category the shopper is in: offered first as “kazumi in Decks” when it holds results (1.5).', 'creative-search' ),
							'type'        => 'string',
							'pattern'     => '^[^\\s/?#]{1,200}$',
							'required'    => false,
						),
					),
				),
				'schema' => array( Schemas::class, 'suggest' ),
			)
		);
	}

	/**
	 * Answer.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The request.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$limited = $this->responses->limited( 'suggest' );
		if ( null !== $limited ) {
			return $limited;
		}
		$query = SearchService::clean( Cast::str( $request->get_param( 'q' ) ) );
		$lang  = $this->search->language( Args::langOf( $request ) );
		$limit = Cast::int( $request->get_param( 'limit' ), Settings::LIMIT_DEFAULT );
		$scope = CategoryScope::bySlug( Cast::str( $request->get_param( 'cat' ) ) );
		$here  = CategoryScope::bySlug( Cast::str( $request->get_param( 'here' ) ) );
		return Responses::cacheable(
			$request,
			$this->search->index()->generation(),
			fn(): array => $this->suggestions->answer( $query, $lang, $limit, $scope, $here )
		);
	}
}
