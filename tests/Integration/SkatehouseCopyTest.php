<?php
/**
 * The loader of the Skatehouse copy.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Tests\Support\IndexTestCase;
use CreativeBranding\Search\Tests\Support\SkatehouseCopy;
use CreativeBranding\Search\Tests\Support\WooCommerceCatalogue;
use ReflectionMethod;
use WC_Product_CSV_Exporter;

/**
 * D-103: what WooCommerce's exporter and Tools → Export write comes back through {@see SkatehouseCopy}, so the
 * `skatehouse` group can trust its copy. Runs on the generated catalogue; needs no export.
 */
final class SkatehouseCopyTest extends IndexTestCase {

	/**
	 * Files to remove after the test.
	 *
	 * @var string[]
	 */
	private array $files = array();

	public function tear_down(): void {
		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}
		parent::tear_down();
	}

	public function test_a_products_export_comes_back_through_woocommerces_importer(): void {
		$ids  = $this->products( array( 0, 6, 15 ) );
		$spec = WooCommerceCatalogue::spec( 0 );
		require_once WC_ABSPATH . 'includes/export/class-wc-product-csv-exporter.php';
		$exporter = new WC_Product_CSV_Exporter();
		$exporter->prepare_data_to_export();
		$csv           = (string) ( new ReflectionMethod( $exporter, 'export_column_headers' ) )->invoke( $exporter ) . (string) ( new ReflectionMethod( $exporter, 'get_csv_data' ) )->invoke( $exporter );
		$file          = get_temp_dir() . 'csearch-skatehouse-products.csv';
		$this->files[] = $file;
		file_put_contents( $file, $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test writing its own temporary file.
		$variations = 0;
		foreach ( $ids as $id ) {
			$variations += count( wc_get_product( $id )?->get_children() ?? array() );
			wc_get_product( $id )?->delete( true );
		}
		self::assertSame( 0, wc_get_product_id_by_sku( (string) $spec['sku'] ) );

		$loaded = SkatehouseCopy::loadProducts( $file );
		self::assertSame( array(), $loaded['failed'] );
		self::assertSame( 3, $loaded['imported'] );
		self::assertSame( $variations, $loaded['variations'], 'every variation' );
		$product = wc_get_product( wc_get_product_id_by_sku( (string) $spec['sku'] ) );
		self::assertInstanceOf( \WC_Product_Variable::class, $product );
		self::assertSame( $spec['name'], $product->get_name() );
		self::assertContains( 'Decks', wp_list_pluck( get_the_terms( $product->get_id(), 'product_cat' ) ?: array(), 'name' ) );
		self::assertEqualsCanonicalizing( array_map( 'sanitize_title', (array) $spec['attributes']['pa_width'] ), wc_get_product_terms( $product->get_id(), 'pa_width', array( 'fields' => 'slugs' ) ) );
	}

	public function test_a_pages_export_comes_back_with_its_parents(): void {
		$guides = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Size guides',
				'post_name'    => 'size-guides',
				'post_content' => '<!-- wp:paragraph --><p>Every size, in one place.</p><!-- /wp:paragraph -->',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Deck widths',
				'post_name'    => 'deck-widths',
				'post_parent'  => $guides,
				'post_content' => 'Deck widths from 7.5″ to 9″.',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Draft page',
				'post_status' => 'draft',
			)
		);
		require_once ABSPATH . 'wp-admin/includes/export.php';
		// export_wp() sends download headers, which a test process has already passed.
		set_error_handler( static fn( int $level, string $message ): bool => str_contains( $message, 'Cannot modify header information' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- the one warning export_wp() raises outside a request.
		ob_start();
		export_wp( array( 'content' => 'page' ) );
		restore_error_handler();
		$file          = get_temp_dir() . 'csearch-skatehouse-pages.xml';
		$this->files[] = $file;
		file_put_contents( $file, (string) ob_get_clean() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test writing its own temporary file.
		foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $page ) {
			wp_delete_post( (int) $page, true );
		}

		$slugs = SkatehouseCopy::loadPages( $file );
		self::assertArrayHasKey( 'size-guides', $slugs );
		self::assertArrayHasKey( 'deck-widths', $slugs );
		self::assertSame( $slugs['size-guides'], wp_get_post_parent_id( $slugs['deck-widths'] ) );
		self::assertSame( 'Deck widths from 7.5″ to 9″.', get_post_field( 'post_content', $slugs['deck-widths'] ) );
		self::assertSame( 'publish', get_post_status( $slugs['size-guides'] ) );
		self::assertContains( 'draft', array_map( 'get_post_status', $slugs ), 'a draft stays a draft' );
	}

	public function test_nothing_is_found_without_an_export(): void {
		if ( null !== SkatehouseCopy::productsFile() || null !== SkatehouseCopy::pagesFile() ) {
			self::assertFileExists( (string) ( SkatehouseCopy::productsFile() ?? SkatehouseCopy::pagesFile() ) );
			return;
		}
		self::assertNull( SkatehouseCopy::productsFile() );
		self::assertNull( SkatehouseCopy::pagesFile() );
	}
}
