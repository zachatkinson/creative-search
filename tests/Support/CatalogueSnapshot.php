<?php
/**
 * A loaded catalogue, kept as a file for the next run.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Support;

/**
 * The rows a catalogue load writes, dumped to a gzipped file and inserted again by the next run (D-148), so CI
 * pays for loading 10,000 products through WooCommerce (about 42 minutes on a runner) only when the catalogue,
 * its loader, or WordPress and WooCommerce change. Opt-in: `CSEARCH_BENCH_SNAPSHOT` names the file (relative to
 * the plugin's root); without it the benchmark loads through WooCommerce as before.
 *
 * Only what the load added is kept: the rows of each table above the highest id it held before the load, the
 * relationships and WooCommerce lookups of the new products, and every term's count (the load recounts the
 * built-in product types and visibility terms too). The rows go back with their own ids, so the dump is used only
 * when no row in the way exists, and only when its key (the catalogue, the loader, this class, the product count,
 * the WordPress and WooCommerce versions) is this run's; otherwise the test loads as usual and writes a new one.
 * Both happen inside the test's transaction, so the database is left as WordPress's test case leaves it.
 */
final class CatalogueSnapshot {

	public const ENV = 'CSEARCH_BENCH_SNAPSHOT';

	/**
	 * Tables with an auto-increment id: everything above the mark is the load's.
	 */
	private const BY_ID = array(
		'posts'                            => 'ID',
		'postmeta'                         => 'meta_id',
		'terms'                            => 'term_id',
		'term_taxonomy'                    => 'term_taxonomy_id',
		'termmeta'                         => 'meta_id',
		'woocommerce_attribute_taxonomies' => 'attribute_id',
	);

	/**
	 * Tables keyed by the product: the new products' rows are the load's.
	 */
	private const BY_PRODUCT = array(
		'term_relationships'           => 'object_id',
		'wc_product_meta_lookup'       => 'product_id',
		'wc_product_attributes_lookup' => 'product_id',
	);

	private const CHUNK = 500;

	/**
	 * The file, or null when the snapshot is off.
	 */
	public static function path(): ?string {
		$file = (string) getenv( self::ENV );
		if ( '' === $file ) {
			return null;
		}
		return str_starts_with( $file, '/' ) ? $file : dirname( __DIR__, 2 ) . '/' . $file;
	}

	/**
	 * What the dump depends on.
	 *
	 * @param int $count Products.
	 */
	public static function key( int $count ): string {
		$root  = dirname( __DIR__, 2 );
		$parts = array( (string) $count, (string) $GLOBALS['wp_version'], defined( 'WC_VERSION' ) ? (string) WC_VERSION : '' );
		foreach ( array( 'tests/Fixtures/Catalogue.php', 'tests/Support/WooCommerceCatalogue.php', 'tests/Support/CatalogueSnapshot.php' ) as $file ) {
			$parts[] = (string) sha1_file( $root . '/' . $file );
		}
		return sha1( implode( '|', $parts ) );
	}

	/**
	 * Each table's highest id now: taken before the load.
	 *
	 * @return array<string, int>
	 */
	public static function marks(): array {
		global $wpdb;
		$marks = array();
		foreach ( self::BY_ID as $table => $column ) {
			$marks[ $table ] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(%i), 0) FROM %i', $column, $wpdb->prefix . $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return $marks;
	}

	/**
	 * Write what the load added.
	 *
	 * @param string             $path  The file.
	 * @param string             $key   {@see key()}.
	 * @param array<string, int> $marks {@see marks()}, from before the load.
	 * @param array<int, int>    $ids   Catalogue id to product id.
	 */
	public static function save( string $path, string $key, array $marks, array $ids ): void {
		global $wpdb;
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}
		$tmp = $path . '.part';
		$out = gzopen( $tmp, 'wb6' );
		if ( false === $out ) {
			throw new \RuntimeException( 'Cannot write ' . $tmp );
		}
		$counts = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT term_taxonomy_id, count FROM %i WHERE term_taxonomy_id <= %d', $wpdb->term_taxonomy, $marks['term_taxonomy'] ), ARRAY_N ) as $row ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$counts[ (int) $row[0] ] = (int) $row[1];
		}
		gzwrite( $out, (string) wp_json_encode( array( 'key' => $key, 'marks' => $marks, 'ids' => $ids, 'counts' => $counts ) ) . "\n" );
		foreach ( self::BY_ID as $table => $column ) {
			$last = $marks[ $table ];
			do {
				$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE %i > %d ORDER BY %i LIMIT %d', $wpdb->prefix . $table, $column, $last, $column, self::CHUNK ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( array() !== $rows ) {
					gzwrite( $out, (string) wp_json_encode( array( 't' => $table, 'rows' => $rows ) ) . "\n" );
					$last = (int) end( $rows )[ $column ];
				}
			} while ( count( $rows ) === self::CHUNK );
		}
		foreach ( self::BY_PRODUCT as $table => $column ) {
			if ( ! self::exists( $table ) ) {
				continue;
			}
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE %i > %d', $wpdb->prefix . $table, $column, $marks['posts'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( array_chunk( $rows, self::CHUNK ) as $chunk ) {
				gzwrite( $out, (string) wp_json_encode( array( 't' => $table, 'rows' => $chunk ) ) . "\n" );
			}
		}
		gzclose( $out );
		rename( $tmp, $path );
	}

	/**
	 * Insert a dump's rows again, when it is this run's and nothing is in their way.
	 *
	 * @param string $path The file.
	 * @param string $key  {@see key()}.
	 * @return array<int, int>|null Catalogue id to product id, or null when the catalogue must be loaded.
	 */
	public static function restore( string $path, string $key ): ?array {
		global $wpdb;
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$in = gzopen( $path, 'rb' );
		if ( false === $in ) {
			return null;
		}
		$header = json_decode( (string) gzgets( $in ), true );
		if ( ! is_array( $header ) || ( $header['key'] ?? '' ) !== $key ) {
			gzclose( $in );
			return null;
		}
		$now = self::marks();
		foreach ( (array) $header['marks'] as $table => $mark ) {
			if ( ( $now[ (string) $table ] ?? PHP_INT_MAX ) > (int) $mark ) {
				gzclose( $in );
				return null; // Rows with the dump's ids already exist.
			}
		}
		while ( ! gzeof( $in ) ) {
			$line = gzgets( $in );
			if ( false === $line || '' === trim( $line ) ) {
				continue;
			}
			$chunk = json_decode( $line, true );
			if ( ! is_array( $chunk ) || ! is_array( $chunk['rows'] ?? null ) || array() === $chunk['rows'] ) {
				continue;
			}
			self::insert( (string) $chunk['t'], $chunk['rows'] );
		}
		gzclose( $in );
		foreach ( (array) $header['counts'] as $ttId => $count ) {
			$wpdb->update( $wpdb->term_taxonomy, array( 'count' => (int) $count ), array( 'term_taxonomy_id' => (int) $ttId ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		wp_cache_flush();
		delete_transient( 'wc_attribute_taxonomies' );
		WooCommerceCatalogue::ensureWidthTaxonomy();
		$ids = array();
		foreach ( (array) $header['ids'] as $spec => $id ) {
			$ids[ (int) $spec ] = (int) $id;
		}
		return $ids;
	}

	/**
	 * One multi-row insert.
	 *
	 * @param string                            $table Table, without the prefix.
	 * @param array<int, array<string, ?string>> $rows  Rows.
	 */
	private static function insert( string $table, array $rows ): void {
		global $wpdb;
		$columns = array_keys( $rows[0] );
		$values  = array();
		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( $columns as $column ) {
				// The connection's own escaping: wpdb's would swap each % for its placeholder escape.
				$cells[] = null === $row[ $column ] ? 'NULL' : "'" . mysqli_real_escape_string( $wpdb->dbh, (string) $row[ $column ] ) . "'";
			}
			$values[] = '(' . implode( ', ', $cells ) . ')';
		}
		$names = implode( ', ', array_map( static fn( string $c ): string => '`' . str_replace( '`', '', $c ) . '`', $columns ) );
		$done  = $wpdb->query( 'INSERT INTO `' . $wpdb->prefix . $table . '` (' . $names . ') VALUES ' . implode( ', ', $values ) ); // phpcs:ignore WordPress.DB
		if ( false === $done ) {
			throw new \RuntimeException( $table . ': ' . $wpdb->last_error );
		}
	}

	/**
	 * Whether a table exists (WooCommerce's attribute lookup arrived in 6.x; older shops may lack it).
	 *
	 * @param string $table Table, without the prefix.
	 */
	private static function exists( string $table ): bool {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
