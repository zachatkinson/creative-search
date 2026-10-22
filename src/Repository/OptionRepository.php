<?php
/**
 * Options read past every cache.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads an option's stored string straight from the options table: one query, no write to the object cache
 * (D-053). For the plugin's own non-autoloaded, plain-string options only (the index state is JSON).
 */
final class OptionRepository extends Repository {

	/**
	 * The stored value, or null when the option does not exist.
	 *
	 * @param string $name Option name.
	 */
	public function raw( string $name ): ?string {
		global $wpdb;
		return $this->value( $this->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, $name ) );
	}
}
