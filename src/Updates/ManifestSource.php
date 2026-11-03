<?php
/**
 * Where the release manifest comes from.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Updates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The latest release's manifest. The one remote call the plugin makes sits behind this, so tests never touch the
 * network.
 */
interface ManifestSource {

	/**
	 * The latest release, or null when it can't be read.
	 */
	public function latest(): ?Manifest;

	/**
	 * The latest release read again now, past any cache, or null when it can't be read (D-144).
	 */
	public function fresh(): ?Manifest;
}
