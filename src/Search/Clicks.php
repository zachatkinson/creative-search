<?php
/**
 * Click recording.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Search;

use CreativeBranding\Search\Repository\LogRepository;
use CreativeBranding\Search\Support\Clock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Marks the latest unclicked log row for the query, written in the last ten minutes, with what the shopper
 * chose (D-052). The suggest response cannot carry the row's id because the row is written after the
 * response; the query and language find it instead.
 */
final class Clicks {

	public const TYPES  = array( 'product', 'category', 'tag', 'brand' );
	public const WINDOW = 600;

	/**
	 * Constructor.
	 *
	 * @param LogRepository $log The log table.
	 */
	public function __construct( private LogRepository $log ) {
	}

	/**
	 * Record a click.
	 *
	 * @param string $query The query the suggestion answered.
	 * @param string $lang  Its language.
	 * @param string $type  What was clicked.
	 * @param int    $id    Its id.
	 * @return bool Whether a log row was marked.
	 */
	public function record( string $query, string $lang, string $type, int $id ): bool {
		$normalised = SearchLog::normalise( SearchService::clean( $query ) );
		if ( '' === $normalised || ! in_array( $type, self::TYPES, true ) || $id <= 0 ) {
			return false;
		}
		return $this->log->click( $normalised, $lang, Clock::format( Clock::timestamp() - self::WINDOW ), $type, $id );
	}
}
