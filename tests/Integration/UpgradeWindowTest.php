<?php
/**
 * Between an update and its schema upgrade.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Domain\Hit;
use CreativeBranding\Search\Hooks\JobHooks;
use CreativeBranding\Search\Index\Indexer;
use CreativeBranding\Search\Index\IndexQueue;
use CreativeBranding\Search\Index\IndexState;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Install\Migration;
use CreativeBranding\Search\Install\Upgrader;
use CreativeBranding\Search\Search\SearchService;
use CreativeBranding\Search\Support\Clock;
use CreativeBranding\Search\Support\Options;
use CreativeBranding\Search\Support\Tables;
use CreativeBranding\Search\Tests\Support\IndexTestCase;
use RuntimeException;

/**
 * D-090: a shop updated to schema 2 keeps searching before its upgrade has run, its writes wait for the upgrade,
 * any request queues the upgrade, and the rebuild the upgrade owes is not lost to a reindex already running.
 */
final class UpgradeWindowTest extends IndexTestCase {

	public function test_the_shop_searches_as_before_while_the_upgrade_waits(): void {
		global $wpdb;
		$ids = $this->products( array( 0, 1, 2, 3, 4, 5 ) );
		foreach ( $ids as $id ) {
			$this->service( Indexer::class )->indexProduct( $id );
		}
		$queries = array( 'kazumi', 'deck', 'kazumi deck', 'dekc', 'wheel', 'tee -kazumi' );
		$before  = $this->answers( $queries );
		self::assertNotSame( array(), array_filter( $before ) );

		// The tables as an update finds them: schema 1 postings, no term counts. Another request holds the lock.
		$this->withRealTables(
			static function () use ( $wpdb ): void {
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP PRIMARY KEY, DROP COLUMN lang, DROP COLUMN product, ADD PRIMARY KEY (term_id,document_id,field)', Tables::postings() ) ); // phpcs:ignore
				$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', Tables::stats() ) ); // phpcs:ignore
			}
		);
		update_option( Options::DB_VERSION, '1' );
		add_option( Options::UPGRADE_LOCK, Clock::timestamp(), '', false );
		self::assertTrue( Upgrader::needed() );
		self::assertSame( $before, $this->answers( $queries ), 'the same products in the same order, from schema 1 reads' );

		// Writes wait for the upgrade; the rebuild it owes writes them.
		$ids[] = $this->products( array( 6 ) )[0];
		try {
			$this->service( Indexer::class )->indexProduct( end( $ids ) );
			self::fail( 'a write went ahead of the upgrade' );
		} catch ( RuntimeException $e ) {
			self::assertStringContainsString( 'upgraded', $e->getMessage() );
		}
		self::assertSame( $before, $this->answers( $queries ), 'and nothing was half-written' );

		// Any request queues the upgrade as a job; the job upgrades once the lock is free.
		$this->service( JobHooks::class )->queueUpgrade();
		self::assertTrue( IndexQueue::isPending( IndexQueue::UPGRADE ) );
		delete_option( Options::UPGRADE_LOCK );
		// As real tables: the suite would create the stats table as a temporary one, which MySQL 8.0 and MariaDB
		// 10.6 leave out of information_schema, so the migration could never see itself complete.
		$this->withRealTables( fn() => $this->service( JobHooks::class )->upgrade() );
		self::assertFalse( Upgrader::needed() );
		self::assertTrue( Migration::complete() );
		self::assertSame( $before, $this->answers( $queries ), 'the same after the upgrade, from the statement' );
		self::assertTrue( IndexQueue::isPending( Reindex::START ), 'the owed rebuild is queued' );

		// The DDL committed the products, so the rollback would leave them for the next test: delete them for good.
		foreach ( $ids as $id ) {
			wc_get_product( $id )?->delete( true );
		}
		$wpdb->query( 'COMMIT' );
	}

	public function test_a_rebuild_owed_during_a_running_reindex_follows_it(): void {
		$state    = $this->service( IndexState::class );
		$reindex  = $this->service( Reindex::class );
		$building = $state->begin( 1 );
		Upgrader::rebuildAfter( '1' );
		self::assertSame( '0', (string) get_option( Options::REBUILD_OWED ) );
		self::assertFalse( IndexQueue::isPending( Reindex::START ), 'not while one runs' );

		// The running reindex was started before the upgrade, so it does not settle the debt; when it is done,
		// the next is queued.
		$state->cancel();
		$state->abandon();
		self::assertNull( $reindex->cleanup( $building, 0 ) );
		self::assertTrue( IndexQueue::isPending( Reindex::START ) );

		// The owed rebuild is cancelled in its turn: still owed, and queued again once it is cleaned up.
		$this->service( IndexQueue::class )->clear();
		$first = $reindex->start();
		self::assertNotNull( $first );
		self::assertSame( (string) $first[1][0], (string) get_option( Options::REBUILD_OWED ), 'starting does not settle it' );
		$state->cancel();
		$state->abandon();
		self::assertNull( $reindex->cleanup( (int) $first[1][0], 0 ) );
		self::assertTrue( IndexQueue::isPending( Reindex::START ) );

		// One that goes live settles it.
		$this->service( IndexQueue::class )->clear();
		$second = $reindex->start();
		self::assertNotNull( $second );
		self::assertSame( $first[1][0], $second[1][0], 'the abandoned number is reused, so numbers alone cannot tell them apart' );
		$reindex->terms( (int) $second[1][0], 0 );
		self::assertSame( (int) $second[1][0], $state->live() );
		self::assertFalse( get_option( Options::REBUILD_OWED ), 'a generation begun after the upgrade went live' );
	}

	public function test_nothing_is_queued_while_the_schema_is_current(): void {
		$this->service( JobHooks::class )->queueUpgrade();
		self::assertFalse( IndexQueue::isPending( IndexQueue::UPGRADE ) );
		self::assertFalse( $this->service( Reindex::class )->queueOwed() );
	}

	/**
	 * Each query's products as `object id:total`.
	 *
	 * @param string[] $queries Queries.
	 * @return array<string, string[]>
	 */
	private function answers( array $queries ): array {
		$out = array();
		foreach ( $queries as $q ) {
			wp_cache_flush();
			$out[ $q ] = array_map( static fn( Hit $h ): string => $h->document->objectId . ':' . number_format( $h->score->total(), 4 ), $this->service( SearchService::class )->run( $q, 'en' )->products );
		}
		return $out;
	}
}
