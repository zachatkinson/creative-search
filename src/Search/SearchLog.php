<?php
/**
 * The deferred log writer.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Search;

use CreativeBranding\Search\Domain\Scrub;
use CreativeBranding\Search\Repository\LogRepository;
use CreativeBranding\Search\Support\Clock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects one row per search with a non-empty query and writes them at `shutdown`, after the response
 * (spec: The search API → Logging). Nothing about the shopper is kept: the query as typed and as normalised,
 * both passed through `Domain\Scrub` first, the language, the result count, the source and the minute.
 */
final class SearchLog {

	public const SOURCES = array( 'suggest', 'page' );

	/**
	 * Rows waiting for shutdown.
	 *
	 * @var array<int, array{query: string, normalised: string, lang: string, results: int, source: string, created_at: string}>
	 */
	private array $rows = array();

	/**
	 * Whether the shutdown callback is attached.
	 *
	 * @var bool
	 */
	private bool $attached = false;

	/**
	 * Constructor.
	 *
	 * @param LogRepository $repository The log table.
	 */
	public function __construct( private LogRepository $repository ) {
	}

	/**
	 * Note a search.
	 *
	 * @param string $query   The query as typed (already trimmed and capped).
	 * @param string $lang    Language code.
	 * @param int    $results Products found.
	 * @param string $source  suggest or page.
	 */
	public function record( string $query, string $lang, int $results, string $source ): void {
		$query = Scrub::redact( $query );
		if ( '' === trim( $query ) ) {
			return;
		}
		$this->rows[] = array(
			'query'      => mb_substr( $query, 0, 200, 'UTF-8' ),
			'normalised' => self::normalise( $query ),
			'lang'       => $lang,
			'results'    => $results,
			'source'     => in_array( $source, self::SOURCES, true ) ? $source : 'suggest',
			'created_at' => substr( Clock::now(), 0, 16 ) . ':00',
		);
		if ( ! $this->attached ) {
			$this->attached = true;
			add_action( 'shutdown', array( $this, 'flush' ), 20 );
		}
	}

	/**
	 * Write what was collected. Attached to `shutdown`; the tests call it directly.
	 */
	public function flush(): void {
		$rows = $this->rows;
		$this->clear();
		remove_action( 'shutdown', array( $this, 'flush' ), 20 );
		$this->repository->insert( $rows );
	}

	/**
	 * Forget what is waiting without writing it (a test resets the request between cases).
	 */
	public function clear(): void {
		$this->rows     = array();
		$this->attached = false;
	}

	/**
	 * What is waiting (tests).
	 *
	 * @return array<int, array{query: string, normalised: string, lang: string, results: int, source: string, created_at: string}>
	 */
	public function pending(): array {
		return $this->rows;
	}

	/**
	 * A query as the log groups it: redacted, lower-cased, whitespace collapsed, 200 characters.
	 *
	 * @param string $query Query.
	 */
	public static function normalise( string $query ): string {
		$lower = mb_strtolower( Scrub::redact( $query ), 'UTF-8' );
		$flat  = (string) preg_replace( '/\s+/u', ' ', $lower );
		return mb_substr( trim( $flat ), 0, 200, 'UTF-8' );
	}
}
