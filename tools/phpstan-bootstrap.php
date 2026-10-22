<?php
/**
 * Constants the main file defines at runtime, for PHPStan.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

define( 'CSEARCH_VERSION', '1.0.0' );
define( 'CSEARCH_FILE', __DIR__ . '/../creative-search.php' );
define( 'CSEARCH_PATH', __DIR__ . '/../' );
define( 'CSEARCH_URL', 'https://example.com/wp-content/plugins/creative-search/' );
define( 'CSEARCH_MIN_WC', '10.0' );
define( 'WC_VERSION', '11.2.1' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WooCommerce's own constant, for the version check.
