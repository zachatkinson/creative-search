<?php
/**
 * The full reindex.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Index;

use CreativeBranding\Search\Repository\DocumentRepository;
use CreativeBranding\Search\Repository\ProductIdRepository;
use CreativeBranding\Search\Support\Options;
use Throwable;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds generation N+1 beside N (spec: Indexing → Full reindex): products 100 at a time in id order (keyset:
 * each job carries the last id it walked, D-054), one job per batch; then the categories, tags and brands; then
 * the switch; then the old generation deleted in batches. Every step returns the next job, so the same code
 * runs as Action Scheduler jobs and inline under `wp csearch reindex --inline`. Progress lives in
 * {@see IndexState}; the old index serves throughout; a reindex already running is not started twice.
 *
 * @phpstan-type Job array{0: string, 1: array<int, int>}
 */
final class Reindex {

	public const START         = 'csearch_reindex';
	public const BATCH_ACTION  = 'csearch_reindex_batch';
	public const TERMS_ACTION  = 'csearch_reindex_terms';
	public const CLEANUP       = 'csearch_reindex_cleanup';
	public const BATCH         = 100;
	public const CLEANUP_BATCH = 500;

	/**
	 * Constructor.
	 *
	 * @param Indexer             $indexer   The indexer.
	 * @param IndexState          $state     The generations.
	 * @param DocumentRepository  $documents Documents, for the cleanup.
	 * @param ProductIdRepository $products  Product ids, for the walk.
	 */
	public function __construct( private Indexer $indexer, private IndexState $state, private DocumentRepository $documents, private ProductIdRepository $products ) {
	}

	/**
	 * Queue a reindex as a job, unless one runs or waits already.
	 *
	 * @return bool Whether it was queued now.
	 */
	public function queue(): bool {
		if ( $this->state->isBuilding() || IndexQueue::isPending( self::START ) ) {
			return false;
		}
		IndexQueue::schedule( self::START );
		return true;
	}

	/**
	 * The first build, queued once ever: on activation, or on the first admin request after it when Action
	 * Scheduler was not ready at activation.
	 *
	 * @return bool Whether it was queued now.
	 */
	public function queueFirstBuild(): bool {
		if ( (bool) $this->state->all()[ IndexState::FIRST_QUEUED ] || ! IndexQueue::available() ) {
			return false;
		}
		$this->queue();
		$this->state->firstBuildQueued();
		return true;
	}

	/**
	 * Begin: open the next generation and name the first batch.
	 *
	 * @return Job|null The first job, or null when a reindex is already running.
	 */
	public function start(): ?array {
		if ( $this->state->isBuilding() ) {
			return null;
		}
		$generation = $this->state->begin( $this->products->publishedCount() );
		return array( self::BATCH_ACTION, array( $generation, 0 ) );
	}

	/**
	 * One batch of products.
	 *
	 * @param int $generation The generation being built.
	 * @param int $after      The last product id walked (0 to start).
	 * @return Job|null The next job.
	 */
	public function batch( int $generation, int $after ): ?array {
		if ( $generation !== $this->state->building() ) {
			return null; // A job from a reindex that was cancelled or finished.
		}
		if ( $this->state->isCancelled() ) {
			return $this->abandon();
		}
		$ids     = $this->products->publishedAfter( $after, self::BATCH );
		$written = 0;
		$errors  = array();
		foreach ( $ids as $productId ) {
			try {
				if ( $this->indexer->indexProduct( $productId, $generation ) ) {
					++$written;
				}
			} catch ( Throwable $e ) {
				$errors[] = sprintf( '#%d: %s', $productId, $e->getMessage() );
			}
		}
		$last = array() === $ids ? $after : max( $ids );
		$this->state->progress( count( $ids ), $written, $last, $errors );
		if ( count( $ids ) < self::BATCH ) {
			return array( self::TERMS_ACTION, array( $generation, 0 ) );
		}
		return array( self::BATCH_ACTION, array( $generation, $last ) );
	}

	/**
	 * One batch of categories, tags and brands; the switch after the last.
	 *
	 * @param int $generation The generation being built.
	 * @param int $offset     Terms walked so far.
	 * @return Job|null The next job.
	 */
	public function terms( int $generation, int $offset ): ?array {
		if ( $generation !== $this->state->building() ) {
			return null;
		}
		if ( $this->state->isCancelled() ) {
			return $this->abandon();
		}
		$terms  = $this->termPage( $offset, self::BATCH );
		$errors = array();
		foreach ( $terms as $term ) {
			try {
				$this->indexer->indexTerm( $term->taxonomy, $term->term_id, $generation );
			} catch ( Throwable $e ) {
				$errors[] = sprintf( '%s #%d: %s', $term->taxonomy, $term->term_id, $e->getMessage() );
			}
		}
		if ( array() !== $errors ) {
			$this->state->progress( 0, 0, 0, $errors );
		}
		if ( count( $terms ) < self::BATCH ) {
			$previous = $this->state->switchLive();
			wp_cache_flush_group( Options::CACHE_GROUP );
			/**
			 * The index switched to a new generation after a full reindex.
			 *
			 * @since 0.4.0
			 *
			 * @param int $generation The generation now live.
			 * @param int $previous   The generation that was live, about to be deleted.
			 */
			do_action( 'csearch_index_switched', $generation, $previous );
			return array( self::CLEANUP, array( $previous, 0 ) );
		}
		return array( self::TERMS_ACTION, array( $generation, $offset + self::BATCH ) );
	}

	/**
	 * Delete a batch of an old (or abandoned) generation.
	 *
	 * @param int $generation The generation to delete.
	 * @param int $round      Batches done, so each job has its own arguments.
	 * @return Job|null The next job.
	 */
	public function cleanup( int $generation, int $round ): ?array {
		if ( $generation === $this->state->live() || $generation === $this->state->building() ) {
			return null; // Never the live one, never the one being built.
		}
		$ids = $this->documents->idsInGeneration( $generation, self::CLEANUP_BATCH );
		$this->indexer->removeDocuments( $ids );
		if ( count( $ids ) < self::CLEANUP_BATCH ) {
			$this->state->cleaned();
			return null;
		}
		return array( self::CLEANUP, array( $generation, $round + 1 ) );
	}

	/**
	 * Stop a reindex. One queued but not started is unqueued. One running is asked to stop after its current
	 * batch; when no job of its chain is waiting or running (a job died), it is abandoned here and its cleanup
	 * queued, so a stuck reindex can always be cleared.
	 *
	 * @return bool Whether there was one to stop.
	 */
	public function cancel(): bool {
		$queued = $this->unqueueStart();
		if ( ! $this->state->isBuilding() ) {
			return $queued;
		}
		if ( self::chainAlive() ) {
			$this->state->cancel();
			return true;
		}
		$abandoned = $this->state->abandon();
		if ( null !== $abandoned ) {
			IndexQueue::schedule( self::CLEANUP, array( $abandoned, 0 ) );
		}
		return true;
	}

	/**
	 * Run one job by its hook and return the next.
	 *
	 * @param string          $hook The job's hook.
	 * @param array<int, int> $args Its arguments.
	 * @return Job|null
	 */
	public function run( string $hook, array $args ): ?array {
		switch ( $hook ) {
			case self::START:
				return $this->start();
			case self::BATCH_ACTION:
				return $this->batch( $args[0] ?? 0, $args[1] ?? 0 );
			case self::TERMS_ACTION:
				return $this->terms( $args[0] ?? 0, $args[1] ?? 0 );
			case self::CLEANUP:
				return $this->cleanup( $args[0] ?? 0, $args[1] ?? 0 );
			default:
				return null;
		}
	}

	/**
	 * Run a job and queue the next one.
	 *
	 * @param string          $hook The job's hook.
	 * @param array<int, int> $args Its arguments.
	 */
	public function dispatch( string $hook, array $args ): void {
		$next = $this->run( $hook, $args );
		if ( null !== $next ) {
			IndexQueue::schedule( $next[0], $next[1] );
		}
	}

	/**
	 * The whole reindex, now, in this process (`wp csearch reindex --inline`). A queued start is removed first
	 * so a second reindex does not follow.
	 *
	 * @param callable|null $afterStep Called after each job with the state.
	 * @return bool False when a reindex was already running.
	 */
	public function runInline( ?callable $afterStep = null ): bool {
		$this->unqueueStart();
		$job = $this->start();
		if ( null === $job ) {
			return false;
		}
		while ( null !== $job ) {
			$job = $this->run( $job[0], $job[1] );
			if ( null !== $afterStep ) {
				$afterStep( $this->state->all() );
			}
		}
		return true;
	}

	/**
	 * Stop: the generation under construction is given up and deleted.
	 *
	 * @return Job|null The cleanup job.
	 */
	private function abandon(): ?array {
		$building = $this->state->abandon();
		return null === $building ? null : array( self::CLEANUP, array( $building, 0 ) );
	}

	/**
	 * Remove a queued start that has not run, so it cannot start a second reindex.
	 *
	 * @return bool Whether one was queued.
	 */
	private function unqueueStart(): bool {
		if ( ! IndexQueue::isPending( self::START ) ) {
			return false;
		}
		as_unschedule_all_actions( self::START, array(), Options::QUEUE_GROUP );
		return true;
	}

	/**
	 * Whether any job of a reindex chain is waiting or running.
	 */
	private static function chainAlive(): bool {
		foreach ( array( self::BATCH_ACTION, self::TERMS_ACTION ) as $hook ) {
			if ( IndexQueue::isScheduled( $hook ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A page of the categories, tags and brands, in term id order.
	 *
	 * @param int $offset Skip.
	 * @param int $limit  At most.
	 * @return WP_Term[]
	 */
	private function termPage( int $offset, int $limit ): array {
		$taxonomies = array_keys( TermReader::taxonomies() );
		if ( array() === $taxonomies ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomies,
				'hide_empty' => false,
				'number'     => $limit,
				'offset'     => $offset,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
			)
		);
		return is_array( $terms ) ? array_values( array_filter( $terms, static fn( $t ): bool => $t instanceof WP_Term ) ) : array();
	}
}
