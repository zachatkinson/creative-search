<?php
/**
 * Schema tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Index\IndexQueue;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Install\Activator;
use CreativeBranding\Search\Install\Migration;
use CreativeBranding\Search\Install\Schema;
use CreativeBranding\Search\Install\Upgrader;
use CreativeBranding\Search\Support\Clock;
use CreativeBranding\Search\Support\Options;
use CreativeBranding\Search\Support\Tables;
use CreativeBranding\Search\Tests\Support\IntegrationTestCase;

/**
 * dbDelta() must be idempotent, the seven tables InnoDB and utf8mb4, with the keys the spec names (and D-087's).
 */
final class SchemaTest extends IntegrationTestCase {

	public function test_second_dbdelta_reports_no_changes(): void {
		Schema::install();
		$second = Schema::install();
		self::assertSame( array(), $second, 'dbDelta would alter tables on every upgrade: ' . implode( '; ', $second ) );
	}

	public function test_seven_tables_exist_with_innodb_and_utf8mb4(): void {
		global $wpdb;
		self::assertCount( 7, Tables::all() );
		foreach ( Tables::all() as $table ) {
			$name = Tables::name( $table );
			$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $name ), ARRAY_A );
			self::assertIsArray( $row, $name . ' exists' );
			self::assertSame( 'InnoDB', $row['ENGINE'], $name );
			self::assertStringStartsWith( 'utf8mb4', (string) $row['TABLE_COLLATION'], $name );
		}
	}

	public function test_table_names_carry_the_prefix(): void {
		global $wpdb;
		self::assertSame( $wpdb->prefix . 'csearch_documents', Tables::documents() );
		self::assertSame( $wpdb->prefix . 'csearch_terms', Tables::terms() );
		self::assertSame( $wpdb->prefix . 'csearch_postings', Tables::postings() );
		self::assertSame( $wpdb->prefix . 'csearch_trigrams', Tables::trigrams() );
		self::assertSame( $wpdb->prefix . 'csearch_term_stats', Tables::stats() );
		self::assertSame( $wpdb->prefix . 'csearch_synonyms', Tables::synonyms() );
		self::assertSame( $wpdb->prefix . 'csearch_log', Tables::log() );
	}

	public function test_the_columns_the_spec_names_exist(): void {
		$expected = array(
			Tables::documents() => array( 'id', 'generation', 'type', 'object_id', 'lang', 'title', 'name_key', 'url', 'image_id', 'price_min', 'price_max', 'in_stock', 'visible', 'boost', 'total_sales', 'indexed_at' ),
			Tables::terms()     => array( 'id', 'term', 'df' ),
			Tables::postings()  => array( 'term_id', 'document_id', 'field', 'generation', 'lang', 'product', 'tf', 'first_pos' ),
			Tables::trigrams()  => array( 'trigram', 'term_id' ),
			Tables::stats()     => array( 'term_id', 'generation', 'lang', 'df' ),
			Tables::synonyms()  => array( 'id', 'lang', 'word', 'means', 'both_ways', 'created_by', 'created_at' ),
			Tables::log()       => array( 'id', 'query', 'normalised', 'lang', 'results', 'clicked_type', 'clicked_id', 'source', 'created_at' ),
		);
		foreach ( $expected as $table => $columns ) {
			// dbDelta() appends a column an upgrade adds (name_key, schema 2), so an upgraded table orders them differently.
			self::assertEqualsCanonicalizing( $columns, $this->columns( $table ), $table );
		}
	}

	public function test_no_json_or_enum_columns(): void {
		global $wpdb;
		foreach ( Tables::all() as $table ) {
			$types = $wpdb->get_col( $wpdb->prepare( 'SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', Tables::name( $table ) ) );
			foreach ( $types as $type ) {
				self::assertNotContains( strtolower( (string) $type ), array( 'json', 'enum', 'set' ), Tables::name( $table ) );
			}
		}
	}

	public function test_indexes_the_spec_names_exist(): void {
		$expected = array(
			Tables::documents() => array( 'PRIMARY', 'object', 'generation_lang' ),
			Tables::terms()     => array( 'PRIMARY', 'term' ),
			Tables::postings()  => array( 'PRIMARY', 'document_id', 'generation' ),
			Tables::trigrams()  => array( 'PRIMARY' ),
			Tables::stats()     => array( 'PRIMARY' ),
			Tables::synonyms()  => array( 'PRIMARY', 'lang_word', 'lang_means' ),
			Tables::log()       => array( 'PRIMARY', 'created_at', 'normalised' ),
		);
		foreach ( $expected as $table => $keys ) {
			$names = array_keys( $this->indexes( $table ) );
			foreach ( $keys as $key ) {
				self::assertContains( $key, $names, "{$table} lacks index {$key}" );
			}
		}
	}

	public function test_primary_keys_are_the_spec_s(): void {
		self::assertSame( array( 'term_id', 'generation', 'lang', 'product', 'document_id', 'field' ), $this->indexes( Tables::postings() )['PRIMARY'], 'the postings PK answers a term lookup in one generation, language and kind from the index alone (D-087)' );
		self::assertSame( array( 'term_id', 'generation', 'lang' ), $this->indexes( Tables::stats() )['PRIMARY'] );
		self::assertSame( array( 'trigram', 'term_id' ), $this->indexes( Tables::trigrams() )['PRIMARY'] );
		self::assertSame( array( 'generation', 'type', 'object_id', 'lang' ), $this->indexes( Tables::documents() )['object'], 'D-012' );
	}

	public function test_unique_keys_are_unique(): void {
		global $wpdb;
		foreach ( array( array( Tables::documents(), 'object' ), array( Tables::terms(), 'term' ) ) as list( $table, $key ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, $key ), ARRAY_A );
			self::assertNotEmpty( $rows, "{$table}.{$key}" );
			self::assertSame( '0', (string) $rows[0]['Non_unique'], "{$table}.{$key} must be unique" );
		}
	}

	public function test_db_version_option_is_stored_and_autoloaded(): void {
		Schema::install();
		self::assertSame( Schema::DB_VERSION, get_option( Options::DB_VERSION ) );
		self::assertSame( '2', Schema::DB_VERSION );
		$alloptions = wp_load_alloptions( true );
		self::assertArrayHasKey( Options::DB_VERSION, $alloptions, 'the upgrader compares it on admin requests' );
	}

	public function test_maybe_upgrade_runs_when_behind_and_not_when_current(): void {
		global $wpdb;
		// A column is dropped to stand in for an older schema; dbDelta() adds it back.
		update_option( Options::DB_VERSION, '0' );
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN clicked_type', Tables::log() ) );
		self::assertNotContains( 'clicked_type', $this->columns( Tables::log() ) );
		self::assertTrue( Upgrader::maybeUpgrade() );
		self::assertSame( Schema::DB_VERSION, get_option( Options::DB_VERSION ) );
		self::assertFalse( get_option( Options::UPGRADE_LOCK ), 'the lock is released' );
		self::assertContains( 'clicked_type', $this->columns( Tables::log() ), 'the missing column came back' );

		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN clicked_type', Tables::log() ) );
		self::assertFalse( Upgrader::maybeUpgrade() );
		self::assertNotContains( 'clicked_type', $this->columns( Tables::log() ), 'nothing runs when the version is current' );
		Schema::install();
		self::assertContains( 'clicked_type', $this->columns( Tables::log() ) );
		// dbDelta() appends the column; the DDL committed, so put it back where the next run expects it.
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i MODIFY clicked_type varchar(16) NULL AFTER results', Tables::log() ) );
	}

	public function test_upgrade_waits_for_a_held_lock_and_takes_over_a_stale_one(): void {
		update_option( Options::DB_VERSION, '0' );
		Clock::freeze( 1_800_000_000 );
		add_option( Options::UPGRADE_LOCK, 1_800_000_000 - 10, '', false );
		self::assertFalse( Upgrader::maybeUpgrade(), 'another request holds the lock' );
		self::assertSame( '0', get_option( Options::DB_VERSION ) );
		update_option( Options::UPGRADE_LOCK, 1_800_000_000 - Upgrader::LOCK_TTL - 1, false );
		self::assertTrue( Upgrader::maybeUpgrade(), 'a lock older than five minutes is taken over' );
		self::assertSame( Schema::DB_VERSION, get_option( Options::DB_VERSION ) );
	}

	public function test_schema_one_postings_are_migrated_in_place_and_counted(): void {
		global $wpdb;
		$this->schemaOneWithRows();
		update_option( Options::DB_VERSION, '1' );
		self::assertTrue( Upgrader::maybeUpgrade() );

		self::assertSame( array( 'term_id', 'generation', 'lang', 'product', 'document_id', 'field' ), $this->indexes( Tables::postings() )['PRIMARY'] );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT document_id, lang, product FROM %i ORDER BY document_id, field', Tables::postings() ), ARRAY_A );
		self::assertSame(
			array(
				array( 'document_id' => '1', 'lang' => 'en', 'product' => '1' ),
				array( 'document_id' => '1', 'lang' => 'en', 'product' => '1' ),
				array( 'document_id' => '2', 'lang' => 'fr', 'product' => '1' ),
				array( 'document_id' => '3', 'lang' => 'en', 'product' => '0' ),
			),
			$rows,
			'each posting took its document\'s language and kind'
		);
		$stats = $wpdb->get_results( $wpdb->prepare( 'SELECT term_id, generation, lang, df FROM %i ORDER BY term_id, lang', Tables::stats() ), ARRAY_A );
		self::assertSame(
			array(
				array( 'term_id' => '7', 'generation' => '1', 'lang' => 'en', 'df' => '2' ),
				array( 'term_id' => '7', 'generation' => '1', 'lang' => 'fr', 'df' => '1' ),
			),
			$stats,
			'a document counts once however many fields carry the term; each language apart'
		);
		self::assertSame( array(), Schema::install(), 'the migrated tables are what a new install creates' );
		self::assertTrue( IndexQueue::isPending( Reindex::START ), 'a schema 1 index is rebuilt for its name keys' );
	}

	public function test_migration_steps_change_nothing_the_second_time(): void {
		global $wpdb;
		$this->schemaOneWithRows();
		update_option( Options::DB_VERSION, '1' );
		Schema::install();
		$before = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY term_id, lang', Tables::stats() ), ARRAY_A );
		Migration::postingsToTwo();
		Migration::statsToTwo();
		self::assertSame( $before, $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY term_id, lang', Tables::stats() ), ARRAY_A ) );
		self::assertSame( 4, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Tables::postings() ) ) );
	}

	public function test_a_migration_cut_off_halfway_resumes(): void {
		global $wpdb;
		$this->schemaOneWithRows();
		update_option( Options::DB_VERSION, '1' );
		// The first attempt added the columns and filled in one document before it died; the old key is still there.
		$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN lang varchar(8) NOT NULL DEFAULT '' AFTER generation, ADD COLUMN product tinyint(1) NOT NULL DEFAULT 0 AFTER lang", Tables::postings() ) );
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET lang = 'en', product = 1 WHERE document_id = 1", Tables::postings() ) );
		self::assertFalse( Migration::complete() );

		self::assertTrue( Upgrader::maybeUpgrade() );
		self::assertTrue( Migration::complete() );
		self::assertSame( '2', get_option( Options::DB_VERSION ) );
		self::assertSame( array( 'term_id', 'generation', 'lang', 'product', 'document_id', 'field' ), $this->indexes( Tables::postings() )['PRIMARY'] );
		self::assertSame( array( 'en', 'fr', 'en' ), $wpdb->get_col( $wpdb->prepare( 'SELECT lang FROM %i WHERE field = 1 ORDER BY document_id', Tables::postings() ) ) );
	}

	public function test_the_version_waits_until_the_tables_are_schema_two(): void {
		$this->schemaOneWithRows();
		update_option( Options::DB_VERSION, '1' );
		$fail = static fn( $sql ) => is_string( $sql ) && str_contains( $sql, 'DROP PRIMARY KEY' ) ? 'SELECT 1' : $sql;
		add_filter( 'query', $fail ); // The key swap is cut off, so dbDelta's ADD PRIMARY KEY fails.
		$quiet = $GLOBALS['wpdb']->suppress_errors( true );
		self::assertTrue( Upgrader::maybeUpgrade() );
		$GLOBALS['wpdb']->suppress_errors( $quiet );
		remove_filter( 'query', $fail );
		self::assertSame( '1', get_option( Options::DB_VERSION ), 'not recorded: the key is still schema 1' );
		self::assertTrue( Upgrader::needed() );
		self::assertFalse( get_option( Options::REBUILD_OWED ), 'no rebuild is owed before the upgrade is done' );

		self::assertTrue( Upgrader::maybeUpgrade(), 'the next request finishes it' );
		self::assertSame( '2', get_option( Options::DB_VERSION ) );
		self::assertSame( '0', (string) get_option( Options::REBUILD_OWED ) );
	}

	public function test_a_request_with_a_stale_version_does_not_upgrade_again(): void {
		global $wpdb;
		// This request loaded version 1; another finished the upgrade since.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $wpdb->options, Schema::DB_VERSION, Options::DB_VERSION ) );
		$alloptions                         = wp_load_alloptions();
		$alloptions[ Options::DB_VERSION ] = '1';
		wp_cache_set( 'alloptions', $alloptions, 'options' );
		self::assertTrue( Upgrader::needed() );
		$installs = 0;
		$count    = static function ( $sql ) use ( &$installs ) {
			if ( is_string( $sql ) && str_contains( $sql, 'INSERT INTO' ) && str_contains( $sql, Tables::stats() ) ) {
				++$installs;
			}
			return $sql;
		};
		add_filter( 'query', $count );
		self::assertFalse( Upgrader::maybeUpgrade() );
		remove_filter( 'query', $count );
		self::assertSame( 0, $installs, 'the counts were not remade' );
		self::assertFalse( Upgrader::needed() );
		self::assertFalse( get_option( Options::UPGRADE_LOCK ) );
		self::assertFalse( get_option( Options::REBUILD_OWED ) );
	}

	public function test_activation_does_not_migrate_beside_a_running_upgrade(): void {
		$this->schemaOneWithRows();
		update_option( Options::DB_VERSION, '1' );
		add_option( Options::UPGRADE_LOCK, Clock::timestamp(), '', false );
		Activator::site();
		self::assertNotContains( 'lang', $this->columns( Tables::postings() ), 'the lock holder migrates, not the activation' );
		delete_option( Options::UPGRADE_LOCK );
		Activator::site();
		self::assertTrue( Migration::complete() );
	}

	public function test_column_types_are_lower_case(): void {
		self::assertDoesNotMatchRegularExpression( '/\b(VARCHAR|DATETIME|LONGTEXT|TEXT|INT|BIGINT|SMALLINT|TINYINT|DECIMAL|CHAR)\b/', Schema::sql(), 'core writes its schema in lower case; dbDelta compares the query side lowercased' );
	}

	public function test_sql_contains_each_table_once(): void {
		foreach ( Tables::all() as $table ) {
			self::assertSame( 1, substr_count( Schema::sql(), 'CREATE TABLE ' . Tables::name( $table ) . ' ' ), $table );
		}
	}

	/**
	 * Put the postings table back in its schema 1 shape (no language or kind; the old primary key) with rows
	 * for an English and a French product and an English category, all carrying term 7, and empty the stats.
	 */
	private function schemaOneWithRows(): void {
		global $wpdb;
		// ALTER, not DROP and CREATE: the core test case turns those into temporary tables.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Tables::postings() ) );
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP PRIMARY KEY, DROP COLUMN lang, DROP COLUMN product, ADD PRIMARY KEY (term_id,document_id,field)', Tables::postings() ) );
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Tables::stats() ) );
		foreach ( array( array( 1, 'product', 'en' ), array( 2, 'product', 'fr' ), array( 3, 'category', 'en' ) ) as [ $id, $type, $lang ] ) {
			$wpdb->insert( Tables::documents(), array( 'id' => $id, 'generation' => 1, 'type' => $type, 'object_id' => $id, 'lang' => $lang, 'title' => 'x', 'url' => 'x', 'indexed_at' => '2026-10-22 00:00:00' ) );
		}
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (term_id, document_id, field, generation) VALUES (7, 1, 1, 1), (7, 1, 2, 1), (7, 2, 1, 1), (7, 3, 1, 1)', Tables::postings() ) );
	}

	/**
	 * Column names of a table, in order.
	 *
	 * @param string $table Table name.
	 * @return string[]
	 */
	private function columns( string $table ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
		return array_map( static fn( array $row ): string => (string) $row['Field'], $rows );
	}

	/**
	 * Indexes of a table: name => columns in sequence.
	 *
	 * @param string $table Table name.
	 * @return array<string, string[]>
	 */
	private function indexes( string $table ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['Key_name'] ][ (int) $row['Seq_in_index'] - 1 ] = (string) $row['Column_name'];
		}
		foreach ( $out as &$columns ) {
			ksort( $columns );
			$columns = array_values( $columns );
		}
		return $out;
	}
}
