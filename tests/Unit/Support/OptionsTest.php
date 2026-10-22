<?php
/**
 * Options name tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Support;

use CreativeBranding\Search\Support\Options;
use PHPUnit\Framework\TestCase;

/**
 * Every stored name carries the plugin prefix (spec: Name).
 */
final class OptionsTest extends TestCase {

	public function test_every_name_is_prefixed(): void {
		foreach ( Options::names() as $name ) {
			self::assertStringStartsWith( 'csearch_', $name );
		}
		foreach ( Options::metaKeys() as $key ) {
			self::assertStringStartsWith( '_csearch_', $key );
		}
		self::assertSame( 'csearch_', Options::TRANSIENT_PREFIX );
		self::assertSame( 'csearch_stopwords_', Options::STOPWORDS_PREFIX );
		self::assertSame( 'csearch', Options::CACHE_GROUP );
		self::assertSame( 'csearch', Options::QUEUE_GROUP );
	}

	public function test_the_spec_names_are_present(): void {
		self::assertSame( array( 'csearch_settings', 'csearch_db_version', 'csearch_index_state', 'csearch_upgrade_lock', 'csearch_reindex_cancel' ), Options::names() );
		self::assertSame( array( '_csearch_boost', '_csearch_exclude' ), Options::metaKeys() );
	}
}
