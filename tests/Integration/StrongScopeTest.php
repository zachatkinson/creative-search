<?php
/**
 * The scope row follows the strong matches (D-147).
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Domain\Synonym;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Repository\SynonymRepository;
use CreativeBranding\Search\Search\Suggestions;
use CreativeBranding\Search\Tests\Support\IndexTestCase;
use WC_Product_Simple;

/**
 * The 1.6.3 brief's item 3, from the demo's "beanie": 4 hats found through their category's name, 5 decks whose
 * descriptions mention a beanie. The scope row is chosen from the hats; one built on description matches alone
 * says so, for the panel's cap.
 */
final class StrongScopeTest extends IndexTestCase {

	/**
	 * Category ids by slug.
	 *
	 * @var array<string, int>
	 */
	private array $categories = array();

	public function set_up(): void {
		parent::set_up();
		$this->categories['hats']  = $this->catalogue->term( 'product_cat', 'Hats and toques', 0, 'hats-and-toques' );
		$this->categories['decks'] = $this->catalogue->term( 'product_cat', 'Decks', 0, 'decks' );
		foreach ( array( 'Black', 'Grey', 'Navy', 'Olive' ) as $i => $colour ) {
			$this->simple( "Cuffed Toque {$colour}", $this->categories['hats'], 10 + $i, 'Knit warm, with deep cuffs.' );
		}
		foreach ( array( 'Ghost', 'Tiger', 'Dealer', 'Blank', 'Mini' ) as $i => $name ) {
			$this->simple( "HZRD {$name} Deck", $this->categories['decks'], 50 + $i, 'Ships with a free beanie and griptape.' );
		}
		$this->simple( 'Bolt Set', $this->catalogue->term( 'product_cat', 'Hardware', 0, 'hardware' ), 5, 'Fits under any griptape.' );
		$this->service( SynonymRepository::class )->add( new Synonym( 'en', 'beanie', 'toque', true ) );
		$this->service( Reindex::class )->runInline();
	}

	/**
	 * A simple product in a category.
	 *
	 * @param string $name     Name.
	 * @param int    $category Category id.
	 * @param int    $sales    Total sales.
	 * @param string $text     Description.
	 */
	private function simple( string $name, int $category, int $sales, string $text ): void {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_description( $text );
		$product->set_status( 'publish' );
		$product->set_regular_price( '30' );
		$product->set_total_sales( $sales );
		$product->set_category_ids( array( $category ) );
		$product->save();
	}

	/**
	 * The panel's answer.
	 *
	 * @param string $q What is typed.
	 * @return array<string, mixed>
	 */
	private function answer( string $q ): array {
		return $this->service( Suggestions::class )->answer( $q, 'en', 6 );
	}

	public function test_the_scope_follows_the_strong_matches(): void {
		$answer = $this->answer( 'beanie' );
		self::assertSame( 9, $answer['total'], 'the hats and the decks' );
		self::assertNotContains( 'Decks', array_column( $answer['scopes'], 'name' ), 'five decks only mention a beanie: no scope for them while the hats match by name' );
		self::assertSame( array(), $answer['scopes'], 'the hats\' own category is already a category row' );
		self::assertContains( 'Hats and toques', array_column( $answer['terms'], 'name' ), 'the category row, which the cap keeps' );
	}

	public function test_description_matches_alone_choose_as_before_and_say_so(): void {
		$scopes = $this->answer( 'griptape' )['scopes'];
		self::assertSame( array( 'Decks' ), array_column( $scopes, 'name' ), 'every match is in a description: the category most of them are in, as in 1.6.2' );
		self::assertTrue( $scopes[0]['described'], 'and says so, so the cap keeps a category row ahead of it' );
		// A scope on strong matches carries no flag: PanelTest's 1.6.2 expectation for "kazumi" holds it exactly.
	}
}
