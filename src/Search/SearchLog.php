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
use CreativeBranding\Search\Settings\Settings;
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
	 * The request header an agency's test suite sends so its searches stay out of the log (D-139).
	 */
	public const TEST_HEADER = 'X-Creative-Test';

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
		if ( '' === trim( $query ) || $this->ignored() ) {
			return;
		}
		/**
		 * Whether to log a search. Return false to leave it out of the log and every count built from it, as a
		 * test suite or a monitoring check might (D-139). Runs after the staff and test-header checks.
		 *
		 * @since 1.6.0
		 *
		 * @param bool   $log     True.
		 * @param string $query   The query as typed, already redacted (`Domain\Scrub`).
		 * @param string $lang    Its language.
		 * @param int    $results Products found.
		 * @param string $source  `suggest` or `page`.
		 */
		if ( false === apply_filters( 'csearch_log_search', true, $query, $lang, $results, $source ) ) {
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
	 * Whether this request's searches and clicks stay out of the log (D-139): it carries `X-Creative-Test: 1`, or
	 * it comes from a signed-in user who can edit products while `log_staff_searches` is off. The panel's REST
	 * requests carry no nonce, so WordPress treats them as signed out; the logged-in cookie is read directly to
	 * know who sent them. It only ever leaves a row out, never grants anything, so it needs no nonce.
	 */
	public function ignored(): bool {
		// Compared, never stored or printed: only an exact "1" counts.
		if ( isset( $_SERVER['HTTP_X_CREATIVE_TEST'] ) && '1' === $_SERVER['HTTP_X_CREATIVE_TEST'] ) {
			return true;
		}
		if ( Settings::isOn( 'log_staff_searches' ) ) {
			return false;
		}
		$user = get_current_user_id();
		if ( 0 === $user ) {
			$user = (int) wp_validate_auth_cookie( '', 'logged_in' );
		}
		return $user > 0 && user_can( $user, 'edit_products' );
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
