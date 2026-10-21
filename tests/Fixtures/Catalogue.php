<?php
/**
 * The catalogue generator.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Fixtures;

use InvalidArgumentException;

/**
 * Builds catalogues of any size with realistic names (brands, series, widths, colours, SKUs, French names) so
 * the same tests run at 300 and at 10,000 products (spec: Testing and quality → Fixtures).
 *
 * Deterministic: product i depends only on the seed and i, through the generator's own xorshift PRNG, so the
 * first 300 products of a 10,000-product catalogue are the 300-product catalogue, on every PHP version. The
 * first products are hand-written Skatehouse-shaped "hero" products the golden queries anchor on
 * (docs/DECISIONS.md D-016).
 *
 * Output is plain arrays (see {@see Catalogue::product()} for the shape). Writing them into WooCommerce is a
 * separate loader, from milestone 3.
 */
final class Catalogue {

	public const DEFAULT_SEED = 20261021;

	/**
	 * Categories: slug => [English name, French name, parent slug|null, kind].
	 * Kind drives the attributes a product in the category carries.
	 *
	 * @var array<string, array{0: string, 1: string, 2: string|null, 3: string}>
	 */
	public const CATEGORIES = array(
		'skateboards' => array( 'Skateboards', 'Planches à roulettes', null, 'group' ),
		'decks'       => array( 'Decks', 'Planches', 'skateboards', 'deck' ),
		'trucks'      => array( 'Trucks', 'Trucks', 'skateboards', 'truck' ),
		'wheels'      => array( 'Wheels', 'Roues', 'skateboards', 'wheel' ),
		'bearings'    => array( 'Bearings', 'Roulements', 'skateboards', 'bearing' ),
		'griptape'    => array( 'Griptape', 'Grip', 'skateboards', 'grip' ),
		'hardware'    => array( 'Hardware', 'Visserie', 'skateboards', 'hardware' ),
		'completes'   => array( 'Completes', 'Planches complètes', 'skateboards', 'complete' ),
		'apparel'     => array( 'Apparel', 'Vêtements', null, 'group' ),
		't-shirts'    => array( 'T-Shirts', 'T-shirts', 'apparel', 'tee' ),
		'hoodies'     => array( 'Hoodies', 'Chandails à capuchon', 'apparel', 'hoodie' ),
		'hats'        => array( 'Hats', 'Casquettes', 'apparel', 'hat' ),
		'footwear'    => array( 'Footwear', 'Chaussures', null, 'group' ),
		'shoes'       => array( 'Shoes', 'Souliers', 'footwear', 'shoe' ),
		'accessories' => array( 'Accessories', 'Accessoires', null, 'group' ),
		'backpacks'   => array( 'Backpacks', 'Sacs à dos', 'accessories', 'bag' ),
		'tools'       => array( 'Tools', 'Outils', 'accessories', 'tool' ),
	);

	/**
	 * Brands: name => SKU code. Invented, like the shop's own; a few are the Stockroom's deck designs so the
	 * two plugins' fixtures describe the same shop.
	 *
	 * @var array<string, string>
	 */
	public const BRANDS = array(
		'KAZUMI'     => 'KAZ',
		"Philly's"   => 'PHL',
		'Rascals'    => 'RSC',
		'Northside'  => 'NTH',
		'Ferrum'     => 'FER',
		'Orbit'      => 'ORB',
		'Keel'       => 'KEL',
		'Dockyard'   => 'DCK',
		'Mainline'   => 'MNL',
		'Halftone'   => 'HLF',
		'Riveter'    => 'RVT',
		'Cinder'     => 'CND',
		'Québécois'  => 'QBC',
		'Skatehouse' => 'SKH',
	);

	/**
	 * Sellable (leaf) categories by kind, in the proportion a skate shop stocks them.
	 *
	 * @var string[]
	 */
	private const LEAF_WEIGHTS = array(
		'decks',
		'decks',
		'decks',
		'decks',
		'trucks',
		'wheels',
		'wheels',
		'bearings',
		'griptape',
		'hardware',
		'completes',
		't-shirts',
		't-shirts',
		'hoodies',
		'hats',
		'shoes',
		'shoes',
		'backpacks',
		'tools',
	);

	/**
	 * Series names: two word lists, combined deterministically.
	 *
	 * @var string[]
	 */
	private const SERIES_FIRST = array( 'Harbour', 'Static', 'Night', 'Paper', 'Iron', 'Velvet', 'Signal', 'Copper', 'Ghost', 'Summer', 'Winter', 'Granite', 'Neon', 'Quiet', 'Rapid', 'Hollow', 'Golden', 'Broken', 'Little', 'Grand', 'Electric', 'Frozen', 'Hidden', 'Lucky', 'Midnight', 'Northern', 'Pacific', 'Royal', 'Silver', 'Urban', 'Wild', 'Yellow', 'Atlantic', 'Burnt', 'Cosmic', 'Dusty', 'Early', 'Faded', 'Glass', 'Heavy' );

	/**
	 * Second half of a series name.
	 *
	 * @var string[]
	 */
	private const SERIES_SECOND = array( 'Lights', 'Bloom', 'Shift', 'Tiger', 'Lung', 'Party', 'Fire', 'Garden', 'Riot', 'Camp', 'Bridge', 'Canyon', 'Dream', 'Echo', 'Falcon', 'Harvest', 'Island', 'Jungle', 'Kingdom', 'Lagoon', 'Market', 'Nomad', 'Orchard', 'Pulse', 'Quarry', 'River', 'Storm', 'Temple', 'Union', 'Valley', 'Wave', 'Zenith', 'Anchor', 'Beacon', 'Circuit', 'Drift', 'Ember', 'Forge', 'Glacier', 'Horizon' );

	/**
	 * Colours: English => French.
	 *
	 * @var array<string, string>
	 */
	public const COLOURS = array(
		'Black'        => 'Noir',
		'White'        => 'Blanc',
		'Navy'         => 'Marine',
		'Forest Green' => 'Vert forêt',
		'Burgundy'     => 'Bordeaux',
		'Sand'         => 'Sable',
		'Grey'         => 'Gris',
		'Red'          => 'Rouge',
	);

	/**
	 * Deck widths in inches, as the shop writes them.
	 *
	 * @var string[]
	 */
	public const WIDTHS = array( '7.75', '8.0', '8.125', '8.25', '8.38', '8.5', '8.625', '8.75', '9.0' );

	/**
	 * Wheel diameters in millimetres.
	 *
	 * @var string[]
	 */
	public const DIAMETERS = array( '50mm', '52mm', '53mm', '54mm', '55mm', '56mm', '58mm', '60mm' );

	/**
	 * Wheel durometers.
	 *
	 * @var string[]
	 */
	public const DUROMETERS = array( '99a', '101a', '78a' );

	/**
	 * Truck sizes.
	 *
	 * @var string[]
	 */
	public const TRUCK_SIZES = array( '139', '144', '149', '159', '169' );

	/**
	 * Bearing ratings.
	 *
	 * @var string[]
	 */
	public const BEARING_RATINGS = array( 'ABEC 5', 'ABEC 7', 'ABEC 9', 'Swiss' );

	/**
	 * Apparel sizes.
	 *
	 * @var string[]
	 */
	public const APPAREL_SIZES = array( 'XS', 'S', 'M', 'L', 'XL', 'XXL' );

	/**
	 * Shoe sizes (US).
	 *
	 * @var string[]
	 */
	public const SHOE_SIZES = array( '7', '7.5', '8', '8.5', '9', '9.5', '10', '10.5', '11', '12', '13' );

	/**
	 * PRNG state.
	 *
	 * @var int
	 */
	private int $state;

	/**
	 * Constructor.
	 *
	 * @param int $seed Seed; the same seed gives the same catalogue.
	 */
	public function __construct( private int $seed = self::DEFAULT_SEED ) {
		$this->state = 1;
	}

	/**
	 * Generate a catalogue of $count products.
	 *
	 * @param int $count Number of products, at least 1.
	 * @return array<int, array<string, mixed>> Products, index 0 first.
	 * @throws InvalidArgumentException When $count is below 1.
	 */
	public function generate( int $count ): array {
		if ( $count < 1 ) {
			throw new InvalidArgumentException( 'A catalogue has at least one product.' );
		}
		$products = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$products[] = $this->product( $i );
		}
		return $products;
	}

	/**
	 * The i-th product, the same for every catalogue size.
	 *
	 * Shape:
	 *   id (int, 1-based), sku, type (simple|variable), status (publish), visible (bool), name, name_fr,
	 *   short, short_fr, long, long_fr, brand, categories (slug path, parent first), category_names,
	 *   category_names_fr, tags (string[]), attributes (array<string, string[]>), price (float), in_stock
	 *   (bool), total_sales (int), variations (array of sku, attributes, price, stock, in_stock).
	 *
	 * @param int $i Zero-based index.
	 * @return array<string, mixed>
	 */
	public function product( int $i ): array {
		$heroes = self::heroes();
		if ( isset( $heroes[ $i ] ) ) {
			return $this->complete( $heroes[ $i ], $i );
		}
		$this->reseed( $i );
		$category = self::LEAF_WEIGHTS[ $this->below( count( self::LEAF_WEIGHTS ) ) ];
		$brands   = array_keys( self::BRANDS );
		$brand    = $brands[ $this->below( count( $brands ) ) ];
		$first    = $this->below( count( self::SERIES_FIRST ) );
		$second   = $this->below( count( self::SERIES_SECOND ) );
		$series   = self::SERIES_FIRST[ $first ] . ' ' . self::SERIES_SECOND[ $second ];
		while ( self::isHero( $brand, $series, $category ) ) {
			$second = ( $second + 1 ) % count( self::SERIES_SECOND );
			$series = self::SERIES_FIRST[ $first ] . ' ' . self::SERIES_SECOND[ $second ];
		}
		$spec     = array(
			'brand'    => $brand,
			'series'   => $series,
			'category' => $category,
			// A quarter of generated products are sold out; three in a hundred are hidden from the catalogue.
			'in_stock' => $this->below( 4 ) > 0,
			'visible'  => $this->below( 100 ) >= 3,
			'sales'    => $this->below( 400 ),
			'price'    => null,
			'tags'     => $this->tags(),
		);
		return $this->complete( $spec, $i );
	}

	/**
	 * Whether a brand, series and category combination is one of the heroes.
	 *
	 * @param string $brand    Brand.
	 * @param string $series   Series.
	 * @param string $category Category slug.
	 */
	public static function isHero( string $brand, string $series, string $category ): bool {
		foreach ( self::heroes() as $hero ) {
			if ( $hero['brand'] === $brand && $hero['series'] === $series && $hero['category'] === $category ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The hand-written products the golden queries anchor on, in order. Each is completed like a generated one.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function heroes(): array {
		return array(
			array(
				'brand'    => 'KAZUMI',
				'series'   => 'Dealer',
				'category' => 'decks',
				'widths'   => array( '8.0', '8.25', '8.5' ),
				'price'    => 84.99,
				'in_stock' => true,
				'visible'  => true,
				'sales'    => 340,
				'tags'     => array( 'popsicle', 'maple' ),
			),
			array(
				'brand'    => "Philly's",
				'series'   => 'Rascals',
				'category' => 'decks',
				'widths'   => array( '8.25', '8.5' ),
				'price'    => 79.99,
				'in_stock' => true,
				'visible'  => true,
				'sales'    => 210,
				'tags'     => array( 'popsicle', 'maple' ),
			),
			array(
				'brand'    => "Philly's",
				'series'   => 'Horror Party',
				'category' => 'decks',
				'widths'   => array( '8.0', '8.25', '8.5', '8.75' ),
				'price'    => 79.99,
				'in_stock' => true,
				'visible'  => true,
				'sales'    => 180,
				'tags'     => array( 'popsicle', 'maple' ),
			),
			array(
				'brand'    => "Philly's",
				'series'   => 'Night Shift',
				'category' => 'decks',
				'widths'   => array( '8.25', '8.5' ),
				'price'    => 79.99,
				'in_stock' => false,
				'visible'  => true,
				'sales'    => 95,
				'tags'     => array( 'popsicle', 'maple' ),
			),
			array(
				'brand'    => 'Rascals',
				'series'   => 'Static Bloom',
				'category' => 'decks',
				'widths'   => array( '8.125', '8.38' ),
				'price'    => 74.99,
				'in_stock' => true,
				'visible'  => true,
				'sales'    => 60,
				'tags'     => array( 'popsicle' ),
			),
			array(
				'brand'    => 'Ferrum',
				'series'   => 'Hollow Forge',
				'category' => 'trucks',
				'sizes'    => array( '139', '144', '149' ),
				'price'    => 64.99,
				'in_stock' => true,
				'visible'  => true,
				'sales'    => 150,
				'tags'     => array( 'hollow' ),
			),
			array(
				'brand'    => 'Orbit',
				'series'   => 'Classic',
				'category' => 'wheels',
				'diameter' => array( '52mm', '54mm', '56mm' ),
				'duro'     => '99a',
				'price'    => 44.99,
				'in_stock' => true,
				'visible'  => true,
				'sales'    => 260,
				'tags'     => array( 'street' ),
			),
			array(
				'brand'    => 'Orbit',
				'series'   => 'Cruiser',
				'category' => 'wheels',
				'diameter' => array( '58mm', '60mm' ),
				'duro'     => '78a',
				'price'    => 49.99,
				'in_stock' => true,
				'visible'  => true,
				'sales'    => 40,
				'tags'     => array( 'cruiser', 'soft' ),
			),
			array(
				'brand'    => 'Keel',
				'series'   => 'Swiss',
				'category' => 'bearings',
				'rating'   => 'Swiss',
				'price'    => 59.99,
				'in_stock' => true,
				'visible'  => true,
				'sales'    => 120,
				'tags'     => array(),
			),
			array(
				'brand'    => 'Keel',
				'series'   => 'Seven',
				'category' => 'bearings',
				'rating'   => 'ABEC 7',
				'price'    => 24.99,
				'in_stock' => true,
				'visible'  => true,
				'sales'    => 300,
				'tags'     => array(),
			),
			array(
				'brand'    => 'Skatehouse',
				'series'   => 'Shop Logo',
				'category' => 't-shirts',
				'colours'  => array( 'Black', 'White' ),
				'price'    => 34.99,
				'in_stock' => true,
				'visible'  => true,
				'sales'    => 400,
				'tags'     => array( 'logo' ),
			),
			array(
				'brand'    => 'Skatehouse',
				'series'   => 'Shop Logo',
				'category' => 'hoodies',
				'colours'  => array( 'Black', 'Forest Green' ),
				'price'    => 74.99,
				'in_stock' => true,
				'visible'  => true,
				'sales'    => 220,
				'tags'     => array( 'logo' ),
			),
			array(
				'brand'    => 'Québécois',
				'series'   => 'Fleur',
				'category' => 't-shirts',
				'colours'  => array( 'Navy', 'Sand' ),
				'price'    => 32.99,
				'in_stock' => true,
				'visible'  => true,
				'sales'    => 85,
				'tags'     => array( 'montréal' ),
			),
			array(
				'brand'    => 'Dockyard',
				'series'   => 'Harbour Lights',
				'category' => 'shoes',
				'colours'  => array( 'Black', 'Grey' ),
				'price'    => 109.99,
				'in_stock' => true,
				'visible'  => true,
				'sales'    => 130,
				'tags'     => array( 'suede' ),
			),
			array(
				'brand'    => 'Halftone',
				'series'   => 'Signal',
				'category' => 'hats',
				'colours'  => array( 'Black', 'Burgundy' ),
				'price'    => 29.99,
				'in_stock' => true,
				'visible'  => true,
				'sales'    => 70,
				'tags'     => array( 'snapback' ),
			),
			array(
				'brand'    => 'Mainline',
				'series'   => 'Perforated',
				'category' => 'griptape',
				'price'    => 12.99,
				'in_stock' => true,
				'visible'  => true,
				'sales'    => 500,
				'tags'     => array(),
			),
			array(
				'brand'    => 'Riveter',
				'series'   => 'Allen 1 inch',
				'category' => 'hardware',
				'price'    => 6.99,
				'in_stock' => true,
				'visible'  => true,
				'sales'    => 450,
				'tags'     => array( 'allen' ),
			),
			array(
				'brand'    => 'Cinder',
				'series'   => 'Multi Tool',
				'category' => 'tools',
				'price'    => 19.99,
				'in_stock' => false,
				'visible'  => true,
				'sales'    => 90,
				'tags'     => array(),
			),
			array(
				'brand'    => 'Northside',
				'series'   => 'Daypack',
				'category' => 'backpacks',
				'colours'  => array( 'Black', 'Navy' ),
				'price'    => 89.99,
				'in_stock' => true,
				'visible'  => true,
				'sales'    => 55,
				'tags'     => array( 'straps' ),
			),
			array(
				'brand'    => 'Skatehouse',
				'series'   => 'Starter Complete',
				'category' => 'completes',
				'widths'   => array( '7.75', '8.0', '8.25' ),
				'price'    => 149.99,
				'in_stock' => true,
				'visible'  => true,
				'sales'    => 160,
				'tags'     => array( 'beginner' ),
			),
			// A dealer-only line: hidden from the catalogue, found by SKU only when the setting allows.
			array(
				'brand'    => 'KAZUMI',
				'series'   => 'Dealer Blank',
				'category' => 'decks',
				'widths'   => array( '8.25' ),
				'price'    => 49.99,
				'in_stock' => true,
				'visible'  => false,
				'sales'    => 0,
				'tags'     => array( 'blank' ),
			),
			// Two near-duplicates for ranking: same brand, different series, one sold out.
			array(
				'brand'    => 'KAZUMI',
				'series'   => 'Ghost Tiger',
				'category' => 'decks',
				'widths'   => array( '8.25', '8.5' ),
				'price'    => 84.99,
				'in_stock' => false,
				'visible'  => true,
				'sales'    => 30,
				'tags'     => array( 'popsicle' ),
			),
			array(
				'brand'    => 'KAZUMI',
				'series'   => 'Shop Logo',
				'category' => 't-shirts',
				'colours'  => array( 'White' ),
				'price'    => 36.99,
				'in_stock' => true,
				'visible'  => true,
				'sales'    => 25,
				'tags'     => array( 'logo' ),
			),
			array(
				'brand'    => 'Orbit',
				'series'   => 'Classic',
				'category' => 'wheels',
				'diameter' => array( '53mm' ),
				'duro'     => '101a',
				'price'    => 44.99,
				'in_stock' => true,
				'visible'  => true,
				'sales'    => 15,
				'tags'     => array( 'street', 'hard' ),
			),
		);
	}

	/**
	 * Turn a short spec (brand, series, category, options) into a full product array.
	 *
	 * @param array<string, mixed> $spec Spec.
	 * @param int                  $i    Zero-based index.
	 * @return array<string, mixed>
	 */
	private function complete( array $spec, int $i ): array {
		// Heroes are the same whatever the seed; generated products are reseeded per product so i never depends on i - 1.
		$this->reseed( $i + 1_000_000, isset( self::heroes()[ $i ] ) ? self::DEFAULT_SEED : $this->seed );
		$slug     = (string) $spec['category'];
		$category = self::CATEGORIES[ $slug ];
		$kind     = $category[3];
		$brand    = (string) $spec['brand'];
		$series   = (string) $spec['series'];
		$code     = self::BRANDS[ $brand ] . '-' . self::seriesCode( $series ) . '-' . str_pad( (string) ( $i + 1 ), 5, '0', STR_PAD_LEFT );

		$nouns = self::nouns( $kind );
		$name  = sprintf( '%s %s, %s', $brand, $nouns[0], $series );
		$nameF = sprintf( '%s %s, %s', $nouns[1], $brand, $series );

		$price = isset( $spec['price'] ) && is_float( $spec['price'] ) ? $spec['price'] : self::price( $kind, $this->below( 1000 ) );

		$attributes = $this->attributes( $kind, $spec );
		$variations = $this->variations( $code, $attributes, $price, (bool) $spec['in_stock'] );
		$type       = array() === $variations ? 'simple' : 'variable';

		$path       = array();
		$names      = array();
		$namesF     = array();
		$walk       = $slug;
		while ( null !== $walk ) {
			array_unshift( $path, $walk );
			array_unshift( $names, self::CATEGORIES[ $walk ][0] );
			array_unshift( $namesF, self::CATEGORIES[ $walk ][1] );
			$walk = self::CATEGORIES[ $walk ][2];
		}

		$inStock = (bool) $spec['in_stock'];
		if ( array() !== $variations ) {
			$inStock = false;
			foreach ( $variations as $variation ) {
				$inStock = $inStock || $variation['in_stock'];
			}
		}

		return array(
			'id'                => $i + 1,
			'sku'               => $code,
			'type'              => $type,
			'status'            => 'publish',
			'visible'           => (bool) $spec['visible'],
			'name'              => $name,
			'name_fr'           => $nameF,
			'short'             => self::short( $kind, $brand, $series, $attributes, 'en' ),
			'short_fr'          => self::short( $kind, $brand, $series, $attributes, 'fr' ),
			'long'              => self::long( $kind, $brand, $series, 'en' ),
			'long_fr'           => self::long( $kind, $brand, $series, 'fr' ),
			'brand'             => $brand,
			'categories'        => $path,
			'category_names'    => $names,
			'category_names_fr' => $namesF,
			'tags'              => array_values( (array) $spec['tags'] ),
			'attributes'        => $attributes,
			'price'             => $price,
			'in_stock'          => $inStock,
			'total_sales'       => (int) $spec['sales'],
			'variations'        => $variations,
		);
	}

	/**
	 * Attributes for a kind, from the spec's options or generated.
	 *
	 * @param string               $kind Category kind.
	 * @param array<string, mixed> $spec Spec.
	 * @return array<string, string[]>
	 */
	private function attributes( string $kind, array $spec ): array {
		switch ( $kind ) {
			case 'deck':
			case 'complete':
				return array( 'pa_width' => isset( $spec['widths'] ) ? (array) $spec['widths'] : $this->pick( self::WIDTHS, 1 + $this->below( 4 ) ) );
			case 'truck':
				return array( 'pa_size' => isset( $spec['sizes'] ) ? (array) $spec['sizes'] : $this->pick( self::TRUCK_SIZES, 1 + $this->below( 3 ) ) );
			case 'wheel':
				return array(
					'pa_diameter'  => isset( $spec['diameter'] ) ? (array) $spec['diameter'] : $this->pick( self::DIAMETERS, 1 + $this->below( 3 ) ),
					'pa_durometer' => array( isset( $spec['duro'] ) ? (string) $spec['duro'] : self::DUROMETERS[ $this->below( count( self::DUROMETERS ) ) ] ),
				);
			case 'bearing':
				return array( 'pa_rating' => array( isset( $spec['rating'] ) ? (string) $spec['rating'] : self::BEARING_RATINGS[ $this->below( count( self::BEARING_RATINGS ) ) ] ) );
			case 'tee':
			case 'hoodie':
				return array(
					'pa_colour' => isset( $spec['colours'] ) ? (array) $spec['colours'] : $this->pick( array_keys( self::COLOURS ), 1 + $this->below( 3 ) ),
					'pa_size'   => self::APPAREL_SIZES,
				);
			case 'hat':
			case 'bag':
				return array( 'pa_colour' => isset( $spec['colours'] ) ? (array) $spec['colours'] : $this->pick( array_keys( self::COLOURS ), 1 + $this->below( 2 ) ) );
			case 'shoe':
				return array(
					'pa_colour' => isset( $spec['colours'] ) ? (array) $spec['colours'] : $this->pick( array_keys( self::COLOURS ), 1 + $this->below( 2 ) ),
					'pa_size'   => self::SHOE_SIZES,
				);
			default:
				return array();
		}
	}

	/**
	 * Variations: one per combination of attribute values, with their SKUs; none for a simple product.
	 *
	 * @param string                  $code       Parent SKU.
	 * @param array<string, string[]> $attributes Attributes.
	 * @param float                   $price      Parent price.
	 * @param bool                    $inStock    Whether the product is sold at all.
	 * @return array<int, array{sku: string, attributes: array<string, string>, price: float, stock: int, in_stock: bool}>
	 */
	private function variations( string $code, array $attributes, float $price, bool $inStock ): array {
		if ( array() === $attributes ) {
			return array();
		}
		$combos = array( array() );
		foreach ( $attributes as $attribute => $values ) {
			$next = array();
			foreach ( $combos as $combo ) {
				foreach ( $values as $value ) {
					$next[] = $combo + array( $attribute => $value );
				}
			}
			$combos = $next;
		}
		$out = array();
		foreach ( $combos as $combo ) {
			$stock = $inStock ? $this->below( 12 ) : 0;
			$out[] = array(
				'sku'        => $code . '-' . self::valueCode( implode( '-', $combo ) ),
				'attributes' => $combo,
				'price'      => $price,
				'stock'      => $stock,
				'in_stock'   => $stock > 0,
			);
		}
		// A variable product that is "in stock" has at least one purchasable variation.
		if ( $inStock && array() !== $out ) {
			$any = false;
			foreach ( $out as $variation ) {
				$any = $any || $variation['in_stock'];
			}
			if ( ! $any ) {
				$out[0]['stock']    = 1;
				$out[0]['in_stock'] = true;
			}
		}
		return $out;
	}

	/**
	 * Tags for a generated product: none to two from a small list.
	 *
	 * @return string[]
	 */
	private function tags(): array {
		$pool = array( 'popsicle', 'maple', 'street', 'cruiser', 'soft', 'hard', 'logo', 'sale', 'new', 'classic', 'limited', 'canadian' );
		return $this->pick( $pool, $this->below( 3 ) );
	}

	/**
	 * The English and French product nouns for a kind.
	 *
	 * @param string $kind Category kind.
	 * @return array{0: string, 1: string}
	 */
	private static function nouns( string $kind ): array {
		$map = array(
			'deck'     => array( 'Skate Deck', 'Planche' ),
			'truck'    => array( 'Trucks', 'Trucks' ),
			'wheel'    => array( 'Wheels', 'Roues' ),
			'bearing'  => array( 'Bearings', 'Roulements' ),
			'grip'     => array( 'Griptape', 'Grip' ),
			'hardware' => array( 'Hardware', 'Visserie' ),
			'complete' => array( 'Complete', 'Planche complète' ),
			'tee'      => array( 'T-Shirt', 'T-shirt' ),
			'hoodie'   => array( 'Hoodie', 'Chandail à capuchon' ),
			'hat'      => array( 'Hat', 'Casquette' ),
			'shoe'     => array( 'Shoes', 'Souliers' ),
			'bag'      => array( 'Backpack', 'Sac à dos' ),
			'tool'     => array( 'Skate Tool', 'Outil' ),
		);
		return $map[ $kind ] ?? array( 'Product', 'Produit' );
	}

	/**
	 * A short description in one language, naming the attributes so they are searchable text too.
	 *
	 * @param string                  $kind       Kind.
	 * @param string                  $brand      Brand.
	 * @param string                  $series     Series.
	 * @param array<string, string[]> $attributes Attributes.
	 * @param string                  $lang       en or fr.
	 */
	private static function short( string $kind, string $brand, string $series, array $attributes, string $lang ): string {
		$values = array();
		foreach ( $attributes as $attribute => $list ) {
			if ( 'pa_size' === $attribute && count( $list ) > 4 ) {
				continue;
			}
			foreach ( $list as $value ) {
				$values[] = 'fr' === $lang && isset( self::COLOURS[ $value ] ) ? self::COLOURS[ $value ] : $value;
			}
		}
		$list = implode( ', ', $values );
		if ( 'fr' === $lang ) {
			return sprintf( '%s %s de %s. %s', self::nouns( $kind )[1], $series, $brand, '' !== $list ? 'Offert en ' . $list . '.' : 'Édition québécoise.' );
		}
		return sprintf( 'The %s %s by %s. %s', $series, self::nouns( $kind )[0], $brand, '' !== $list ? 'Available in ' . $list . '.' : 'Shop exclusive.' );
	}

	/**
	 * A long description in one language, with the accented words the tokeniser tests fold.
	 *
	 * @param string $kind   Kind.
	 * @param string $brand  Brand.
	 * @param string $series Series.
	 * @param string $lang   en or fr.
	 */
	private static function long( string $kind, string $brand, string $series, string $lang ): string {
		$material = array(
			'deck'     => array( 'seven plies of Canadian maple, pressed in Québec', 'sept plis d’érable canadien, pressés au Québec' ),
			'complete' => array( 'a maple deck, trucks, wheels and bearings, assembled in the shop', 'une planche d’érable, des trucks, des roues et des roulements, assemblés à l’atelier' ),
			'truck'    => array( 'forged baseplates and hollow kingpins', 'des embases forgées et des axes creux' ),
			'wheel'    => array( 'a high-rebound urethane that holds its shape', 'un uréthane à haut rebond qui garde sa forme' ),
			'bearing'  => array( 'precision races and removable shields', 'des bagues de précision et des flasques amovibles' ),
			'tee'      => array( 'heavyweight cotton, printed in Montréal', 'un coton épais, imprimé à Montréal' ),
			'hoodie'   => array( 'brushed fleece with a double-lined hood', 'une molleton brossé avec un capuchon doublé' ),
			'shoe'     => array( 'suede uppers and a vulcanised sole', 'une tige en suède et une semelle vulcanisée' ),
		);
		$m = $material[ $kind ] ?? array( 'materials chosen to last', 'des matériaux choisis pour durer' );
		if ( 'fr' === $lang ) {
			return sprintf( 'La série %s de %s : %s. Livraison partout au Québec et au Canada.', $series, $brand, $m[1] );
		}
		return sprintf( 'The %s series from %s: %s. Ships across Québec and Canada.', $series, $brand, $m[0] );
	}

	/**
	 * A price for a kind, in a realistic band.
	 *
	 * @param string $kind Kind.
	 * @param int    $r    A number 0 to 999.
	 */
	private static function price( string $kind, int $r ): float {
		$band = array(
			'deck'     => array( 59.99, 99.99 ),
			'complete' => array( 129.99, 219.99 ),
			'truck'    => array( 49.99, 89.99 ),
			'wheel'    => array( 34.99, 59.99 ),
			'bearing'  => array( 19.99, 79.99 ),
			'grip'     => array( 9.99, 19.99 ),
			'hardware' => array( 4.99, 12.99 ),
			'tee'      => array( 29.99, 44.99 ),
			'hoodie'   => array( 64.99, 99.99 ),
			'hat'      => array( 24.99, 39.99 ),
			'shoe'     => array( 79.99, 139.99 ),
			'bag'      => array( 59.99, 119.99 ),
			'tool'     => array( 14.99, 29.99 ),
		);
		$b = $band[ $kind ] ?? array( 9.99, 99.99 );
		// Whole dollars plus .99, so prices look like a shop's.
		$dollars = (int) floor( $b[0] ) + (int) floor( ( $b[1] - $b[0] ) * $r / 1000 );
		return $dollars + 0.99;
	}

	/**
	 * A three-letter code from a series name (first letters of its words, padded from the first word).
	 *
	 * @param string $series Series name.
	 */
	public static function seriesCode( string $series ): string {
		$ascii = self::ascii( $series );
		$words = preg_split( '/[^A-Za-z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY );
		$words = false === $words ? array() : $words;
		$code  = '';
		foreach ( $words as $word ) {
			$code .= strtoupper( $word[0] );
		}
		$first = $words[0] ?? 'XXX';
		while ( strlen( $code ) < 3 ) {
			$code .= strtoupper( substr( $first . 'XX', strlen( $code ), 1 ) );
		}
		return substr( $code, 0, 3 );
	}

	/**
	 * A variation SKU suffix from attribute values: `8.25` → `825`, `Forest Green-M` → `FORESTGREEN-M`.
	 *
	 * @param string $value Joined values.
	 */
	public static function valueCode( string $value ): string {
		$ascii = strtoupper( self::ascii( $value ) );
		$ascii = (string) preg_replace( '/[^A-Z0-9-]+/', '', $ascii );
		return $ascii;
	}

	/**
	 * Fold the accents the fixture uses (the Domain tokeniser has the full table; this is only for SKUs).
	 *
	 * @param string $text Text.
	 */
	private static function ascii( string $text ): string {
		return strtr( $text, array( 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'ç' => 'c', 'ô' => 'o', 'î' => 'i', 'ï' => 'i', 'û' => 'u', 'ù' => 'u', 'É' => 'E', 'È' => 'E', 'À' => 'A', 'Ç' => 'C', '’' => '', "'" => '' ) );
	}

	/**
	 * Re-seed the PRNG for one product so product i never depends on product i-1.
	 *
	 * @param int      $i    Product index.
	 * @param int|null $seed Seed to use; the generator's own when null.
	 */
	private function reseed( int $i, ?int $seed = null ): void {
		// SplitMix-style scramble of seed and index into a non-zero 32-bit state.
		$x = ( ( $seed ?? $this->seed ) * 0x9E3779B1 + ( $i + 1 ) * 0x85EBCA77 ) & 0xFFFFFFFF;
		$x ^= $x >> 15;
		$x = ( $x * 0x2C1B3C6D ) & 0xFFFFFFFF;
		$x ^= $x >> 12;
		$this->state = 0 === $x ? 0x6D2B79F5 : $x;
	}

	/**
	 * Next PRNG value, xorshift32.
	 */
	private function next(): int {
		$x  = $this->state;
		$x ^= ( $x << 13 ) & 0xFFFFFFFF;
		$x ^= $x >> 17;
		$x ^= ( $x << 5 ) & 0xFFFFFFFF;
		$this->state = $x & 0xFFFFFFFF;
		return $this->state;
	}

	/**
	 * A number from 0 to $n - 1.
	 *
	 * @param int $n Exclusive upper bound, at least 1.
	 */
	private function below( int $n ): int {
		return $this->next() % max( 1, $n );
	}

	/**
	 * Pick $count distinct values from a list, keeping the list's order.
	 *
	 * @param string[] $list  Values.
	 * @param int      $count How many.
	 * @return string[]
	 */
	private function pick( array $list, int $count ): array {
		$count = min( $count, count( $list ) );
		$keys  = array_keys( $list );
		$taken = array();
		while ( count( $taken ) < $count ) {
			$k = $keys[ $this->below( count( $keys ) ) ];
			if ( ! in_array( $k, $taken, true ) ) {
				$taken[] = $k;
			}
		}
		sort( $taken );
		$out = array();
		foreach ( $taken as $k ) {
			$out[] = $list[ $k ];
		}
		return $out;
	}

	/**
	 * The hero products' SKUs by hero index, a convenience for the golden queries.
	 *
	 * @return array<int, string>
	 */
	public function heroSkus(): array {
		$out = array();
		foreach ( array_keys( self::heroes() ) as $i ) {
			$out[ $i ] = (string) $this->product( $i )['sku'];
		}
		return $out;
	}

	/**
	 * Count variations across a catalogue.
	 *
	 * @param array<int, array<string, mixed>> $products Products.
	 */
	public static function variationCount( array $products ): int {
		$n = 0;
		foreach ( $products as $product ) {
			$n += count( (array) $product['variations'] );
		}
		return $n;
	}
}
