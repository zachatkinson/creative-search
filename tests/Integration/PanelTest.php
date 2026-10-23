<?php
/**
 * The panel's 1.3 rows: suggested searches, category scopes, the typo line, add to cart.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Repository\LogRepository;
use CreativeBranding\Search\Search\CategoryScopes;
use CreativeBranding\Search\Search\Suggestions;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Clock;
use CreativeBranding\Search\Tests\Support\IndexTestCase;

/**
 * Suggested searches from the log (D-112), scopes (D-113), the typo's correction (D-114), the cart flag (D-115),
 * each switched off by its setting.
 */
final class PanelTest extends IndexTestCase {

	/**
	 * Product ids by catalogue index.
	 *
	 * @var array<int, int>
	 */
	private array $ids = array();

	public function set_up(): void {
		parent::set_up();
		$indexes   = array( 0, 1, 2, 6, 7, 15, 20, 21, 22 );
		$this->ids = array_combine( $indexes, $this->products( $indexes ) );
		$this->service( Reindex::class )->runInline();
	}

	/**
	 * The panel's answer.
	 *
	 * @param string $q    What is typed.
	 * @param string $lang Language.
	 * @return array<string, mixed>
	 */
	private function answer( string $q, string $lang = 'en' ): array {
		return $this->service( Suggestions::class )->answer( $q, $lang, 6 );
	}

	/**
	 * Log a search a number of times.
	 *
	 * @param string $query   As typed.
	 * @param int    $times   How many rows.
	 * @param array{lang?: string, results?: int, source?: string, ago?: int} $with Overrides; `ago` in days.
	 */
	private function logged( string $query, int $times, array $with = array() ): void {
		$row = array(
			'query'      => $query,
			'normalised' => strtolower( $query ),
			'lang'       => $with['lang'] ?? 'en',
			'results'    => $with['results'] ?? 3,
			'source'     => $with['source'] ?? 'page',
			'created_at' => Clock::format( Clock::timestamp() - ( $with['ago'] ?? 1 ) * DAY_IN_SECONDS ),
		);
		$this->service( LogRepository::class )->insert( array_fill( 0, $times, $row ) );
	}

	public function test_searches_other_shoppers_made_are_suggested(): void {
		$this->logged( 'kazumi deck', 4 );
		$this->logged( 'Kazumi Ghost', 3, array( 'source' => 'suggest' ) );
		$log   = $this->service( LogRepository::class );
		$since = Clock::format( Clock::timestamp() - 2 * DAY_IN_SECONDS );
		for ( $i = 0; $i < 3; $i++ ) {
			$log->click( 'kazumi ghost', 'en', $since, 'product', $this->ids[21] );
		}
		$this->logged( 'kazumi dealer', 2 );
		$this->logged( 'kazumi tiger', 3, array( 'source' => 'suggest' ) );
		$this->logged( 'kazumi wheels', 3, array( 'results' => 0 ) );
		$this->logged( 'kazumi zebra', 3 );
		$this->logged( 'kazumi planche', 3, array( 'lang' => 'fr' ) );
		$this->logged( 'kazumi shop', 3, array( 'ago' => Settings::retentionDays() + 2 ) );
		$this->logged( 'kazumi [redacted]', 5 );

		self::assertSame( array( 'kazumi deck', 'kazumi ghost' ), $this->answer( 'kaz' )['queries'], 'three times or more, found something, from a results page or chosen in the panel, every word indexed, in the window' );
		self::assertSame( array(), $this->answer( 'ka' )['queries'], 'three characters first' );
		self::assertSame( array( 'kazumi ghost' ), $this->answer( 'kazumi g' )['queries'] );
		self::assertSame( array(), $this->answer( 'kazumi deck' )['queries'], 'never what is already typed' );
	}

	public function test_the_owner_filters_and_switches_off_suggested_searches(): void {
		$this->logged( 'kazumi deck', 3 );
		$this->logged( 'kazumi ghost', 3 );
		$drop = static fn( array $picked ): array => array_merge( array_diff( $picked, array( 'kazumi ghost' ) ), array( 42, '  ' ) );
		add_filter( 'csearch_suggested_searches', $drop );
		self::assertSame( array( 'kazumi deck' ), $this->answer( 'kaz' )['queries'], 'only non-empty strings are kept' );
		remove_filter( 'csearch_suggested_searches', $drop );
		Settings::set( 'suggest_queries', 'no' );
		self::assertSame( array(), $this->answer( 'kaz' )['queries'] );
	}

	public function test_categories_most_products_are_in_are_offered(): void {
		$scopes = $this->answer( 'kazumi' )['scopes'];
		$decks  = get_term_by( 'slug', 'decks', 'product_cat' );
		self::assertInstanceOf( \WP_Term::class, $decks );
		self::assertSame(
			array(
				array(
					'id'   => $decks->term_id,
					'name' => 'Decks',
					'url'  => CategoryScopes::url( 'kazumi', 'decks' ),
				),
			),
			$scopes,
			'two of three in Decks; Skateboards holds no more, and one T-shirt is too few'
		);
		self::assertStringContainsString( 'product_cat=decks', $scopes[0]['url'] );
		self::assertStringContainsString( 'post_type=product', $scopes[0]['url'] );
		self::assertSame( array(), $this->answer( 'orbit' )['scopes'], 'a category holding every product found narrows nothing' );
		self::assertSame( array(), $this->answer( 'nothing-matches-this' )['scopes'] );
		Settings::set( 'suggest_scopes', 'no' );
		self::assertSame( array(), $this->answer( 'kazumi' )['scopes'] );
	}

	public function test_a_typo_is_read_without_a_way_back(): void {
		$answer = $this->answer( 'kazuni' );
		self::assertSame( 'kazumi', $answer['corrected'] );
		self::assertNotSame( array(), $answer['products'] );
	}

	public function test_products_carry_their_price_and_whether_they_go_straight_to_the_cart(): void {
		$row = $this->answer( 'kazumi dealer' )['products'][0];
		self::assertSame( $this->ids[0], $row['id'] );
		self::assertSame( 84.99, $row['price'] );
		self::assertFalse( $row['cart'], 'off by default' );
		Settings::set( 'suggest_cart', 'yes' );
		$cart = $this->answer( 'kazumi dealer' )['products'][0];
		self::assertIsBool( $cart['cart'] );
		self::assertFalse( $cart['cart'], 'a deck with widths to choose needs its page' );
	}
}
