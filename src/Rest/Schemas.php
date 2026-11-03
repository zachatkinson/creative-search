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
							'price'      => array( 'type' => array( 'number', 'null' ) ),
							'in_stock'   => array( 'type' => 'boolean' ),
							'type'       => array( 'type' => 'string' ),
							'classes'    => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'matched'    => array( 'type' => 'string' ),
							'cart'       => array( 'type' => 'boolean' ),
							'described'  => array( 'type' => 'boolean' ),
						),
					),
				),
				'queries'   => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'scopes'    => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'   => array( 'type' => 'integer' ),
							'name' => array( 'type' => 'string' ),
							'url'  => array(
								'type'   => 'string',
								'format' => 'uri',
							),
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
				'content'   => self::content(),
				'total'     => array( 'type' => 'integer' ),
				'query'     => array( 'type' => 'string' ),
				'corrected' => array( 'type' => array( 'string', 'null' ) ),
				'lang'      => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * The posts and pages beside the products (1.1): empty while both are switched off.
	 *
	 * @return array<string, mixed>
	 */
	private static function content(): array {
		return array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'id'     => array( 'type' => 'integer' ),
					'type'   => array(
						'type' => 'string',
						'enum' => array( 'post', 'page' ),
					),
					'name'   => array( 'type' => 'string' ),
					'parent' => array( 'type' => 'string' ),
					'url'    => array(
						'type'   => 'string',
						'format' => 'uri',
					),
					'image'  => array( 'type' => array( 'object', 'null' ) ),
				),
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
				'content'   => self::content(),
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
