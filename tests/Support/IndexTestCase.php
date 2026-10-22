<?php
/**
 * Base class for index integration tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Support;

use CreativeBranding\Search\Index\AnalyzerFactory;
use CreativeBranding\Search\Index\IndexQueue;
use CreativeBranding\Search\Index\IndexState;
use CreativeBranding\Search\Support\Options;
use CreativeBranding\Search\Support\Tables;

/**
 * Helpers for the index tests: a catalogue loader, the queue reset between cases, the plugin's pending jobs
 * run the way Action Scheduler's runner would, and readers for the four index tables.
 */
abstract class IndexTestCase extends IntegrationTestCase {

	/**
	 * The loader.
	 *
	 * @var WooCommerceCatalogue
	 */
	protected WooCommerceCatalogue $catalogue;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->catalogue = new WooCommerceCatalogue();
		$this->service( IndexQueue::class )->clear();
		$this->service( \CreativeBranding\Search\Search\SearchLog::class )->clear();
		$this->service( AnalyzerFactory::class )->flush();
		delete_option( Options::INDEX_STATE );
	}

	/**
	 * Tear down: nothing collected in one test reaches the shutdown of the process.
	 */
	public function tear_down(): void {
		$this->service( IndexQueue::class )->clear();
		$this->service( \CreativeBranding\Search\Search\SearchLog::class )->clear();
		parent::tear_down();
	}

	/**
	 * Write catalogue products by index and forget what their saves queued.
	 *
	 * @param int[] $indexes Zero-based catalogue indexes.
	 * @return int[] Product ids, in the same order.
	 */
	protected function products( array $indexes ): array {
		$ids = array();
		foreach ( $indexes as $i ) {
			$ids[] = $this->catalogue->product( WooCommerceCatalogue::spec( $i ) );
		}
		$this->service( IndexQueue::class )->clear();
		return $ids;
	}

	/**
	 * The plugin's pending Action Scheduler jobs.
	 *
	 * @return array<int, array{hook: string, args: array<int, mixed>}> By action id.
	 */
	protected function pendingJobs(): array {
		$out = array();
		foreach ( as_get_scheduled_actions(
			array(
				'group'    => Options::QUEUE_GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
				'orderby'  => 'action_id',
				'order'    => 'ASC',
			)
		) as $id => $action ) {
			$out[ (int) $id ] = array(
				'hook' => $action->get_hook(),
				'args' => $action->get_args(),
			);
		}
		return $out;
	}

	/**
	 * Flush the request's queue to Action Scheduler and run every pending job, and the jobs they queue, the
	 * way the runner would.
	 *
	 * @param int $max Safety stop.
	 * @return int Jobs run.
	 */
	protected function runQueue( int $max = 500 ): int {
		$this->service( IndexQueue::class )->flush();
		$ran = 0;
		while ( $ran < $max ) {
			$pending = $this->pendingJobs();
			if ( array() === $pending ) {
				break;
			}
			foreach ( array_keys( $pending ) as $actionId ) {
				\ActionScheduler::runner()->process_action( $actionId, 'csearch-tests' );
				++$ran;
			}
			$this->service( IndexQueue::class )->flush();
		}
		return $ran;
	}

	/**
	 * Document rows.
	 *
	 * @param string   $type     Type.
	 * @param int|null $objectId Object id, or null for all.
	 * @param int|null $generation Generation, or null for the live one.
	 * @return array<int, array<string, string|null>>
	 */
	protected function documents( string $type = 'product', ?int $objectId = null, ?int $generation = null ): array {
		global $wpdb;
		$generation ??= $this->service( IndexState::class )->live();
		$sql          = 'SELECT * FROM %i WHERE type = %s AND generation = %d';
		$args         = array( Tables::documents(), $type, $generation );
		if ( null !== $objectId ) {
			$sql   .= ' AND object_id = %d';
			$args[] = $objectId;
		}
		return $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY id', ...$args ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * The postings of a document: term to field to [tf, first position].
	 *
	 * @param int $documentId Document id.
	 * @return array<string, array<int, array{0: int, 1: int}>>
	 */
	protected function postingsOf( int $documentId ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT t.term, p.field, p.tf, p.first_pos FROM %i p JOIN %i t ON t.id = p.term_id WHERE p.document_id = %d', Tables::postings(), Tables::terms(), $documentId ), ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['term'] ][ (int) $row['field'] ] = array( (int) $row['tf'], (int) $row['first_pos'] );
		}
		return $out;
	}

	/**
	 * A term's df, or null when the dictionary lacks it.
	 *
	 * @param string $term Term.
	 */
	protected function df( string $term ): ?int {
		global $wpdb;
		$df = $wpdb->get_var( $wpdb->prepare( 'SELECT df FROM %i WHERE term = %s', Tables::terms(), $term ) ); // phpcs:ignore
		return null === $df ? null : (int) $df;
	}

	/**
	 * Rows in a table.
	 *
	 * @param string $table Full table name.
	 */
	protected function rowCount( string $table ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore
	}
}
