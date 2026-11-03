<?php
/**
 * In stock first, as the store's choice (D-138).
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Search\OwnerRules;
use CreativeBranding\Search\Search\SearchService;
use CreativeBranding\Search\Search\Suggestions;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Tests\Support\IndexTestCase;
use WC_Product_Simple;
use WP_REST_Request;

/**
 * A better-matching sold-out product and weaker ones that can be bought, through the one SQL statement, on the
 * panel, the results page and `/search`.
 *
 * @group sql-mutation
 */
final class StockFirstTest extends IndexTestCase {

	/**
	 * Product ids by name.
	 *
	 * @var array<string, int>
	 */
	private array $ids = array();

	public function set_up(): void {
		parent::set_up();
		// "Hood" is named exactly as the query reads: its exact bonus outweighs the −6 for sold out.
		$this->ids['Hood']           = $this->simple( 'Hood', 'outofstock', 50 );
		$this->ids['Hoodie Classic'] = $this->simple( 'Hoodie Classic', 'instock', 10 );
		$this->ids['Hoodie Preorder'] = $this->simple( 'Hoodie Preorder', 'onbackorder', 5 );
		$this->ids['Hoody Sold']     = $this->simple( 'Hoody Sold', 'outofstock', 1 );
		// In stock, with "hood" only in its description: after every name match, sold out or not (D-142).
		$this->ids['Deck Broke'] = $this->simple( 'Deck Broke', 'instock', 90, 'Skate it with your hood up.' );
		$this->service( Reindex::class )->runInline();
	}

	public function tear_down(): void {
		Settings::update(
			array(
				'in_stock_first'  => 'no',
				'pinned_products' => array(),
			)
		);
		parent::tear_down();
	}

	/**
	 * A simple product.
	 *
	 * @param string $name   Name.
	 * @param string $status Stock status.
	 * @param int    $sales  Total sales.
	 * @param string $text   Description.
	 */
	private function simple( string $name, string $status, int $sales, string $text = '' ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_description( $text );
		$product->set_status( 'publish' );
		$product->set_regular_price( '60' );
		$product->set_stock_status( $status );
		$product->set_total_sales( $sales );
		return $product->save();
	}

	/**
	 * The products' names in an order of ids.
	 *
	 * @param int[] $ids Product ids.
	 * @return string[]
	 */
	private function names( array $ids ): array {
		$names = array_flip( $this->ids );
		return array_values( array_map( static fn( int $id ): string => $names[ $id ] ?? (string) $id, $ids ) );
	}

	/**
	 * The three surfaces' orders: the panel, the results page and `/search`.
	 *
	 * @return array{panel: string[], page: string[], api: string[]}
	 */
	private function orders(): array {
		do_action( 'csearch_flush_cache' );
		$panel   = array_column( $this->service( Suggestions::class )->answer( 'hood', 'en', 6 )['products'], 'id' );
		$page    = $this->service( SearchService::class )->rankedIds( 'hood', 'en', '' )['ids'];
		$request = new WP_REST_Request( 'GET', '/csearch/v1/search' );
		$request->set_param( 'q', 'hood' );
		$api = rest_do_request( $request )->get_data();
		return array(
			'panel' => $this->names( $panel ),
			'page'  => $this->names( $page ),
			'api'   => $this->names( is_array( $api ) ? $api['ids'] : array() ),
		);
	}

	public function test_off_the_score_decides_as_before(): void {
		$orders = $this->orders();
		self::assertSame( 'Hood', $orders['panel'][0], 'the sold-out exact name first' );
		self::assertSame( $orders['panel'], $orders['page'] );
		self::assertSame( $orders['panel'], $orders['api'] );
	}

	public function test_on_what_can_be_bought_comes_first_on_every_surface(): void {
		$off = $this->orders()['page'];
		Settings::update( array( 'in_stock_first' => 'yes' ) );
		$orders = $this->orders();
		$want   = array_merge(
			array_values( array_filter( $off, static fn( string $n ): bool => in_array( $n, array( 'Hoodie Classic', 'Hoodie Preorder' ), true ) ) ),
			array_values( array_filter( $off, static fn( string $n ): bool => in_array( $n, array( 'Hood', 'Hoody Sold' ), true ) ) ),
			array( 'Deck Broke' )
		);
		self::assertContains( 'Deck Broke', $off, 'the description match is found' );
		self::assertSame( $want, $orders['page'], 'name matches first, each part in score order; the in-stock description match after the sold-out name matches (D-142)' );
		self::assertContains( 'Hoodie Preorder', array_slice( $orders['page'], 0, 2 ), 'on backorder can be bought' );
		self::assertSame( $want, $orders['panel'] );
		self::assertSame( $want, $orders['api'] );
	}

	public function test_the_panel_marks_the_products_found_only_by_their_description(): void {
		$described = array();
		foreach ( $this->service( Suggestions::class )->answer( 'hood', 'en', 6 )['products'] as $row ) {
			$described[ $this->names( array( (int) $row['id'] ) )[0] ] = $row['described'];
		}
		ksort( $described );
		self::assertSame(
			array(
				'Deck Broke'      => true,
				'Hood'            => false,
				'Hoodie Classic'  => false,
				'Hoodie Preorder' => false,
				'Hoody Sold'      => false,
			),
			$described,
			'the panel puts the page asked for before the described ones (D-143)'
		);
	}

	public function test_a_pinned_sold_out_product_keeps_its_place(): void {
		Settings::update( array( 'in_stock_first' => 'yes' ) );
		$this->service( OwnerRules::class )->addPins( 'hood', '', array( $this->ids['Hoody Sold'] ) );
		$orders = $this->orders();
		self::assertSame( 'Hoody Sold', $orders['page'][0], 'pinned first (D-110)' );
		self::assertSame( 'Hoody Sold', $orders['panel'][0] );
		self::assertSame( array( 'Hood', 'Deck Broke' ), array_slice( $orders['page'], -2 ), 'the rest in stock first among the name matches, then the description match' );
	}
}
