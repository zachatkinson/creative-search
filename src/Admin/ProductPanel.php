<?php
/**
 * The product editor's Search panel.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Admin;

use CreativeBranding\Search\Index\IndexState;
use CreativeBranding\Search\Repository\DocumentRepository;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Cast;
use CreativeBranding\Search\Support\Options;
use WC_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The product's own search settings (spec: Admin → Product editor): a Search tab in the product data box with
 * the boost (−10 to 10), Exclude from search, and when the product was last indexed with a Reindex now link;
 * the same two values as `Meta:` columns in WooCommerce's product CSV exporter and importer. Saved through the
 * product object on WooCommerce's own save (its nonce and capability), so the save hooks queue the reindex.
 */
final class ProductPanel {

	public const TAB = 'csearch';

	/**
	 * Export column id to meta key.
	 */
	public const COLUMNS = array(
		'csearch_boost'   => Options::META_BOOST,
		'csearch_exclude' => Options::META_EXCLUDE,
	);

	/**
	 * Constructor.
	 *
	 * @param DocumentRepository $documents Documents, for "last indexed".
	 * @param IndexState         $state     The live generation.
	 */
	public function __construct( private DocumentRepository $documents, private IndexState $state ) {
	}

	/**
	 * The Search tab in the product data box.
	 *
	 * @param array<string, mixed>|mixed $tabs WooCommerce's tabs.
	 * @return array<string, mixed>|mixed
	 */
	public function tab( $tabs ) {
		if ( ! is_array( $tabs ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return $tabs;
		}
		$tabs[ self::TAB ] = array(
			'label'    => __( 'Search', 'creative-search' ),
			'target'   => 'csearch_product_data',
			'class'    => array(),
			'priority' => 85,
		);
		return $tabs;
	}

	/**
	 * The panel.
	 */
	public function panel(): void {
		global $post;
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$productId = $post instanceof \WP_Post ? $post->ID : 0;
		$product   = wc_get_product( $productId );
		$boost     = $product instanceof WC_Product ? Settings::clamp( Cast::int( $product->get_meta( Options::META_BOOST ) ), Settings::BOOST_MIN, Settings::BOOST_MAX ) : 0;
		$excluded  = $product instanceof WC_Product && 'yes' === $product->get_meta( Options::META_EXCLUDE );
		echo '<div id="csearch_product_data" class="panel woocommerce_options_panel hidden"><div class="options_group">';
		woocommerce_wp_text_input(
			array(
				'id'                => 'csearch_boost',
				'name'              => 'csearch_boost',
				'label'             => __( 'Search boost', 'creative-search' ),
				'type'              => 'number',
				'value'             => (string) $boost,
				'desc_tip'          => false,
				/* translators: 1: lowest boost, 2: highest boost */
				'description'       => sprintf( __( 'From %1$d to %2$d: above zero ranks the product higher among equally good matches, below zero lower.', 'creative-search' ), Settings::BOOST_MIN, Settings::BOOST_MAX ),
				'custom_attributes' => array(
					'min'  => (string) Settings::BOOST_MIN,
					'max'  => (string) Settings::BOOST_MAX,
					'step' => '1',
				),
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => 'csearch_exclude',
				'name'        => 'csearch_exclude',
				'label'       => __( 'Exclude from search', 'creative-search' ),
				'value'       => $excluded ? 'yes' : 'no',
				'cbvalue'     => 'yes',
				'description' => __( 'Keep this product out of search suggestions and results.', 'creative-search' ),
			)
		);
		echo '<p class="form-field csearch-last-indexed"><span class="csearch-label">' . esc_html__( 'Search index', 'creative-search' ) . '</span> ' . esc_html( $this->lastIndexed( $productId, $excluded ) );
		if ( $productId > 0 && ! $excluded ) {
			echo ' <a href="' . esc_url( Actions::link( Actions::PRODUCT_REINDEX, array( 'product' => $productId ) ) ) . '">' . esc_html__( 'Reindex now', 'creative-search' ) . '</a>';
		}
		echo '</p></div></div>';
	}

	/**
	 * The "last indexed" line.
	 *
	 * @param int  $productId Product id.
	 * @param bool $excluded  Whether the owner excludes it.
	 */
	public function lastIndexed( int $productId, bool $excluded ): string {
		if ( $excluded ) {
			return __( 'Excluded from search.', 'creative-search' );
		}
		$at = $productId > 0 ? $this->documents->indexedAt( $productId, $this->state->live() ) : null;
		$ts = null === $at ? false : strtotime( $at . ' UTC' );
		if ( false === $ts ) {
			return __( 'Not in the search index yet.', 'creative-search' );
		}
		/* translators: %s: how long ago, such as "2 minutes" */
		return sprintf( __( 'Last indexed %s ago.', 'creative-search' ), human_time_diff( $ts ) );
	}

	/**
	 * Save the two fields on WooCommerce's product save (it has checked its nonce and the user's capability).
	 *
	 * @param WC_Product|mixed $product The product being saved.
	 */
	public function save( $product ): void {
		if ( ! $product instanceof WC_Product || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified its meta-box nonce before this action.
		if ( isset( $_POST['csearch_boost'] ) && is_string( $_POST['csearch_boost'] ) ) {
			self::apply( $product, Options::META_BOOST, sanitize_text_field( wp_unslash( $_POST['csearch_boost'] ) ) );
		}
		self::apply( $product, Options::META_EXCLUDE, isset( $_POST['csearch_exclude'] ) ? 'yes' : '' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Set or clear one of the two values on a product, normalised: a boost is clamped and 0 clears it; exclude
	 * is "yes" or cleared.
	 *
	 * @param WC_Product $product The product.
	 * @param string     $key     The meta key.
	 * @param mixed      $value   The value.
	 */
	public static function apply( WC_Product $product, string $key, mixed $value ): void {
		$value = Options::META_BOOST === $key
			? Settings::clamp( Cast::int( $value ), Settings::BOOST_MIN, Settings::BOOST_MAX )
			: ( in_array( strtolower( Cast::str( $value ) ), array( 'yes', '1', 'true' ), true ) ? 'yes' : '' );
		if ( 0 === $value || '' === $value ) {
			$product->delete_meta_data( $key );
			return;
		}
		$product->update_meta_data( $key, (string) $value );
	}

	/**
	 * The two columns in the exporter, under the names the importer maps to meta.
	 *
	 * @param array<string, string>|mixed $columns Column id to name.
	 * @return array<string, string>|mixed
	 */
	public function exportColumns( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}
		foreach ( self::COLUMNS as $id => $key ) {
			// WooCommerce's importer maps an untranslated "Meta: key" header to the meta key in every language (D-070).
			$columns[ $id ] = 'Meta: ' . $key;
		}
		return $columns;
	}

	/**
	 * A column's value.
	 *
	 * @param mixed      $value    The value so far.
	 * @param WC_Product $product  The product.
	 * @param string     $columnId The column.
	 */
	public function exportValue( $value, $product, $columnId = '' ): string {
		unset( $value );
		$key = self::COLUMNS[ Cast::str( $columnId ) ] ?? null;
		return null !== $key && $product instanceof WC_Product ? Cast::str( $product->get_meta( $key ) ) : '';
	}

	/**
	 * Keep the two keys out of the exporter's "custom meta" columns, which would repeat them.
	 *
	 * @param string[]|mixed $keys Keys to skip.
	 * @return string[]|mixed
	 */
	public function skipMeta( $keys ) {
		return is_array( $keys ) ? array_merge( $keys, array_values( self::COLUMNS ) ) : $keys;
	}

	/**
	 * Normalise the two values an import set, as the panel would.
	 *
	 * @param WC_Product|mixed $product The product about to be saved.
	 * @return WC_Product|mixed
	 */
	public function import( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return $product;
		}
		foreach ( self::COLUMNS as $key ) {
			$meta = $product->get_meta( $key, true, 'edit' );
			if ( '' !== $meta && null !== $meta ) {
				self::apply( $product, $key, $meta );
			}
		}
		return $product;
	}
}
