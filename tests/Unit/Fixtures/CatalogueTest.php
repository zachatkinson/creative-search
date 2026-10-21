<?php
/**
 * Catalogue generator tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Fixtures;

use CreativeBranding\Search\Tests\Fixtures\Catalogue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic, realistic, the same first products at every size, and under a minute at 10,000.
 */
final class CatalogueTest extends TestCase {

	/**
	 * The 300-product catalogue, built once.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static ?array $small = null;

	/**
	 * The catalogue at Skatehouse's size.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function small(): array {
		if ( null === self::$small ) {
			self::$small = ( new Catalogue() )->generate( 300 );
		}
		return self::$small;
	}

	public function test_generates_the_requested_count(): void {
		self::assertCount( 300, self::small() );
		self::assertCount( 1, ( new Catalogue() )->generate( 1 ) );
	}

	public function test_zero_products_is_an_error(): void {
		$this->expectException( InvalidArgumentException::class );
		( new Catalogue() )->generate( 0 );
	}

	public function test_the_same_seed_gives_the_same_catalogue(): void {
		self::assertSame( self::small(), ( new Catalogue( Catalogue::DEFAULT_SEED ) )->generate( 300 ) );
	}

	public function test_another_seed_gives_another_catalogue_with_the_same_heroes(): void {
		$other = ( new Catalogue( 7 ) )->generate( 300 );
		self::assertNotSame( self::small(), $other );
		$heroes = count( Catalogue::heroes() );
		self::assertSame( array_slice( self::small(), 0, $heroes ), array_slice( $other, 0, $heroes ), 'the heroes do not depend on the seed' );
		self::assertNotSame( self::small()[ $heroes + 5 ]['name'], $other[ $heroes + 5 ]['name'] );
	}

	public function test_product_i_is_the_same_at_every_size(): void {
		$c = new Catalogue();
		self::assertSame( self::small(), array_slice( $c->generate( 1000 ), 0, 300 ) );
		self::assertSame( self::small()[299], $c->product( 299 ) );
	}

	public function test_heroes_sit_first_with_fixed_skus(): void {
		$p = self::small();
		self::assertSame( 'KAZ-DEA-00001', $p[0]['sku'] );
		self::assertSame( 'KAZUMI Skate Deck, Dealer', $p[0]['name'] );
		self::assertSame( 'Planche KAZUMI, Dealer', $p[0]['name_fr'] );
		self::assertSame( 84.99, $p[0]['price'] );
		self::assertSame( array( 'skateboards', 'decks' ), $p[0]['categories'] );
		self::assertSame( array( 'Skateboards', 'Decks' ), $p[0]['category_names'] );
		self::assertSame( array( 'Planches à roulettes', 'Planches' ), $p[0]['category_names_fr'] );
		self::assertSame( array( 'pa_width' => array( '8.0', '8.25', '8.5' ) ), $p[0]['attributes'] );
		self::assertSame( 'KAZ-DEA-00001-825', $p[0]['variations'][1]['sku'] );
		self::assertSame( array( 'pa_width' => '8.25' ), $p[0]['variations'][1]['attributes'] );
		self::assertSame( 'PHL-RAS-00002', $p[1]['sku'] );
		self::assertSame( 'PHL-NSG-00004', $p[3]['sku'] );
		self::assertFalse( $p[3]['in_stock'], 'Night Shift is sold out' );
		self::assertSame( 'KAZ-DBA-00021', $p[20]['sku'] );
		self::assertFalse( $p[20]['visible'], 'the dealer-only blank is hidden' );
		self::assertSame( 'simple', $p[15]['type'], 'griptape has no attributes' );
		self::assertSame( array(), $p[15]['variations'] );
		self::assertCount( 24, Catalogue::heroes() );
	}

	public function test_skus_are_unique_including_variations(): void {
		$skus = array();
		foreach ( self::small() as $product ) {
			$skus[] = $product['sku'];
			foreach ( $product['variations'] as $variation ) {
				$skus[] = $variation['sku'];
			}
		}
		self::assertSame( count( $skus ), count( array_unique( $skus ) ) );
		foreach ( $skus as $sku ) {
			self::assertMatchesRegularExpression( '/^[A-Z]{3}-[A-Z0-9]{3}-\d{5}(-[A-Z0-9-]+)?$/', $sku );
		}
	}

	public function test_every_product_has_the_shape_the_indexer_reads(): void {
		$keys = array( 'id', 'sku', 'type', 'status', 'visible', 'name', 'name_fr', 'short', 'short_fr', 'long', 'long_fr', 'brand', 'categories', 'category_names', 'category_names_fr', 'tags', 'attributes', 'price', 'in_stock', 'total_sales', 'variations' );
		foreach ( self::small() as $i => $product ) {
			self::assertSame( $keys, array_keys( $product ), "product {$i}" );
			self::assertSame( $i + 1, $product['id'] );
			self::assertSame( 'publish', $product['status'] );
			self::assertContains( $product['type'], array( 'simple', 'variable' ) );
			self::assertArrayHasKey( $product['brand'], Catalogue::BRANDS );
			self::assertIsFloat( $product['price'] );
			self::assertSame( '.99', substr( number_format( (float) $product['price'], 2, '.', '' ), -3 ), 'prices look like a shop\'s' );
			self::assertIsInt( $product['total_sales'] );
			self::assertGreaterThanOrEqual( 0, $product['total_sales'] );
			$leaf = end( $product['categories'] );
			self::assertArrayHasKey( $leaf, Catalogue::CATEGORIES );
			self::assertNotSame( 'group', Catalogue::CATEGORIES[ $leaf ][3], 'a product sits in a leaf category' );
			self::assertSame( Catalogue::CATEGORIES[ $leaf ][2], $product['categories'][0] === $leaf ? null : $product['categories'][ count( $product['categories'] ) - 2 ], 'the path runs parent first' );
			self::assertStringContainsString( $product['brand'], $product['name'] );
			self::assertNotSame( $product['name'], $product['name_fr'] );
			self::assertNotEmpty( $product['long'] );
			self::assertNotEmpty( $product['long_fr'] );
			if ( 'variable' === $product['type'] ) {
				self::assertNotEmpty( $product['variations'] );
				foreach ( $product['variations'] as $variation ) {
					self::assertSame( array_keys( $product['attributes'] ), array_keys( $variation['attributes'] ) );
					foreach ( $variation['attributes'] as $attribute => $value ) {
						self::assertContains( $value, $product['attributes'][ $attribute ] );
					}
					self::assertSame( $variation['stock'] > 0, $variation['in_stock'] );
					self::assertStringStartsWith( $product['sku'] . '-', $variation['sku'] );
				}
				$any = false;
				foreach ( $product['variations'] as $variation ) {
					$any = $any || $variation['in_stock'];
				}
				self::assertSame( $any, $product['in_stock'], 'in_stock rolls up from the variations' );
			} else {
				self::assertSame( array(), $product['variations'] );
				self::assertSame( array(), $product['attributes'] );
			}
		}
	}

	public function test_the_catalogue_is_realistic(): void {
		$p        = self::small();
		$variable = 0;
		$soldOut  = 0;
		$hidden   = 0;
		$leaves   = array();
		$brands   = array();
		$accents  = 0;
		foreach ( $p as $product ) {
			$variable += 'variable' === $product['type'] ? 1 : 0;
			$soldOut  += $product['in_stock'] ? 0 : 1;
			$hidden   += $product['visible'] ? 0 : 1;
			$leaves[ end( $product['categories'] ) ] = true;
			$brands[ $product['brand'] ]              = true;
			$accents += 1 === preg_match( '/[éèàçô]/u', $product['long_fr'] . $product['name_fr'] ) ? 1 : 0;
		}
		self::assertGreaterThan( 200, $variable, 'most products have variations' );
		self::assertGreaterThan( 30, $soldOut, 'a quarter or so are sold out' );
		self::assertLessThan( 120, $soldOut );
		self::assertGreaterThanOrEqual( 1, $hidden, 'some products are hidden from the catalogue' );
		self::assertLessThan( 30, $hidden );
		self::assertCount( 13, $leaves, 'every leaf category is stocked' );
		self::assertCount( count( Catalogue::BRANDS ), $brands, 'every brand is stocked' );
		self::assertGreaterThan( 250, $accents, 'French text carries accents for the folding tests' );
		$variations = Catalogue::variationCount( $p );
		self::assertGreaterThan( 3 * 300, $variations, 'about four variations per product, as the spec\'s 40,000 for 10,000 says' );
		self::assertLessThan( 7 * 300, $variations );
	}

	public function test_no_generated_product_duplicates_a_hero(): void {
		$heroes = count( Catalogue::heroes() );
		foreach ( array_slice( self::small(), $heroes ) as $product ) {
			$series = substr( (string) $product['name'], strrpos( (string) $product['name'], ', ' ) + 2 );
			self::assertFalse( Catalogue::isHero( $product['brand'], $series, end( $product['categories'] ) ), $product['name'] );
		}
		self::assertTrue( Catalogue::isHero( 'KAZUMI', 'Dealer', 'decks' ) );
		self::assertFalse( Catalogue::isHero( 'KAZUMI', 'Dealer', 'wheels' ) );
	}

	public function test_series_and_value_codes(): void {
		self::assertSame( 'DEA', Catalogue::seriesCode( 'Dealer' ) );
		self::assertSame( 'HPR', Catalogue::seriesCode( 'Horror Party' ) );
		self::assertSame( 'A1I', Catalogue::seriesCode( 'Allen 1 inch' ) );
		self::assertSame( 'FLE', Catalogue::seriesCode( 'Fleur' ) );
		self::assertSame( 'XXX', Catalogue::seriesCode( '' ) );
		self::assertSame( '825', Catalogue::valueCode( '8.25' ) );
		self::assertSame( 'FORESTGREEN-M', Catalogue::valueCode( 'Forest Green-M' ) );
		self::assertSame( 'VERTFORET', Catalogue::valueCode( 'Vert forêt' ) );
	}

	public function test_hero_skus_helper(): void {
		$skus = ( new Catalogue() )->heroSkus();
		self::assertSame( 'KAZ-DEA-00001', $skus[0] );
		self::assertSame( 'ORB-CLA-00024', $skus[23] );
		self::assertCount( 24, $skus );
	}

	/**
	 * The spec's milestone 1 budget: a 10,000-product fixture generates in under a minute.
	 */
	#[Group( 'benchmark' )]
	public function test_ten_thousand_products_generate_in_under_a_minute(): void {
		$start    = microtime( true );
		$products = ( new Catalogue() )->generate( 10000 );
		$elapsed  = microtime( true ) - $start;
		self::assertCount( 10000, $products );
		self::assertLessThan( 60.0, $elapsed, sprintf( '10,000 products took %.1f s', $elapsed ) );
		$variations = Catalogue::variationCount( $products );
		self::assertGreaterThan( 30000, $variations );
		self::assertLessThan( 70000, $variations );
		$skus = array_column( $products, 'sku' );
		self::assertSame( 10000, count( array_unique( $skus ) ) );
	}
}
