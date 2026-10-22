<?php
/**
 * Writes catalogue products into WooCommerce.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Support;

use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * The loader the catalogue generator's docblock promised for milestone 3: each product from
 * `Tests\Fixtures\Catalogue` becomes a WooCommerce product through the CRUD classes, with its category path,
 * tags, brand, attributes (`pa_width` as a global attribute, the rest custom, so both paths are exercised),
 * variations with their SKUs and stock, price, visibility and sales.
 */
final class WooCommerceCatalogue {

	public const META_NAME_FR  = '_test_name_fr';
	public const META_SHORT_FR = '_test_short_fr';
	public const META_LONG_FR  = '_test_long_fr';

	/**
	 * Term ids by taxonomy and name.
	 *
	 * @var array<string, array<string, int>>
	 */
	private array $terms = array();

	/**
	 * Write products.
	 *
	 * @param array<int, array<string, mixed>> $products   Products from the catalogue generator.
	 * @param bool                             $variations False writes a variable product as a simple one carrying the
	 *                                                     same attribute values (faster, for the timing runs).
	 * @return array<int, int> Catalogue id to WooCommerce product id.
	 */
	public function load( array $products, bool $variations = true ): array {
		$ids = array();
		foreach ( $products as $spec ) {
			$ids[ (int) $spec['id'] ] = $this->product( $spec, $variations );
		}
		return $ids;
	}

	/**
	 * Write one product.
	 *
	 * @param array<string, mixed> $spec       A catalogue product.
	 * @param bool                 $variations Write variations.
	 */
	public function product( array $spec, bool $variations = true ): int {
		$variable = $variations && 'variable' === $spec['type'];
		$product  = $variable ? new WC_Product_Variable() : new WC_Product_Simple();
		$product->set_name( (string) $spec['name'] );
		$product->set_sku( (string) $spec['sku'] );
		$product->set_status( (string) $spec['status'] );
		$product->set_short_description( (string) $spec['short'] );
		$product->set_description( (string) $spec['long'] );
		$product->set_catalog_visibility( $spec['visible'] ? 'visible' : 'hidden' );
		$product->set_total_sales( (int) $spec['total_sales'] );
		if ( ! $variable ) {
			$product->set_regular_price( (string) $spec['price'] );
			$product->set_stock_status( $spec['in_stock'] ? 'instock' : 'outofstock' );
		}
		$product->set_category_ids( $this->categoryPath( (array) $spec['categories'], (array) $spec['category_names'] ) );
		$product->set_tag_ids( array_map( fn( string $tag ): int => $this->term( 'product_tag', $tag ), (array) $spec['tags'] ) );
		$product->set_attributes( $this->attributes( (array) $spec['attributes'], $variable ) );
		$product->update_meta_data( self::META_NAME_FR, (string) $spec['name_fr'] );
		$product->update_meta_data( self::META_SHORT_FR, (string) $spec['short_fr'] );
		$product->update_meta_data( self::META_LONG_FR, (string) $spec['long_fr'] );
		$id = $product->save();
		wp_set_object_terms( $id, array( $this->term( 'product_brand', (string) $spec['brand'] ) ), 'product_brand' );
		if ( $variable ) {
			foreach ( (array) $spec['variations'] as $v ) {
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $id );
				$variation->set_sku( (string) $v['sku'] );
				$variation->set_regular_price( (string) $v['price'] );
				$variation->set_manage_stock( true );
				$variation->set_stock_quantity( (int) $v['stock'] );
				$attrs = array();
				foreach ( (array) $v['attributes'] as $name => $value ) {
					$attrs[ self::attributeKey( (string) $name ) ] = 'pa_width' === $name ? sanitize_title( (string) $value ) : (string) $value;
				}
				$variation->set_attributes( $attrs );
				$variation->save();
			}
			WC_Product_Variable::sync( $id );
		}
		return $id;
	}

	/**
	 * The category ids of a path, parents created first.
	 *
	 * @param string[] $slugs Slugs, parent first.
	 * @param string[] $names Names, parent first.
	 * @return int[]
	 */
	private function categoryPath( array $slugs, array $names ): array {
		$parent = 0;
		foreach ( $slugs as $slug ) {
			$names  = \CreativeBranding\Search\Tests\Fixtures\Catalogue::CATEGORIES[ (string) $slug ];
			$parent = $this->term( 'product_cat', $names[0], $parent, (string) $slug );
			if ( '' === get_term_meta( $parent, self::META_NAME_FR, true ) ) {
				update_term_meta( $parent, self::META_NAME_FR, $names[1] );
			}
		}
		return array( $parent );
	}

	/**
	 * A term's id, created on first use.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $name     Name.
	 * @param int    $parent   Parent id.
	 * @param string $slug     Slug, or empty for the default.
	 */
	public function term( string $taxonomy, string $name, int $parent = 0, string $slug = '' ): int {
		if ( isset( $this->terms[ $taxonomy ][ $name ] ) ) {
			return $this->terms[ $taxonomy ][ $name ];
		}
		$existing = get_term_by( 'name', $name, $taxonomy );
		if ( $existing instanceof \WP_Term ) {
			return $this->terms[ $taxonomy ][ $name ] = $existing->term_id;
		}
		$args = array( 'parent' => $parent );
		if ( '' !== $slug ) {
			$args['slug'] = $slug;
		}
		$made = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $made ) ) {
			throw new \RuntimeException( $taxonomy . ' ' . $name . ': ' . $made->get_error_message() );
		}
		return $this->terms[ $taxonomy ][ $name ] = (int) $made['term_id'];
	}

	/**
	 * The product's attributes: `pa_width` global, the rest custom.
	 *
	 * @param array<string, string[]> $attributes Name to values.
	 * @param bool                    $variation  Used for variations.
	 * @return WC_Product_Attribute[]
	 */
	private function attributes( array $attributes, bool $variation ): array {
		$out = array();
		foreach ( $attributes as $name => $values ) {
			$attribute = new WC_Product_Attribute();
			if ( 'pa_width' === $name ) {
				$attribute->set_id( self::ensureWidthTaxonomy() );
				$attribute->set_name( 'pa_width' );
				$attribute->set_options( array_map( fn( string $v ): int => $this->term( 'pa_width', $v ), array_map( 'strval', (array) $values ) ) );
			} else {
				$attribute->set_name( self::label( (string) $name ) );
				$attribute->set_options( array_map( 'strval', (array) $values ) );
			}
			$attribute->set_visible( true );
			$attribute->set_variation( $variation );
			$out[] = $attribute;
		}
		return $out;
	}

	/**
	 * The variation attribute key WooCommerce uses for an attribute.
	 *
	 * @param string $name Catalogue attribute name.
	 */
	private static function attributeKey( string $name ): string {
		return 'pa_width' === $name ? 'pa_width' : sanitize_title( self::label( $name ) );
	}

	/**
	 * A custom attribute's label: `pa_colour` is `Colour`.
	 *
	 * @param string $name Catalogue attribute name.
	 */
	private static function label( string $name ): string {
		return ucfirst( (string) preg_replace( '/^pa_/', '', $name ) );
	}

	/**
	 * The global Width attribute's id, created when missing, with its taxonomy registered for this request.
	 * Each test rolls the attribute row back while WooCommerce's lookup caches survive, so the row is read
	 * directly rather than through `wc_attribute_taxonomy_id_by_name()`.
	 */
	public static function ensureWidthTaxonomy(): int {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT attribute_id FROM %i WHERE attribute_name = %s', $wpdb->prefix . 'woocommerce_attribute_taxonomies', 'width' ) ); // phpcs:ignore
		if ( 0 === $id ) {
			// An earlier test's taxonomy outlives its rolled-back row, and WooCommerce refuses a slug in use.
			unregister_taxonomy( 'pa_width' );
			$made = wc_create_attribute(
				array(
					'name' => 'Width',
					'slug' => 'width',
				)
			);
			$id   = is_int( $made ) ? $made : 0;
		}
		delete_transient( 'wc_attribute_taxonomies' );
		\WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
		if ( ! taxonomy_exists( 'pa_width' ) ) {
			register_taxonomy( 'pa_width', array( 'product' ), array( 'hierarchical' => false ) );
		}
		return $id;
	}

	/**
	 * The `csearch_languages` answer a bilingual shop gives (as skatehouse-core will): English as the product
	 * is, French from the product's and the category's French meta, the way the catalogue fixture's in-memory
	 * indexer reads them.
	 *
	 * @param array<string, mixed> $languages The default.
	 * @return array<string, mixed>
	 */
	public static function bilingual( array $languages ): array {
		$languages['fr'] = array(
			'product' => static function ( WC_Product $product, array $fields ): array {
				$fields[ \CreativeBranding\Search\Domain\Fields::NAME ]  = (string) $product->get_meta( self::META_NAME_FR );
				$fields[ \CreativeBranding\Search\Domain\Fields::SHORT ] = (string) $product->get_meta( self::META_SHORT_FR );
				$fields[ \CreativeBranding\Search\Domain\Fields::LONG ]  = (string) $product->get_meta( self::META_LONG_FR );
				$names = array();
				foreach ( wc_get_product_terms( $product->get_id(), 'product_cat' ) as $term ) {
					foreach ( array_reverse( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) ) as $ancestor ) {
						$names[ $ancestor ] = (string) get_term_meta( (int) $ancestor, self::META_NAME_FR, true );
					}
					$names[ $term->term_id ] = (string) get_term_meta( $term->term_id, self::META_NAME_FR, true );
				}
				$fields[ \CreativeBranding\Search\Domain\Fields::CATEGORY ] = array_values( $names );
				return $fields;
			},
			'term'    => static function ( \WP_Term $term, string $name ): string {
				$fr = 'product_cat' === $term->taxonomy ? (string) get_term_meta( $term->term_id, self::META_NAME_FR, true ) : '';
				return '' !== $fr ? $fr : $name;
			},
		);
		return $languages;
	}

	/**
	 * Products as created: a catalogue product by index.
	 *
	 * @param int $i Zero-based index.
	 * @return array<string, mixed>
	 */
	public static function spec( int $i ): array {
		return ( new \CreativeBranding\Search\Tests\Fixtures\Catalogue() )->product( $i );
	}

	/**
	 * A product by id, freshly read.
	 *
	 * @param int $id Product id.
	 */
	public static function get( int $id ): WC_Product {
		$product = wc_get_product( $id );
		if ( ! $product instanceof WC_Product ) {
			throw new \RuntimeException( 'No product ' . $id );
		}
		return $product;
	}
}
