<?php
/**
 * Multisite housekeeping.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Hooks;

use CreativeBranding\Search\Hookable;
use CreativeBranding\Search\Install\Uninstaller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A network site deleted takes the plugin's seven tables with it (spec: multisite-safe, tables per site).
 */
final class InstallHooks implements Hookable {

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_filter( 'wpmu_drop_tables', array( Uninstaller::class, 'siteTables' ) );
	}
}
