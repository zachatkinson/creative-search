<?php
/**
 * The words that name a product feature.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Domain;

use CreativeBranding\Search\Domain\FeatureFilters;
use CreativeBranding\Search\Domain\QueryToken;
use PHPUnit\Framework\TestCase;

final class FeatureFiltersTest extends TestCase {

	private const VALUES = array(
		'8.25' => array( 'pa_width' => '8-25' ),
		'8.5'  => array( 'pa_width' => '8-50' ),
		'54mm' => array( 'pa_diameter' => '54mm' ),
		'8'    => array(
			'pa_width' => '8-00',
			'pa_size'  => '8',
		),
	);

	public function test_a_word_naming_one_value_becomes_its_filter(): void {
		$deck  = new QueryToken( 'deck', 'deck' );
		$split = FeatureFilters::split( array( new QueryToken( '8.25', '8.25' ), $deck ), self::VALUES );
		self::assertSame( array( 'pa_width' => array( '8-25' ) ), $split['filters'] );
		self::assertSame( array( $deck ), $split['rest'] );
	}

	public function test_several_values_of_one_attribute_and_of_two(): void {
		$split = FeatureFilters::split(
			array( new QueryToken( '8.25', '8.25' ), new QueryToken( '54mm', '54mm' ), new QueryToken( '8.5', '8.5' ), new QueryToken( '8.25', '8.25' ) ),
			self::VALUES
		);
		self::assertSame(
			array(
				'pa_width'    => array( '8-25', '8-50' ),
				'pa_diameter' => array( '54mm' ),
			),
			$split['filters'],
			'in the order typed, once each'
		);
		self::assertSame( array(), $split['rest'] );
	}

	public function test_a_minus_word_a_phrase_word_an_ambiguous_word_and_an_unknown_word_stay_words(): void {
		$tokens = array(
			new QueryToken( '8.25', '8.25', negated: true ),
			new QueryToken( '8.5', '8.5', phrase: 1 ),
			new QueryToken( '8', '8' ),
			new QueryToken( 'wheels', 'wheels' ),
		);
		$split  = FeatureFilters::split( $tokens, self::VALUES );
		self::assertSame( array(), $split['filters'] );
		self::assertSame( $tokens, $split['rest'] );
		self::assertSame( array(), FeatureFilters::split( array(), self::VALUES )['rest'] );
	}
}
