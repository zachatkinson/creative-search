<?php
/**
 * The index state option.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Index;

use CreativeBranding\Search\Support\Cast;
use CreativeBranding\Search\Support\Clock;
use CreativeBranding\Search\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `csearch_index_state`: the live generation, the generation being built and its progress, the last reindex
 * (spec: Data model → Options, Generations). Read fresh from the database on every call, never from the request's
 * option cache: an Action Scheduler process runs many jobs, and each must see what the others wrote (D-053). The
 * cancel flag lives in its own option, `csearch_reindex_cancel`, so a batch writing its progress cannot
 * overwrite it.
 */
final class IndexState {

	public const LIVE         = 'live';
	public const BUILDING     = 'building';
	public const OFFSET       = 'offset';
	public const TOTAL        = 'total';
	public const DONE         = 'done';
	public const LAST_ID      = 'last_id';
	public const STARTED_AT   = 'started_at';
	public const FINISHED_AT  = 'finished_at';
	public const CANCELLED    = 'cancelled';
	public const ERRORS       = 'errors';
	public const CLEANING     = 'cleaning';
	public const FIRST_QUEUED = 'first_queued';

	/**
	 * The whole state, with every key present, read fresh.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = Options::fresh( Options::INDEX_STATE, array() );
		$state  = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );

		$state[ self::CANCELLED ] = null !== $state[ self::BUILDING ] && Cast::int( $state[ self::BUILDING ] ) === self::cancelledGeneration();
		return $state;
	}

	/**
	 * The defaults: generation 1 live, nothing building.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			self::LIVE         => 1,
			self::BUILDING     => null,
			self::OFFSET       => 0,
			self::TOTAL        => 0,
			self::DONE         => 0,
			self::LAST_ID      => 0,
			self::STARTED_AT   => null,
			self::FINISHED_AT  => null,
			self::CANCELLED    => false,
			self::ERRORS       => array(),
			self::CLEANING     => null,
			self::FIRST_QUEUED => false,
		);
	}

	/**
	 * The generation the shop searches.
	 */
	public function live(): int {
		return max( 1, Cast::int( $this->all()[ self::LIVE ] ) );
	}

	/**
	 * The generation a reindex is writing, or null.
	 */
	public function building(): ?int {
		$building = $this->all()[ self::BUILDING ];
		return null === $building ? null : Cast::int( $building );
	}

	/**
	 * The generation new writes go to: the one being built when a reindex runs, else the live one.
	 */
	public function target(): int {
		return $this->building() ?? $this->live();
	}

	/**
	 * Whether a reindex is running.
	 */
	public function isBuilding(): bool {
		return null !== $this->building();
	}

	/**
	 * Whether the running reindex was asked to stop.
	 */
	public function isCancelled(): bool {
		return (bool) $this->all()[ self::CANCELLED ];
	}

	/**
	 * Begin a reindex into a generation no row can already carry: above the live one, any half-built one and
	 * any being cleaned.
	 *
	 * @param int $total Products to walk.
	 * @return int The generation being built.
	 */
	public function begin( int $total ): int {
		$state      = $this->all();
		$generation = max( Cast::int( $state[ self::LIVE ] ), Cast::int( $state[ self::BUILDING ] ), Cast::int( $state[ self::CLEANING ] ) ) + 1;

		$state[ self::BUILDING ]    = $generation;
		$state[ self::OFFSET ]      = 0;
		$state[ self::TOTAL ]       = $total;
		$state[ self::DONE ]        = 0;
		$state[ self::LAST_ID ]     = 0;
		$state[ self::STARTED_AT ]  = Clock::now();
		$state[ self::FINISHED_AT ] = null;
		$state[ self::ERRORS ]      = array();
		delete_option( Options::REINDEX_CANCEL );
		$this->save( $state );
		return $generation;
	}

	/**
	 * Record a batch's progress, re-reading the state first so nothing written meanwhile is lost.
	 *
	 * @param int      $walked  Products walked in this batch.
	 * @param int      $written Products written in this batch.
	 * @param int      $lastId  The highest product id walked.
	 * @param string[] $errors  New error lines.
	 */
	public function progress( int $walked, int $written, int $lastId, array $errors = array() ): void {
		$state                  = $this->all();
		$state[ self::OFFSET ]  = Cast::int( $state[ self::OFFSET ] ) + $walked;
		$state[ self::DONE ]    = Cast::int( $state[ self::DONE ] ) + $written;
		$state[ self::LAST_ID ] = max( Cast::int( $state[ self::LAST_ID ] ), $lastId );
		$state[ self::ERRORS ]  = array_slice( array_merge( (array) $state[ self::ERRORS ], $errors ), -50 );
		$this->save( $state );
	}

	/**
	 * Ask the running reindex to stop after its current batch.
	 */
	public function cancel(): void {
		$building = $this->building();
		if ( null !== $building ) {
			update_option( Options::REINDEX_CANCEL, $building, false );
		}
	}

	/**
	 * The built generation goes live; the old one is queued for cleanup.
	 *
	 * @return int The generation that was live before.
	 */
	public function switchLive(): int {
		$state    = $this->all();
		$previous = Cast::int( $state[ self::LIVE ] );

		$state[ self::LIVE ]        = Cast::int( $state[ self::BUILDING ] );
		$state[ self::BUILDING ]    = null;
		$state[ self::CLEANING ]    = $previous;
		$state[ self::FINISHED_AT ] = Clock::now();
		$this->save( $state );
		delete_option( Options::REINDEX_CANCEL );
		return $previous;
	}

	/**
	 * A reindex stopped without switching: the half-built generation is cleaned up.
	 *
	 * @return int|null The generation to remove.
	 */
	public function abandon(): ?int {
		$state    = $this->all();
		$building = $state[ self::BUILDING ];

		$state[ self::BUILDING ]    = null;
		$state[ self::CLEANING ]    = $building;
		$state[ self::FINISHED_AT ] = Clock::now();
		$this->save( $state );
		delete_option( Options::REINDEX_CANCEL );
		return null === $building ? null : Cast::int( $building );
	}

	/**
	 * The cleanup finished.
	 */
	public function cleaned(): void {
		$state                   = $this->all();
		$state[ self::CLEANING ] = null;
		$this->save( $state );
	}

	/**
	 * Remember that activation queued the first build.
	 */
	public function firstBuildQueued(): void {
		$state                       = $this->all();
		$state[ self::FIRST_QUEUED ] = true;
		$this->save( $state );
	}

	/**
	 * The generation the owner asked to stop, read fresh, or 0.
	 */
	private static function cancelledGeneration(): int {
		return Cast::int( Options::fresh( Options::REINDEX_CANCEL, 0 ) );
	}

	/**
	 * Write the state, without the derived cancel flag. Not autoloaded: it is read fresh anyway.
	 *
	 * @param array<string, mixed> $state State.
	 */
	private function save( array $state ): void {
		unset( $state[ self::CANCELLED ] );
		update_option( Options::INDEX_STATE, $state, false );
	}
}
