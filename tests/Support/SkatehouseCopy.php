<?php
/**
 * Loads an export of the Skatehouse demo into the test site.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ReflectionMethod;
use WC_Product_CSV_Importer;
use WC_Product_CSV_Importer_Controller;

/**
 * The copy of Skatehouse the `skatehouse` test group runs on (D-103, handoff item 11): the products as
 * WooCommerce's CSV exporter writes them, through WooCommerce's own importer with its own column mapping, and
 * the pages as Tools → Export writes them, read with DOMDocument (no WordPress Importer dependency). The files
 * live in the gitignored tests/fixtures/skatehouse/; nothing here runs when they are absent.
 */
final class SkatehouseCopy {

	/**
	 * Where the export goes.
	 */
	public static function dir(): string {
		return dirname( __DIR__ ) . '/fixtures/skatehouse';
	}

	/**
	 * The products CSV, or null when there is none.
	 */
	public static function productsFile(): ?string {
		$found = glob( self::dir() . '/*.csv' );
		return is_array( $found ) && array() !== $found ? $found[0] : null;
	}

	/**
	 * The pages export (WXR), or null when there is none.
	 */
	public static function pagesFile(): ?string {
		$found = glob( self::dir() . '/*.xml' );
		return is_array( $found ) && array() !== $found ? $found[0] : null;
	}

	/**
	 * Import a products CSV as the admin's importer would, with the columns mapped the way it maps them.
	 *
	 * @param string $file The CSV.
	 * @return array{imported: int, variations: int, failed: string[]} What was made, and each row that failed.
	 */
	public static function loadProducts( string $file ): array {
		// The importer is an admin tool: it makes a missing category, tag or attribute term only for a user who
		// may manage them.
		if ( ! current_user_can( 'manage_product_terms' ) ) {
			$admins = get_users(
				array(
					'role'   => 'administrator',
					'number' => 1,
					'fields' => 'ID',
				)
			);
			wp_set_current_user( (int) ( $admins[0] ?? 0 ) );
		}
		require_once WC_ABSPATH . 'includes/import/class-wc-product-csv-importer.php';
		require_once WC_ABSPATH . 'includes/admin/importers/class-wc-product-csv-importer-controller.php';
		$headers = ( new WC_Product_CSV_Importer( $file, array( 'lines' => 1 ) ) )->get_raw_keys();
		$map     = new ReflectionMethod( WC_Product_CSV_Importer_Controller::class, 'auto_map_columns' );
		$mapping = (array) $map->invoke( new WC_Product_CSV_Importer_Controller(), $headers, false );
		$result  = ( new WC_Product_CSV_Importer(
			$file,
			array(
				'mapping'          => array_filter( $mapping ),
				'parse'            => true,
				'prevent_timeouts' => false,
			)
		) )->import();
		$failed = array();
		foreach ( (array) ( $result['failed'] ?? array() ) as $error ) {
			$failed[] = is_wp_error( $error ) ? $error->get_error_message() : (string) wp_json_encode( $error );
		}
		return array(
			'imported'   => count( (array) ( $result['imported'] ?? array() ) ),
			'variations' => count( (array) ( $result['imported_variations'] ?? array() ) ),
			'failed'     => $failed,
		);
	}

	/**
	 * Make the pages of a WordPress export, published ones and drafts as they were, each under its parent.
	 *
	 * @param string $file The WXR file.
	 * @return array<string, int> Each page's slug to its new id.
	 */
	public static function loadPages( string $file ): array {
		$xml = new DOMDocument();
		if ( ! $xml->load( $file, LIBXML_NONET ) ) {
			throw new \RuntimeException( 'Not an XML file: ' . $file );
		}
		$path = new DOMXPath( $xml );
		$path->registerNamespace( 'wp', self::namespaceOf( $xml, 'wp', 'http://wordpress.org/export/1.2/' ) );
		$path->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
		$ids     = array();
		$slugs   = array();
		$parents = array();
		$items   = $path->query( '//item[wp:post_type="page"]' );
		foreach ( false === $items ? array() : $items as $item ) {
			if ( ! $item instanceof DOMElement ) {
				continue;
			}
			$read   = static fn( string $field ): string => trim( (string) $path->evaluate( 'string(' . $field . ')', $item ) );
			$status = $read( 'wp:status' );
			$id     = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => in_array( $status, array( 'publish', 'draft', 'private', 'pending' ), true ) ? $status : 'draft',
					'post_title'   => $read( 'title' ),
					'post_name'    => $read( 'wp:post_name' ),
					'post_content' => $read( 'content:encoded' ),
					'menu_order'   => (int) $read( 'wp:menu_order' ),
					'post_password' => $read( 'wp:post_password' ),
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				throw new \RuntimeException( $id->get_error_message() );
			}
			$old             = (int) $read( 'wp:post_id' );
			$ids[ $old ]     = (int) $id;
			$parents[ $old ] = (int) $read( 'wp:post_parent' );
			$slugs[ (string) get_post_field( 'post_name', (int) $id ) ] = (int) $id;
		}
		foreach ( $parents as $old => $parent ) {
			if ( $parent > 0 && isset( $ids[ $parent ] ) ) {
				wp_update_post(
					array(
						'ID'          => $ids[ $old ],
						'post_parent' => $ids[ $parent ],
					)
				);
			}
		}
		return $slugs;
	}

	/**
	 * The namespace an export binds to a prefix (`wp` is 1.0, 1.1 or 1.2 by WordPress version).
	 *
	 * @param DOMDocument $xml      The export.
	 * @param string      $prefix   The prefix.
	 * @param string      $fallback The namespace when the export binds none.
	 */
	private static function namespaceOf( DOMDocument $xml, string $prefix, string $fallback ): string {
		$uri = $xml->documentElement?->lookupNamespaceURI( $prefix );
		return is_string( $uri ) && '' !== $uri ? $uri : $fallback;
	}
}
