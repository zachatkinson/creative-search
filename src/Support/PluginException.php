<?php
/**
 * The plugin's exception.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Support;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown for a state the plugin cannot continue from (an unknown service, a reindex that cannot start). The
 * message is plain text for logs and WP-CLI; callers that print it escape it at the sink.
 */
final class PluginException extends RuntimeException {
}
