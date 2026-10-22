<?php
/**
 * Full reindex tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Index\Indexer;
use CreativeBranding\Search\Index\IndexQueue;
use CreativeBranding\Search\Index\IndexState;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Support\Tables;
use CreativeBranding\Search\Tests\Support\IndexTestCase;

/**
 * The reindex builds N+1 beside N and switches; it is not started twice; cancel gives the half-built
 * generation up; live changes during a reindex reach both generations; the cleanup puts df back.
 */
final class ReindexTest extends IndexTestCase {

	private function reindex(): Reindex {
		return $this->service( Reindex::class );
	}

	private function state(): IndexState {
		return $this->service( IndexState::class );
	}

	public function test_reindex_builds_the_next_generation_switches_and_cleans_up(): void {
		$ids = $this->products( array( 0, 1, 6, 15 ) );
		$this->service( Indexer::class )->indexProduct( $ids[0] );
		self::assertSame( 1, $this->state()->live() );
		self::assertSame( 1, $this->df( 'kazumi' ) );

		$switched = array();
		$listener = static function ( int $generation, int $previous ) use ( &$switched ): void {
			$switched = array( $generation, $previous );
		};
		add_action( 'csearch_index_switched', $listener, 10, 2 );
		self::assertTrue( $this->reindex()->queue() );
		self::assertFalse( $this->reindex()->queue(), 'not queued twice' );
		$this->runQueue();
		remove_action( 'csearch_index_switched', $listener, 10 );

		self::assertSame( 2, $this->state()->live() );
		self::assertNull( $this->state()->building() );
		self::assertSame( array( 2, 1 ), $switched );
		self::assertCount( 4, $this->documents( 'product', null, 2 ) );
		self::assertSame( array(), $this->documents( 'product', null, 1 ), 'the old generation is gone' );
		self::assertNotSame( array(), $this->documents( 'category', null, 2 ), 'terms at the end' );
		self::assertNotSame( array(), $this->documents( 'brand', null, 2 ) );
		self::assertSame( 2, $this->df( 'kazumi' ), 'the product and the KAZUMI brand document: the old generation\'s count is gone after the cleanup' );
		$state = $this->state()->all();
		self::assertSame( 4, $state[ IndexState::OFFSET ] );
		self::assertSame( 4, $state[ IndexState::TOTAL ] );
		self::assertSame( 4, $state[ IndexState::DONE ] );
		self::assertNull( $state[ IndexState::CLEANING ] );
		self::assertIsString( $state[ IndexState::FINISHED_AT ] );
	}

	public function test_a_reindex_already_running_is_not_started_twice(): void {
		$this->products( array( 15 ) );
		self::assertNotNull( $this->reindex()->start() );
		self::assertNull( $this->reindex()->start() );
		self::assertFalse( $this->reindex()->queue() );
		self::assertFalse( $this->reindex()->runInline() );
	}

	public function test_cancel_gives_up_the_half_built_generation(): void {
		$ids = $this->products( array( 0, 15 ) );
		$this->service( Indexer::class )->indexProduct( $ids[0] );
		$first = $this->reindex()->start();
		self::assertSame( array( Reindex::BATCH_ACTION, array( 2, 0 ) ), $first );
		IndexQueue::schedule( $first[0], $first[1] ); // The chain is alive: its next job waits.
		$this->service( Indexer::class )->indexProduct( $ids[1] ); // A live save during the reindex.
		self::assertCount( 1, $this->documents( 'product', $ids[1], 2 ), 'a live change reaches the generation being built' );
		self::assertCount( 1, $this->documents( 'product', $ids[1], 1 ), 'and the live one' );

		self::assertTrue( $this->reindex()->cancel() );
		$next = $this->reindex()->run( $first[0], $first[1] );
		self::assertSame( array( Reindex::CLEANUP, array( 2, 0 ) ), $next, 'the batch sees the flag and abandons' );
		self::assertNull( $this->reindex()->run( $next[0], $next[1] ) );
		self::assertSame( 1, $this->state()->live() );
		self::assertNull( $this->state()->building() );
		self::assertSame( array(), $this->documents( 'product', null, 2 ) );
		self::assertCount( 2, $this->documents( 'product', null, 1 ), 'the live index is untouched' );
		self::assertSame( 1, $this->df( 'kazumi' ) );
		self::assertFalse( $this->reindex()->cancel(), 'nothing left to cancel' );
		self::assertNull( $this->reindex()->batch( 2, 0 ), 'a stale job does nothing' );
	}

	public function test_cleanup_never_touches_the_live_or_building_generation(): void {
		[ $id ] = $this->products( array( 15 ) );
		$this->service( Indexer::class )->indexProduct( $id );
		self::assertNull( $this->reindex()->cleanup( 1, 0 ) );
		self::assertCount( 1, $this->documents( 'product', $id ) );
	}

	public function test_first_build_is_queued_once(): void {
		$this->products( array( 15 ) );
		self::assertTrue( $this->reindex()->queueFirstBuild() );
		self::assertFalse( $this->reindex()->queueFirstBuild() );
		self::assertSame( array( Reindex::START ), array_column( $this->pendingJobs(), 'hook' ) );
	}

	public function test_inline_reindex_runs_every_step(): void {
		$this->products( array( 0, 15 ) );
		$steps = 0;
		self::assertTrue(
			$this->reindex()->runInline(
				static function () use ( &$steps ): void {
					++$steps;
				}
			)
		);
		self::assertSame( 2, $this->state()->live() );
		self::assertGreaterThanOrEqual( 3, $steps, 'a product batch, a term batch, the cleanup' );
		self::assertSame( array(), $this->pendingJobs(), 'nothing queued' );
		self::assertSame( 0, (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM %i WHERE generation = 1', Tables::postings() ) ) ); // phpcs:ignore
	}

	public function test_job_errors_are_recorded_not_fatal(): void {
		$ids   = $this->products( array( 0, 15 ) );
		$throw = static function ( array $doc ) {
			if ( 'Mainline Griptape, Perforated' === $doc['title'] ) {
				throw new \RuntimeException( 'broken filter' );
			}
			return $doc;
		};
		add_filter( 'csearch_document', $throw );
		$this->reindex()->runInline();
		remove_filter( 'csearch_document', $throw );
		$state = $this->state()->all();
		self::assertSame( 1, $state[ IndexState::DONE ] );
		self::assertSame( array( sprintf( '#%d: broken filter', $ids[1] ) ), $state[ IndexState::ERRORS ] );
		self::assertSame( 2, $this->state()->live(), 'the switch still happens' );
		self::assertSame( IndexQueue::BATCH, Reindex::BATCH, 'batches of 100' );
	}

	public function test_a_cancel_written_by_another_process_is_seen_by_the_running_batch(): void {
		global $wpdb;
		$this->products( array( 15 ) );
		$first = $this->reindex()->start();
		self::assertNotNull( $first );
		$this->state()->all(); // Prime the request's option cache, as a long job would.
		// Another process (the Tools screen, WP-CLI) sets the flag straight in the database.
		$wpdb->insert( $wpdb->options, array( 'option_name' => 'csearch_reindex_cancel', 'option_value' => '2', 'autoload' => 'off' ) ); // phpcs:ignore
		self::assertTrue( $this->state()->isCancelled() );
		self::assertSame( array( Reindex::CLEANUP, array( 2, 0 ) ), $this->reindex()->run( $first[0], $first[1] ) );
		self::assertFalse( get_option( 'csearch_reindex_cancel' ), 'the flag is cleared with the abandon' );
	}

	public function test_progress_never_overwrites_a_cancel(): void {
		$this->products( array( 15 ) );
		$this->reindex()->start();
		$this->state()->cancel();
		$this->state()->progress( 1, 1, 10 );
		self::assertTrue( $this->state()->isCancelled() );
	}

	public function test_a_reindex_whose_chain_died_can_be_cancelled(): void {
		$this->products( array( 15 ) );
		$this->reindex()->start(); // Started, but its next job never ran and nothing is queued.
		self::assertTrue( $this->reindex()->cancel() );
		self::assertNull( $this->state()->building(), 'abandoned at once' );
		self::assertSame( array( Reindex::CLEANUP ), array_column( $this->pendingJobs(), 'hook' ) );
		self::assertTrue( $this->reindex()->queue(), 'a new reindex can start' );
	}

	public function test_a_new_generation_never_reuses_one_being_cleaned(): void {
		$this->products( array( 15 ) );
		$this->reindex()->start();
		$this->reindex()->cancel(); // Abandons 2, cleanup queued but not run.
		$job = $this->reindex()->start();
		self::assertSame( array( Reindex::BATCH_ACTION, array( 3, 0 ) ), $job );
		while ( null !== $job ) {
			$job = $this->reindex()->run( $job[0], $job[1] );
		}
		$this->runQueue(); // The abandoned generation's cleanup.
		self::assertSame( 3, $this->state()->live() );
		self::assertSame( array(), $this->documents( 'product', null, 2 ) );
		self::assertNull( $this->state()->all()[ IndexState::CLEANING ] );
	}

	public function test_a_product_trashed_mid_walk_does_not_shift_the_rest(): void {
		$ids = $this->products( array( 15, 16, 17 ) );
		$job = $this->reindex()->start();
		self::assertNotNull( $job );
		// The walk is keyset: the batch after the first id carries that id.
		self::assertSame( array( $ids[1], $ids[2] ), $this->service( \CreativeBranding\Search\Repository\ProductIdRepository::class )->publishedAfter( $ids[0], 10 ) );
		wp_trash_post( $ids[0] );
		while ( null !== $job ) {
			$job = $this->reindex()->run( $job[0], $job[1] );
		}
		self::assertCount( 2, $this->documents( 'product' ) );
		self::assertSame( 2, $this->state()->all()[ IndexState::DONE ] );
	}

	public function test_cleanup_restores_df_with_many_documents_per_term(): void {
		$languages = array( \CreativeBranding\Search\Tests\Support\WooCommerceCatalogue::class, 'bilingual' );
		add_filter( 'csearch_languages', $languages );
		$this->service( \CreativeBranding\Search\Index\AnalyzerFactory::class )->flush();
		$this->products( array( 0, 1, 2, 21 ) );
		$this->reindex()->runInline();
		$after = $this->df( 'kazumi' );
		$this->reindex()->runInline();
		remove_filter( 'csearch_languages', $languages );
		$this->service( \CreativeBranding\Search\Index\AnalyzerFactory::class )->flush();
		self::assertSame( $after, $this->df( 'kazumi' ), 'a second full reindex and cleanup leave df where the first did' );
		self::assertSame( 6, $after, 'two KAZUMI products and the brand, in two languages' );
	}
}
