<?php
/**
 * A REST route.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One route of `csearch/v1`, registered on `rest_api_init` by {@see \CreativeBranding\Search\Hooks\RestHooks}.
 */
interface Route {

	public const NAMESPACE = 'csearch/v1';

	/**
	 * Register the route with its args and response schema.
	 */
	public function register(): void;
}
