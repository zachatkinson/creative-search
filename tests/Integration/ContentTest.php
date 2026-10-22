<?php
/**
 * Posts and pages tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Admin\Actions;
use CreativeBranding\Search\Admin\ContentPanel;
use CreativeBranding\Search\Hooks\ResultsHooks;
use CreativeBranding\Search\Index\Indexer;
use CreativeBranding\Search\Index\IndexQueue;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Results\ProductSearch;
use CreativeBranding\Search\Results\ResultsView;
use CreativeBranding\Search\Search\SearchService;
use CreativeBranding\Search\Search\Suggestions;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Options;
use CreativeBranding\Search\Support\Tables;
use CreativeBranding\Search\Tests\Support\IndexTestCase;

/**
 * Posts and pages (1.1, D-093, Skatehouse handoff item 7): off by default; a page switched on is indexed with
 * its parent's title; WooCommerce's pages, the front page, private, protected and excluded pages stay out;
 * the panel and the results page show them apart from the products; the products' order never changes.
 */
final class ContentTest extends IndexTestCase {

	/**
	 * Page ids by title.
	 *
	 * @var array<string, int>
	 */
	private array $pages = array();

	public function set_up(): void {
		parent::set_up();
		$this->products( array( 0, 1, 2, 6, 7, 15 ) );
		$guides                                = $this->page( 'Size guides', 'Every size we stock, measured.' );
		$this->pages['Size guides']            = $guides;
		$this->pages['Wheel guide']            = $this->page( 'Wheel guide', 'Which wheel diameter for street or park.', $guides );
		$this->pages['Deck width guide']       = $this->page( 'Deck width guide', 'Pick a deck width by your shoe size.', $guides );
		$this->pages['Shipping and returns']   = $this->page( 'Shipping &amp; returns', 'Free shipping over $100. Returns within 30 days.' );
		$this->pages['Contact']                = $this->page( 'Contact', 'Write to us.' );
		$this->pages['Track an order']         = $this->page( 'Track an order', 'Where is my parcel?' );
		$this->pages['Dealers']                = $this->page( 'Dealers', 'Open a wholesale account.' );
		$this->pages['Drops and pre-books']    = $this->page( 'Drops and pre-books', 'Reserve the next series before it lands.' );
		$this->pages['Private notes']          = $this->page( 'Private returns notes', 'returns', 0, 'private' );
		$this->pages['Protected']              = $this->page( 'Protected returns', 'returns', 0, 'publish', 'secret' );
		$this->pages['Excluded']               = $this->page( 'Excluded returns', 'returns' );
		$this->pages['Home']                   = $this->page( 'Home returns', 'returns' );
		$this->pages['Cart']                   = $this->page( 'Cart returns', 'returns' );
		$this->pages['Shop']                   = $this->page( 'Shop returns', 'returns' );
		$this->pages['Checkout']               = $this->page( 'Checkout returns', 'returns' );
		$this->pages['My account']             = $this->page( 'Account returns', 'returns' );
		$this->pages['Under a private page']   = $this->page( 'Staff rota', 'Who opens on Sunday.', $this->pages['Private notes'] );
		update_post_meta( $this->pages['Excluded'], Options::META_EXCLUDE, 'yes' );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $this->pages['Home'] );
		update_option( 'woocommerce_cart_page_id', $this->pages['Cart'] );
		update_option( 'woocommerce_shop_page_id', $this->pages['Shop'] );
		update_option( 'woocommerce_checkout_page_id', $this->pages['Checkout'] );
		update_option( 'woocommerce_myaccount_page_id', $this->pages['My account'] );
		self::factory()->post->create(
			array(
				'post_title'   => 'Dealer deck drop',
				'post_content' => 'The KAZUMI Dealer is back in 8.25.',
				'post_status'  => 'publish',
			)
		);
		$this->service( IndexQueue::class )->clear();
		Settings::update( array( 'index_pages' => 'yes' ) );
		$this->service( Reindex::class )->runInline();
	}

	public function tear_down(): void {
		Settings::update(
			array(
				'index_pages' => 'no',
				'index_posts' => 'no',
			)
		);
		parent::tear_down();
	}

	/**
	 * Create a page and forget what its save queued.
	 *
	 * @param string $title    Title.
	 * @param string $content  Content.
	 * @param int    $parent   Parent page.
	 * @param string $status   Status.
	 * @param string $password Password.
	 */
	private function page( string $title, string $content, int $parent = 0, string $status = 'publish', string $password = '' ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'     => 'page',
				'post_title'    => $title,
				'post_content'  => $content,
				'post_status'   => $status,
				'post_parent'   => $parent,
				'post_password' => $password,
			)
		);
		$this->service( IndexQueue::class )->clear();
		return $id;
	}

	/**
	 * The titles of the pages the search finds.
	 *
	 * @param string $query   Query.
	 * @param string $context `suggest` or `page`.
	 * @return string[]
	 */
	private function found( string $query, string $context = 'page' ): array {
		return array_column( $this->service( SearchService::class )->content( $query, 'en', $context ), 'name' );
	}

	public function test_published_pages_are_indexed_and_the_rest_stay_out(): void {
		$indexed = array_map( 'intval', array_column( $this->documents( 'page' ), 'object_id' ) );
		sort( $indexed );
		$want = array( $this->pages['Size guides'], $this->pages['Wheel guide'], $this->pages['Deck width guide'], $this->pages['Shipping and returns'], $this->pages['Contact'], $this->pages['Track an order'], $this->pages['Dealers'], $this->pages['Drops and pre-books'], $this->pages['Under a private page'] );
		sort( $want );
		self::assertSame( $want, $indexed, 'not private, protected, excluded, the front page, the shop, cart, checkout or account page' );
		self::assertSame( array(), $this->documents( 'post' ), 'posts are off' );
		self::assertSame( 'Shipping & returns', $this->documents( 'page', $this->pages['Shipping and returns'] )[0]['title'], 'the title decoded' );
		$wheel = $this->postingsOf( (int) $this->documents( 'page', $this->pages['Wheel guide'] )[0]['id'] );
		self::assertArrayHasKey( \CreativeBranding\Search\Domain\Fields::CATEGORY, $wheel['size'], 'the parent\'s title, as a category' );
	}

	public function test_the_handoff_page_queries(): void {
		self::assertSame( array( 'Shipping & returns' ), $this->found( 'returns' ) );
		self::assertSame( array( 'Shipping & returns' ), $this->found( 'shipping' ) );
		self::assertSame( 'Size guides', $this->found( 'size guide' )[0], 'the parent ahead of its children' );
		self::assertSame( 'Wheel guide', $this->found( 'wheel guide' )[0] );
		self::assertContains( 'Deck width guide', $this->found( 'deck width' ) );
		self::assertSame( array( 'Contact' ), $this->found( 'contact' ) );
		self::assertSame( 'Track an order', $this->found( 'track order' )[0] );
		self::assertContains( 'Dealers', $this->found( 'dealer' ) );
		self::assertSame( 'Drops and pre-books', $this->found( 'pre-book' )[0] );
		self::assertCount( 2, $this->found( 'guide', 'suggest' ), 'two in the panel' );
		self::assertCount( 3, $this->found( 'guide' ), 'three on the results page' );
	}

	public function test_a_page_that_becomes_the_front_page_leaves_and_children_follow_their_parent(): void {
		$this->service( IndexQueue::class )->clear();
		update_option( 'page_on_front', $this->pages['Contact'] );
		$this->runQueue();
		self::assertSame( array(), $this->documents( 'page', $this->pages['Contact'] ), 'the new front page leaves' );
		self::assertCount( 1, $this->documents( 'page', $this->pages['Home'] ), 'the old one is an ordinary page again' );
		update_option( 'woocommerce_checkout_page_id', $this->pages['Dealers'] );
		$this->runQueue();
		self::assertSame( array(), $this->documents( 'page', $this->pages['Dealers'] ) );
		self::assertCount( 1, $this->documents( 'page', $this->pages['Checkout'] ) );

		wp_update_post( array( 'ID' => $this->pages['Size guides'], 'post_title' => 'Fit guides' ) );
		$this->runQueue();
		$wheel = $this->postingsOf( (int) $this->documents( 'page', $this->pages['Wheel guide'] )[0]['id'] );
		self::assertArrayHasKey( 'fit', $wheel, 'a renamed parent reaches its children' );
		self::assertArrayNotHasKey( 'size', $wheel );
		wp_update_post( array( 'ID' => $this->pages['Size guides'], 'post_status' => 'private' ) );
		$this->runQueue();
		$wheel = $this->postingsOf( (int) $this->documents( 'page', $this->pages['Wheel guide'] )[0]['id'] );
		self::assertArrayNotHasKey( 'fit', $wheel, 'and a parent made private takes its title away' );
	}

	public function test_the_limit_filter_lowers_the_panel_and_moves_the_results_page(): void {
		$limit = static fn( int $limit, string $context ): int => 'suggest' === $context ? 9 : 1;
		add_filter( 'csearch_content_limit', $limit, 10, 2 );
		self::assertCount( 2, $this->found( 'guide', 'suggest' ), 'the panel holds 2 at most' );
		self::assertCount( 1, $this->found( 'guide' ) );
		remove_filter( 'csearch_content_limit', $limit, 10 );
		add_filter( 'csearch_content_limit', '__return_zero' );
		self::assertSame( array(), $this->found( 'guide', 'suggest' ) );
		remove_filter( 'csearch_content_limit', '__return_zero' );
	}

	public function test_a_private_parent_s_title_is_never_indexed_or_shown(): void {
		$rota = $this->postingsOf( (int) $this->documents( 'page', $this->pages['Under a private page'] )[0]['id'] );
		self::assertArrayNotHasKey( 'privat', $rota, 'the private parent\'s title is not indexed' );
		self::assertArrayNotHasKey( 'note', $rota );
		self::assertSame( array(), $this->found( 'private notes' ), 'nor found by it' );
		$rows = $this->service( SearchService::class )->content( 'staff rota', 'en', 'suggest' );
		self::assertSame( 'Staff rota', $rows[0]['name'] );
		self::assertSame( '', $rows[0]['parent'], 'no "Private: …" in a public answer' );
		wp_update_post( array( 'ID' => $this->pages['Private notes'], 'post_status' => 'publish', 'post_password' => 'secret' ) );
		self::assertSame( '', $this->service( SearchService::class )->content( 'staff rota', 'en', 'page' )[0]['parent'], 'nor a protected one' );
	}

	public function test_a_row_carries_its_parent_and_the_panel_its_group(): void {
		$rows = $this->service( SearchService::class )->content( 'wheel guide', 'en', 'suggest' );
		self::assertSame( 'Wheel guide', $rows[0]['name'] );
		self::assertSame( 'Size guides', $rows[0]['parent'] );
		self::assertSame( 'page', $rows[0]['type'] );
		self::assertSame( get_permalink( $this->pages['Wheel guide'] ), $rows[0]['url'] );
		$answer = $this->service( Suggestions::class )->answer( 'wheel guide', 'en', 6 );
		self::assertSame( 'Wheel guide', $answer['content'][0]['name'] );
		self::assertSame( 'Pages', ResultsView::contentHeading() );
		Settings::update( array( 'index_posts' => 'yes' ) );
		self::assertSame( 'Pages and posts', ResultsView::contentHeading() );
		Settings::update( array( 'index_pages' => 'no' ) );
		self::assertSame( 'Posts', ResultsView::contentHeading() );
	}

	public function test_turning_pages_on_changes_no_product_s_rank(): void {
		$search  = $this->service( SearchService::class );
		$queries = array( 'kazumi', 'deck', 'kazumi dealer', 'orbit wheels', 'size', 'returns' );
		$with    = array();
		foreach ( $queries as $q ) {
			$with[ $q ] = $search->run( $q, 'en' )->productIds();
		}
		Settings::update( array( 'index_pages' => 'no' ) );
		$this->service( Reindex::class )->runInline();
		self::assertSame( array(), $this->documents( 'page' ) );
		foreach ( $queries as $q ) {
			self::assertSame( $with[ $q ], $search->run( $q, 'en' )->productIds(), $q );
		}
		self::assertSame( array(), $this->found( 'returns' ), 'switched off, nothing runs' );
	}

	public function test_switching_a_type_on_and_off_in_the_settings_indexes_and_removes_it(): void {
		$queue = $this->service( IndexQueue::class );
		$this->service( Actions::class )->saveTools( array( 'index_posts' => 'yes', 'index_pages' => 'yes' ) + array_fill_keys( array( 'index_long_description', 'index_tags', 'index_variation_skus', 'show_prices', 'show_stock', 'show_terms', 'label_visible' ), 'yes' ) );
		self::assertSame( array( 'post' ), $queue->pending()['content_types'] );
		$this->runQueue();
		self::assertCount( 1, $this->documents( 'post' ) );
		self::assertSame( array( 'Dealer deck drop' ), $this->found( 'dealer drop' ) );
		$this->service( Actions::class )->saveTools( array( 'index_posts' => 'no', 'index_pages' => 'yes' ) );
		$this->runQueue();
		self::assertSame( array(), $this->documents( 'post' ) );
		self::assertCount( 9, $this->documents( 'page' ), 'the pages stay' );
	}

	public function test_saving_trashing_and_excluding_a_page_reach_the_index(): void {
		$id = $this->pages['Contact'];
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Contact the shop',
			)
		);
		$this->runQueue();
		self::assertSame( 'Contact the shop', $this->documents( 'page', $id )[0]['title'] );
		update_post_meta( $id, Options::META_EXCLUDE, 'yes' );
		$this->runQueue();
		self::assertSame( array(), $this->documents( 'page', $id ) );
		delete_post_meta( $id, Options::META_EXCLUDE );
		$this->runQueue();
		self::assertCount( 1, $this->documents( 'page', $id ) );
		wp_trash_post( $id );
		$this->runQueue();
		self::assertSame( array(), $this->documents( 'page', $id ) );
		wp_untrash_post( $id );
		wp_publish_post( $id );
		$this->runQueue();
		self::assertCount( 1, $this->documents( 'page', $id ) );
		wp_delete_post( $id, true );
		$this->runQueue();
		self::assertSame( array(), $this->documents( 'page', $id ) );
	}

	public function test_a_post_saved_while_posts_are_off_queues_nothing(): void {
		self::factory()->post->create(
			array(
				'post_title'  => 'Another post',
				'post_status' => 'publish',
			)
		);
		self::assertSame( array(), $this->service( IndexQueue::class )->pending()['content'] );
	}

	public function test_the_exclusion_box_saves_with_its_nonce_and_the_edit_capability(): void {
		$id    = $this->pages['Contact'];
		$panel = $this->service( ContentPanel::class );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_POST = array(
			ContentPanel::NONCE => wp_create_nonce( ContentPanel::NONCE ),
			'csearch_exclude'   => 'yes',
		);
		$panel->save( $id, get_post( $id ) );
		self::assertSame( 'yes', get_post_meta( $id, Options::META_EXCLUDE, true ) );
		$_POST = array( ContentPanel::NONCE => wp_create_nonce( ContentPanel::NONCE ) );
		$panel->save( $id, get_post( $id ) );
		self::assertSame( '', get_post_meta( $id, Options::META_EXCLUDE, true ), 'unticked' );
		$_POST = array(
			ContentPanel::NONCE => 'forged',
			'csearch_exclude'   => 'yes',
		);
		$panel->save( $id, get_post( $id ) );
		self::assertSame( '', get_post_meta( $id, Options::META_EXCLUDE, true ), 'a forged nonce changes nothing' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_POST = array(
			ContentPanel::NONCE => wp_create_nonce( ContentPanel::NONCE ),
			'csearch_exclude'   => 'yes',
		);
		$panel->save( $id, get_post( $id ) );
		self::assertSame( '', get_post_meta( $id, Options::META_EXCLUDE, true ), 'nor a user who cannot edit the page' );
		ob_start();
		$panel->render( get_post( $id ) );
		self::assertStringContainsString( 'name="csearch_exclude"', (string) ob_get_clean() );
		$_POST = array();
	}

	/**
	 * Visit a search page.
	 *
	 * @param string $query The query.
	 */
	private function visit( string $query ): void {
		$this->service( ProductSearch::class )->reset();
		$this->service( ResultsHooks::class )->reset();
		$this->go_to( add_query_arg( array( 's' => $query, 'post_type' => 'product' ), home_url( '/' ) ) );
	}

	public function test_the_results_page_shows_the_pages_above_the_products(): void {
		$this->visit( 'deck width' );
		$view = $this->service( ResultsView::class );
		$html = $view->summary();
		self::assertStringContainsString( 'csearch-content', $html );
		self::assertStringContainsString( '>Deck width guide</a>', $html );
		self::assertStringContainsString( '<span class="csearch-content-parent">Size guides</span>', $html );
		self::assertSame( '', $view->contentAfter(), 'not again below' );
		self::assertFalse( apply_filters( 'woocommerce_redirect_single_search_result', true ), 'a page found keeps the results page' );
	}

	public function test_a_product_named_exactly_keeps_the_pages_below_it(): void {
		$name = get_the_title( $this->documents( 'product' )[0]['object_id'] );
		wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'About ' . $name,
				'post_content' => $name,
				'post_status'  => 'publish',
			)
		);
		$this->runQueue();
		$this->visit( $name );
		$view = $this->service( ResultsView::class );
		self::assertStringNotContainsString( 'csearch-content', $view->summary() );
		self::assertStringContainsString( 'csearch-content', $view->contentAfter() );
	}

	public function test_a_page_leads_when_no_product_matches(): void {
		$this->visit( 'returns' );
		$view = $this->service( ResultsView::class );
		self::assertTrue( $view->contentLeads() );
		$html = $view->noResults();
		self::assertStringContainsString( 'No products match “returns”, but these do:', $html );
		self::assertLessThan( strpos( $html, 'csearch-no-results-categories' ) ?: PHP_INT_MAX, strpos( $html, 'Shipping &amp; returns' ) );
		$this->service( ResultsHooks::class )->leadWithContent();
		self::assertFalse( has_action( 'woocommerce_no_products_found', 'wc_no_products_found' ), 'WooCommerce\'s bare notice goes' );
		$this->service( ResultsHooks::class )->reset();
		self::assertNotFalse( has_action( 'woocommerce_no_products_found', 'wc_no_products_found' ) );
		$block = apply_filters( 'render_block_woocommerce/product-collection-no-results', '<p>No products</p>', array() );
		self::assertStringNotContainsString( 'No products</p>', $block );
		self::assertStringContainsString( 'Shipping &amp; returns', $block );
	}

	public function test_the_no_results_links_follow_the_settings(): void {
		Settings::update(
			array(
				'help_page'  => $this->pages['Contact'],
				'guide_page' => $this->pages['Size guides'],
				'guide_text' => 'Not sure of your size? See our guides',
			)
		);
		$this->visit( 'xyzzy' );
		$html = $this->service( ResultsView::class )->noResults();
		self::assertStringContainsString( '<a href="' . esc_url( get_permalink( $this->pages['Contact'] ) ) . '">Can’t find what you need? Contact us</a>', $html, 'the default wording' );
		self::assertStringContainsString( 'Not sure of your size? See our guides</a>', $html );
		self::assertLessThan( strpos( $html, 'csearch-no-results-guide' ), strpos( $html, 'csearch-no-results-help' ) );
		wp_update_post(
			array(
				'ID'          => $this->pages['Contact'],
				'post_status' => 'draft',
			)
		);
		$this->visit( 'xyzzy' );
		self::assertStringNotContainsString( 'csearch-no-results-help', $this->service( ResultsView::class )->noResults(), 'an unpublished page is no link' );
		Settings::set( 'help_page', $this->products( array( 4 ) )[0] );
		$this->visit( 'xyzzy' );
		self::assertStringNotContainsString( 'csearch-no-results-help', $this->service( ResultsView::class )->noResults(), 'a product is not a page' );
		add_filter( 'csearch_no_results_links', '__return_empty_array' );
		$this->visit( 'xyzzy' );
		self::assertStringNotContainsString( 'csearch-no-results-link', $this->service( ResultsView::class )->noResults() );
		remove_filter( 'csearch_no_results_links', '__return_empty_array' );
		Settings::update(
			array(
				'help_page'  => 0,
				'guide_page' => 0,
				'guide_text' => '',
			)
		);
	}

	public function test_the_api_carries_the_pages(): void {
		$request = new \WP_REST_Request( 'GET', '/csearch/v1/suggest' );
		$request->set_param( 'q', 'size guide' );
		$data = rest_do_request( $request )->get_data();
		self::assertSame( 'Size guides', $data['content'][0]['name'] );
		$request = new \WP_REST_Request( 'GET', '/csearch/v1/search' );
		$request->set_param( 'q', 'returns' );
		$data = rest_do_request( $request )->get_data();
		self::assertSame( 'Shipping & returns', $data['content'][0]['name'] );
		$request->set_param( 'page', 2 );
		self::assertSame( array(), rest_do_request( $request )->get_data()['content'], 'the first page only' );
	}

	public function test_a_click_on_a_page_is_logged(): void {
		self::assertTrue( in_array( 'page', \CreativeBranding\Search\Search\Clicks::TYPES, true ) );
		self::assertTrue( in_array( 'post', \CreativeBranding\Search\Search\Clicks::TYPES, true ) );
	}

	public function test_the_index_command_takes_a_page_id(): void {
		self::assertTrue( $this->service( Indexer::class )->indexContent( $this->pages['Contact'] ) );
		self::assertFalse( $this->service( Indexer::class )->indexContent( $this->pages['Protected'] ) );
		self::assertFalse( $this->service( Indexer::class )->indexContent( 999999 ) );
	}

	public function test_content_counts_stay_out_of_the_catalogue_s(): void {
		global $wpdb;
		$wheel = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE term = %s', Tables::terms(), 'shipping' ) ); // phpcs:ignore
		self::assertGreaterThan( 0, $wheel );
		self::assertSame( 0, $this->df( 'shipping' ), 'a word only a page carries has no dictionary count' );
		self::assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE term_id = %d', Tables::stats(), $wheel ) ) ); // phpcs:ignore
	}
}
