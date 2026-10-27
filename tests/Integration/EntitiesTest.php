<?php
/**
 * Names stored with HTML entities.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Index\IndexQueue;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Results\MatchDecorations;
use CreativeBranding\Search\Results\ProductSearch;
use CreativeBranding\Search\Search\CategoryScopes;
use CreativeBranding\Search\Search\Suggestions;
use CreativeBranding\Search\Tests\Support\IndexTestCase;
use WC_Product_Attribute;
use WC_Product_Simple;
use WP_Term;

/**
 * 1.5.1 (D-133): a product name an import left as `Philly&#8217;s Hoodie`, a category WordPress stores as
 * `Rock &amp; Roll` and an attribute value written `Black &amp; White` are indexed as the text they show, found
 * by every spelling of the apostrophe, and shown decoded in the panel and escaped once on the page.
 */
final class EntitiesTest extends IndexTestCase {

	private int $hoodie = 0;

	private int $category = 0;

	public function set_up(): void {
		parent::set_up();
		$term           = wp_insert_term( 'Rock & Roll', 'product_cat' );
		$this->category = is_array( $term ) ? (int) $term['term_id'] : 0;
		$colour         = new WC_Product_Attribute();
		$colour->set_name( 'Colour' );
		$colour->set_options( array( 'Black &amp; White' ) );
		$colour->set_visible( true );
		$hoodie = new WC_Product_Simple();
		$hoodie->set_name( 'Philly&#8217;s Hoodie' );
		$hoodie->set_sku( 'PHL-HOO-00001' );
		$hoodie->set_regular_price( '64.99' );
		$hoodie->set_category_ids( array( $this->category ) );
		$hoodie->set_attributes( array( $colour ) );
		$this->hoodie = $hoodie->save();

		$this->service( IndexQueue::class )->clear();
		$this->service( Reindex::class )->runInline();
	}

	public function test_the_names_are_stored_with_their_entities(): void {
		self::assertSame( 'Philly&#8217;s Hoodie', get_post( $this->hoodie )->post_title ?? '' );
		$term = get_term( $this->category, 'product_cat' );
		self::assertInstanceOf( WP_Term::class, $term );
		self::assertSame( 'Rock &amp; Roll', $term->name, 'WordPress escapes a term name as it saves it' );
	}

	public function test_the_index_holds_the_text_not_the_entity(): void {
		self::assertSame( 'Philly’s Hoodie', $this->documents( 'product', $this->hoodie )[0]['title'] ?? '' );
		self::assertNotNull( $this->df( 'phillys' ) );
		self::assertNotNull( $this->df( 'philly' ) );
		self::assertNull( $this->df( '8217' ), 'the entity\'s number is no word' );
		self::assertNull( $this->df( 'amp' ), 'nor the ampersand\'s name' );
	}

	public function test_every_spelling_finds_the_product_and_the_panel_shows_the_name(): void {
		$suggest = $this->service( Suggestions::class );
		foreach ( array( 'phillys', "Philly's", 'Philly’s', 'Philly&#8217;s', 'phillys hoodie' ) as $q ) {
			$product = $suggest->answer( $q, 'en', 6 )['products'][0] ?? array();
			self::assertSame( $this->hoodie, $product['id'] ?? 0, $q );
			self::assertSame( 'Philly’s Hoodie', $product['name'] ?? '', "{$q}: the name is text, the panel builds it as nodes" );
		}
		self::assertSame( array(), $suggest->answer( '8217', 'en', 6 )['products'] );

		$terms = array_column( $suggest->answer( 'rock roll', 'en', 6 )['terms'], 'name' );
		self::assertContains( 'Rock & Roll', $terms );

		$term = get_term( $this->category, 'product_cat' );
		self::assertInstanceOf( WP_Term::class, $term );
		self::assertSame( 'Rock & Roll', $this->service( CategoryScopes::class )->for( 'phillys', array( $this->hoodie ), array(), $term )[0]['name'] ?? '' );
	}

	public function test_the_results_page_finds_it_and_escapes_once(): void {
		$this->service( ProductSearch::class )->reset();
		$this->service( MatchDecorations::class )->reset();
		$this->go_to( add_query_arg( array( 's' => 'phillys', 'post_type' => 'product' ), home_url( '/' ) ) );
		self::assertSame( array( $this->hoodie ), $this->service( ProductSearch::class )->answer()['ids'] ?? array() );

		$this->service( ProductSearch::class )->reset();
		$this->service( MatchDecorations::class )->reset();
		$this->go_to( add_query_arg( array( 's' => 'philly black white', 'post_type' => 'product' ), home_url( '/' ) ) );
		$line = $this->service( MatchDecorations::class )->line( $this->hoodie );
		self::assertSame( '<p class="csearch-matched"><small>Matches Colour: Black &amp; White</small></p>', $line );
		self::assertStringNotContainsString( '&amp;amp;', $line );
	}
}
