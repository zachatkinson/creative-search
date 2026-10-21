<?php
/**
 * Hookable contract.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A class that attaches its thin callbacks to WordPress hooks. Hooks call services; the logic is in the services.
 */
interface Hookable {

	/**
	 * Attach hooks.
	 */
	public function register(): void;
}
