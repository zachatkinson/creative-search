<?php
/**
 * Base repository.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one place `$wpdb` is called. Every statement arrives prepared (`%i` for identifiers, placeholders for
 * values, identifier lists from integers only); the helpers here only run it and type the answer.
 */
abstract class Repository {

	/**
	 * Run a prepared statement.
	 *
	 * @param string $prepared A statement from `$wpdb->prepare()`.
	 * @return int Rows affected.
	 */
	protected function run( string $prepared ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared by the caller; the index tables are the plugin's own and are never cached.
		$result = $wpdb->query( $prepared );
		return is_int( $result ) ? $result : 0;
	}

	/**
	 * Rows of a prepared statement, as associative arrays of strings.
	 *
	 * @param string $prepared A statement from `$wpdb->prepare()`.
	 * @return array<int, array<string, string|null>>
	 */
	protected function rows( string $prepared ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared by the caller.
		$rows = $wpdb->get_results( $prepared, ARRAY_A );
		/** @var array<int, array<string, string|null>> $rows */
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One value of a prepared statement.
	 *
	 * @param string $prepared A statement from `$wpdb->prepare()`.
	 */
	protected function value( string $prepared ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared by the caller.
		$value = $wpdb->get_var( $prepared );
		return null === $value ? null : (string) $value;
	}

	/**
	 * The last insert id.
	 */
	protected function insertId(): int {
		global $wpdb;
		return (int) $wpdb->insert_id;
	}

	/**
	 * Prepare a statement.
	 *
	 * @param string $sql  SQL with placeholders.
	 * @param mixed  ...$args Values.
	 */
	protected function prepare( string $sql, ...$args ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- this is the prepare call.
		return $wpdb->prepare( $sql, ...$args );
	}

	/**
	 * `%d, %d, %d` for a list of integers.
	 *
	 * @param int[] $ids Integers.
	 */
	protected static function ints( array $ids ): string {
		return implode( ',', array_map( 'intval', $ids ) );
	}

	/**
	 * `%s, %s, %s` for a list of strings, to be prepared with the strings.
	 *
	 * @param int $n How many.
	 */
	protected static function strings( int $n ): string {
		return implode( ',', array_fill( 0, max( 1, $n ), '%s' ) );
	}
}
