<?php
/**
 * The Analytics tab's numbers.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Admin;

use CreativeBranding\Search\Repository\LogRepository;
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
	 * A period the screen offers, from a request value.
	 *
	 * @param mixed $value Days asked for.
	 */
	public static function period( mixed $value ): int {
		$days = Cast::int( $value, 30 );
		return in_array( $days, self::PERIODS, true ) ? $days : 30;
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
	 * Searches, searches with no results and clicks per day, every day of the period present (0 when quiet).
	 *
	 * @param int $days Days.
	 * @return array<string, array{searches: int, zero: int, clicks: int}>
	 */
	public function daily( int $days ): array {
		$live  = $this->log->daily( self::since( $days ) );
		$stats = Settings::get( 'stats' );
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
	 * The totals of a period.
	 *
	 * @param int $days Days.
	 * @return array{searches: int, zero: int, clicks: int, suggest: int, page: int}
	 */
	public function totals( int $days ): array {
		$out = array(
			'searches' => 0,
			'zero'     => 0,
			'clicks'   => 0,
		);
		foreach ( $this->daily( $days ) as $counts ) {
			$out['searches'] += $counts['searches'];
			$out['zero']     += $counts['zero'];
			$out['clicks']   += $counts['clicks'];
		}
		$sources = $this->log->sources( self::since( $days ) );
		return $out + array(
			'suggest' => $sources['suggest'] ?? 0,
			'page'    => $sources['page'] ?? 0,
		);
	}

	/**
	 * The top queries of a period, with click-through (clicks over searches, a share).
	 *
	 * @param int  $days     Days.
	 * @param bool $zeroOnly Only those that found nothing.
	 * @param int  $limit    At most.
	 * @param int  $offset   Skip.
	 * @return array<int, array{query: string, lang: string, searches: int, results: int, clicks: int, ctr: float}>
	 */
	public function topQueries( int $days, bool $zeroOnly, int $limit = self::TOP, int $offset = 0 ): array {
		return array_map(
			static fn( array $row ): array => $row + array( 'ctr' => $row['searches'] > 0 ? $row['clicks'] / $row['searches'] : 0.0 ),
			$this->log->topQueries( self::since( $days ), $zeroOnly, $limit, $offset )
		);
	}

	/**
	 * The most clicked suggestions, with their names.
	 *
	 * @param int $days Days.
	 * @return array<int, array{type: string, id: int, clicks: int, name: string, url: string}>
	 */
	public function topClicked( int $days ): array {
		$out  = array();
		$rows = $this->log->topClicked( self::since( $days ), 10 );
		_prime_post_caches( array_column( array_filter( $rows, static fn( array $r ): bool => 'product' === $r['type'] ), 'id' ) );
		$terms = array_column( array_filter( $rows, static fn( array $r ): bool => 'product' !== $r['type'] ), 'id' );
		if ( array() !== $terms ) {
			_prime_term_caches( $terms );
		}
		foreach ( $rows as $row ) {
			$name = '';
			$url  = '';
			if ( 'product' === $row['type'] ) {
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
