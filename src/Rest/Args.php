<?php
/**
 * Shared route arguments.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Rest;

use CreativeBranding\Search\Search\SearchService;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `q` and `lang` arguments every search route takes: typed, sanitised, length-capped (spec: Security).
 */
final class Args {

	/**
	 * `q`: the query. An empty query is valid and answers an empty body.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function query(): array {
		return array(
			'q' => array(
				'description'       => __( 'The search query, 200 characters at most.', 'creative-search' ),
				'type'              => 'string',
				'default'           => '',
				// The same cleaning as the results page, so both paths search the same words (control characters
				// removed, 200 characters); the query is only ever output escaped.
				'sanitize_callback' => static fn( $value ): string => SearchService::clean( is_scalar( $value ) ? (string) $value : '' ),
			),
		);
	}

	/**
	 * `lang`: a language code; one the shop does not index falls back to the site's.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function lang(): array {
		return array(
			'lang' => array(
				'description' => __( 'Language code, such as en or fr.', 'creative-search' ),
				'type'        => 'string',
				'pattern'     => '^[A-Za-z]{2,8}([_-][A-Za-z0-9]{1,8})?$',
				'required'    => false,
			),
		);
	}

	/**
	 * The `lang` argument, or null.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The request.
	 */
	public static function langOf( WP_REST_Request $request ): ?string {
		$lang = $request->get_param( 'lang' );
		return is_string( $lang ) && '' !== $lang ? $lang : null;
	}
}
