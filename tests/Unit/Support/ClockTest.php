<?php
/**
 * Clock tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Support;

use CreativeBranding\Search\Support\Clock;
use PHPUnit\Framework\TestCase;

/**
 * UTC, freezable.
 */
final class ClockTest extends TestCase {

	protected function tearDown(): void {
		Clock::freeze( null );
	}

	public function test_frozen_clock_formats_in_utc(): void {
		Clock::freeze( 1_792_000_000 );
		self::assertSame( 1_792_000_000, Clock::timestamp() );
		self::assertSame( '2026-10-14 17:46:40', Clock::now() );
		self::assertSame( '1970-01-01 00:00:00', Clock::format( 0 ) );
	}

	public function test_unfrozen_clock_is_now(): void {
		$before = time();
		$ts     = Clock::timestamp();
		self::assertGreaterThanOrEqual( $before, $ts );
		self::assertLessThanOrEqual( time(), $ts );
	}
}
