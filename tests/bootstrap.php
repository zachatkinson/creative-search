<?php
/**
 * Unit test bootstrap: no WordPress loaded.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/tests/Support/fake-abspath/' );
}

require_once __DIR__ . '/Support/wp-shims.php';
