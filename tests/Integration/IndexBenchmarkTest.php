<?php
/**
 * Index timing.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Index\IndexState;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Support\Tables;
use CreativeBranding\Search\Tests\Fixtures\Catalogue;
use CreativeBranding\Search\Tests\Support\IndexTestCase;

/**
 * The spec's indexing budgets: a full reindex of 10,000 products within 5 minutes on the CI runner, one
 * product within 50 ms. The catalogue size comes from `CSEARCH_BENCH_PRODUCTS` (default 300, the shop's
 * size); the reindex budget scales linearly from the spec's figure, 30 ms a product, so a 300-product run
 * must finish within 9 s. Run with `composer test:integration:benchmark`.
 *
 * @group benchmark
 */
final class IndexBenchmarkTest extends IndexTestCase {

	public function test_full_reindex_within_budget(): void {
		$count = max( 1, (int) ( getenv( 'CSEARCH_BENCH_PRODUCTS' ) ?: 300 ) );
		$start = hrtime( true );
		$this->catalogue->load( ( new Catalogue() )->generate( $count ) );
		$this->service( \CreativeBranding\Search\Index\IndexQueue::class )->clear();
		$loaded = ( hrtime( true ) - $start ) / 1e9;

		$start = hrtime( true );
		self::assertTrue( $this->service( Reindex::class )->runInline() );
		$seconds = ( hrtime( true ) - $start ) / 1e9;
		$budget  = 300.0 * $count / 10000;

		$state = $this->service( IndexState::class )->all();
		fwrite(
			STDERR,
			sprintf(
				"\n[benchmark] %d products (loaded in %.1f s): reindex %.2f s (budget %.1f s), %.1f ms a product; %d documents, %d postings, %d terms; %d errors\n",
				$count,
				$loaded,
				$seconds,
				$budget,
				1000 * $seconds / $count,
				$this->rowCount( Tables::documents() ),
				$this->rowCount( Tables::postings() ),
				$this->rowCount( Tables::terms() ),
				count( (array) $state[ IndexState::ERRORS ] )
			)
		);
		self::assertSame( array(), $state[ IndexState::ERRORS ] );
		self::assertSame( $count, $state[ IndexState::DONE ] );
		self::assertLessThan( $budget, $seconds );
	}
}
