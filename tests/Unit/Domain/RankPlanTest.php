<?php
/**
 * The ranking plan.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Domain;

use CreativeBranding\Search\Domain\Query;
use CreativeBranding\Search\Domain\QueryToken;
use CreativeBranding\Search\Domain\RankPlan;
use CreativeBranding\Search\Domain\SearchOptions;
use CreativeBranding\Search\Domain\TermMatch;
use CreativeBranding\Search\Domain\TokenWidening;
use PHPUnit\Framework\TestCase;

final class RankPlanTest extends TestCase {

	public function test_a_plan_carries_the_query_the_widenings_and_the_options(): void {
		$query   = new Query( 'kazumi 825', 'en', array( new QueryToken( 'kazumi', 'kazumi' ), new QueryToken( '825', '825' ) ) );
		$deck    = new TermMatch( 'kazumi', TermMatch::EXACT, 3 );
		$number  = new TermMatch( '825', TermMatch::EXACT, 7 );
		$prefix  = new TermMatch( 'kazumi', TermMatch::PREFIX, 3 );
		$stem    = new TermMatch( 'deck', TermMatch::STEM, 5 );
		$options = new SearchOptions( array( 'name' => 4 ), false, 'en', array( 'brand:3' => 2 ), 12 );
		$plan    = RankPlan::of(
			$query,
			array(
				new TokenWidening( array( 'kazumi' => $deck ), array(), array() ),
				new TokenWidening( array( '825' => $number, 'kazumi' => $prefix, 'deck' => $stem ), array(), array() ),
			),
			'fr',
			99,
			array( 'dealer' ),
			$options
		);
		self::assertSame( 'fr', $plan->lang );
		self::assertSame( array( 'kazumi', '825' ), $plan->texts );
		self::assertSame( array( array( 'kazumi' => $deck ), array( '825' => $number, 'kazumi' => $prefix, 'deck' => $stem ) ), $plan->tokens );
		self::assertSame( $options->weightsById(), $plan->weights );
		self::assertSame( 99, $plan->n );
		self::assertSame( array( 'dealer' ), $plan->excluded );
		self::assertSame( 'kazumi 825', $plan->key );
		self::assertFalse( $plan->hiddenBySku );
		self::assertSame( array( 'brand:3' => 2 ), $plan->boostRules );
		self::assertSame( 12, $plan->limit );
		self::assertSame( array( 'kazumi', '825', 'deck' ), $plan->terms(), 'each term once, numbers as strings, in order' );
		self::assertSame( log( 100 / 4 ), $plan->idf( 3 ) );
	}
}
