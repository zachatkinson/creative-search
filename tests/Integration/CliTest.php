<?php
/**
 * WP-CLI command tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Cli\IndexCommand;
use CreativeBranding\Search\Cli\MaintenanceCommand;
use CreativeBranding\Search\Cli\SearchCommand;
use CreativeBranding\Search\Cli\SynonymsCommand;
use CreativeBranding\Search\Repository\LogRepository;
use CreativeBranding\Search\Repository\SynonymRepository;
use CreativeBranding\Search\Search\SearchLog;
use CreativeBranding\Search\Support\Clock;
use CreativeBranding\Search\Index\IndexState;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Tests\Support\IndexTestCase;
use RuntimeException;
use WP_CLI;

require_once dirname( __DIR__ ) . '/Support/wp-cli-double.php';

/**
 * `wp csearch index`, `reindex`, `status`, `explain`, `search`, `synonyms`, `log purge` and `cache flush` against a test double of WP_CLI that records what it
 * prints and throws where WP-CLI would exit non-zero.
 */
final class CliTest extends IndexTestCase {

	private function command(): IndexCommand {
		WP_CLI::$lines = array();
		return $this->service( IndexCommand::class );
	}

	public function test_index_reports_each_product_and_fails_when_one_fails(): void {
		[ $id ] = $this->products( array( 15 ) );
		$this->command()->index( array( (string) $id ) );
		self::assertStringStartsWith( sprintf( 'log: Indexed product %d in ', $id ), WP_CLI::$lines[0] );
		self::assertSame( 'success: 1 of 1 product indexed.', WP_CLI::$lines[1] );
		self::assertCount( 1, $this->documents( 'product', $id ) );

		try {
			$this->command()->index( array( (string) $id, '999999' ) );
			self::fail( 'a product that cannot be indexed exits non-zero' );
		} catch ( RuntimeException $e ) {
			self::assertSame( '1 of 2 products indexed.', $e->getMessage() );
		}
		self::assertSame( 'log: Product 999999 is not indexable (missing, unpublished or excluded): removed from the index.', WP_CLI::$lines[1] );

		$this->command()->index( array( (string) $id ), array( 'format' => 'json' ) );
		$json = json_decode( substr( WP_CLI::$lines[0], strlen( 'line: ' ) ), true );
		self::assertSame( $id, $json[0]['id'] );
		self::assertTrue( $json[0]['indexed'] );
		self::assertCount( 1, WP_CLI::$lines, 'JSON only' );
	}

	public function test_reindex_queues_inline_and_cancels(): void {
		$this->products( array( 15 ) );
		$command = $this->command();
		$command->reindex( array(), array() );
		self::assertSame( 'success: Reindex queued. Follow it with: wp csearch status', WP_CLI::$lines[0] );
		try {
			$command->reindex( array(), array() );
			self::fail( 'queued twice' );
		} catch ( RuntimeException $e ) {
			self::assertSame( 'A reindex is already running or queued.', $e->getMessage() );
		}
		$command->reindex( array(), array( 'cancel' => true ) );
		self::assertSame( 'success: The reindex will stop after its current batch.', WP_CLI::$lines[1], 'a queued reindex is unqueued' );
		self::assertSame( array(), $this->pendingJobs() );

		WP_CLI::$lines = array();
		$command->reindex( array(), array( 'inline' => true ) );
		self::assertSame( 'log: 1 of 1 products', WP_CLI::$lines[0] );
		self::assertStringStartsWith( 'success: Generation 2 is live (', (string) end( WP_CLI::$lines ) );

		WP_CLI::$lines = array();
		$command->reindex( array(), array( 'inline' => true, 'format' => 'json' ) );
		$json = json_decode( substr( WP_CLI::$lines[0], strlen( 'line: ' ) ), true );
		self::assertSame( 3, $json['generation'] );
		self::assertSame( 1, $json['products'] );
		self::assertSame( array(), $json['errors'] );

		try {
			$command->reindex( array(), array( 'cancel' => true ) );
			self::fail( 'nothing to cancel' );
		} catch ( RuntimeException $e ) {
			self::assertSame( 'No reindex is queued or running.', $e->getMessage() );
		}

		$this->service( Reindex::class )->start();
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'A reindex is already running. Cancel it first with --cancel.' );
		$command->reindex( array(), array( 'inline' => true ) );
	}

	public function test_status_lists_the_index(): void {
		[ $id ] = $this->products( array( 0 ) );
		$this->command()->index( array( (string) $id ) );
		WP_CLI::$lines = array();
		$this->command()->status( array(), array( 'format' => 'json' ) );
		$rows = array_column( WP_CLI::$items, 'value', 'key' );
		self::assertSame( 1, $rows['live generation'] );
		self::assertSame( '-', $rows['building'] );
		self::assertSame( 1, $rows['products'] );
		self::assertSame( 1, $rows['documents'] );
		self::assertGreaterThan( 20, $rows['postings'] );
		self::assertMatchesRegularExpression( '/^\d+ \(\d+ in use\)$/', $rows['terms'] );
		self::assertSame( 0, $rows['errors'] );
		self::assertSame( 'json', WP_CLI::$format );
		self::assertSame( 1, $this->service( IndexState::class )->live() );
	}

	public function test_explain_shows_each_candidate_and_its_parts(): void {
		$this->products( array( 0, 21 ) );
		$this->service( Reindex::class )->runInline();
		WP_CLI::$lines = array();
		$this->service( SearchCommand::class )->explain( array( 'kazumi dealer' ), array( 'format' => 'json' ) );
		self::assertSame( 'log: Query "kazumi dealer" in en: 1 product, 1 term.', WP_CLI::$lines[0] );
		$row = WP_CLI::$items[0];
		self::assertSame( 1, $row['rank'] );
		self::assertSame( 'KAZUMI Skate Deck, Dealer', $row['title'] );
		self::assertStringContainsString( 'kazumi→kazumi exact name w10', $row['tokens'] );
		self::assertSame( 4.0, $row['phrase'], 'in order, not adjacent' );
	}

	public function test_search_lists_the_ranked_answer_without_logging(): void {
		$this->products( array( 0, 21 ) );
		$this->service( Reindex::class )->runInline();
		WP_CLI::$lines = array();
		$this->service( SearchCommand::class )->search( array( 'kazumi dealer' ), array( 'format' => 'json' ) );
		self::assertSame( 'KAZUMI Skate Deck, Dealer', WP_CLI::$items[0]['title'] );
		self::assertSame( array( 'rank', 'type', 'id', 'title', 'score' ), array_keys( WP_CLI::$items[0] ) );
		self::assertSame( 0, $this->service( LogRepository::class )->count() + count( $this->service( SearchLog::class )->pending() ), 'not logged' );
		$this->expectException( RuntimeException::class );
		$this->service( SearchCommand::class )->search( array( '  ' ), array() );
	}

	public function test_synonyms_add_list_delete_export_import(): void {
		$command       = $this->service( SynonymsCommand::class );
		WP_CLI::$lines = array();
		$command->add( array( 'board', 'deck' ), array() );
		self::assertMatchesRegularExpression( '/^success: Synonym \d+ added: "board" means "deck"\.$/', WP_CLI::$lines[0] );
		$command->add( array( 'board', 'deck' ), array() );
		self::assertSame( 'warning: "board" already means "deck"; nothing was added.', WP_CLI::$lines[1] );
		$command->add( array( 'trucks', 'axles' ), array( 'one-way' => true ) );
		try {
			$command->add( array( 'deck', 'deck' ), array() );
			self::fail( 'an invalid synonym exits non-zero' );
		} catch ( RuntimeException $e ) {
			self::assertSame( 'A word cannot be its own synonym.', $e->getMessage() );
		}

		$command->list_( array(), array( 'format' => 'json' ) );
		self::assertSame( array( 'trucks', 'board' ), array_column( WP_CLI::$items, 'word' ) );
		self::assertSame( array( 'no', 'yes' ), array_column( WP_CLI::$items, 'both_ways' ) );

		WP_CLI::$lines = array();
		$command->export( array(), array() );
		$csv = substr( WP_CLI::$lines[0], strlen( 'line: ' ) );
		self::assertSame( "lang,word,means,both_ways\nen,board,deck,yes\nen,trucks,axles,no", $csv );
		WP_CLI::$lines = array();
		$command->export( array(), array( 'format' => 'json' ) );
		self::assertSame( 'line: [{"lang":"en","word":"board","means":"deck","both_ways":"yes"},{"lang":"en","word":"trucks","means":"axles","both_ways":"no"}]', WP_CLI::$lines[0] );

		$ids           = array_column( WP_CLI::$items, 'id' );
		WP_CLI::$lines = array();
		$command->delete( array_map( 'strval', $ids ), array( 'format' => 'json' ) );
		self::assertSame( 'line: {"deleted":2}', WP_CLI::$lines[0] );
		self::assertSame( 0, $this->service( SynonymRepository::class )->count() );
		try {
			$command->delete( array( '999' ), array() );
			self::fail( 'deleting nothing exits non-zero' );
		} catch ( RuntimeException $e ) {
			self::assertSame( '0 of 1 synonym deleted.', $e->getMessage() );
		}

		$file = wp_tempnam( 'synonyms.csv' );
		file_put_contents( $file, $csv . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		WP_CLI::$lines = array();
		$command->import( array( $file ), array( 'format' => 'json' ) );
		self::assertSame( 'line: {"added":2,"existing":0,"skipped":0}', WP_CLI::$lines[0] );
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$this->expectException( RuntimeException::class );
		$command->import( array( '/no/such/file.csv' ), array() );
	}

	public function test_log_purge_rolls_up_first_and_cache_flush(): void {
		Clock::freeze( strtotime( '2026-10-21 12:00:00 UTC' ) );
		$log  = $this->service( LogRepository::class );
		$rows = array();
		foreach ( array( '2026-10-21 11:00:00', '2026-10-18 11:00:00', '2026-06-01 11:00:00' ) as $at ) {
			$rows[] = array(
				'query'      => 'deck',
				'normalised' => 'deck',
				'lang'       => 'en',
				'results'    => 3,
				'source'     => 'page',
				'created_at' => $at,
			);
		}
		$log->insert( $rows );
		$command       = $this->service( MaintenanceCommand::class );
		WP_CLI::$lines = array();
		$command->purge( array(), array() );
		self::assertSame( 'success: 1 search deleted from the log.', WP_CLI::$lines[0], 'the 90-day retention by default' );
		$command->purge( array(), array( 'days' => '1' ) );
		self::assertSame( 'success: 1 search deleted from the log.', WP_CLI::$lines[1] );
		self::assertSame( 1, \CreativeBranding\Search\Settings\Settings::get( 'stats' )['2026-10-18']['searches'] ?? null, 'the day was rolled up before its rows went, so the chart keeps it' );
		$command->purge(
			array(),
			array(
				'days'   => '0',
				'format' => 'json',
			)
		);
		self::assertSame( 'line: {"deleted":1}', WP_CLI::$lines[2] );
		self::assertSame( 0, $log->count() );
		try {
			$command->purge( array(), array( 'days' => '-1' ) );
			self::fail( 'a negative period exits non-zero' );
		} catch ( RuntimeException $e ) {
			self::assertSame( '--days takes a number of days, 0 or more.', $e->getMessage() );
		}

		$key = \CreativeBranding\Search\Search\Cache::key( 1, 'suggest', array( 'deck' ) );
		$this->service( \CreativeBranding\Search\Search\Cache::class )->set( $key, array( 'x' ) );
		$command->flush( array(), array() );
		self::assertNull( $this->service( \CreativeBranding\Search\Search\Cache::class )->get( $key ) );
		self::assertSame( 'success: Search answers dropped; the next searches are answered fresh.', WP_CLI::$lines[3] );
	}
}
