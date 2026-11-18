<?php
/**
 * Admin tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Admin\Actions;
use CreativeBranding\Search\Admin\AdminPage;
use CreativeBranding\Search\Admin\Analytics;
use CreativeBranding\Search\Admin\Csv;
use CreativeBranding\Search\Index\IndexHealth;
use CreativeBranding\Search\Admin\Notices;
use CreativeBranding\Search\Admin\ProductPanel;
use CreativeBranding\Search\Admin\SynonymService;
use CreativeBranding\Search\Admin\SynonymsTab;
use CreativeBranding\Search\Domain\Synonym;
use CreativeBranding\Search\Index\Indexer;
use CreativeBranding\Search\Index\IndexState;
use CreativeBranding\Search\Repository\LogRepository;
use CreativeBranding\Search\Repository\SynonymRepository;
use CreativeBranding\Search\Search\SearchService;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Clock;
use CreativeBranding\Search\Support\Options;
use CreativeBranding\Search\Tests\Support\IndexTestCase;
use CreativeBranding\Search\Tests\Support\WooCommerceCatalogue;
use WPDieException;

/**
 * Milestone 7: nonces and capabilities refused, the list table paginates, synonyms apply at once, the Analytics
 * numbers match the log, the CSV round trips (synonyms, the log and the product columns), the product panel, the
 * Tools settings and the warnings.
 */
final class AdminTest extends IndexTestCase {

	private int $admin;

	public function set_up(): void {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
	}

	public function tear_down(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	private function actions(): Actions {
		return $this->service( Actions::class );
	}

	/**
	 * What a download handler would stream to the browser.
	 *
	 * @param array{name: string, write: callable} $download The handler's answer.
	 */
	private function download( array $download ): string {
		$out = fopen( 'php://memory', 'r+' );
		( $download['write'] )( $out );
		rewind( $out );
		return (string) stream_get_contents( $out );
	}

	private function nonce( string $action ): void {
		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );
	}

	public function test_every_action_refuses_a_user_without_the_capability_and_a_missing_nonce(): void {
		foreach ( array_keys( Actions::handlers() ) as $action ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
			$this->nonce( $action );
			try {
				Actions::guard( $action );
				self::fail( $action . ' let a customer through' );
			} catch ( WPDieException $e ) {
				self::assertStringContainsString( 'not allowed', $e->getMessage(), $action );
			}

			wp_set_current_user( $this->admin );
			$_REQUEST['_wpnonce'] = 'not-a-nonce';
			try {
				Actions::guard( $action );
				self::fail( $action . ' let a forged request through' );
			} catch ( WPDieException $e ) {
				self::assertNotSame( '', $e->getMessage(), $action );
			}

			$this->nonce( $action );
			Actions::guard( $action );
		}
		// A nonce for one action does not open another.
		$this->nonce( Actions::SYNONYM_EXPORT );
		$this->expectException( WPDieException::class );
		Actions::guard( Actions::SYNONYM_DELETE );
	}

	public function test_the_screen_and_its_hooks_are_there_for_shop_managers_only(): void {
		self::assertTrue( has_action( 'admin_post_' . Actions::SYNONYM_SAVE ) );
		self::assertNotFalse( has_filter( 'woocommerce_product_data_tabs' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$this->expectException( WPDieException::class );
		$this->service( AdminPage::class )->render();
	}

	public function test_each_tab_renders_with_labelled_controls(): void {
		$this->products( array( 0 ) );
		foreach ( array_keys( AdminPage::tabs() ) as $tab ) {
			$_GET['tab'] = $tab;
			ob_start();
			$this->service( AdminPage::class )->render();
			$html = (string) ob_get_clean();
			self::assertStringContainsString( 'aria-current="page"', $html, $tab );
			self::assertSame( 0, preg_match( '/<input(?![^>]*type="(hidden|submit|checkbox)")(?![^>]*id=)[^>]*>/', $html ), $tab . ': every visible input has an id for its label' );
			preg_match_all( '/id="(csearch-[a-z0-9-]+)"/', $html, $ids );
			self::assertSame( array_unique( $ids[1] ), $ids[1], $tab . ': ids are unique' );
			self::assertStringContainsString( '_wpnonce', $html, $tab . ': forms carry a nonce' );
		}
	}

	public function test_a_synonym_applies_to_searches_at_once(): void {
		[ $id ] = $this->products( array( 0 ) );
		$this->service( Indexer::class )->indexProduct( $id );
		$brand  = strtolower( strtok( WooCommerceCatalogue::get( $id )->get_name(), ' ' ) );
		$search = $this->service( SearchService::class );
		self::assertSame( array(), $search->rankedIds( 'zorblax', 'en', '' )['ids'], 'a word nothing carries finds nothing (and the answer is cached)' );

		$redirect = $this->actions()->saveSynonym(
			array(
				'lang'      => 'en',
				'word'      => 'Zorblax',
				'means'     => $brand,
				'both_ways' => '1',
			)
		);
		self::assertSame( AdminPage::url( 'synonyms' ), $redirect );
		self::assertSame( array( 'success', sprintf( 'Synonym added: “zorblax” means “%s”. Searches use it now.', $brand ) ), Notices::takeFlash() );
		self::assertContains( $id, $search->rankedIds( 'zorblax', 'en', '' )['ids'], 'the cached answer was dropped' );

		$this->actions()->saveSynonym( array( 'lang' => 'en', 'word' => 'zorblax', 'means' => $brand ) );
		self::assertSame( 'info', Notices::takeFlash()[0] ?? '', 'the same synonym twice is not added twice' );
		$this->actions()->saveSynonym( array( 'lang' => 'en', 'word' => 'deck', 'means' => 'deck' ) );
		self::assertSame( array( 'error', 'A word cannot be its own synonym.' ), Notices::takeFlash() );
		self::assertSame( 1, $this->service( SynonymRepository::class )->count() );

		$row = $this->service( SynonymRepository::class )->page( '', '', 1, 0 )[0];
		$this->actions()->deleteSynonyms( array( 'id' => $row['id'] ) );
		self::assertSame( array( 'success', '1 synonym deleted.' ), Notices::takeFlash() );
		self::assertSame( array(), $search->rankedIds( 'zorblax', 'en', '' )['ids'], 'and a delete applies at once too' );
	}

	public function test_editing_a_synonym_and_the_test_box(): void {
		$service = $this->service( SynonymService::class );
		$service->add( new Synonym( 'en', 'board', 'deck', true ), $this->admin );
		$row = $this->service( SynonymRepository::class )->page( '', '', 1, 0 )[0];
		$this->actions()->saveSynonym( array( 'id' => $row['id'], 'lang' => 'en', 'word' => 'board', 'means' => 'skateboard' ) );
		self::assertSame( 'skateboard', $this->service( SynonymRepository::class )->find( (int) $row['id'] )['means'] );
		self::assertContains( 'skateboard', $service->expand( 'board', 'en' ) );
		self::assertNotContains( 'board', $service->expand( 'skateboard', 'en' ), 'one way now' );
	}

	public function test_the_synonyms_table_paginates_filters_and_bulk_deletes(): void {
		$service = $this->service( SynonymService::class );
		for ( $i = 1; $i <= 25; $i++ ) {
			$service->add( new Synonym( 'en', 'word' . $i, 'means' . $i, false ), $this->admin );
		}
		$table = $this->service( SynonymsTab::class )->table();
		$table->prepare_items();
		self::assertCount( 20, $table->items );
		self::assertSame( 2, $table->get_pagination_arg( 'total_pages' ) );
		self::assertSame( 'word25', $table->items[0]['word'], 'newest first' );

		$_GET['paged'] = $_REQUEST['paged'] = '2';
		$table         = $this->service( SynonymsTab::class )->table();
		$table->prepare_items();
		self::assertCount( 5, $table->items );

		$_REQUEST = array( 's' => 'word1' );
		$table    = $this->service( SynonymsTab::class )->table();
		$table->prepare_items();
		self::assertSame( 11, $table->get_pagination_arg( 'total_items' ), 'word1 and word10 to word19' );

		// The bulk Delete posts back to the screen with the list table's own nonce.
		$ids                  = array_column( $this->service( SynonymRepository::class )->page( '', '', 3, 0 ), 'id' );
		$_REQUEST             = array(
			'action' => 'delete',
			'ids'    => $ids,
		);
		$_REQUEST['_wpnonce'] = wp_create_nonce( SynonymsTab::BULK_NONCE );
		self::assertSame( AdminPage::url( 'synonyms' ), $this->service( SynonymsTab::class )->bulkDelete() );
		self::assertSame( 22, $this->service( SynonymRepository::class )->count() );
		self::assertSame( array( 'success', '3 synonyms deleted.' ), Notices::takeFlash() );

		$_REQUEST['_wpnonce'] = 'forged';
		$this->expectException( WPDieException::class );
		$this->service( SynonymsTab::class )->bulkDelete();
	}

	public function test_synonyms_csv_round_trip(): void {
		$service = $this->service( SynonymService::class );
		$service->add( new Synonym( 'en', 'board', 'deck', true ), $this->admin );
		$service->add( new Synonym( 'en', 'trucks', 'axles', false ), $this->admin );
		$service->add( new Synonym( 'en', '=cmd', 'deck', false ), $this->admin );
		$csv = $this->download( $this->actions()->exportSynonyms( array() ) );
		self::assertStringStartsWith( "lang,word,means,both_ways\n", $csv );
		self::assertStringContainsString( "'=cmd", $csv, 'a cell that would be a spreadsheet formula is defused' );

		$repo   = $this->service( SynonymRepository::class );
		$before = $repo->all();
		$repo->delete( array_map( 'intval', array_column( $repo->page( '', '', 100, 0 ), 'id' ) ) );
		self::assertSame(
			array(
				'added'    => 3,
				'existing' => 0,
				'skipped'  => 2,
			),
			$service->import( $csv . "en,,deck,yes\nxx,a,b,no\n", 0 ),
			'a row with no word and a row in a language the shop does not index are skipped'
		);
		self::assertSame( $before, $repo->all(), 'the same rows come back' );
		self::assertSame( array( 'added' => 0, 'existing' => 3, 'skipped' => 0 ), $service->import( $csv, 0 ), 'a second import adds nothing' );
	}

	public function test_analytics_numbers_match_the_log(): void {
		Clock::freeze( strtotime( '2026-10-21 12:00:00 UTC' ) );
		$log  = $this->service( LogRepository::class );
		$rows = array();
		foreach (
			array(
				array( 'kazumi', 12, 'suggest', '2026-10-21 10:00:00' ),
				array( 'kazumi', 12, 'page', '2026-10-21 10:05:00' ),
				array( 'kazumi', 11, 'suggest', '2026-10-20 09:00:00' ),
				array( 'zzzq', 0, 'page', '2026-10-20 09:00:00' ),
				array( 'zzzq', 0, 'suggest', '2026-10-19 09:00:00' ),
				array( 'old', 3, 'page', '2026-09-01 09:00:00' ),
			) as $r
		) {
			$rows[] = array(
				'query'      => $r[0],
				'normalised' => $r[0],
				'lang'       => 'en',
				'results'    => $r[1],
				'source'     => $r[2],
				'created_at' => $r[3],
			);
		}
		$log->insert( $rows );
		$log->click( 'kazumi', 'en', '2026-10-21 00:00:00', 'product', 42 );

		$analytics = $this->service( Analytics::class );
		self::assertSame(
			array(
				'searches' => 5,
				'zero'     => 2,
				'clicks'   => 1,
				'sources'  => array(
					'suggest' => 3,
					'page'    => 2,
				),
			),
			$analytics->totals( 7 )
		);
		self::assertSame( 6, $analytics->totals( 90 )['searches'] );
		$daily = $analytics->daily( 7 );
		self::assertCount( 7, $daily );
		self::assertSame( array( 'searches' => 2, 'zero' => 0, 'clicks' => 1 ), $daily['2026-10-21'] );
		self::assertSame( array( 'searches' => 0, 'zero' => 0, 'clicks' => 0 ), $daily['2026-10-16'] );

		$top = $analytics->topQueries( 7, false );
		self::assertSame( array( 'kazumi', 'zzzq' ), array_column( $top, 'query' ) );
		self::assertSame( 3, $top[0]['searches'] );
		self::assertEqualsWithDelta( 1 / 3, $top[0]['ctr'], 0.0001 );
		self::assertSame( array( 'zzzq' ), array_column( $analytics->topQueries( 7, true ), 'query' ) );
		self::assertSame( array( array( 'type' => 'product', 'id' => 42, 'clicks' => 1 ) ), array_map( static fn( array $r ): array => array_intersect_key( $r, array_flip( array( 'type', 'id', 'clicks' ) ) ), $analytics->topClicked( 7 ) ) );

		$csv = Csv::parse( $this->download( $this->actions()->exportLog( array( 'days' => '7' ) ) ) );
		self::assertCount( 5, $csv, 'the export carries the period’s rows' );
		self::assertSame( array( 'id', 'query', 'normalised', 'lang', 'results', 'clicked_type', 'clicked_id', 'source', 'created_at' ), array_keys( $csv[0] ), 'the same columns as the table' );
	}

	public function test_boosts_save_rules_fallbacks_and_let_excluded_products_back_in(): void {
		[ $a, $b ] = $this->products( array( 0, 1 ) );
		$cat       = (int) WooCommerceCatalogue::get( $a )->get_category_ids()[0];
		$product   = WooCommerceCatalogue::get( $b );
		$product->update_meta_data( Options::META_EXCLUDE, 'yes' );
		$product->save();

		$this->actions()->saveBoosts(
			array(
				'new_ref'           => 'product_cat:' . $cat,
				'new_boost'         => '25',
				'fallback_add'      => WooCommerceCatalogue::get( $a )->get_sku() . ', NOPE-1',
				'hidden_by_sku'     => 'yes',
				'include'           => array( (string) $b ),
			)
		);
		self::assertSame( array( array( 'taxonomy' => 'product_cat', 'term_id' => $cat, 'boost' => 10 ) ), Settings::get( 'boost_rules' ), 'clamped to 10' );
		self::assertSame( array( $a ), Settings::get( 'fallback_products' ) );
		self::assertSame( 'yes', Settings::get( 'hidden_by_sku' ) );
		self::assertSame( '', WooCommerceCatalogue::get( $b )->get_meta( Options::META_EXCLUDE ) );
		$flash = Notices::takeFlash();
		self::assertSame( 'warning', $flash[0] ?? '' );
		self::assertStringContainsString( '1 product is back in search', $flash[1] ?? '' );
		self::assertStringContainsString( 'No product has the SKU or ID NOPE-1.', $flash[1] ?? '' );

		$this->actions()->saveBoosts(
			array(
				'rules'         => array(
					array(
						'ref'    => 'product_cat:' . $cat,
						'boost'  => '3',
						'remove' => '1',
					),
				),
				'hidden_by_sku'     => 'no',
				'fallback_products' => array( (string) $a ),
				'fallback_remove'   => array( (string) $a ),
				'fallback_add'      => (string) $b,
			)
		);
		self::assertSame( array(), Settings::get( 'boost_rules' ), 'removed' );
		self::assertSame( array( $b ), Settings::get( 'fallback_products' ), 'one removed, one added by id' );
	}

	public function test_tools_save_settings_and_stop_words(): void {
		$this->actions()->saveTools(
			array(
				'index_long_description' => 'no',
				'retention_days'         => '400',
				'suggest_limit'          => '8',
				'stopwords'              => array( 'en' => "Shop, Store\nshop" ),
			)
		);
		self::assertSame( 'no', Settings::get( 'index_long_description' ) );
		self::assertSame( 'no', Settings::get( 'show_prices' ), 'an unticked box saves no' );
		self::assertSame( 365, Settings::get( 'retention_days' ) );
		self::assertSame( 8, Settings::get( 'suggest_limit' ) );
		self::assertSame( array( 'shop', 'store' ), get_option( Options::STOPWORDS_PREFIX . 'en' ) );
		self::assertStringContainsString( 'rebuild the index', Notices::takeFlash()[1] ?? '' );
	}

	public function test_rebuild_and_cancel(): void {
		$this->actions()->rebuild( array() );
		self::assertStringStartsWith( 'The index is being rebuilt.', Notices::takeFlash()[1] ?? '' );
		$this->actions()->rebuild( array() );
		self::assertSame( 'A rebuild is already running or waiting to run.', Notices::takeFlash()[1] ?? '' );
		$this->actions()->cancel( array() );
		self::assertStringStartsWith( 'The rebuild is stopping.', Notices::takeFlash()[1] ?? '' );
		self::assertSame( array(), $this->pendingJobs() );
	}

	public function test_the_product_panel_saves_clamps_and_clears(): void {
		[ $id ] = $this->products( array( 0 ) );
		$panel  = $this->service( ProductPanel::class );
		$_POST  = array(
			'csearch_boost'   => '14',
			'csearch_exclude' => 'yes',
		);
		$product = WooCommerceCatalogue::get( $id );
		$panel->save( $product );
		$product->save();
		$product = WooCommerceCatalogue::get( $id );
		self::assertSame( '10', $product->get_meta( Options::META_BOOST ) );
		self::assertSame( 'yes', $product->get_meta( Options::META_EXCLUDE ) );
		self::assertSame( 'Excluded from search.', $panel->lastIndexed( $id, true ) );

		$_POST = array( 'csearch_boost' => '0' );
		$panel->save( $product );
		$product->save();
		$product = WooCommerceCatalogue::get( $id );
		self::assertSame( '', $product->get_meta( Options::META_BOOST ), 'zero clears the meta' );
		self::assertSame( '', $product->get_meta( Options::META_EXCLUDE ) );

		self::assertSame( 'Not in the search index yet.', $panel->lastIndexed( $id, false ) );
		$this->service( Indexer::class )->indexProduct( $id );
		self::assertMatchesRegularExpression( '/^Last indexed \d+ (second|seconds|min|mins) ago\.$/', $panel->lastIndexed( $id, false ) );

		// The product screen loads WooCommerce's meta-box field helpers; a test request does not.
		include_once WC()->plugin_path() . '/includes/admin/wc-meta-box-functions.php';
		ob_start();
		$GLOBALS['post'] = get_post( $id );
		$panel->panel();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'id="csearch_boost"', $html );
		self::assertStringContainsString( 'Reindex now', $html );
	}

	public function test_the_product_csv_columns_round_trip(): void {
		[ $id ] = $this->products( array( 0 ) );
		$panel  = $this->service( ProductPanel::class );
		$columns = apply_filters( 'woocommerce_product_export_product_default_columns', array( 'id' => 'ID' ) );
		self::assertSame( 'Meta: _csearch_boost', $columns['csearch_boost'] );
		self::assertSame( 'Meta: _csearch_exclude', $columns['csearch_exclude'] );
		self::assertContains( Options::META_BOOST, apply_filters( 'woocommerce_product_export_skip_meta_keys', array(), WooCommerceCatalogue::get( $id ) ), 'not repeated by the custom-meta export' );

		$product = WooCommerceCatalogue::get( $id );
		$product->update_meta_data( Options::META_BOOST, '-4' );
		$product->update_meta_data( Options::META_EXCLUDE, 'yes' );
		$product->save();
		$product = WooCommerceCatalogue::get( $id );
		self::assertSame( '-4', apply_filters( 'woocommerce_product_export_product_column_csearch_boost', '', $product, 'csearch_boost' ) );
		self::assertSame( 'yes', apply_filters( 'woocommerce_product_export_product_column_csearch_exclude', '', $product, 'csearch_exclude' ) );

		// The importer sets meta from the "Meta:" columns, then the plugin normalises it before the save.
		$product->update_meta_data( Options::META_BOOST, '99' );
		$product->update_meta_data( Options::META_EXCLUDE, 'TRUE' );
		$product = apply_filters( 'woocommerce_product_import_pre_insert_product_object', $product, array() );
		self::assertSame( '10', $product->get_meta( Options::META_BOOST ) );
		self::assertSame( 'yes', $product->get_meta( Options::META_EXCLUDE ) );
		unset( $panel );
	}

	public function test_warnings_and_their_dismissal(): void {
		[ $a, $b ] = $this->products( array( 0, 1 ) );
		$notices   = $this->service( Notices::class );
		self::assertArrayHasKey( 'empty', $notices->warnings() );

		$this->service( Indexer::class )->indexProduct( $a );
		self::assertSame( array( 'missing' => array( 'count' => 1 ) ), $notices->warnings(), '1 of 2 missing is more than 10 %' );
		self::assertSame( '1 product is missing from the search index.', Notices::message( 'missing', array( 'count' => 1 ) ) );

		Notices::dismiss( 'missing' );
		self::assertSame( array(), $notices->warnings(), 'dismissed' );
		$this->service( Indexer::class )->indexProduct( $b );
		self::assertSame( array(), $notices->warnings(), 'the condition cleared, so the dismissal is forgotten' );
		self::assertSame( array(), get_user_meta( $this->admin, Options::USER_DISMISSED, true ) );

		$this->service( Indexer::class )->removeProduct( $b );
		self::assertArrayHasKey( 'missing', $notices->warnings(), 'back when it comes back' );

		$state = $this->service( IndexState::class );
		$state->begin( 2 );
		self::assertArrayNotHasKey( 'missing', $this->service( IndexHealth::class )->warnings(), 'not while a rebuild runs' );
		$state->progress( 2, 1, $b, array( 'Product 9: broken' ) );
		$state->switchLive();
		self::assertSame( array( 'Product 9: broken' ), $this->service( IndexHealth::class )->warnings()['errors']['errors'] ?? null );

		ob_start();
		$this->service( AdminPage::class )->warnings();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'Product 9: broken', $html );
		self::assertStringContainsString( Actions::DISMISS, $html );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		ob_start();
		$this->service( AdminPage::class )->warnings();
		self::assertSame( '', ob_get_clean(), 'not for those who cannot act on them' );
	}

	public function test_a_stale_queue_warns(): void {
		Clock::freeze( Clock::timestamp() - HOUR_IN_SECONDS );
		as_schedule_single_action( Clock::timestamp(), 'csearch_job_index_product', array( 1 ), Options::QUEUE_GROUP );
		Clock::freeze( null );
		self::assertTrue( IndexHealth::queueStale() );
	}
}
