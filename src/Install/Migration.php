<?php
/**
 * Schema migrations dbDelta() cannot make.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Install;

use CreativeBranding\Search\Support\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The steps of an upgrade that `dbDelta()` cannot take: it adds columns and keys but never replaces a primary
 * key, and it fills nothing in. Each step looks at the table before it acts, so an upgrade cut off halfway (a
 * time limit, a crash) resumes where it stopped (D-090), and `complete()` says whether the tables are the current
 * schema, which `Schema::install()` checks before it records the version.
 */
final class Migration {

	/**
	 * Documents per backfill statement.
	 */
	public const BATCH = 5000;

	/**
	 * The schema 2 primary key of the postings (D-087), as the ALTER below and `Schema::sql()` write it.
	 */
	public const POSTINGS_KEY = array( 'term_id', 'generation', 'lang', 'product', 'document_id', 'field' );

	/**
	 * Schema 2, before `dbDelta()`: give the postings their document's language and kind, filled in from the
	 * documents a range at a time, and lead the primary key with term, generation, language and kind.
	 */
	public static function postingsToTwo(): void {
		global $wpdb;
		$postings = Tables::postings();
		if ( ! self::tableExists( $postings ) ) {
			return;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- a schema migration; nothing to cache.
		if ( ! self::columnExists( $postings, 'lang' ) ) {
			$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN lang varchar(8) NOT NULL DEFAULT '' AFTER generation, ADD COLUMN product tinyint(1) NOT NULL DEFAULT 0 AFTER lang", $postings ) );
		}
		$last = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(document_id) FROM %i WHERE lang = %s', $postings, '' ) );
		for ( $from = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(document_id) FROM %i WHERE lang = %s', $postings, '' ) ); $from > 0 && $from <= $last; $from += self::BATCH ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE %i p INNER JOIN %i d ON d.id = p.document_id SET p.lang = d.lang, p.product = (d.type = %s) WHERE p.document_id BETWEEN %d AND %d AND p.lang = %s', $postings, Tables::documents(), 'product', $from, $from + self::BATCH - 1, '' ) );
			Upgrader::heartbeat();
		}
		if ( self::POSTINGS_KEY !== self::primaryKey( $postings ) ) {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP PRIMARY KEY, ADD PRIMARY KEY (term_id,generation,lang,product,document_id,field)', $postings ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Schema 2, after `dbDelta()` has created `csearch_term_stats`: count each term's documents per generation
	 * and language from the postings, replacing whatever the table holds (the indexer does not write while the
	 * upgrade is due, D-090, so the count is not raced).
	 */
	public static function statsToTwo(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- a schema migration; nothing to cache.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Tables::stats() ) );
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (term_id, generation, lang, df) SELECT term_id, generation, lang, COUNT(DISTINCT document_id) FROM %i GROUP BY term_id, generation, lang', Tables::stats(), Tables::postings() ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Whether the tables are the current schema: the postings keyed and filled in, the term counts there (2), the
	 * facts table and the documents' cart flag there (3, D-104, D-106; `dbDelta()` adds both, and the rebuild the
	 * upgrade owes fills them).
	 */
	public static function complete(): bool {
		global $wpdb;
		$postings = Tables::postings();
		return self::tableExists( Tables::stats() )
			&& self::tableExists( Tables::facts() )
			&& self::columnExists( Tables::documents(), 'cart' )
			&& self::columnExists( Tables::documents(), 'name_key' )
			&& self::columnExists( $postings, 'lang' )
			&& self::POSTINGS_KEY === self::primaryKey( $postings )
			&& null === $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE lang = %s LIMIT 1', $postings, '' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check.
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Table name.
	 */
	private static function tableExists( string $table ): bool {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema introspection.
	}

	/**
	 * Whether a table has a column.
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 */
	private static function columnExists( string $table, string $column ): bool {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s', $table, $column ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema introspection.
	}

	/**
	 * A table's primary key columns, in order.
	 *
	 * @param string $table Table name.
	 * @return string[]
	 */
	private static function primaryKey( string $table ): array {
		global $wpdb;
		$columns = $wpdb->get_col( $wpdb->prepare( 'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s ORDER BY SEQ_IN_INDEX', $table, 'PRIMARY' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema introspection.
		return array_map( 'strval', $columns );
	}
}
