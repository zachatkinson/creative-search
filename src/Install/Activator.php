<?php
/**
 * Activation.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Install;

use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates the tables and the default settings. Does no heavy work: the first index build is the owner's
 * (Tools → Rebuild index, or `wp csearch reindex`), announced by a notice when the index is empty.
 */
final class Activator {

	/**
	 * Activation hook.
	 */
	public static function activate(): void {
		Schema::install();
		if ( false === get_option( Options::SETTINGS, false ) ) {
			add_option( Options::SETTINGS, Settings::defaults(), '', true );
		}
	}
}
