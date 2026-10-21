<?php
/**
 * Schema tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Install\Schema;
use CreativeBranding\Search\Support\Options;
use CreativeBranding\Search\Support\Tables;
use CreativeBranding\Search\Tests\Support\IntegrationTestCase;

/**
 * dbDelta() must be idempotent, the six tables InnoDB and utf8mb4, with the keys the spec names.
 */
final class SchemaTest extends IntegrationTestCase {

	public function test_second_dbdelta_reports_no_changes(): void {
		Schema::install();
		$second = Schema::install();
		self::assertSame( array(), $second, 'dbDelta would alter tables on every upgrade: ' . implode( '; ', $second ) );
	}

	public function test_six_tables_exist_with_innodb_and_utf8mb4(): void {
		global $wpdb;
		self::assertCount( 6, Tables::all() );
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
		self::assertSame( $wpdb->prefix . 'csearch_synonyms', Tables::synonyms() );
		self::assertSame( $wpdb->prefix . 'csearch_log', Tables::log() );
	}

	public function test_the_columns_the_spec_names_exist(): void {
		$expected = array(
			Tables::documents() => array( 'id', 'generation', 'type', 'object_id', 'lang', 'title', 'url', 'image_id', 'price_min', 'price_max', 'in_stock', 'visible', 'boost', 'total_sales', 'indexed_at' ),
			Tables::terms()     => array( 'id', 'term', 'df' ),
			Tables::postings()  => array( 'term_id', 'document_id', 'field', 'generation', 'tf', 'first_pos' ),
			Tables::trigrams()  => array( 'trigram', 'term_id' ),
			Tables::synonyms()  => array( 'id', 'lang', 'word', 'means', 'both_ways', 'created_by', 'created_at' ),
			Tables::log()       => array( 'id', 'query', 'normalised', 'lang', 'results', 'clicked_type', 'clicked_id', 'source', 'created_at' ),
		);
		foreach ( $expected as $table => $columns ) {
			self::assertSame( $columns, $this->columns( $table ), $table );
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
		self::assertSame( array( 'term_id', 'document_id', 'field' ), $this->indexes( Tables::postings() )['PRIMARY'], 'the postings PK answers a term lookup from the index alone' );
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
		self::assertSame( '1', Schema::DB_VERSION );
		$alloptions = wp_load_alloptions( true );
		self::assertArrayHasKey( Options::DB_VERSION, $alloptions, 'maybeUpgrade() reads it on every request' );
	}

	public function test_maybe_upgrade_runs_when_behind_and_not_when_current(): void {
		global $wpdb;
		// A column is dropped to stand in for an older schema; dbDelta() adds it back.
		update_option( Options::DB_VERSION, '0' );
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN clicked_type', Tables::log() ) );
		self::assertNotContains( 'clicked_type', $this->columns( Tables::log() ) );
		Schema::maybeUpgrade();
		self::assertSame( '1', get_option( Options::DB_VERSION ) );
		self::assertContains( 'clicked_type', $this->columns( Tables::log() ), 'the missing column came back' );

		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN clicked_type', Tables::log() ) );
		Schema::maybeUpgrade();
		self::assertNotContains( 'clicked_type', $this->columns( Tables::log() ), 'nothing runs when the version is current' );
		Schema::install();
		self::assertContains( 'clicked_type', $this->columns( Tables::log() ) );
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
