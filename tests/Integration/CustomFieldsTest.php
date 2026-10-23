<?php
/**
 * Custom fields in the index.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Admin\Actions;
use CreativeBranding\Search\Admin\Notices;
use CreativeBranding\Search\Admin\ToolsTab;
use CreativeBranding\Search\Domain\Fields;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Repository\MetaKeyRepository;
use CreativeBranding\Search\Search\SearchService;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Tests\Support\IndexTestCase;

/**
 * D-119 (competitors: custom fields and ACF): the meta keys the owner chooses are searched as the "custom" field,
 * at its own weight; a list is flattened, the text capped; a change to a chosen field reindexes the product; the
 * Tools tab offers the keys products use and asks for a rebuild when the list changes.
 */
final class CustomFieldsTest extends IndexTestCase {

	/**
	 * Product ids by catalogue index.
	 *
	 * @var array<int, int>
	 */
	private array $ids = array();

	public function set_up(): void {
		parent::set_up();
		$indexes   = array( 0, 6, 22 );
		$this->ids = array_combine( $indexes, $this->products( $indexes ) );
		update_post_meta( $this->ids[0], 'material', 'Xylarite veneer' );
		update_post_meta( $this->ids[0], 'colours', array( 'zinnober', array( 'smaragd' ) ) );
		update_post_meta( $this->ids[6], 'material', 'Polyurex' );
		update_post_meta( $this->ids[6], 'notes', 'quokka' );
		$this->service( \CreativeBranding\Search\Index\IndexQueue::class )->clear();
	}

	/**
	 * The product ids a query finds.
	 *
	 * @param string $query The query.
	 * @return int[]
	 */
	private function find( string $query ): array {
		return $this->service( SearchService::class )->run( $query, 'en' )->productIds();
	}

	public function test_nothing_is_searched_until_the_owner_chooses(): void {
		$this->service( Reindex::class )->runInline();
		self::assertSame( array(), $this->find( 'xylarite' ) );
	}

	public function test_the_chosen_fields_are_searched_as_their_own_field(): void {
		Settings::set( 'custom_fields', array( 'material', 'colours' ) );
		$this->service( Reindex::class )->runInline();
		self::assertSame( array( $this->ids[0] ), $this->find( 'xylarite' ) );
		self::assertSame( array( $this->ids[6] ), $this->find( 'polyurex' ) );
		self::assertSame( array( $this->ids[0] ), $this->find( 'smaragd' ), 'a list, flattened' );
		self::assertSame( array( $this->ids[0] ), $this->find( 'zinnober' ) );
		self::assertSame( array(), $this->find( 'quokka' ), 'a field not chosen' );
		$document = $this->documents( 'product', $this->ids[0] )[0];
		self::assertSame( array( Fields::CUSTOM ), array_keys( $this->postingsOf( (int) $document['id'] )['xylarite'] ?? array() ), 'field 9' );
		$explained = $this->service( SearchService::class )->run( 'xylarite', 'en', 10, 0, true, true )->products[0]->score->tokens[0];
		self::assertSame( Fields::CUSTOM, $explained['field'] );
		self::assertSame( 4, $explained['weight'], 'between an attribute and a tag' );
	}

	public function test_a_chosen_field_s_text_is_capped(): void {
		update_post_meta( $this->ids[22], 'material', str_repeat( 'fillerword ', 250 ) . 'wombat' );
		Settings::set( 'custom_fields', array( 'material' ) );
		$this->service( Reindex::class )->runInline();
		self::assertSame( array(), $this->find( 'wombat' ), 'past 2,000 characters' );
		self::assertSame( array( $this->ids[22] ), $this->find( 'fillerword' ) );
	}

	public function test_a_change_to_a_chosen_field_reindexes_the_product(): void {
		Settings::set( 'custom_fields', array( 'material' ) );
		$this->service( Reindex::class )->runInline();
		update_post_meta( $this->ids[22], 'material', 'Plimsolite blend' );
		update_post_meta( $this->ids[22], 'notes', 'ignored' );
		$this->runQueue();
		self::assertSame( array( $this->ids[22] ), $this->find( 'plimsolite' ) );
		delete_post_meta( $this->ids[0], 'material' );
		$this->runQueue();
		self::assertSame( array(), $this->find( 'xylarite' ) );
	}

	public function test_the_tools_tab_offers_the_keys_and_asks_for_a_rebuild(): void {
		$keys = $this->service( MetaKeyRepository::class )->productKeys( 10 );
		self::assertSame( 'material', $keys[0], 'the most used first' );
		self::assertContains( 'notes', $keys );
		foreach ( $keys as $key ) {
			self::assertStringStartsNotWith( '_', $key, 'WooCommerce\'s own keys are not offered' );
			self::assertNotSame( 'total_sales', $key );
		}
		global $wpdb;
		$before = $wpdb->num_queries;
		self::assertSame( $keys, $this->service( MetaKeyRepository::class )->productKeys( 10 ) );
		self::assertSame( $before, $wpdb->num_queries, 'the second visit reads the object cache (1.3 review M8)' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		$this->service( ToolsTab::class )->render();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'name="custom_fields[]" value="material"', $html );
		self::assertStringContainsString( 'name="custom_fields_other"', $html );

		// The product settings as they are, so only the custom fields change what is indexed.
		$indexed = array_fill_keys( Actions::INDEX_FLAGS, 'yes' );
		$this->service( Actions::class )->saveTools(
			$indexed + array(
				'custom_fields'       => array( '', 'material' ),
				'custom_fields_other' => 'colours, acf_grip ,_wp_page_template',
			)
		);
		self::assertSame( array( 'material', 'colours', 'acf_grip' ), Settings::get( 'custom_fields' ) );
		self::assertStringContainsString( 'rebuild the index', Notices::takeFlash()[1] ?? '' );
		$this->service( Actions::class )->saveTools( $indexed + array( 'custom_fields' => array( '', 'material', 'colours', 'acf_grip' ) ) );
		self::assertSame( 'Settings saved.', Notices::takeFlash()[1] ?? '', 'the same list asks for nothing' );
		$this->service( Actions::class )->saveTools( $indexed + array( 'custom_fields' => array( '' ) ) );
		self::assertSame( array(), Settings::get( 'custom_fields' ), 'every box unticked' );
	}
}
