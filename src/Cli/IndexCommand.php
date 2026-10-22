<?php
/**
 * `wp csearch` index commands.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Cli;

use CreativeBranding\Search\Index\Indexer;
use CreativeBranding\Search\Index\IndexState;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Repository\DocumentRepository;
use CreativeBranding\Search\Repository\PostingRepository;
use CreativeBranding\Search\Repository\TermRepository;
use CreativeBranding\Search\Support\Cast;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Index, rebuild and inspect the search index. Every command exits non-zero on failure and prints JSON with
 * `--format=json` (spec: Admin → WP-CLI).
 */
final class IndexCommand {

	/**
	 * Constructor.
	 *
	 * @param Indexer            $indexer    The indexer.
	 * @param Reindex            $reindex    The reindex.
	 * @param IndexState         $state      The generations.
	 * @param DocumentRepository $documents  Documents.
	 * @param PostingRepository  $postings   Postings.
	 * @param TermRepository     $dictionary Terms.
	 */
	public function __construct(
		private Indexer $indexer,
		private Reindex $reindex,
		private IndexState $state,
		private DocumentRepository $documents,
		private PostingRepository $postings,
		private TermRepository $dictionary
	) {
	}

	/**
	 * Index products now.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Product ids (a variation indexes its parent).
	 *
	 * [--format=<format>]
	 * : text or json.
	 * ---
	 * default: text
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp csearch index 12 13
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function index( array $args, array $assoc = array() ): void {
		$json    = self::json( $assoc );
		$results = array();
		foreach ( $args as $arg ) {
			$id        = absint( $arg );
			$start     = hrtime( true );
			$indexed   = $this->indexer->indexProduct( $id );
			$ms        = round( ( hrtime( true ) - $start ) / 1e6, 1 );
			$results[] = array(
				'id'      => $id,
				'indexed' => $indexed,
				'ms'      => $ms,
			);
			if ( ! $json ) {
				WP_CLI::log(
					$indexed
						/* translators: 1: product id, 2: milliseconds */
						? sprintf( __( 'Indexed product %1$d in %2$s ms.', 'creative-search' ), $id, number_format_i18n( $ms, 1 ) )
						/* translators: %d: product id */
						: sprintf( __( 'Product %d is not indexable (missing, unpublished or excluded): removed from the index.', 'creative-search' ), $id )
				);
			}
		}
		$done = count( array_filter( array_column( $results, 'indexed' ) ) );
		if ( $json ) {
			WP_CLI::line( (string) wp_json_encode( $results ) );
		}
		/* translators: 1: products indexed, 2: products asked for */
		$summary = sprintf( _n( '%1$d of %2$d product indexed.', '%1$d of %2$d products indexed.', count( $args ), 'creative-search' ), $done, count( $args ) );
		if ( $done < count( $args ) ) {
			WP_CLI::error( $summary );
		}
		if ( ! $json ) {
			WP_CLI::success( $summary );
		}
	}

	/**
	 * Rebuild the whole index into a new generation and switch to it.
	 *
	 * ## OPTIONS
	 *
	 * [--inline]
	 * : Run every batch now in this process instead of queuing jobs.
	 *
	 * [--cancel]
	 * : Stop the reindex that is queued or running.
	 *
	 * [--format=<format>]
	 * : text or json.
	 * ---
	 * default: text
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp csearch reindex
	 *     wp csearch reindex --inline
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function reindex( array $args, array $assoc ): void {
		$json = self::json( $assoc );
		if ( isset( $assoc['cancel'] ) ) {
			if ( ! $this->reindex->cancel() ) {
				WP_CLI::error( __( 'No reindex is queued or running.', 'creative-search' ) );
			}
			$this->finish( $json, array( 'cancelled' => true ), __( 'The reindex will stop after its current batch.', 'creative-search' ) );
			return;
		}
		if ( isset( $assoc['inline'] ) ) {
			$start = hrtime( true );
			$ran   = $this->reindex->runInline(
				static function ( array $state ) use ( $json ): void {
					if ( ! $json ) {
						/* translators: 1: products walked, 2: products to walk */
						WP_CLI::log( sprintf( _n( '%1$d of %2$d product', '%1$d of %2$d products', Cast::int( $state[ IndexState::TOTAL ] ), 'creative-search' ), Cast::int( $state[ IndexState::OFFSET ] ), Cast::int( $state[ IndexState::TOTAL ] ) ) );
					}
				}
			);
			if ( ! $ran ) {
				WP_CLI::error( __( 'A reindex is already running. Cancel it first with --cancel.', 'creative-search' ) );
			}
			$seconds = round( ( hrtime( true ) - $start ) / 1e9, 1 );
			$state   = $this->state->all();
			$this->finish(
				$json,
				array(
					'generation' => $this->state->live(),
					'seconds'    => $seconds,
					'products'   => Cast::int( $state[ IndexState::DONE ] ),
					'errors'     => array_values( (array) $state[ IndexState::ERRORS ] ),
				),
				/* translators: 1: generation number, 2: seconds */
				sprintf( __( 'Generation %1$d is live (%2$s s).', 'creative-search' ), $this->state->live(), number_format_i18n( $seconds, 1 ) )
			);
			return;
		}
		if ( ! $this->reindex->queue() ) {
			WP_CLI::error( __( 'A reindex is already running or queued.', 'creative-search' ) );
		}
		$this->finish( $json, array( 'queued' => true ), __( 'Reindex queued. Follow it with: wp csearch status', 'creative-search' ) );
	}

	/**
	 * Show the index: the live generation, a running reindex, the counts.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function status( array $args, array $assoc ): void {
		$state    = $this->state->all();
		$live     = $this->state->live();
		$terms    = $this->dictionary->counts();
		$building = null === $state[ IndexState::BUILDING ]
			? '-'
			: sprintf(
				/* translators: 1: generation, 2: products walked, 3: products to walk */
				_n( 'generation %1$d: %2$d of %3$d product', 'generation %1$d: %2$d of %3$d products', Cast::int( $state[ IndexState::TOTAL ] ), 'creative-search' ),
				Cast::int( $state[ IndexState::BUILDING ] ),
				Cast::int( $state[ IndexState::OFFSET ] ),
				Cast::int( $state[ IndexState::TOTAL ] )
			) . ( (bool) $state[ IndexState::CANCELLED ] ? ' ' . __( '(cancelling)', 'creative-search' ) : '' );
		$rows   = array(
			array(
				'key'   => 'live generation',
				'value' => $live,
			),
			array(
				'key'   => 'building',
				'value' => $building,
			),
			array(
				'key'   => 'products',
				'value' => $this->documents->productCount( $live ),
			),
			array(
				'key'   => 'documents',
				'value' => $this->documents->count( $live ),
			),
			array(
				'key'   => 'postings',
				'value' => $this->postings->count( $live ),
			),
			array(
				'key'   => 'terms',
				/* translators: 1: terms in the dictionary, 2: terms some document carries */
				'value' => sprintf( __( '%1$d; in use: %2$d', 'creative-search' ), $terms['terms'], $terms['used'] ),
			),
			array(
				'key'   => 'last finished',
				'value' => is_string( $state[ IndexState::FINISHED_AT ] ) ? $state[ IndexState::FINISHED_AT ] . ' UTC' : '-',
			),
			array(
				'key'   => 'errors',
				'value' => count( (array) $state[ IndexState::ERRORS ] ),
			),
		);
		$format = isset( $assoc['format'] ) && is_string( $assoc['format'] ) ? $assoc['format'] : 'table';
		WP_CLI\Utils\format_items( $format, $rows, array( 'key', 'value' ) );
	}

	/**
	 * Whether JSON was asked for.
	 *
	 * @param array<string, mixed> $assoc Flags.
	 */
	private static function json( array $assoc ): bool {
		return isset( $assoc['format'] ) && 'json' === $assoc['format'];
	}

	/**
	 * Print the outcome: JSON, or a success line.
	 *
	 * @param bool                 $json    JSON asked for.
	 * @param array<string, mixed> $data    The outcome.
	 * @param string               $message The success line.
	 */
	private function finish( bool $json, array $data, string $message ): void {
		if ( $json ) {
			WP_CLI::line( (string) wp_json_encode( $data ) );
			return;
		}
		WP_CLI::success( $message );
	}
}
