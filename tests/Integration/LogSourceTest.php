<?php
/**
 * A log source for other plugins' searches.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Admin\Analytics;
use CreativeBranding\Search\Admin\AnalyticsTab;
use CreativeBranding\Search\Index\IndexQueue;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Repository\LogRepository;
use CreativeBranding\Search\Search\LogMaintenance;
use CreativeBranding\Search\Search\SearchLog;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Clock;
use CreativeBranding\Search\Tests\Support\IndexTestCase;
use WC_Product_Simple;

/**
 * Item 3 of the 1.7.0 brief (D-163) on a generic store, a kitchen shop, with a caller that is neither the shop nor
 * any plugin the agency ships: `builder` joins `suggest` and `page`, an unknown source is `other`, a plugin
 * registers its own, and the shop's own numbers (the daily chart, top queries, suggested searches) count only
 * `suggest` and `page`.
 */
final class LogSourceTest extends IndexTestCase {

	public function set_up(): void {
		parent::set_up();
		foreach ( array( 'Cast Iron Skillet', 'Ceramic Teapot' ) as $name ) {
			$product = new WC_Product_Simple();
			$product->set_name( $name );
			$product->set_regular_price( '20' );
			$product->save();
		}
		$this->service( IndexQueue::class )->clear();
		$this->service( Reindex::class )->runInline();
		do_action( 'csearch_flush_cache' );
	}

	public function test_the_builder_source_is_logged(): void {
		apply_filters( 'csearch_ranked_ids', null, 'skillet', array( 'source' => 'builder' ) );
		self::assertSame( array( 'builder' ), array_column( $this->service( SearchLog::class )->pending(), 'source' ) );
	}

	public function test_an_unknown_source_is_logged_as_other_and_a_plugin_registers_its_own(): void {
		apply_filters( 'csearch_ranked_ids', null, 'skillet', array( 'source' => 'kiosk' ) );
		add_filter( 'csearch_log_sources', static fn( array $sources ): array => array_merge( $sources, array( 'kiosk', 'TooLong12', 'x1', 'ninechars', 7 ) ) );
		apply_filters( 'csearch_ranked_ids', null, 'skillet', array( 'source' => 'kiosk' ) );
		apply_filters( 'csearch_ranked_ids', null, 'skillet', array( 'source' => 'ninechars' ) );
		apply_filters( 'csearch_ranked_ids', null, 'skillet', array( 'source' => 'x1' ) );
		self::assertSame( array( 'other', 'kiosk', 'other', 'other' ), array_column( $this->service( SearchLog::class )->pending(), 'source' ), 'only [a-z], 8 characters at most' );
		self::assertSame( array( 'suggest', 'page', 'builder', 'kiosk' ), SearchLog::sources() );
	}

	public function test_a_caller_cannot_log_as_the_shop(): void {
		apply_filters( 'csearch_ranked_ids', null, 'skillet', array( 'source' => 'page' ) );
		apply_filters( 'csearch_ranked_ids', null, 'skillet', array( 'source' => 'suggest' ) );
		add_filter( 'csearch_log_sources', static fn( array $sources ): array => array_merge( $sources, array( 'other' ) ) );
		self::assertSame( array( 'other', 'other' ), array_column( $this->service( SearchLog::class )->pending(), 'source' ), 'the shop’s own sources are the shop’s' );
		self::assertSame( array( 'builder', 'other' ), \CreativeBranding\Search\Admin\Analytics::sources(), 'other once' );
	}

	public function test_the_log_filter_receives_the_source_and_can_keep_one_out(): void {
		$seen = array();
		add_filter(
			'csearch_log_search',
			static function ( bool $log, string $query, string $lang, int $results, string $source ) use ( &$seen ): bool {
				$seen[] = $source;
				return $log && 'builder' !== $source;
			},
			10,
			5
		);
		apply_filters( 'csearch_ranked_ids', null, 'skillet', array( 'source' => 'builder' ) );
		apply_filters( 'csearch_ranked_ids', null, 'skillet', array( 'source' => 'kiosk' ) );
		self::assertSame( array( 'builder', 'other' ), $seen, 'the source as it would be logged' );
		self::assertSame( array( 'other' ), array_column( $this->service( SearchLog::class )->pending(), 'source' ) );
	}

	public function test_the_shops_numbers_count_only_its_own_sources(): void {
		Clock::freeze( (int) strtotime( '2026-11-18 12:00:00 UTC' ) );
		$log  = $this->service( LogRepository::class );
		$rows = array();
		foreach ( array( array( 'skillet', 'page', 1 ), array( 'skillet', 'suggest', 1 ), array( 'teapot', 'builder', 1 ), array( 'teapot', 'builder', 1 ), array( 'teapot', 'builder', 1 ), array( 'teapot', 'builder', 1 ), array( 'zzq', 'other', 0 ) ) as [ $query, $source, $results ] ) {
			$rows[] = array(
				'query'      => $query,
				'normalised' => $query,
				'lang'       => 'en',
				'results'    => $results,
				'source'     => $source,
				'created_at' => '2026-11-18 10:00:00',
			);
		}
		$log->insert( $rows );
		// A click on a query the builder searched too goes to the shop's row.
		self::assertFalse( $log->click( 'teapot', 'en', '2026-11-18 00:00:00', 'product', 7 ), 'no shop row of "teapot" to click' );

		$analytics = $this->service( Analytics::class );
		self::assertSame( array( 'skillet' ), array_column( $analytics->topQueries( 7, false ), 'query' ), 'top queries: the shop’s' );
		self::assertSame( array(), $analytics->topQueries( 7, true ), 'no results: the shop’s' );
		self::assertSame( 2, $analytics->daily( 7 )['2026-11-18']['searches'], 'the chart: the shop’s' );
		$totals = $analytics->totals( 7 );
		self::assertSame( 2, $totals['searches'] );
		self::assertSame(
			array(
				'suggest' => 1,
				'page'    => 1,
				'builder' => 4,
				'other'   => 1,
			),
			$totals['sources'],
			'the totals list each source'
		);

		self::assertSame( array( 'teapot' ), array_column( $analytics->topQueries( 7, false, Analytics::TOP, 0, 'builder' ), 'query' ), 'one source on its own' );
		self::assertSame( 4, $analytics->daily( 7, 'builder' )['2026-11-18']['searches'] );
		self::assertSame( 4, $analytics->totals( 7, 'builder' )['searches'] );
		self::assertSame( array( 'zzq' ), array_column( $analytics->topQueries( 7, true, Analytics::TOP, 0, 'other' ), 'query' ) );

		// Suggested searches are built from the shop's results-page searches only: four builder searches don't count.
		self::assertSame( array(), $log->completions( 'tea', 'en', '2026-11-01 00:00:00', 1, 5 ) );
		self::assertSame( array( 'skillet' ), $log->completions( 'ski', 'en', '2026-11-01 00:00:00', 1, 5 ) );

		// The nightly roll-up keeps the shop's counts, as the chart shows them.
		Clock::freeze( (int) strtotime( '2026-11-19 03:00:00 UTC' ) );
		$this->service( LogMaintenance::class )->rollUp();
		self::assertSame(
			array(
				'searches' => 2,
				'zero'     => 0,
				'clicks'   => 0,
			),
			Settings::get( 'stats' )['2026-11-18'] ?? null
		);
	}

	public function test_the_analytics_screen_filters_by_source(): void {
		self::assertSame( '', Analytics::source( null ), 'the shop’s by default' );
		self::assertSame( 'builder', Analytics::source( 'builder' ) );
		self::assertSame( 'other', Analytics::source( 'other' ) );
		self::assertSame( '', Analytics::source( 'kiosk' ), 'an unregistered source is the shop’s' );
		self::assertSame( '', Analytics::source( array( 'builder' ) ) );

		$_GET['source'] = 'builder';
		ob_start();
		$this->service( AnalyticsTab::class )->render();
		$html = (string) ob_get_clean();
		unset( $_GET['source'] );
		self::assertStringContainsString( 'id="csearch-source"', $html );
		self::assertMatchesRegularExpression( '/<option value="builder" selected/', $html );
	}
}
