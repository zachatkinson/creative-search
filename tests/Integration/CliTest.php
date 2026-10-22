<?php
/**
 * WP-CLI command tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Cli\IndexCommand;
use CreativeBranding\Search\Cli\SearchCommand;
use CreativeBranding\Search\Index\IndexState;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Tests\Support\IndexTestCase;
use RuntimeException;
use WP_CLI;

require_once dirname( __DIR__ ) . '/Support/wp-cli-double.php';

/**
 * `wp csearch index`, `reindex`, `status` and `explain` against a test double of WP_CLI that records what it
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
}
