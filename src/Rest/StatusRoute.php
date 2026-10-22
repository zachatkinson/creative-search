<?php
/**
 * GET /csearch/v1/status.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Rest;

use CreativeBranding\Search\Index\IndexState;
use CreativeBranding\Search\Repository\DocumentRepository;
use CreativeBranding\Search\Repository\TermRepository;
use CreativeBranding\Search\Support\Cast;
use CreativeBranding\Search\Support\Options;
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
	 * @param IndexState         $state      The generations.
	 * @param DocumentRepository $documents  Documents.
	 * @param TermRepository     $dictionary Terms.
	 */
	public function __construct( private IndexState $state, private DocumentRepository $documents, private TermRepository $dictionary ) {
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
		$state    = $this->state->all();
		$live     = $this->state->live();
		$terms    = $this->dictionary->counts();
		$data     = array(
			'generation'   => $live,
			'building'     => $this->state->building(),
			'products'     => $this->documents->productCount( $live ),
			'documents'    => $this->documents->count( $live ),
			'terms'        => $terms['used'],
			'last_reindex' => is_string( $state[ IndexState::FINISHED_AT ] ) ? $state[ IndexState::FINISHED_AT ] : null,
			'progress'     => array(
				'offset'    => Cast::int( $state[ IndexState::OFFSET ] ),
				'total'     => Cast::int( $state[ IndexState::TOTAL ] ),
				'done'      => Cast::int( $state[ IndexState::DONE ] ),
				'cancelled' => (bool) $state[ IndexState::CANCELLED ],
			),
			'backlog'      => self::backlog(),
			'errors'       => array_values( (array) $state[ IndexState::ERRORS ] ),
		);
		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Jobs waiting in the plugin's Action Scheduler group.
	 */
	private static function backlog(): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}
		$ids = as_get_scheduled_actions(
			array(
				'group'    => Options::QUEUE_GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1000,
			),
			'ids'
		);
		return is_array( $ids ) ? count( $ids ) : 0;
	}
}
