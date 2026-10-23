<?php
/**
 * Fields tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Domain;

use CreativeBranding\Search\Domain\Fields;
use PHPUnit\Framework\TestCase;

/**
 * The field ids are the spec's, the names round-trip, the default weights are the spec table.
 */
final class FieldsTest extends TestCase {

	public function test_ids_are_the_spec_data_model(): void {
		self::assertSame( 1, Fields::NAME );
		self::assertSame( 2, Fields::SKU );
		self::assertSame( 3, Fields::SHORT );
		self::assertSame( 4, Fields::LONG );
		self::assertSame( 5, Fields::CATEGORY );
		self::assertSame( 6, Fields::TAG );
		self::assertSame( 7, Fields::BRAND );
		self::assertSame( 8, Fields::ATTRIBUTE );
		self::assertSame( 9, Fields::CUSTOM, 'the owner\'s custom fields (D-119)' );
		self::assertSame( range( 1, 9 ), array_keys( Fields::NAMES ) );
	}

	public function test_names_and_ids_round_trip(): void {
		foreach ( Fields::NAMES as $id => $name ) {
			self::assertSame( $id, Fields::id( $name ) );
			self::assertSame( $name, Fields::name( $id ) );
		}
		self::assertSame( 0, Fields::id( 'colour' ) );
		self::assertSame( '', Fields::name( 10 ) );
		self::assertSame( '', Fields::name( 0 ) );
	}

	public function test_default_weights_are_the_spec_table(): void {
		self::assertSame(
			array(
				'name'      => 10,
				'sku'       => 10,
				'brand'     => 6,
				'category'  => 5,
				'attribute' => 5,
				'tag'       => 3,
				'short'     => 2,
				'long'      => 1,
				'custom'    => 4,
			),
			Fields::DEFAULT_WEIGHTS
		);
	}

	public function test_weights_by_id_take_overrides_and_fill_defaults(): void {
		$weights = Fields::weightsById(
			array(
				'name'   => 20,
				'long'   => 0,
				'colour' => 7,
			)
		);
		self::assertSame(
			array(
				Fields::NAME      => 20,
				Fields::SKU       => 10,
				Fields::SHORT     => 2,
				Fields::LONG      => 0,
				Fields::CATEGORY  => 5,
				Fields::TAG       => 3,
				Fields::BRAND     => 6,
				Fields::ATTRIBUTE => 5,
				Fields::CUSTOM    => 4,
			),
			$weights
		);
		self::assertSame( Fields::DEFAULT_WEIGHTS['tag'], Fields::weightsById( array() )[ Fields::TAG ] );
	}
}
