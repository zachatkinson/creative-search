<?php
/**
 * The handoff's golden queries on a copy of Skatehouse.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Hooks\ResultsHooks;
use CreativeBranding\Search\Index\AttributeTerms;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Results\ProductSearch;
use CreativeBranding\Search\Results\QueryRouter;
use CreativeBranding\Search\Results\ResultsView;
use CreativeBranding\Search\Search\SearchLog;
use CreativeBranding\Search\Search\SearchService;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Tests\Support\IndexTestCase;
use CreativeBranding\Search\Tests\Support\SkatehouseCopy;

/**
 * D-103, handoff item 11: the golden queries at the end of docs/research/SKATEHOUSE-HANDOFF-2026-10.md, run on
 * the export Zach puts in tests/fixtures/skatehouse/ (products CSV, pages WXR). Skipped without it. Run with
 * `composer test:skatehouse`; the default run leaves the group out (the import takes minutes).
 *
 * @group skatehouse
 */
final class SkatehouseTest extends IndexTestCase {

	public function set_up(): void {
		parent::set_up();
		$products = SkatehouseCopy::productsFile();
		$pages    = SkatehouseCopy::pagesFile();
		if ( null === $products || null === $pages ) {
			self::markTestSkipped( 'No Skatehouse export in tests/fixtures/skatehouse/ (a products CSV and a pages WXR; D-103).' );
		}
		$loaded = SkatehouseCopy::loadProducts( $products );
		self::assertSame( array(), $loaded['failed'], 'every product row imports' );
		$slugs = SkatehouseCopy::loadPages( $pages );
		Settings::update(
			array(
				'index_pages'       => 'yes',
				'filter_attributes' => wc_get_attribute_taxonomy_names(),
				'help_page'         => $slugs['contact'] ?? 0,
				'guide_page'        => $slugs['size-guides'] ?? 0,
			)
		);
		$this->service( AttributeTerms::class )->rebuild();
		$this->service( Reindex::class )->runInline();
	}

	/**
	 * The names of the products a query ranks.
	 *
	 * @param string $query The query.
	 * @return string[]
	 */
	private function names( string $query ): array {
		$ids = $this->service( SearchService::class )->rankedIds( $query, 'en', '' )['ids'];
		return array_map( static fn( int $id ): string => (string) get_the_title( $id ), $ids );
	}

	/**
	 * Where the results page sends a query, or null.
	 *
	 * @param string $query The query.
	 */
	private function target( string $query ): ?string {
		$this->service( ProductSearch::class )->reset();
		$this->service( ResultsHooks::class )->reset();
		$this->go_to(
			add_query_arg(
				array(
					's'         => rawurlencode( $query ),
					'post_type' => 'product',
				),
				home_url( '/' )
			)
		);
		return $this->service( QueryRouter::class )->target();
	}

	/**
	 * The titles of the pages a query finds, in order.
	 *
	 * @param string $query The query.
	 * @return string[]
	 */
	private function pages( string $query ): array {
		return array_column( $this->service( SearchService::class )->content( $query, 'en', 'page' ), 'name' );
	}

	/**
	 * A category's link, by name.
	 *
	 * @param string $name The category.
	 */
	private static function category( string $name ): string {
		$term = get_term_by( 'name', $name, 'product_cat' );
		$link = $term instanceof \WP_Term ? get_term_link( $term ) : '';
		return is_string( $link ) ? $link : '';
	}

	/**
	 * The result count logged for a query the results page sent on.
	 *
	 * @param string $query The query.
	 */
	private function logged( string $query ): int {
		$log = $this->service( SearchLog::class );
		$log->clear();
		$this->target( $query );
		return (int) ( $log->pending()[0]['results'] ?? -1 );
	}

	public function test_the_golden_queries(): void {
		$phillys = $this->names( "philly's" );
		self::assertNotSame( array(), $phillys, "philly's" );
		self::assertEqualsCanonicalizing( $phillys, $this->names( 'phillys' ), 'the apostrophe' );

		$wide = $this->names( '8.25″' );
		self::assertNotSame( array(), $wide, 'the decks that come in 8.25″' );
		foreach ( array( '8.25in', '8.25 in', '8.25 inch', '8.25"' ) as $query ) {
			self::assertEqualsCanonicalizing( $wide, $this->names( $query ), $query );
		}
		self::assertNotSame( array(), $this->names( '8.5 deck' ) );
		self::assertEqualsCanonicalizing( $this->names( '8.5 deck' ), $this->names( '8.50 deck' ), 'trailing zero' );
		self::assertNotSame( array(), $this->names( '54mm wheels' ) );
		self::assertEqualsCanonicalizing( $this->names( '54mm wheels' ), $this->names( '54 mm wheels' ), '54 mm' );
		$grip = $this->names( '9x33 grip' );
		self::assertCount( 3, $grip, 'the three 33″ x 9″ grip sheets' );
		self::assertEqualsCanonicalizing( $grip, $this->names( '33 x 9 grip' ) );

		$wheels = (string) $this->target( 'wheels' );
		self::assertStringStartsWith( self::category( 'Wheels' ), $wheels, 'wheels opens Wheels' );
		self::assertSame( 28, $this->logged( 'wheels' ), 'the Wheels category (28)' );
		self::assertStringStartsWith( self::category( 'Decks' ), (string) $this->target( 'decks' ), 'decks opens Decks, not Fingerboard decks' );
		self::assertSame( 122, $this->logged( 'decks' ), 'Decks (122)' );
		$sized = (string) $this->target( '8.25 deck' );
		self::assertStringStartsWith( self::category( 'Decks' ), $sized, '8.25 deck opens Decks' );
		self::assertMatchesRegularExpression( '/[?&]filter_[a-z0-9_-]+=8-25(&|$)/', $sized, 'filtered to Width 8.25' );
		self::assertSame( 88, $this->logged( '8.25 deck' ), 'Decks in 8.25 (88)' );
		self::assertStringStartsWith( self::category( 'Wheels' ), (string) $this->target( '54mm wheels' ), 'Wheels, filtered to 54 mm' );
		$tiny = (string) $this->target( '32 mm fingerboard deck' );
		self::assertStringStartsWith( self::category( 'Fingerboard decks' ), $tiny, 'fingerboard decks in 32 mm' );
		self::assertStringContainsString( 'filter_', $tiny );

		self::assertSame( 'Shipping and returns', $this->pages( 'returns' )[0] ?? null );
		self::assertMatchesRegularExpression( '/^Size guides?$/i', $this->pages( 'size guide' )[0] ?? '' );
		self::assertSame( 'Contact', $this->pages( 'contact' )[0] ?? null );
		self::assertMatchesRegularExpression( '/^Track (an|your) order$/i', $this->pages( 'track order' )[0] ?? '' );

		$mumber = $this->names( 'mumber fur' );
		self::assertNotSame( array(), $mumber );
		foreach ( $mumber as $name ) {
			self::assertMatchesRegularExpression( '/mumber.?fur/i', $name );
		}
		$kazumi = $this->names( 'kazumi deck' );
		self::assertNotSame( array(), $kazumi );
		foreach ( array_slice( $kazumi, 0, 10 ) as $name ) {
			self::assertMatchesRegularExpression( '/kazumi/i', $name, 'the KAZUMI decks first' );
		}

		// The owner's fallback products are a setting the export does not carry: two of the shop's own stand in.
		Settings::set( 'fallback_products', array_slice( $this->service( SearchService::class )->rankedIds( 'deck', 'en', '' )['ids'], 0, 2 ) );
		self::assertNull( $this->target( 'xyzzy' ) );
		$empty = $this->service( ResultsView::class )->noResults();
		self::assertStringContainsString( 'csearch-no-results-help', $empty, 'the help line' );
		self::assertStringContainsString( 'csearch-no-results-guide', $empty, 'the size guides link' );
		self::assertStringContainsString( 'Try one of these categories:', $empty, 'the nearest categories' );
		self::assertStringContainsString( 'You might like', $empty, 'the fallback products' );
	}
}
