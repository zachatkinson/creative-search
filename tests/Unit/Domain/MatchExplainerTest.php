<?php
/**
 * Which variation a query named, and why a product matched.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Domain;

use CreativeBranding\Search\Domain\Fact;
use CreativeBranding\Search\Domain\Fields;
use CreativeBranding\Search\Domain\MatchDetail;
use CreativeBranding\Search\Domain\MatchExplainer;
use PHPUnit\Framework\TestCase;

/**
 * D-104 and D-105, on hand-built facts: Mumber-Fur grip in designs, a deck in widths, a simple product.
 */
final class MatchExplainerTest extends TestCase {

	private static function design( int $variation, string $label, ?int $image ): Fact {
		return new Fact( $variation, Fields::ATTRIBUTE, 'attribute_pa_design', 'Design', strtolower( $label ), $label, $image, array( strtolower( $label ) ) );
	}

	private static function width( int $variation, string $label, ?int $image = null ): Fact {
		return new Fact( $variation, Fields::ATTRIBUTE, 'attribute_pa_width', 'Width', str_replace( '.', '-', $label ), $label, $image, array( $label ) );
	}

	private static function sku( int $variation, string $sku, ?int $image = null ): Fact {
		$key = strtolower( $sku );
		return new Fact( $variation, Fields::SKU, '', '', '', $sku, $image, array_merge( explode( '-', $key ), array( '=' . str_replace( '-', ' ', $key ) ) ) );
	}

	/**
	 * Mumber-Fur grip in three designs, each with its own photo and SKU.
	 *
	 * @return Fact[]
	 */
	private static function grip(): array {
		return array(
			new Fact( 0, Fields::ATTRIBUTE, '', 'Design', '', 'Fire', null, array( 'fire' ) ),
			new Fact( 0, Fields::ATTRIBUTE, '', 'Design', '', 'Ice', null, array( 'ice' ) ),
			new Fact( 0, Fields::ATTRIBUTE, '', 'Design', '', 'Leaf', null, array( 'leaf' ) ),
			self::sku( 0, 'MUM-FUR' ),
			self::design( 101, 'Fire', 501 ),
			self::sku( 101, 'MUM-FUR-FIRE', 501 ),
			self::design( 102, 'Ice', 502 ),
			self::sku( 102, 'MUM-FUR-ICE', 502 ),
			self::design( 103, 'Leaf', null ),
		);
	}

	private static function explain( array $tokens, array $fields, array $facts, string $skuKey = '' ): MatchDetail {
		return ( new MatchExplainer() )->explain( $tokens, $skuKey, $fields, $facts );
	}

	public function test_a_token_some_variations_carry_names_the_first_of_them_with_its_photo_and_value(): void {
		$d = self::explain(
			array( array( 'mumber' ), array( 'fur' ), array( 'fire' ) ),
			array(
				Fields::NAME      => array( 'mumber', 'fur' ),
				Fields::ATTRIBUTE => array( 'fire' ),
			),
			self::grip()
		);
		self::assertSame( 101, $d->variationId );
		self::assertSame( 501, $d->imageId );
		self::assertSame( array( 'attribute_pa_design' => 'fire' ), $d->args );
		self::assertSame( MatchDetail::ATTRIBUTE, $d->reason, 'fire is not in the name' );
		self::assertSame( 'Design', $d->name );
		self::assertSame( 'Fire', $d->label );
		self::assertFalse( $d->isEmpty() );
	}

	public function test_the_second_variation_is_named_with_its_own_photo(): void {
		$d = self::explain( array( array( 'ice' ) ), array( Fields::ATTRIBUTE => array( 'ice' ) ), self::grip() );
		self::assertSame( 102, $d->variationId );
		self::assertSame( 502, $d->imageId, 'its own photo, not the first variation\'s' );
	}

	public function test_a_named_variation_without_a_photo_keeps_the_products(): void {
		$d = self::explain( array( array( 'leaf' ) ), array( Fields::ATTRIBUTE => array( 'leaf' ) ), self::grip() );
		self::assertSame( 103, $d->variationId );
		self::assertNull( $d->imageId );
		self::assertSame( array( 'attribute_pa_design' => 'leaf' ), $d->args );
	}

	public function test_a_variation_sku_typed_in_full_names_it_and_says_so(): void {
		$d = self::explain( array( array( 'mum' ), array( 'fur' ), array( 'ice' ) ), array( Fields::SKU => array( 'mum', 'fur', 'ice', '=mum fur ice' ) ), self::grip(), '=mum fur ice' );
		self::assertSame( 102, $d->variationId );
		self::assertSame( 502, $d->imageId );
		self::assertSame( array( 'attribute_pa_design' => 'ice' ), $d->args, 'its attributes, not the SKU' );
		self::assertSame( MatchDetail::SKU, $d->reason );
		self::assertSame( 'MUM-FUR-ICE', $d->label, 'the SKU typed' );
	}

	public function test_a_variation_sku_names_it_when_no_attribute_word_was_typed(): void {
		$facts = array(
			self::design( 701, 'Fire', 801 ),
			self::width( 701, '8' ),
			self::sku( 701, 'KZ-0041', 801 ),
			self::design( 702, 'Fire', 802 ),
			self::width( 702, '9' ),
			self::sku( 702, 'KZ-0042', 802 ),
		);
		$d     = self::explain( array( array( 'kz' ), array( '0042' ) ), array( Fields::SKU => array( 'kz', '0042', '=kz 0042' ) ), $facts, '=kz 0042' );
		self::assertSame( 702, $d->variationId, 'the whole SKU, though no word of it is an attribute value' );
		self::assertSame( 802, $d->imageId );
		self::assertSame(
			array(
				'attribute_pa_design' => 'fire',
				'attribute_pa_width'  => '9',
			),
			$d->args,
			'every value of the variation'
		);
		self::assertSame( 'KZ-0042', $d->label );
	}

	public function test_the_whole_sku_typed_wins_over_a_sku_word(): void {
		$facts = array(
			new Fact( 0, Fields::ATTRIBUTE, '', 'Colour', '', 'Red', null, array( 'red' ) ),
			self::sku( 0, 'ABC-RED' ),
			self::sku( 0, 'RED-1' ),
		);
		$d     = self::explain( array( array( 'red' ), array( '1' ) ), array( Fields::SKU => array( 'red', '1', '=red 1' ) ), $facts, '=red 1' );
		self::assertSame( MatchDetail::SKU, $d->reason );
		self::assertSame( 'RED-1', $d->label, 'not ABC-RED, the first SKU carrying red' );
	}

	public function test_the_key_of_the_product_s_own_sku_names_no_variation(): void {
		$d = self::explain( array( array( 'mum' ), array( 'fur' ) ), array( Fields::SKU => array( 'mum', 'fur', '=mum fur' ) ), self::grip(), '=mum fur' );
		self::assertSame( 0, $d->variationId, 'mum and fur are in every variation\'s SKU, and the SKU field never names one' );
		self::assertSame( array(), $d->args );
		self::assertNull( $d->imageId );
		self::assertSame( MatchDetail::SKU, $d->reason );
		self::assertSame( 'MUM-FUR', $d->label, 'the product\'s own SKU, first in order' );
	}

	public function test_a_sku_word_names_no_variation(): void {
		$d = self::explain( array( array( 'fire' ) ), array( Fields::SKU => array( 'fire' ) ), array( self::sku( 101, 'MUM-FUR-FIRE', 501 ), self::sku( 102, 'MUM-FUR-ICE', 502 ) ) );
		self::assertSame( 0, $d->variationId, 'only an attribute value or the whole SKU names a variation' );
		self::assertSame( MatchDetail::SKU, $d->reason );
		self::assertSame( 'MUM-FUR-FIRE', $d->label );
	}

	public function test_a_token_every_variation_carries_names_none(): void {
		$facts = array(
			self::width( 201, '8.25' ),
			self::width( 202, '8.25' ),
			new Fact( 0, Fields::ATTRIBUTE, '', 'Size', '', '8.25', 900, array( '8.25' ) ),
		);
		$d     = self::explain( array( array( '8.25' ) ), array( Fields::ATTRIBUTE => array( '8.25' ) ), $facts );
		self::assertSame( 0, $d->variationId );
		self::assertNull( $d->imageId, 'no variation, no photo, even when a fact of the product carries one' );
		self::assertSame( array(), $d->args );
		self::assertSame( 'Size', $d->name, 'the product\'s own value comes first when no variation is named' );
		self::assertSame( '8.25', $d->label );
	}

	public function test_the_variation_reaching_the_most_tokens_wins(): void {
		$facts = array(
			self::design( 301, 'Fire', 601 ),
			self::width( 301, '8' ),
			self::design( 302, 'Fire', 602 ),
			self::width( 302, '9' ),
			self::design( 303, 'Ice', 603 ),
			self::width( 303, '9' ),
		);
		$d     = self::explain( array( array( 'fire' ), array( '9' ) ), array( Fields::ATTRIBUTE => array( 'fire', '9' ) ), $facts );
		self::assertSame( 302, $d->variationId, 'fire and 9' );
		self::assertSame( 602, $d->imageId );
		self::assertSame(
			array(
				'attribute_pa_design' => 'fire',
				'attribute_pa_width'  => '9',
			),
			$d->args
		);
		self::assertSame( 'Fire', $d->label, 'the named variation\'s value' );
		$tie = self::explain( array( array( 'fire' ) ), array( Fields::ATTRIBUTE => array( 'fire' ) ), $facts );
		self::assertSame( 301, $tie->variationId, 'a tie goes to the first in the shop\'s order' );
		self::assertSame( array( 'attribute_pa_design' => 'fire' ), $tie->args, 'the width is left to the shopper' );
	}

	public function test_a_token_every_variation_carries_does_not_stop_the_next(): void {
		$facts = array(
			self::design( 301, 'Fire', 601 ),
			self::width( 301, '8.25' ),
			self::design( 302, 'Ice', 602 ),
			self::width( 302, '8.25' ),
		);
		$d     = self::explain( array( array( '8.25' ), array( 'ice' ) ), array( Fields::ATTRIBUTE => array( '8.25', 'ice' ) ), $facts );
		self::assertSame( 302, $d->variationId );
		self::assertSame( array( 'attribute_pa_design' => 'ice' ), $d->args, 'the width every variation carries is not chosen for the shopper' );
	}

	public function test_a_tie_goes_to_the_shop_s_order_not_the_query_s(): void {
		$d = self::explain( array( array( 'ice' ), array( 'fire' ) ), array( Fields::ATTRIBUTE => array( 'ice', 'fire' ) ), self::grip() );
		self::assertSame( 101, $d->variationId, 'Fire is first in the shop, though ice was typed first' );
	}

	public function test_a_value_without_an_argument_or_a_value_preselects_nothing(): void {
		$facts = array(
			new Fact( 401, Fields::ATTRIBUTE, '', 'Colour', 'red', 'Red', null, array( 'red' ) ),
			new Fact( 401, Fields::ATTRIBUTE, 'attribute_colour', 'Colour', '', 'Red', null, array( 'red' ) ),
			self::design( 402, 'Blue', null ),
		);
		$d     = self::explain( array( array( 'red' ) ), array( Fields::ATTRIBUTE => array( 'red' ) ), $facts );
		self::assertSame( 401, $d->variationId );
		self::assertSame( array(), $d->args );
	}

	public function test_nothing_is_said_when_every_token_is_in_the_name(): void {
		$d = self::explain( array( array( 'mumber' ), array( 'fur' ) ), array( Fields::NAME => array( 'mumber', 'fur' ), Fields::SKU => array( '=mumber fur' ), Fields::LONG => array( 'fur' ) ), array( self::sku( 0, 'MUMBER-FUR' ) ), '=mumber fur' );
		self::assertSame( '', $d->reason );
		self::assertSame( '', $d->name );
		self::assertSame( '', $d->label );
		self::assertTrue( $d->isEmpty() );
	}

	public function test_a_name_match_still_names_the_variation(): void {
		$facts = array( self::design( 101, 'Fire', 501 ), self::design( 102, 'Ice', 502 ) );
		$d     = self::explain( array( array( 'fire' ) ), array( Fields::NAME => array( 'fire' ) ), $facts );
		self::assertSame( 101, $d->variationId );
		self::assertSame( '', $d->reason, 'the card shows the name' );
		self::assertFalse( $d->isEmpty() );
	}

	public function test_the_description_says_so(): void {
		self::assertSame( MatchDetail::DESCRIPTION, self::explain( array( array( 'maple' ) ), array( Fields::SHORT => array( 'maple' ) ), array() )->reason );
		self::assertSame( MatchDetail::DESCRIPTION, self::explain( array( array( 'maple' ) ), array( Fields::LONG => array( 'maple' ) ), array() )->reason );
		$d = self::explain( array( array( 'deck' ), array( 'maple' ) ), array( Fields::NAME => array( 'deck' ), Fields::LONG => array( 'maple' ) ), array() );
		self::assertSame( MatchDetail::DESCRIPTION, $d->reason, 'the token outside the name says it' );
	}

	public function test_a_field_without_a_fact_falls_through_to_the_next(): void {
		$d = self::explain( array( array( 'kz' ) ), array( Fields::SKU => array( 'kz', '=kz' ), Fields::ATTRIBUTE => array( 'kz' ), Fields::LONG => array( 'kz' ) ), array(), '=kz' );
		self::assertSame( MatchDetail::DESCRIPTION, $d->reason, 'no SKU or value to name, so the description' );
		$e = self::explain( array( array( 'red' ) ), array( Fields::SKU => array( 'red' ), Fields::ATTRIBUTE => array( 'red' ) ), array( new Fact( 0, Fields::ATTRIBUTE, '', '', '', 'Red', null, array( 'red' ) ) ) );
		self::assertSame( MatchDetail::ATTRIBUTE, $e->reason, 'the SKU field reached, but no SKU fact: the value' );
		self::assertSame( '', $e->name );
		self::assertSame( 'Red', $e->label );
	}

	public function test_a_fact_the_field_did_not_reach_is_not_named(): void {
		$facts = array( self::sku( 0, 'RED-1' ), new Fact( 0, Fields::ATTRIBUTE, '', 'Colour', '', 'Red', null, array( 'red' ) ) );
		$d     = self::explain( array( array( 'red' ) ), array( Fields::ATTRIBUTE => array( 'red' ) ), $facts );
		self::assertSame( MatchDetail::ATTRIBUTE, $d->reason, 'the SKU carries red too, but the document\'s SKU field did not match' );
		$e = self::explain( array( array( 'red' ) ), array( Fields::LONG => array( 'red' ) ), $facts );
		self::assertSame( MatchDetail::DESCRIPTION, $e->reason, 'only the description matched' );
	}

	public function test_the_description_must_carry_the_token_outside_the_name(): void {
		$d = self::explain( array( array( 'deck' ), array( 'decks' ) ), array( Fields::NAME => array( 'deck' ), Fields::LONG => array( 'deck' ), Fields::CATEGORY => array( 'decks' ) ), array() );
		self::assertSame( '', $d->reason, 'the description carries deck, which the name shows' );
	}

	public function test_a_category_tag_or_brand_says_nothing(): void {
		$d = self::explain( array( array( 'decks' ) ), array( Fields::CATEGORY => array( 'decks' ), Fields::BRAND => array( 'decks' ) ), array() );
		self::assertSame( '', $d->reason );
		self::assertTrue( $d->isEmpty() );
		self::assertTrue( self::explain( array(), array(), self::grip() )->isEmpty(), 'no tokens, nothing to say' );
	}

	public function test_the_first_token_outside_the_name_decides(): void {
		$d = self::explain(
			array( array( 'grip' ), array( 'maple' ), array( 'fire' ) ),
			array(
				Fields::NAME      => array( 'grip' ),
				Fields::LONG      => array( 'maple' ),
				Fields::ATTRIBUTE => array( 'fire' ),
			),
			self::grip()
		);
		self::assertSame( MatchDetail::DESCRIPTION, $d->reason, 'maple comes before fire' );
		self::assertSame( 101, $d->variationId, 'fire still names the design' );
	}

	public function test_a_fact_reaches_any_of_its_terms(): void {
		$fact = self::design( 1, 'Fire', null );
		self::assertTrue( $fact->reaches( array( 'ice', 'fire' ) ) );
		self::assertFalse( $fact->reaches( array( 'ice' ) ) );
		self::assertFalse( $fact->reaches( array() ) );
	}

	public function test_a_detail_with_a_variation_or_a_reason_is_not_empty(): void {
		self::assertTrue( ( new MatchDetail() )->isEmpty() );
		self::assertFalse( ( new MatchDetail( 5 ) )->isEmpty() );
		self::assertFalse( ( new MatchDetail( 0, null, array(), MatchDetail::DESCRIPTION ) )->isEmpty() );
	}
}
