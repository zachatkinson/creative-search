<?php
/**
 * Response schemas.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The JSON schema of each route's response, served on an OPTIONS request and checked by the tests.
 */
final class Schemas {

	/**
	 * `/suggest`.
	 *
	 * @return array<string, mixed>
	 */
	public static function suggest(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'csearch-suggest',
			'type'       => 'object',
			'properties' => array(
				'products'  => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'         => array( 'type' => 'integer' ),
							'name'       => array( 'type' => 'string' ),
							'url'        => array(
								'type'   => 'string',
								'format' => 'uri',
							),
							'image'      => array( 'type' => array( 'object', 'null' ) ),
							'price_html' => array( 'type' => 'string' ),
							'in_stock'   => array( 'type' => 'boolean' ),
							'type'       => array( 'type' => 'string' ),
						),
					),
				),
				'terms'     => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'       => array( 'type' => 'integer' ),
							'taxonomy' => array(
								'type' => 'string',
								'enum' => array( 'category', 'tag', 'brand' ),
							),
							'name'     => array( 'type' => 'string' ),
							'count'    => array( 'type' => 'integer' ),
							'url'      => array( 'type' => 'string' ),
						),
					),
				),
				'total'     => array( 'type' => 'integer' ),
				'query'     => array( 'type' => 'string' ),
				'corrected' => array( 'type' => array( 'string', 'null' ) ),
				'lang'      => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * `/search`.
	 *
	 * @return array<string, mixed>
	 */
	public static function search(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'csearch-search',
			'type'       => 'object',
			'properties' => array(
				'ids'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'total'     => array( 'type' => 'integer' ),
				'page'      => array( 'type' => 'integer' ),
				'pages'     => array( 'type' => 'integer' ),
				'corrected' => array( 'type' => array( 'string', 'null' ) ),
				'query'     => array( 'type' => 'string' ),
				'lang'      => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * `/status`.
	 *
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'csearch-status',
			'type'       => 'object',
			'properties' => array(
				'generation'   => array( 'type' => 'integer' ),
				'building'     => array( 'type' => array( 'integer', 'null' ) ),
				'products'     => array( 'type' => 'integer' ),
				'documents'    => array( 'type' => 'integer' ),
				'terms'        => array( 'type' => 'integer' ),
				'last_reindex' => array( 'type' => array( 'string', 'null' ) ),
				'progress'     => array( 'type' => 'object' ),
				'backlog'      => array( 'type' => 'integer' ),
				'errors'       => array( 'type' => 'array' ),
			),
		);
	}
}
