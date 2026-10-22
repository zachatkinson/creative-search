<?php
/**
 * How the index is doing.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Index;

use CreativeBranding\Search\Repository\DocumentRepository;
use CreativeBranding\Search\Repository\ProductIdRepository;
use CreativeBranding\Search\Repository\TermRepository;
use CreativeBranding\Search\Support\Cast;
use CreativeBranding\Search\Support\Clock;
use CreativeBranding\Search\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The index's status for the Tools tab, the notices, `/status` and `wp csearch status` (spec: Admin → Tools and
 * Notices): generation, counts, last build, backlog, errors, and the three warning conditions.
 */
final class IndexHealth {

	public const MISSING_SHARE = 0.10;
	public const STALE_MINUTES = 15;

	/**
	 * Constructor.
	 *
	 * @param IndexState          $state      The generations.
	 * @param DocumentRepository  $documents  Documents.
	 * @param TermRepository      $dictionary Terms.
	 * @param ProductIdRepository $products   Published products.
	 */
	public function __construct( private IndexState $state, private DocumentRepository $documents, private TermRepository $dictionary, private ProductIdRepository $products ) {
	}

	/**
	 * Everything the status screens show.
	 *
	 * @return array{generation: int, building: int|null, products: int, expected: int, documents: int, terms: int, last_reindex: string|null, progress: array{offset: int, total: int, done: int, cancelled: bool}, backlog: int, errors: string[]}
	 */
	public function status(): array {
		$state = $this->state->all();
		$live  = $this->state->live();
		return array(
			'generation'   => $live,
			'building'     => $this->state->building(),
			'products'     => $this->documents->productCount( $live ),
			'expected'     => $this->expected(),
			'documents'    => $this->documents->count( $live ),
			'terms'        => $this->dictionary->counts()['used'],
			'last_reindex' => is_string( $state[ IndexState::FINISHED_AT ] ) ? $state[ IndexState::FINISHED_AT ] : null,
			'progress'     => array(
				'offset'    => Cast::int( $state[ IndexState::OFFSET ] ),
				'total'     => Cast::int( $state[ IndexState::TOTAL ] ),
				'done'      => Cast::int( $state[ IndexState::DONE ] ),
				'cancelled' => (bool) $state[ IndexState::CANCELLED ],
			),
			'backlog'      => self::backlog(),
			'errors'       => Cast::strings( $state[ IndexState::ERRORS ] ),
		);
	}

	/**
	 * Published products the index should hold: all of them but those the owner excludes.
	 */
	public function expected(): int {
		return max( 0, $this->products->publishedCount() - $this->products->excludedCount() );
	}

	/**
	 * The warnings the screen shows, by id: `empty`, `missing` (with how many), `stale` and `errors` (with the
	 * lines). None while a reindex runs, except the stale queue that would stop it.
	 *
	 * @return array<string, array{count?: int, errors?: string[]}>
	 */
	public function warnings(): array {
		$out = array();
		if ( self::queueStale() ) {
			$out['stale'] = array();
		}
		if ( $this->state->isBuilding() ) {
			return $out;
		}
		$expected = $this->expected();
		$indexed  = $this->documents->productCount( $this->state->live() );
		if ( $expected > 0 && 0 === $indexed ) {
			$out['empty'] = array();
		} elseif ( $expected > 0 && ( $expected - $indexed ) / $expected > self::MISSING_SHARE ) {
			$out['missing'] = array( 'count' => $expected - $indexed );
		}
		$errors = Cast::strings( $this->state->all()[ IndexState::ERRORS ] );
		if ( array() !== $errors ) {
			$out['errors'] = array( 'errors' => $errors );
		}
		return $out;
	}

	/**
	 * Jobs waiting in the plugin's Action Scheduler group.
	 */
	public static function backlog(): int {
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

	/**
	 * Whether a job of the plugin's group has waited past its time for 15 minutes: the queue is not running.
	 */
	public static function queueStale(): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}
		$ids = as_get_scheduled_actions(
			array(
				'group'        => Options::QUEUE_GROUP,
				'status'       => \ActionScheduler_Store::STATUS_PENDING,
				'date'         => Clock::format( Clock::timestamp() - self::STALE_MINUTES * MINUTE_IN_SECONDS ),
				'date_compare' => '<=',
				'per_page'     => 1,
			),
			'ids'
		);
		return is_array( $ids ) && array() !== $ids;
	}
}
