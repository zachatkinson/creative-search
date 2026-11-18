<?php
/**
 * The Analytics tab's numbers.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Admin;

use CreativeBranding\Search\Repository\LogRepository;
use CreativeBranding\Search\Search\SearchLog;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Cast;
use CreativeBranding\Search\Support\Clock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What people want, what they cannot find, what they click (spec: Admin → Analytics; Analytics and privacy):
 * searches a day, the top 50 queries with result counts and click-through, the top 50 with no results, the
 * most clicked suggestions, suggestions against the results page, over the last 7, 30 or 90 days. Days
 * already purged from the log come from the daily roll-ups in `csearch_settings['stats']`.
 */
final class Analytics {

	public const PERIODS = array( 7, 30, 90 );
	public const TOP     = 50;

	/**
	 * Constructor.
	 *
	 * @param LogRepository $log The search log.
	 */
	public function __construct( private LogRepository $log ) {
	}

	/**
	 * What Clear analytics would delete (D-139): the search-log rows and the days of roll-ups.
	 *
	 * @return array{searches: int, days: int}
	 */
	public function held(): array {
		$stats = Settings::get( 'stats' );
		return array(
			'searches' => $this->log->count(),
			'days'     => is_array( $stats ) ? count( $stats ) : 0,
		);
	}

	/**
	 * Delete every search-log row and every daily roll-up, for a shop's launch day (D-139). The suggested
	 * searches, which are built from the log, go with them, so the cached answers are dropped too.
	 *
	 * @return array{searches: int, days: int} What was deleted.
	 */
	public function clear(): array {
		$held     = $this->held();
		$searches = $this->log->clear();
		Settings::set( 'stats', array() );
		do_action( 'csearch_flush_cache' );
		return array(
			'searches' => $searches,
			'days'     => $held['days'],
		);
	}

	/**
	 * A period the screen offers, from a request value.
	 *
	 * @param mixed $value Days asked for.
	 */
	public static function period( mixed $value ): int {
		$days = Cast::int( $value, 30 );
		return in_array( $days, self::PERIODS, true ) ? $days : 30;
	}

	/**
	 * A source the screen shows on its own (D-163), from a request value: a registered source other than the shop's
	 * two, or `other`; anything else is the shop's own searches, an empty string.
	 *
	 * @param mixed $value Source asked for.
	 */
	public static function source( mixed $value ): string {
		$source = is_string( $value ) ? $value : '';
		return in_array( $source, self::sources(), true ) ? $source : '';
	}

	/**
	 * The sources the screen can show on their own: every registered source but the shop's two, then `other`.
	 *
	 * @return string[]
	 */
	public static function sources(): array {
		return array_values( array_unique( array_merge( array_diff( SearchLog::sources(), LogRepository::SHOP ), array( SearchLog::OTHER ) ) ) );
	}

	/**
	 * The log sources a view counts: one source, or the shop's own (an empty string).
	 *
	 * @param string $source A source from {@see Analytics::source()}.
	 * @return string[]
	 */
	private static function counted( string $source ): array {
		return '' === $source ? LogRepository::SHOP : array( $source );
	}

	/**
	 * The first moment of a period: midnight UTC, `$days - 1` days ago.
	 *
	 * @param int $days Days.
	 */
	public static function since( int $days ): string {
		return gmdate( 'Y-m-d 00:00:00', Clock::timestamp() - ( $days - 1 ) * DAY_IN_SECONDS );
	}

	/**
	 * Searches, searches with no results and clicks per day, every day of the period present (0 when quiet): the
	 * shop's own (D-163), or one source's. The days already purged come from the roll-ups, which hold the shop's own
	 * only, so one source's view counts them as 0.
	 *
	 * @param int    $days   Days.
	 * @param string $source A source from {@see Analytics::source()}; empty for the shop's own.
	 * @return array<string, array{searches: int, zero: int, clicks: int}>
	 */
	public function daily( int $days, string $source = '' ): array {
		$live  = $this->log->daily( self::since( $days ), self::counted( $source ) );
		$stats = '' === $source ? Settings::get( 'stats' ) : array();
		$stats = is_array( $stats ) ? $stats : array();
		$out   = array();
		for ( $back = $days - 1; $back >= 0; $back-- ) {
			$day = gmdate( 'Y-m-d', Clock::timestamp() - $back * DAY_IN_SECONDS );
			if ( isset( $live[ $day ] ) ) {
				$out[ $day ] = $live[ $day ];
				continue;
			}
			$rolled      = isset( $stats[ $day ] ) && is_array( $stats[ $day ] ) ? $stats[ $day ] : array();
			$out[ $day ] = array(
				'searches' => Cast::int( $rolled['searches'] ?? 0 ),
				'zero'     => Cast::int( $rolled['zero'] ?? 0 ),
				'clicks'   => Cast::int( $rolled['clicks'] ?? 0 ),
			);
		}
		return $out;
	}

	/**
	 * The totals of a period, the shop's own or one source's (D-163), with every source's searches.
	 *
	 * @param int    $days   Days.
	 * @param string $source A source from {@see Analytics::source()}; empty for the shop's own.
	 * @return array{searches: int, zero: int, clicks: int, sources: array<string, int>}
	 */
	public function totals( int $days, string $source = '' ): array {
		$out = array(
			'searches' => 0,
			'zero'     => 0,
			'clicks'   => 0,
		);
		foreach ( $this->daily( $days, $source ) as $counts ) {
			$out['searches'] += $counts['searches'];
			$out['zero']     += $counts['zero'];
			$out['clicks']   += $counts['clicks'];
		}
		return $out + array( 'sources' => $this->log->sources( self::since( $days ) ) );
	}

	/**
	 * The top queries of a period, with click-through (clicks over searches, a share).
	 *
	 * @param int    $days     Days.
	 * @param bool   $zeroOnly Only those that found nothing.
	 * @param int    $limit    At most.
	 * @param int    $offset   Skip.
	 * @param string $source   A source from {@see Analytics::source()}; empty for the shop's own (D-163).
	 * @return array<int, array{query: string, lang: string, searches: int, results: int, clicks: int, ctr: float}>
	 */
	public function topQueries( int $days, bool $zeroOnly, int $limit = self::TOP, int $offset = 0, string $source = '' ): array {
		return array_map(
			static fn( array $row ): array => $row + array( 'ctr' => $row['searches'] > 0 ? $row['clicks'] / $row['searches'] : 0.0 ),
			$this->log->topQueries( self::since( $days ), $zeroOnly, $limit, $offset, self::counted( $source ) )
		);
	}

	/**
	 * The most clicked suggestions, with their names.
	 *
	 * @param int $days Days.
	 * @return array<int, array{type: string, id: int, clicks: int, name: string, url: string}>
	 */
	public function topClicked( int $days ): array {
		$out   = array();
		$rows  = $this->log->topClicked( self::since( $days ), 10 );
		$posts = static fn( array $r ): bool => in_array( $r['type'], array( 'product', 'post', 'page' ), true );
		_prime_post_caches( array_column( array_filter( $rows, $posts ), 'id' ) );
		$terms = array_column( array_filter( $rows, static fn( array $r ): bool => ! $posts( $r ) ), 'id' );
		if ( array() !== $terms ) {
			_prime_term_caches( $terms );
		}
		foreach ( $rows as $row ) {
			$name = '';
			$url  = '';
			if ( $posts( $row ) ) {
				$name = get_the_title( $row['id'] );
				$link = get_permalink( $row['id'] );
				$url  = is_string( $link ) ? $link : '';
			} else {
				$term = get_term( $row['id'] );
				if ( $term instanceof \WP_Term ) {
					$name = $term->name;
					$link = get_term_link( $term );
					$url  = is_string( $link ) ? $link : '';
				}
			}
			$out[] = $row + array(
				'name' => '' !== $name ? $name : '#' . $row['id'],
				'url'  => $url,
			);
		}
		return $out;
	}

	/**
	 * The log's columns, as the export carries them: the same as the table (spec: Analytics and privacy).
	 */
	public const COLUMNS = array( 'id', 'query', 'normalised', 'lang', 'results', 'clicked_type', 'clicked_id', 'source', 'created_at' );

	/**
	 * Stream the log rows of a period as CSV, 1,000 rows a read, so a busy shop's 90 days never sit in memory.
	 *
	 * @param resource $out  The stream (the response, or memory in tests).
	 * @param int      $days Days.
	 */
	public function exportTo( $out, int $days ): void {
		Csv::write( $out, self::COLUMNS, true );
		$after = 0;
		do {
			$batch = $this->log->since( self::since( $days ), $after, 1000 );
			foreach ( $batch as $row ) {
				Csv::write( $out, array_values( $row ) );
				$after = Cast::int( $row['id'] );
			}
			$more = count( $batch ) === 1000;
		} while ( $more );
	}
}
