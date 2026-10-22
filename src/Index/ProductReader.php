<?php
/**
 * Reads a product for the index.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Index;

use CreativeBranding\Search\Domain\Fields;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Cast;
use CreativeBranding\Search\Support\Options;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a WooCommerce product into {@see ProductText} per language (spec: Indexing → What goes in): name,
 * SKUs (variations folded in), descriptions stripped and capped, category names with parents, tags, brands,
 * every attribute value, plus the row a suggestion renders from. Runs in the indexing job, never on a save.
 */
final class ProductReader {

	public const LONG_DESCRIPTION_CHARS = 2000;
	public const CATEGORY_TAXONOMY      = 'product_cat';
	public const TAG_TAXONOMY           = 'product_tag';
	public const BRAND_TAXONOMY         = 'product_brand';

	/**
	 * Constructor.
	 *
	 * @param Languages $languages The languages to index.
	 */
	public function __construct( private Languages $languages ) {
	}

	/**
	 * Whether a product belongs in the index at all: published, not excluded, and not refused by the
	 * `csearch_index_product` filter.
	 *
	 * @param WC_Product $product The product.
	 */
	public function shouldIndex( WC_Product $product ): bool {
		$allowed = 'publish' === $product->get_status() && 'yes' !== get_post_meta( $product->get_id(), Options::META_EXCLUDE, true );
		/**
		 * Whether to index a product at all.
		 *
		 * @since 0.4.0
		 *
		 * @param bool       $allowed False for a draft, trashed or excluded product.
		 * @param WC_Product $product The product.
		 */
		return (bool) apply_filters( 'csearch_index_product', $allowed, $product );
	}

	/**
	 * The product in every language, after the `csearch_document` filter.
	 *
	 * @param WC_Product $product The product.
	 * @return ProductText[] By language code.
	 */
	public function read( WC_Product $product ): array {
		$base = $this->fields( $product );
		$out  = array();
		foreach ( $this->languages->all() as $code => $readers ) {
			$fields = $base;
			if ( null !== $readers['product'] ) {
				$read = call_user_func( $readers['product'], $product, $base, $code );
				if ( is_array( $read ) ) {
					$fields = Cast::fields( $read );
				}
			}
			$name = $fields[ Fields::NAME ] ?? $product->get_name();
			$text = new ProductText(
				$code,
				is_array( $name ) ? ( $name[0] ?? '' ) : $name,
				$fields,
				$this->skus( $product ),
				(string) $product->get_permalink(),
				(int) $product->get_image_id() > 0 ? (int) $product->get_image_id() : null,
				$this->price( $product, 'min' ),
				$this->price( $product, 'max' ),
				$product->is_in_stock(),
				in_array( $product->get_catalog_visibility(), array( 'visible', 'search' ), true ),
				Settings::clamp( Cast::int( get_post_meta( $product->get_id(), Options::META_BOOST, true ) ), Settings::BOOST_MIN, Settings::BOOST_MAX ),
				(int) $product->get_total_sales()
			);
			/**
			 * The document about to be written for a product in one language.
			 *
			 * @since 0.4.0
			 *
			 * @param array<string, mixed> $document `title`, `fields` (Domain\Fields ids to text), `skus`, `url`, `image_id`,
			 *     `price_min`, `price_max`, `in_stock`, `visible`, `boost`, `total_sales`, `lang`.
			 * @param WC_Product           $product  The product.
			 * @param string               $lang     Language code.
			 */
			$filtered     = apply_filters( 'csearch_document', $text->toArray(), $product, $code );
			$out[ $code ] = ProductText::fromArray( is_array( $filtered ) ? $filtered : $text->toArray() );
		}
		return $out;
	}

	/**
	 * The field texts read from the product itself, in the site's language.
	 *
	 * @param WC_Product $product The product.
	 * @return array<int, string|string[]>
	 */
	public function fields( WC_Product $product ): array {
		$fields = array(
			Fields::NAME      => $product->get_name(),
			Fields::SKU       => $this->skus( $product ),
			Fields::SHORT     => self::plain( $product->get_short_description(), self::LONG_DESCRIPTION_CHARS ),
			Fields::LONG      => Settings::isOn( 'index_long_description' ) ? self::plain( $product->get_description(), self::LONG_DESCRIPTION_CHARS ) : '',
			Fields::CATEGORY  => $this->termNames( $product, self::CATEGORY_TAXONOMY, true ),
			Fields::TAG       => Settings::isOn( 'index_tags' ) ? $this->termNames( $product, self::TAG_TAXONOMY, false ) : array(),
			Fields::BRAND     => $this->termNames( $product, self::BRAND_TAXONOMY, false ),
			Fields::ATTRIBUTE => $this->attributeValues( $product ),
		);
		/**
		 * The fields of a product before tokenising.
		 *
		 * @since 0.4.0
		 *
		 * @param array<int, string|string[]> $fields  Domain\Fields ids to text or texts.
		 * @param WC_Product                  $product The product.
		 */
		$filtered = apply_filters( 'csearch_product_fields', $fields, $product );
		return is_array( $filtered ) ? $filtered : $fields;
	}

	/**
	 * The product's SKU and, when the setting is on, every variation's.
	 *
	 * @param WC_Product $product The product.
	 * @return string[]
	 */
	public function skus( WC_Product $product ): array {
		$skus = array();
		if ( '' !== $product->get_sku() ) {
			$skus[] = (string) $product->get_sku();
		}
		if ( $product instanceof WC_Product_Variable && Settings::isOn( 'index_variation_skus' ) ) {
			foreach ( $product->get_children() as $childId ) {
				$sku = Cast::str( get_post_meta( $childId, '_sku', true ) );
				if ( '' !== $sku ) {
					$skus[] = $sku;
				}
			}
		}
		return array_values( array_unique( $skus ) );
	}

	/**
	 * Term names of a taxonomy on a product, with every parent when asked.
	 *
	 * @param WC_Product $product     The product.
	 * @param string     $taxonomy    Taxonomy.
	 * @param bool       $withParents Add the ancestors' names.
	 * @return string[]
	 */
	private function termNames( WC_Product $product, string $taxonomy, bool $withParents ): array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$terms = get_the_terms( $product->get_id(), $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$names = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			if ( $withParents ) {
				foreach ( array_reverse( get_ancestors( $term->term_id, $taxonomy, 'taxonomy' ) ) as $ancestorId ) {
					$ancestor = get_term( (int) $ancestorId, $taxonomy );
					if ( $ancestor instanceof WP_Term ) {
						$names[ $ancestor->term_id ] = $ancestor->name;
					}
				}
			}
			$names[ $term->term_id ] = $term->name;
		}
		return array_values( $names );
	}

	/**
	 * Every attribute value the product carries: global terms by name, custom values as written. A variable
	 * product's variations choose among these, so the parent's list covers them.
	 *
	 * @param WC_Product $product The product.
	 * @return string[]
	 */
	private function attributeValues( WC_Product $product ): array {
		$values = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute ) {
				continue;
			}
			if ( $attribute->is_taxonomy() ) {
				foreach ( $attribute->get_terms() ?? array() as $term ) {
					if ( $term instanceof WP_Term ) {
						$values[] = $term->name;
					}
				}
				continue;
			}
			foreach ( $attribute->get_options() as $option ) {
				$values[] = (string) $option;
			}
		}
		return array_values( array_unique( array_filter( $values, static fn( string $v ): bool => '' !== trim( $v ) ) ) );
	}

	/**
	 * A price bound, or null when the product has none.
	 *
	 * @param WC_Product $product The product.
	 * @param string     $bound   `min` or `max`.
	 */
	private function price( WC_Product $product, string $bound ): ?float {
		$price = $product instanceof WC_Product_Variable ? $product->get_variation_price( $bound ) : $product->get_price();
		return is_numeric( $price ) ? (float) $price : null;
	}

	/**
	 * Text without shortcodes or tags, capped.
	 *
	 * @param string $html Stored HTML.
	 * @param int    $max  Characters.
	 */
	public static function plain( string $html, int $max ): string {
		$text = wp_strip_all_tags( strip_shortcodes( $html ), true );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return mb_substr( $text, 0, $max, 'UTF-8' );
	}
}
