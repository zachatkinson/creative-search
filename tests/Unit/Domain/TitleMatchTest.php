<?php
/**
 * Title match tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Domain;

use CreativeBranding\Search\Domain\Fields;
use CreativeBranding\Search\Domain\TitleMatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A page is suggested in the panel only by its title (D-137).
 */
final class TitleMatchTest extends TestCase {

	/**
	 * Fields by name here: a data provider runs before coverage starts, so it must not load `Fields`.
	 *
	 * @return array<string, array{array<int, string[]>, array<string, string[]>, bool}>
	 */
	public static function cases(): array {
		return array(
			'one word in the title'          => array( array( array( 'hood', 'hoodie' ) ), array( 'name' => array( 'hoodie' ) ), true ),
			'only in the content'            => array( array( array( 'hood' ) ), array( 'long' => array( 'hood' ) ), false ),
			'every word in the title'        => array( array( array( 'wheel' ), array( 'guide' ) ), array( 'name' => array( 'guide', 'wheel' ) ), true ),
			'one word only in the content'   => array( array( array( 'wheel' ), array( 'hood' ) ), array( 'name' => array( 'wheel' ), 'long' => array( 'hood' ) ), false ),
			'the last word missing'          => array( array( array( 'wheel' ), array( 'hood' ) ), array( 'name' => array( 'wheel' ) ), false ),
			'no tokens'                      => array( array(), array( 'name' => array( 'wheel' ) ), false ),
			'a token that reached nothing'   => array( array( array() ), array( 'name' => array( 'wheel' ) ), false ),
			'no title terms'                 => array( array( array( 'wheel' ) ), array(), false ),
			'an empty title list'            => array( array( array( 'wheel' ) ), array( 'name' => array() ), false ),
		);
	}

	/**
	 * @param array<int, string[]> $tokens   Terms each token reached.
	 * @param array<string, string[]> $fields   The document's matched terms by field name.
	 * @param bool                 $expected Whether the title covers the query.
	 */
	#[DataProvider( 'cases' )]
	public function test_every_token_must_reach_the_title( array $tokens, array $fields, bool $expected ): void {
		$ids = array(
			'name' => Fields::NAME,
			'long' => Fields::LONG,
		);
		$byId = array();
		foreach ( $fields as $name => $terms ) {
			$byId[ $ids[ $name ] ] = $terms;
		}
		self::assertSame( $expected, TitleMatch::covers( $tokens, $byId ) );
	}
}
