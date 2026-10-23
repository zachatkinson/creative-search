<?php
/**
 * The variation a query names, and what matched.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Index\Indexer;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Results\MatchDecorations;
use CreativeBranding\Search\Results\ProductSearch;
use CreativeBranding\Search\Search\Suggestions;
use CreativeBranding\Search\Support\Tables;
use CreativeBranding\Search\Tests\Support\IndexTestCase;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * 1.3 (D-104, D-105, D-106): a product's SKUs and attribute values are kept beside its document, its
 * variations' with their own photos and the arguments that choose them; the panel and the results page show
 * the design a query names and say what matched when the card does not show it; a simple product in stock is
 * marked for the panel's add to cart.
 */
final class MatchTest extends IndexTestCase {

	private int $grip = 0;

	private int $wheels = 0;

	/**
	 * Design to its variation id.
	 *
	 * @var array<string, int>
	 */
	private array $designs = array();

	/**
	 * Design to its photo's attachment id.
	 *
	 * @var array<string, int>
	 */
	private array $photos = array();

	public function set_up(): void {
		parent::set_up();
		$this->photos = array(
			'Fire' => $this->photo( 'grip-fire.jpg' ),
			'Ice'  => $this->photo( 'grip-ice.jpg' ),
		);
		$design       = new WC_Product_Attribute();
		$design->set_name( 'Design' );
		$design->set_options( array( 'Fire', 'Ice', 'Leaf' ) );
		$design->set_visible( true );
		$design->set_variation( true );
		$grip = new WC_Product_Variable();
		$grip->set_name( 'Mumber-Fur Grip' );
		$grip->set_sku( 'MUM-FUR' );
		$grip->set_attributes( array( $design ) );
		$this->grip = $grip->save();
		foreach ( array( 'Fire', 'Ice', 'Leaf' ) as $name ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $this->grip );
			$variation->set_sku( 'MUM-FUR-' . strtoupper( $name ) );
			$variation->set_regular_price( '14.99' );
			$variation->set_attributes( array( 'design' => $name ) );
			$variation->set_image_id( $this->photos[ $name ] ?? 0 );
			$this->designs[ $name ] = $variation->save();
		}
		WC_Product_Variable::sync( $this->grip );

		$size = new WC_Product_Attribute();
		$size->set_name( 'Size' );
		$size->set_options( array( '54mm' ) );
		$size->set_visible( true );
		$wheels = new WC_Product_Simple();
		$wheels->set_name( 'Orbit Wheels' );
		$wheels->set_sku( 'ORB-CLA-00007' );
		$wheels->set_regular_price( '44.99' );
		$wheels->set_description( 'Poured urethane, ground true.' );
		$wheels->set_attributes( array( $size ) );
		$this->wheels = $wheels->save();

		$this->service( \CreativeBranding\Search\Index\IndexQueue::class )->clear();
		$this->service( Reindex::class )->runInline();
	}

	/**
	 * An image attachment with the metadata `wp_get_attachment_image_src()` reads.
	 *
	 * @param string $file File name.
	 */
	private function photo( string $file ): int {
		$id = self::factory()->attachment->create_object(
			array(
				'file'           => $file,
				'post_mime_type' => 'image/jpeg',
			)
		);
		wp_update_attachment_metadata(
			$id,
			array(
				'width'  => 600,
				'height' => 600,
				'file'   => $file,
			)
		);
		return $id;
	}

	/**
	 * The facts of a product's English document.
	 *
	 * @param int $productId Product id.
	 * @return array<int, array<string, string|null>>
	 */
	private function facts( int $productId ): array {
		global $wpdb;
		$document = $this->documents( 'product', $productId )[0]['id'] ?? 0;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE document_id = %d ORDER BY position', Tables::facts(), $document ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function test_the_skus_and_values_are_kept_beside_the_document(): void {
		$facts = $this->facts( $this->grip );
		self::assertSame(
			array( 'Fire', 'Ice', 'Leaf', 'MUM-FUR', 'Fire', 'MUM-FUR-FIRE', 'Ice', 'MUM-FUR-ICE', 'Leaf', 'MUM-FUR-LEAF' ),
			array_column( $facts, 'label' ),
			'the product\'s own values and SKU, then each variation\'s value and SKU, in order'
		);
		self::assertSame( array( '0', '0', '0', '0' ), array_column( array_slice( $facts, 0, 4 ), 'variation_id' ) );
		self::assertSame( (string) $this->designs['Ice'], $facts[6]['variation_id'] );
		self::assertSame( 'attribute_design', $facts[6]['attribute'], 'the argument that chooses it' );
		self::assertSame( 'Ice', $facts[6]['value'] );
		self::assertSame( 'Design', $facts[6]['name'] );
		self::assertSame( (string) $this->photos['Ice'], $facts[6]['image_id'], 'its own photo' );
		self::assertNull( $facts[8]['image_id'], 'Leaf has none' );
		self::assertContains( '=mum fur ice', explode( "\t", (string) $facts[7]['terms'] ), 'a SKU carries its key term' );
		self::assertContains( 'ice', explode( "\t", (string) $facts[6]['terms'] ) );

		$this->service( Indexer::class )->removeProduct( $this->grip );
		self::assertSame( array(), $this->facts( $this->grip ), 'gone with the document' );
	}

	public function test_a_reindex_leaves_one_generation_of_facts(): void {
		$before = $this->rowCount( Tables::facts() );
		$this->service( Reindex::class )->runInline();
		self::assertSame( $before, $this->rowCount( Tables::facts() ), 'the old generation\'s facts went with its documents' );
	}

	public function test_a_simple_product_in_stock_can_go_to_the_cart_from_the_panel(): void {
		self::assertSame( '1', $this->documents( 'product', $this->wheels )[0]['cart'] );
		self::assertSame( '0', $this->documents( 'product', $this->grip )[0]['cart'], 'a variable product needs a choice' );
		$wheels = wc_get_product( $this->wheels );
		$wheels->set_stock_status( 'outofstock' );
		$wheels->save();
		$this->service( Indexer::class )->updateStock( $this->wheels );
		self::assertSame( '0', $this->documents( 'product', $this->wheels )[0]['cart'], 'sold out, it cannot' );
	}

	public function test_the_panel_shows_the_design_a_query_names(): void {
		$answer = $this->service( Suggestions::class )->answer( 'mumber fur fire', 'en', 6 );
		$row    = $answer['products'][0];
		self::assertSame( $this->grip, $row['id'] );
		self::assertStringContainsString( 'attribute_design=Fire', $row['url'] );
		self::assertStringContainsString( 'grip-fire.jpg', $row['image']['src'] ?? '' );
		self::assertSame( 'Matches Design: Fire', $row['matched'] );

		$ice = $this->service( Suggestions::class )->answer( 'MUM-FUR-ICE', 'en', 6 )['products'][0];
		self::assertSame( 'Matches SKU MUM-FUR-ICE', $ice['matched'] );
		self::assertStringContainsString( 'grip-ice.jpg', $ice['image']['src'] ?? '' );

		$named = $this->service( Suggestions::class )->answer( 'mumber fur grip', 'en', 6 )['products'][0];
		self::assertSame( '', $named['matched'], 'the row shows the name' );
		self::assertStringNotContainsString( 'attribute_', $named['url'], 'no design named' );
	}

	public function test_the_panel_says_what_matched_on_a_simple_product(): void {
		$suggest = $this->service( Suggestions::class );
		self::assertSame( 'Matches SKU ORB-CLA-00007', $suggest->answer( 'ORB-CLA-00007', 'en', 6 )['products'][0]['matched'] );
		self::assertSame( 'Matches Size: 54mm', $suggest->answer( 'orbit 54mm', 'en', 6 )['products'][0]['matched'] );
		self::assertSame( 'Matches the description', $suggest->answer( 'orbit urethane', 'en', 6 )['products'][0]['matched'] );
	}

	public function test_the_results_page_cards(): void {
		$this->service( ProductSearch::class )->reset();
		$this->service( MatchDecorations::class )->reset();
		$this->go_to( add_query_arg( array( 's' => 'orbit urethane', 'post_type' => 'product' ), home_url( '/' ) ) );
		$cards = $this->service( MatchDecorations::class );
		self::assertSame( '<p class="csearch-matched"><small>Matches the description</small></p>', $cards->line( $this->wheels ) );
		self::assertSame( '', $cards->line( $this->grip ), 'not in this answer' );

		$this->go_to( add_query_arg( array( 's' => 'mumber fur ice', 'post_type' => 'product' ), home_url( '/' ) ) );
		self::assertSame( $this->photos['Ice'], wc_get_product( $this->grip )->get_image_id(), 'the card shows the Ice photo' );
		self::assertStringContainsString( 'attribute_design=Ice', (string) get_permalink( $this->grip ) );
		self::assertSame( '<p class="csearch-matched"><small>Matches Design: Ice</small></p>', $cards->line( $this->grip ) );
		self::assertSame( 0, (int) wc_get_product( $this->wheels )->get_image_id(), 'another product keeps its own' );

		$this->go_to( home_url( '/' ) );
		$this->service( ProductSearch::class )->reset();
		self::assertStringNotContainsString( 'attribute_design', (string) get_permalink( $this->grip ), 'only on the search that named it' );
	}
}
