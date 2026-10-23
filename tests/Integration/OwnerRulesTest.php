<?php
/**
 * The owner's redirects, pinned products and phrase synonyms.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Admin\Actions;
use CreativeBranding\Search\Admin\AdminPage;
use CreativeBranding\Search\Admin\Notices;
use CreativeBranding\Search\Admin\SynonymService;
use CreativeBranding\Search\Cli\RulesCommand;
use CreativeBranding\Search\Domain\Synonym;
use CreativeBranding\Search\Hooks\ResultsHooks;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Repository\SynonymRepository;
use CreativeBranding\Search\Results\ProductSearch;
use CreativeBranding\Search\Results\QueryRouter;
use CreativeBranding\Search\Search\OwnerRules;
use CreativeBranding\Search\Search\SearchLog;
use CreativeBranding\Search\Search\SearchService;
use CreativeBranding\Search\Search\Suggestions;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\PluginException;
use CreativeBranding\Search\Tests\Support\IndexTestCase;
use CreativeBranding\Search\Tests\Support\WooCommerceCatalogue;
use RuntimeException;
use WP_CLI;

require_once dirname( __DIR__ ) . '/Support/wp-cli-double.php';

/**
 * D-108 (a search sent to a page), D-109 (synonyms of more than one word) and D-110 (products pinned to a search),
 * against WooCommerce: the results page, the panel, the cache, the tab, WP-CLI and the hooks.
 */
final class OwnerRulesTest extends IndexTestCase {

	/**
	 * Product ids by catalogue index.
	 *
	 * @var array<int, int>
	 */
	private array $ids = array();

	public function set_up(): void {
		parent::set_up();
		$indexes   = array( 0, 1, 2, 6, 7, 15, 22 );
		$this->ids = array_combine( $indexes, $this->products( $indexes ) );
		$this->service( Reindex::class )->runInline();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'csearch_query_redirect' );
		remove_all_filters( 'csearch_pinned_products' );
		parent::tear_down();
	}

	private function rules(): OwnerRules {
		return $this->service( OwnerRules::class );
	}

	/**
	 * Visit a product search; the ids of the main query's posts, in order.
	 *
	 * @param string                $words The query.
	 * @param array<string, string> $more  More arguments.
	 * @return int[]
	 */
	private function visit( string $words, array $more = array() ): array {
		$this->service( ProductSearch::class )->reset();
		$this->service( ResultsHooks::class )->reset();
		$this->service( SearchLog::class )->clear();
		$this->go_to(
			add_query_arg(
				array(
					's'         => $words,
					'post_type' => 'product',
				) + $more,
				home_url( '/' )
			)
		);
		return array_map( static fn( $p ): int => $p instanceof \WP_Post ? $p->ID : (int) $p, $GLOBALS['wp_query']->posts );
	}

	/**
	 * Where a product search is sent, or null.
	 *
	 * @param string                $words The query.
	 * @param array<string, string> $more  More arguments.
	 */
	private function target( string $words, array $more = array() ): ?string {
		$this->visit( $words, $more );
		return $this->service( QueryRouter::class )->target();
	}

	public function test_a_redirect_sends_its_words_to_its_page_before_any_category(): void {
		$this->rules()->addRedirect( 'Size guide', '', '/size-guide/' );
		$this->rules()->addRedirect( 'wheels', '', home_url( '/wheel-care/' ) );
		self::assertSame( home_url( '/size-guide/' ), $this->target( 'the SIZE guide!' ), 'case, stop words and punctuation do not matter; a path becomes the address' );
		self::assertTrue( $GLOBALS['wp_query']->get( 'csearch_routed' ), 'the results page is left' );
		$pending = $this->service( SearchLog::class )->pending();
		self::assertCount( 1, $pending, 'logged once' );
		self::assertSame( array( 'the SIZE guide!', 'page' ), array( $pending[0]['query'], $pending[0]['source'] ) );
		self::assertSame( home_url( '/wheel-care/' ), $this->target( 'wheels' ), 'before the Wheels category (D-102)' );
		self::assertNull( $this->target( 'size guides' ), 'its words exactly' );
		self::assertNull( $this->target( 'wheels', array( QueryRouter::ALL_ARG => '1' ) ), 'every product, asked for' );
		self::assertNull( $this->target( 'wheels', array( 'paged' => '2' ) ), 'past the first page' );
		self::assertCount( 2, OwnerRules::redirects() );
	}

	public function test_a_redirect_answers_its_language_and_only_this_sites_pages(): void {
		$this->rules()->addRedirect( 'returns', 'fr', '/retours/' );
		self::assertNull( $this->target( 'returns' ), 'an English search' );
		self::assertSame( home_url( '/retours/' ), $this->rules()->redirect( 'returns', 'fr' ) );
		try {
			$this->rules()->addRedirect( 'elsewhere', '', 'https://example.org/' );
			self::fail( 'another site' );
		} catch ( PluginException $e ) {
			self::assertStringContainsString( 'a page of this site', $e->getMessage() );
		}
		try {
			$this->rules()->addRedirect( 'the', '', '/x/' );
			self::fail( 'stop words only' );
		} catch ( PluginException $e ) {
			self::assertStringContainsString( 'not only short words', $e->getMessage() );
		}
		add_filter( 'csearch_query_redirect', static fn(): string => 'https://example.org/phish' );
		self::assertNull( $this->rules()->redirect( 'anything', 'en' ), 'a filter cannot send a shopper off the site' );
		remove_all_filters( 'csearch_query_redirect' );
		add_filter( 'csearch_query_redirect', static fn( $url, string $words ): ?string => 'promo' === $words ? home_url( '/promo/' ) : $url, 10, 2 );
		self::assertSame( home_url( '/promo/' ), $this->target( 'promo' ), 'the filter adds one' );
	}

	public function test_a_redirect_for_the_same_words_is_changed_not_added(): void {
		$this->rules()->addRedirect( 'size guide', '', '/a/' );
		$this->rules()->addRedirect( 'Size-Guide', '', '/b/' );
		$this->rules()->addRedirect( 'size guide', 'fr', '/c/' );
		self::assertSame( array( home_url( '/b/' ), home_url( '/c/' ) ), array_column( OwnerRules::redirects(), 'url' ) );
		self::assertSame( 1, $this->rules()->remove( OwnerRules::REDIRECTS, array( 0, 7 ) ) );
		self::assertSame( array( home_url( '/c/' ) ), array_column( OwnerRules::redirects(), 'url' ) );
		self::assertSame( 0, $this->rules()->remove( OwnerRules::REDIRECTS, array() ) );
	}

	public function test_pins_lead_the_results_page_and_the_panel(): void {
		$griptape = $this->ids[15];
		$tee      = $this->ids[22];
		$search   = $this->service( SearchService::class );
		$before   = $search->rankedIds( 'philly', 'en', '' )['ids'];
		self::assertNotContains( $griptape, $before );
		$this->rules()->addPins( 'Philly', '', array( $griptape, $this->ids[2] ) );
		$ids = $search->rankedIds( 'philly', 'en', '' )['ids'];
		self::assertSame( array( $griptape, $this->ids[2] ), array_slice( $ids, 0, 2 ), 'first, in the owner’s order; the cached answer was dropped' );
		self::assertCount( count( $before ) + 1, $ids, 'a ranked product pinned moves up, not twice' );

		$posts = $this->visit( 'philly' );
		self::assertSame( array( $griptape, $this->ids[2] ), array_slice( $posts, 0, 2 ), 'the results page in that order' );
		self::assertNull( $this->service( QueryRouter::class )->target() );

		$answer = $this->service( Suggestions::class )->answer( 'philly', 'en', 6 );
		self::assertSame( array( $griptape, $this->ids[2], $this->ids[1] ), array_column( $answer['products'], 'id' ) );
		self::assertSame( count( $before ) + 1, $answer['total'] );

		$hidden = WooCommerceCatalogue::get( $tee );
		$hidden->set_catalog_visibility( 'hidden' );
		$hidden->save();
		$this->service( Reindex::class )->runInline();
		$this->rules()->addPins( 'philly', '', array( $tee, $griptape ) );
		self::assertSame( $griptape, array_column( $this->service( Suggestions::class )->answer( 'philly', 'en', 6 )['products'], 'id' )[0], 'a product hidden from search is left out of the panel' );
		self::assertNotContains( $tee, $this->visit( 'philly' ), 'and the results page' );
	}

	public function test_a_pinned_search_is_not_sent_to_a_category(): void {
		self::assertNotNull( $this->target( 'wheels' ) );
		$this->rules()->addPins( 'wheels', '', array( $this->ids[6] ) );
		self::assertNull( $this->target( 'wheels' ) );
		add_filter( 'csearch_pinned_products', static fn( array $ids, string $words ): array => 'griptape' === $words ? array( 0, $GLOBALS['csearch_test_pin'] ) : $ids, 10, 2 );
		$GLOBALS['csearch_test_pin'] = $this->ids[0];
		self::assertSame( array( $this->ids[0] ), $this->rules()->pins( 'griptape', 'en' ), 'the filter, sanitised' );
		self::assertSame( array(), $this->rules()->pins( '', 'en' ) );
	}

	public function test_the_tab_saves_and_removes_rules(): void {
		$actions = $this->service( Actions::class );
		$sku     = WooCommerceCatalogue::get( $this->ids[15] )->get_sku();
		$back    = $actions->saveRules(
			array(
				'redirect_query' => 'size guide',
				'redirect_lang'  => '',
				'redirect_url'   => '/size-guide/',
				'pin_query'      => 'grip',
				'pin_lang'       => '',
				'pin_products'   => $sku . ', ' . $this->ids[0] . ', NOPE-1',
			)
		);
		self::assertSame( AdminPage::url( 'rules' ), $back );
		$flash = Notices::takeFlash();
		self::assertSame( 'warning', $flash[0] ?? '' );
		self::assertStringContainsString( 'NOPE-1', $flash[1] ?? '' );
		self::assertSame( array( $this->ids[15], $this->ids[0] ), OwnerRules::pinned()[0]['ids'] );
		self::assertCount( 1, OwnerRules::redirects() );

		$actions->saveRules(
			array(
				'redirect_remove' => array( '0', 'x' ),
				'pin_remove'      => array( '0' ),
			)
		);
		self::assertSame( array( 'success', 'Redirects and pins saved. Searches use them now.' ), Notices::takeFlash() );
		self::assertSame( array(), OwnerRules::redirects() );
		self::assertSame( array(), OwnerRules::pinned() );

		$actions->saveRules(
			array(
				'redirect_query' => 'away',
				'redirect_url'   => 'https://example.org/',
			)
		);
		self::assertSame( 'error', Notices::takeFlash()[0] ?? '' );
		$actions->saveRules(
			array(
				'pin_query'    => 'grip',
				'pin_products' => 'NOPE-2',
			)
		);
		$flash = Notices::takeFlash();
		self::assertSame( 'error', $flash[0] ?? '' );
		self::assertStringContainsString( 'Choose at least one product', $flash[1] ?? '' );
		self::assertStringContainsString( 'NOPE-2', $flash[1] ?? '' );

		$_GET['tab'] = 'rules';
		ob_start();
		$this->service( AdminPage::class )->render();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'No redirects yet.', $html );
		self::assertStringContainsString( 'name="pin_products"', $html );
	}

	public function test_wp_cli(): void {
		WP_CLI::$lines = array();
		$command       = $this->service( RulesCommand::class );
		$command->redirect( array( 'size guide', '/size-guide/' ), array() );
		$command->pin( array( 'grip', (string) $this->ids[15], (string) $this->ids[0] ), array( 'lang' => 'en' ) );
		self::assertSame( array( 'success: Redirect saved.', 'success: Products pinned.' ), WP_CLI::$lines );
		$command->redirects( array(), array( 'format' => 'json' ) );
		self::assertSame( array( array( 'index' => 0, 'query' => 'size guide', 'lang' => '', 'url' => home_url( '/size-guide/' ) ) ), WP_CLI::$items );
		$command->pins( array(), array() );
		self::assertSame( 'table', WP_CLI::$format );
		self::assertSame( $this->ids[15] . ',' . $this->ids[0], WP_CLI::$items[0]['ids'] );
		WP_CLI::$lines = array();
		$command->unredirect( array( '0' ) );
		$command->unpin( array( '0', '3' ) );
		self::assertSame( array( 'success: 1 rule removed.', 'success: 1 rule removed.' ), WP_CLI::$lines );
		$this->expectException( RuntimeException::class );
		$command->redirect( array( 'away', 'https://example.org/' ), array() );
	}

	public function test_a_phrase_synonym_finds_what_its_meaning_names(): void {
		$search = $this->service( SearchService::class );
		self::assertSame( array(), $search->rankedIds( 'zz board', 'en', '' )['ids'] );
		$this->service( SynonymService::class )->add( new Synonym( 'en', 'zz board', 'skate deck' ), 0 );
		$ids = $search->rankedIds( 'zz board', 'en', '' )['ids'];
		sort( $ids );
		$decks = array( $this->ids[0], $this->ids[1], $this->ids[2] );
		sort( $decks );
		self::assertSame( $decks, $ids, 'the decks, through the meaning as a whole' );
		self::assertSame( array( 'skate deck' ), $this->service( SynonymService::class )->expand( 'the zz board', 'en' ), 'the test box' );
		$this->service( SynonymRepository::class )->add( new Synonym( 'en', 'philly', 'kazumi tee', true ), 0 );
		self::assertContains( 'philly', $this->service( SynonymService::class )->expand( 'Kazumi tee', 'en' ), 'both ways' );
	}

	public function test_the_settings_keep_the_rules(): void {
		Settings::set( OwnerRules::PINS, array( array( 'query' => 'x', 'ids' => array( 1, 2, 3, 4, 5, 6 ) ) ) );
		self::assertSame( array( 1, 2, 3, 4, 5 ), OwnerRules::pinned()[0]['ids'] );
	}
}
