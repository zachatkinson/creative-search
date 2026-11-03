<?php
/**
 * Analytics without staff and test runs (D-139).
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Admin\Actions;
use CreativeBranding\Search\Admin\AdminPage;
use CreativeBranding\Search\Admin\Analytics;
use CreativeBranding\Search\Cli\MaintenanceCommand;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Repository\LogRepository;
use CreativeBranding\Search\Search\Clicks;
use CreativeBranding\Search\Search\SearchLog;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Tables;
use CreativeBranding\Search\Tests\Support\IndexTestCase;
use WP_CLI;
use WP_REST_Request;

/**
 * Staff searches, the test header, the `csearch_log_search` filter, Clear analytics and the Plugins screen link.
 *
 * @group sql-mutation
 */
final class AnalyticsLaunchTest extends IndexTestCase {

	/**
	 * Product ids by catalogue index.
	 *
	 * @var int[]
	 */
	private array $ids = array();

	public function set_up(): void {
		parent::set_up();
		$this->ids = $this->products( array( 0, 1 ) );
		$this->service( Reindex::class )->runInline();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		unset( $_SERVER['HTTP_X_CREATIVE_TEST'], $_GET['clear'], $_GET['tab'] );
		$_COOKIE = array();
		remove_all_filters( 'csearch_log_search' );
		Settings::update(
			array(
				'log_staff_searches' => 'no',
				'stats'              => array(),
			)
		);
		parent::tear_down();
	}

	/**
	 * Ask the panel's route, as the browser does: no nonce.
	 *
	 * @param string $q Query.
	 */
	private function suggest( string $q ): void {
		$request = new WP_REST_Request( 'GET', '/csearch/v1/suggest' );
		$request->set_param( 'q', $q );
		rest_do_request( $request );
	}

	/**
	 * The queries waiting to be logged.
	 *
	 * @return string[]
	 */
	private function pending(): array {
		return array_column( $this->service( SearchLog::class )->pending(), 'query' );
	}

	public function test_a_shopper_is_logged_and_staff_are_not(): void {
		$this->suggest( 'kazumi' );
		self::assertSame( array( 'kazumi' ), $this->pending(), 'a shopper' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$this->suggest( 'deck' );
		self::assertSame( array( 'kazumi', 'deck' ), $this->pending(), 'a signed-in customer is a shopper' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$this->suggest( 'wheels' );
		self::assertSame( array( 'kazumi', 'deck' ), $this->pending(), 'a shop manager is not logged' );
		Settings::update( array( 'log_staff_searches' => 'yes' ) );
		$this->suggest( 'wheels' );
		self::assertSame( array( 'kazumi', 'deck', 'wheels' ), $this->pending(), 'unless the shop asks for it' );
	}

	public function test_staff_are_known_by_their_cookie_where_the_api_signs_them_out(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( 0 );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $admin, time() + HOUR_IN_SECONDS, 'logged_in' );
		$this->suggest( 'kazumi' );
		self::assertSame( array(), $this->pending(), 'the panel sends no nonce, so WordPress sees nobody; the cookie still says who' );
		$_COOKIE[ LOGGED_IN_COOKIE ] = 'forged|0|x|y';
		$this->suggest( 'kazumi' );
		self::assertSame( array( 'kazumi' ), $this->pending(), 'a cookie that does not validate is a shopper' );
	}

	public function test_a_request_with_the_test_header_is_not_logged(): void {
		$_SERVER['HTTP_X_CREATIVE_TEST'] = '1';
		$this->suggest( 'kazumi' );
		self::assertSame( array(), $this->pending() );
		$_SERVER['HTTP_X_CREATIVE_TEST'] = 'yes';
		$this->suggest( 'kazumi' );
		self::assertSame( array( 'kazumi' ), $this->pending(), 'only an exact 1' );
	}

	public function test_the_filter_can_leave_a_search_out(): void {
		$seen = array();
		add_filter(
			'csearch_log_search',
			static function ( bool $log, string $query, string $lang, int $results, string $source ) use ( &$seen ): bool {
				$seen[] = array( $log, $query, $lang, $source );
				return 'xyzzy' !== $query;
			},
			10,
			5
		);
		$this->suggest( 'xyzzy' );
		$this->suggest( 'kazumi' );
		self::assertSame( array( 'kazumi' ), $this->pending() );
		self::assertSame( array( true, 'xyzzy', 'en', 'suggest' ), $seen[0] );
	}

	public function test_a_click_from_staff_marks_no_shopper_s_search(): void {
		$this->suggest( 'kazumi' );
		$this->service( SearchLog::class )->flush();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		self::assertFalse( $this->service( Clicks::class )->record( 'kazumi', 'en', 'product', $this->ids[0] ) );
		wp_set_current_user( 0 );
		$_SERVER['HTTP_X_CREATIVE_TEST'] = '1';
		self::assertFalse( $this->service( Clicks::class )->record( 'kazumi', 'en', 'product', $this->ids[0] ) );
		unset( $_SERVER['HTTP_X_CREATIVE_TEST'] );
		self::assertTrue( $this->service( Clicks::class )->record( 'kazumi', 'en', 'product', $this->ids[0] ), 'a shopper\'s click still counts' );
	}

	public function test_clear_analytics_deletes_the_log_and_the_roll_ups(): void {
		$this->suggest( 'kazumi' );
		$this->suggest( 'deck' );
		$this->service( SearchLog::class )->flush();
		Settings::set(
			'stats',
			array(
				'2026-10-01' => array( 'searches' => 4 ),
				'2026-10-02' => array( 'searches' => 2 ),
			)
		);
		self::assertSame(
			array(
				'searches' => 2,
				'days'     => 2,
			),
			$this->service( Analytics::class )->held()
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['tab'] = 'analytics';
		ob_start();
		$this->service( AdminPage::class )->render();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'Clear analytics…', $html, 'a link first, nothing deleted by one click' );
		self::assertStringNotContainsString( Actions::ANALYTICS_CLEAR, $html );
		$_GET['clear'] = '1';
		ob_start();
		$this->service( AdminPage::class )->render();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'all 2 logged searches', $html, 'the confirmation says what goes' );
		self::assertStringContainsString( '2 days of daily counts', $html );
		self::assertStringContainsString( 'value="' . Actions::ANALYTICS_CLEAR . '"', $html );
		self::assertStringContainsString( '_wpnonce', $html );

		$back = $this->service( Actions::class )->clearAnalytics( array() );
		self::assertSame( AdminPage::url( 'analytics' ), $back );
		self::assertSame( 0, $this->rowCount( Tables::log() ) );
		self::assertSame( array(), Settings::get( 'stats' ) );
	}

	public function test_the_cli_clears_after_asking(): void {
		$this->suggest( 'kazumi' );
		$this->service( SearchLog::class )->flush();
		$command       = $this->service( MaintenanceCommand::class );
		WP_CLI::$lines = array();
		try {
			$command->clear( array(), array() );
			self::fail( 'it asks first' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'not confirmed', $e->getMessage() );
		}
		self::assertSame( 1, $this->service( LogRepository::class )->count(), 'nothing deleted without a yes' );
		self::assertStringStartsWith( 'confirm: Delete 1 logged search and every daily count?', WP_CLI::$lines[0] );
		$command->clear( array(), array( 'yes' => true ) );
		self::assertSame( 'success: Analytics cleared: 1 search deleted, with the daily counts.', WP_CLI::$lines[2] );
		self::assertSame( 0, $this->service( LogRepository::class )->count() );
		$command->clear( array(), array( 'yes' => true, 'format' => 'json' ) );
		self::assertSame( 'line: {"searches":0,"days":0}', WP_CLI::$lines[4] );
	}

	public function test_the_plugins_screen_links_to_the_settings(): void {
		$file = plugin_basename( CSEARCH_FILE );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$links = apply_filters( 'plugin_action_links_' . $file, array( 'deactivate' => '<a>Deactivate</a>' ) );
		self::assertSame( array( 'settings', 'deactivate' ), array_keys( $links ), 'Settings first' );
		self::assertStringContainsString( esc_url( AdminPage::url( 'tools' ) ), $links['settings'] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		self::assertSame( array( 'deactivate' ), array_keys( apply_filters( 'plugin_action_links_' . $file, array( 'deactivate' => '<a>Deactivate</a>' ) ) ), 'nobody else is offered a screen they cannot open' );
	}
}
