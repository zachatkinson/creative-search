<?php
/**
 * Reads posts and pages.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Index;

use CreativeBranding\Search\Domain\Document;
use CreativeBranding\Search\Domain\Fields;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Cast;
use CreativeBranding\Search\Support\Options;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The posts and pages searched beside the products (1.1, D-093): each switched on by its own setting, off by
 * default. A post becomes one document per language: its title, its excerpt, the first 2,000 characters of its
 * content and its parent page's title, its link and its featured image. The front page, WooCommerce's own pages
 * and anything the owner excluded stay out (Skatehouse handoff item 7).
 */
final class ContentReader {

	/**
	 * Post type to document type, and the setting that switches it on.
	 */
	public const TYPES = array(
		'post' => array( Document::POST, 'index_posts' ),
		'page' => array( Document::PAGE, 'index_pages' ),
	);

	/**
	 * Constructor.
	 *
	 * @param Languages $languages The languages to index.
	 */
	public function __construct( private Languages $languages ) {
	}

	/**
	 * The document type of a post type, or null when it is not one this plugin can search.
	 *
	 * @param string $postType Post type.
	 */
	public static function typeOf( string $postType ): ?string {
		return isset( self::TYPES[ $postType ] ) ? self::TYPES[ $postType ][0] : null;
	}

	/**
	 * The post types switched on in the settings.
	 *
	 * @return string[]
	 */
	public static function enabled(): array {
		return array_keys( array_filter( self::TYPES, static fn( array $type ): bool => Settings::isOn( $type[1] ) ) );
	}

	/**
	 * Whether a post belongs in the index: its type switched on, published, without a password, and not refused
	 * by the `csearch_index_content` filter.
	 *
	 * @param WP_Post $post The post.
	 */
	public function shouldIndex( WP_Post $post ): bool {
		$allowed = in_array( $post->post_type, self::enabled(), true )
			&& 'publish' === $post->post_status
			&& '' === $post->post_password
			&& ! in_array( $post->ID, self::shopPages(), true )
			&& 'yes' !== get_post_meta( $post->ID, Options::META_EXCLUDE, true );
		/**
		 * Whether to index a post or page.
		 *
		 * @since 1.1.0
		 *
		 * @param bool    $allowed False for a type switched off, an unpublished or excluded post, one with a
		 *                         password, the front page and WooCommerce's shop, cart, checkout and account pages.
		 * @param WP_Post $post    The post or page.
		 */
		return (bool) apply_filters( 'csearch_index_content', $allowed, $post );
	}

	/**
	 * The pages a search never offers: the front page and WooCommerce's shop, cart, checkout and account pages.
	 *
	 * @return int[]
	 */
	public static function shopPages(): array {
		$ids = array( Cast::int( get_option( 'page_on_front' ) ) );
		foreach ( array( 'shop', 'cart', 'checkout', 'myaccount' ) as $page ) {
			$ids[] = wc_get_page_id( $page );
		}
		return array_values( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) );
	}

	/**
	 * A post's parent's title, as stored, when the parent is public: published with no password. A private or
	 * protected parent's title is never indexed or shown.
	 *
	 * @param WP_Post $post The post.
	 */
	public static function parentTitle( WP_Post $post ): string {
		$parent = $post->post_parent > 0 ? get_post( $post->post_parent ) : null;
		if ( ! $parent instanceof WP_Post || 'publish' !== $parent->post_status || '' !== $parent->post_password ) {
			return '';
		}
		return self::decode( $parent->post_title );
	}

	/**
	 * The post in every language, after the `csearch_content_document` filter.
	 *
	 * @param WP_Post $post The post.
	 * @return ProductText[] By language code.
	 */
	public function read( WP_Post $post ): array {
		$base  = array(
			Fields::NAME     => self::decode( $post->post_title ),
			Fields::SHORT    => ProductReader::plain( $post->post_excerpt, ProductReader::LONG_DESCRIPTION_CHARS ),
			Fields::LONG     => ProductReader::plain( $post->post_content, ProductReader::LONG_DESCRIPTION_CHARS ),
			// A child page is found by its parent's title too ("size guide" finds the Wheel guide), weighted as a product's category.
			Fields::CATEGORY => self::parentTitle( $post ),
		);
		$link  = get_permalink( $post );
		$image = (int) get_post_thumbnail_id( $post );
		$out   = array();
		foreach ( $this->languages->all() as $code => $readers ) {
			$fields = $base;
			if ( null !== $readers['content'] ) {
				$read = call_user_func( $readers['content'], $post, $base, $code );
				if ( is_array( $read ) ) {
					$fields = Cast::fields( $read );
				}
			}
			$name = $fields[ Fields::NAME ] ?? $base[ Fields::NAME ];
			$text = new ProductText(
				$code,
				is_array( $name ) ? ( $name[0] ?? '' ) : $name,
				$fields,
				array(),
				is_string( $link ) ? $link : '',
				$image > 0 ? $image : null,
				null,
				null,
				true,
				true,
				0,
				0
			);
			/**
			 * The document about to be written for a post or page in one language.
			 *
			 * @since 1.1.0
			 *
			 * @param array<string, mixed> $document `title`, `fields` (Domain\Fields ids to text), `url`, `image_id`,
			 *     `lang`, and the product keys, which a post leaves empty.
			 * @param WP_Post              $post     The post or page.
			 * @param string               $lang     Language code.
			 */
			$filtered     = apply_filters( 'csearch_content_document', $text->toArray(), $post, $code );
			$out[ $code ] = ProductText::fromArray( is_array( $filtered ) ? $filtered : $text->toArray() );
		}
		return $out;
	}

	/**
	 * A title as stored, without its tags and entities.
	 *
	 * @param string $title The stored title.
	 */
	private static function decode( string $title ): string {
		return html_entity_decode( wp_strip_all_tags( $title, true ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
