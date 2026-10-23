<?php
/**
 * WooCommerce and WordPress changes that reach the index.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Hooks;

use CreativeBranding\Search\Hookable;
use CreativeBranding\Search\Index\ContentReader;
use CreativeBranding\Search\Index\IndexQueue;
use CreativeBranding\Search\Index\TermReader;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Cast;
use WC_Product;
use WP_Post;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The hook set of the spec (Indexing → When). Each callback only tells {@see IndexQueue} an id: no query, no
 * product load, so the request that saved a product does no work of ours until shutdown.
 */
final class IndexHooks implements Hookable {

	/**
	 * Constructor.
	 *
	 * @param IndexQueue $queue The queue.
	 */
	public function __construct( private IndexQueue $queue ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'woocommerce_before_product_object_save', array( $this, 'beforeSave' ), 10, 1 );
		add_action( 'woocommerce_before_product_variation_object_save', array( $this, 'beforeSave' ), 10, 1 );
		foreach ( array( 'woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_new_product_variation', 'woocommerce_update_product_variation' ) as $hook ) {
			add_action( $hook, array( $this, 'productChanged' ), 10, 2 );
		}
		add_action( 'woocommerce_delete_product_variation', array( $this, 'variationRemoved' ), 10, 1 );
		add_action( 'woocommerce_trash_product_variation', array( $this, 'variationRemoved' ), 10, 1 );
		add_action( 'woocommerce_product_set_stock', array( $this, 'stockChanged' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'stockChanged' ), 10, 1 );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'stockStatusChanged' ), 10, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'stockStatusChanged' ), 10, 3 );
		add_action( 'set_object_terms', array( $this, 'termsSet' ), 10, 6 );
		add_action( 'wp_trash_post', array( $this, 'postChanged' ), 10, 1 );
		add_action( 'untrashed_post', array( $this, 'postChanged' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'postDeleted' ), 10, 2 );
		add_action( 'transition_post_status', array( $this, 'statusChanged' ), 10, 3 );
		add_action( 'wp_after_insert_post', array( $this, 'contentSaved' ), 10, 2 );
		foreach ( array( 'page_on_front', 'woocommerce_shop_page_id', 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id' ) as $option ) {
			add_action( 'update_option_' . $option, array( $this, 'pageRoleChanged' ), 10, 2 );
		}
		add_action( 'edited_term', array( $this, 'termEdited' ), 10, 3 );
		add_action( 'created_term', array( $this, 'termCreated' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'termDeleted' ), 10, 5 );
		add_action( 'woocommerce_product_import_inserted_product_object', array( $this, 'imported' ), 10, 1 );
		add_action( 'updated_post_meta', array( $this, 'metaChanged' ), 10, 3 );
		add_action( 'added_post_meta', array( $this, 'metaChanged' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'metaChanged' ), 10, 3 );
	}

	/**
	 * A product or variation is about to be saved: note which properties changed.
	 *
	 * @param mixed $product The product or variation.
	 */
	public function beforeSave( $product ): void {
		if ( $product instanceof WC_Product ) {
			$this->queue->saving( $product->get_id(), array_keys( $product->get_changes() ) );
		}
	}

	/**
	 * A product or variation was created or saved.
	 *
	 * @param int|mixed $productId Product id.
	 * @param mixed     $product   The product object WooCommerce passes.
	 */
	public function productChanged( $productId, $product = null ): void {
		$this->queue->saved( Cast::int( $productId ), $product instanceof WC_Product ? $product->get_parent_id() : 0 );
	}

	/**
	 * A variation was deleted or trashed: its parent loses its SKU and values. The parent id is read from the
	 * post cache WordPress already holds for the variation.
	 *
	 * @param int|mixed $variationId Variation id.
	 */
	public function variationRemoved( $variationId ): void {
		$parent = wp_get_post_parent_id( Cast::int( $variationId ) );
		if ( false !== $parent && $parent > 0 ) {
			$this->queue->product( $parent );
		}
		// After a forced delete the variation is gone; `before_delete_post` has already queued its parent.
	}

	/**
	 * A stock quantity changed (the product object is passed).
	 *
	 * @param mixed $product The product or variation.
	 */
	public function stockChanged( $product ): void {
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$this->queue->stock( $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id() );
	}

	/**
	 * A stock status changed.
	 *
	 * @param int|mixed $productId Product or variation id.
	 * @param mixed     $status    New status.
	 * @param mixed     $product   The product or variation.
	 */
	public function stockStatusChanged( $productId, $status = null, $product = null ): void {
		if ( $product instanceof WC_Product ) {
			$this->stockChanged( $product );
			return;
		}
		$this->queue->stock( Cast::int( $productId ) );
	}

	/**
	 * Terms were set on an object: a product's categories, tags, brands or attributes changed.
	 *
	 * @param int|mixed    $objectId  Object id.
	 * @param mixed        $terms     Terms as passed.
	 * @param array|mixed  $ttIds     New term taxonomy ids.
	 * @param string|mixed $taxonomy  Taxonomy.
	 * @param mixed        $append    Appended.
	 * @param array|mixed  $oldTtIds  Previous term taxonomy ids.
	 */
	public function termsSet( $objectId, $terms = null, $ttIds = array(), $taxonomy = '', $append = false, $oldTtIds = array() ): void {
		$taxonomy = Cast::str( $taxonomy );
		if ( 'product_visibility' === $taxonomy ) {
			$new     = Cast::ints( $ttIds );
			$old     = Cast::ints( $oldTtIds );
			$changed = array_merge( array_diff( $new, $old ), array_diff( $old, $new ) );
			// The out-of-stock flag moves WooCommerce's counts only when the shop hides out-of-stock products.
			$soldOut = get_term_by( 'slug', 'outofstock', 'product_visibility' );
			if ( $soldOut instanceof WP_Term && 'yes' !== get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
				$changed = array_diff( $changed, array( $soldOut->term_taxonomy_id ) );
			}
			if ( array() !== $changed ) {
				$this->visibilityChanged( Cast::int( $objectId ) );
			}
			return;
		}
		if ( ! self::isProductTaxonomy( $taxonomy ) ) {
			return;
		}
		if ( 'product' !== get_post_type( Cast::int( $objectId ) ) ) {
			return; // Read from the post cache the save already filled.
		}
		$this->queue->product( Cast::int( $objectId ) );
		if ( null !== TermReader::typeOf( $taxonomy ) ) {
			$new = Cast::ints( $ttIds );
			$old = Cast::ints( $oldTtIds );
			$this->queue->termTaxonomies( $taxonomy, array_merge( array_diff( $new, $old ), array_diff( $old, $new ) ) );
		}
	}

	/**
	 * A product's catalogue visibility or out-of-stock flag changed: WooCommerce's count of its categories, tags
	 * and brands (and their parents) may have changed with it, so their documents are rewritten (D-078).
	 *
	 * @param int $productId Product id.
	 */
	private function visibilityChanged( int $productId ): void {
		if ( 'product' !== get_post_type( $productId ) ) {
			return;
		}
		foreach ( array_keys( TermReader::TYPES ) as $taxonomy ) {
			// From the term cache the save has already filled, so the save request stays free of queries.
			$terms = get_the_terms( $productId, $taxonomy );
			if ( is_array( $terms ) ) {
				$this->queue->termTaxonomies( $taxonomy, array_map( static fn( WP_Term $term ): int => $term->term_taxonomy_id, $terms ) );
			}
		}
	}

	/**
	 * A post was trashed or restored.
	 *
	 * @param int|mixed $postId Post id.
	 */
	public function postChanged( $postId ): void {
		$type = get_post_type( Cast::int( $postId ) );
		if ( 'product' === $type ) {
			$this->queue->product( Cast::int( $postId ) );
		} elseif ( 'product_variation' === $type ) {
			$this->variationRemoved( $postId );
		} elseif ( is_string( $type ) ) {
			$this->content( $type, Cast::int( $postId ) );
		}
	}

	/**
	 * A post is about to be deleted.
	 *
	 * @param int|mixed $postId Post id.
	 * @param mixed     $post   The post.
	 */
	public function postDeleted( $postId, $post = null ): void {
		$type = $post instanceof WP_Post ? $post->post_type : get_post_type( Cast::int( $postId ) );
		if ( 'product' === $type ) {
			$this->queue->product( Cast::int( $postId ) );
		} elseif ( 'product_variation' === $type ) {
			$parent = $post instanceof WP_Post ? $post->post_parent : 0;
			$this->queue->product( $parent > 0 ? $parent : Cast::int( $postId ) );
		} elseif ( is_string( $type ) ) {
			$this->content( $type, Cast::int( $postId ) );
		}
	}

	/**
	 * A post or page was created or saved, whatever its status (the job reads it and indexes or removes it).
	 *
	 * @param int|mixed $postId Post id.
	 * @param mixed     $post   The post.
	 */
	public function contentSaved( $postId, $post = null ): void {
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, ContentReader::enabled(), true ) ) {
			return;
		}
		$this->content( $post->post_type, $post->ID );
		// Its children are found by its title, and only while it is public (ContentReader::parentTitle()).
		$children = ( new \WP_Query(
			array(
				'post_type'              => $post->post_type,
				'post_parent'            => $post->ID,
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'posts_per_page'         => IndexQueue::BATCH,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		) )->posts;
		foreach ( Cast::ints( $children ) as $child ) {
			$this->content( $post->post_type, $child );
		}
	}

	/**
	 * A page became, or stopped being, the front page or one of WooCommerce's pages, which are never indexed:
	 * both pages are read again.
	 *
	 * @param mixed $previous The previous page id.
	 * @param mixed $current  The new page id.
	 */
	public function pageRoleChanged( $previous, $current ): void {
		foreach ( array( Cast::int( $previous ), Cast::int( $current ) ) as $id ) {
			$post = $id > 0 ? get_post( $id ) : null;
			if ( $post instanceof WP_Post ) {
				$this->content( $post->post_type, $post->ID );
			}
		}
	}

	/**
	 * Queue a post or page when its type is switched on (D-093); a type switched off has no documents left.
	 *
	 * @param string $postType Post type.
	 * @param int    $postId   Post id.
	 */
	private function content( string $postType, int $postId ): void {
		if ( in_array( $postType, ContentReader::enabled(), true ) ) {
			$this->queue->content( $postId );
		}
	}

	/**
	 * A product was published, drafted, made private or scheduled outside WooCommerce's CRUD (quick edit,
	 * the REST posts endpoint, a scheduled post going live).
	 *
	 * @param string|mixed $newStatus New status.
	 * @param string|mixed $oldStatus Old status.
	 * @param mixed        $post      The post.
	 */
	public function statusChanged( $newStatus, $oldStatus, $post = null ): void {
		if ( $newStatus === $oldStatus || ! $post instanceof WP_Post || 'product' !== $post->post_type ) {
			return;
		}
		if ( 'publish' === $newStatus || 'publish' === $oldStatus ) {
			$this->queue->product( $post->ID );
		}
	}

	/**
	 * A term was renamed or its description changed: its document, and its products, which carry its name.
	 *
	 * @param int|mixed    $termId   Term id.
	 * @param int|mixed    $ttId     Term taxonomy id.
	 * @param string|mixed $taxonomy Taxonomy.
	 */
	public function termEdited( $termId, $ttId = 0, $taxonomy = '' ): void {
		$taxonomy = Cast::str( $taxonomy );
		if ( ! self::isProductTaxonomy( $taxonomy ) ) {
			return;
		}
		$this->queue->term( $taxonomy, Cast::int( $termId ) );
		$this->queue->termProducts( $taxonomy, Cast::int( $termId ) );
	}

	/**
	 * A term was created: its own document (no product carries it yet).
	 *
	 * @param int|mixed    $termId   Term id.
	 * @param int|mixed    $ttId     Term taxonomy id.
	 * @param string|mixed $taxonomy Taxonomy.
	 */
	public function termCreated( $termId, $ttId = 0, $taxonomy = '' ): void {
		$this->queue->term( Cast::str( $taxonomy ), Cast::int( $termId ) );
	}

	/**
	 * A term was deleted: its document goes, and the products that carried it are re-indexed.
	 *
	 * @param int|mixed    $termId    Term id.
	 * @param int|mixed    $ttId      Term taxonomy id.
	 * @param string|mixed $taxonomy  Taxonomy.
	 * @param mixed        $deleted   The deleted term.
	 * @param array|mixed  $objectIds The objects that carried it.
	 */
	public function termDeleted( $termId, $ttId = 0, $taxonomy = '', $deleted = null, $objectIds = array() ): void {
		$taxonomy = Cast::str( $taxonomy );
		if ( ! self::isProductTaxonomy( $taxonomy ) ) {
			return;
		}
		$this->queue->term( $taxonomy, Cast::int( $termId ) );
		$this->queue->products( Cast::ints( $objectIds ) );
	}

	/**
	 * The CSV importer created or updated a product.
	 *
	 * @param mixed $product The product.
	 */
	public function imported( $product ): void {
		if ( $product instanceof WC_Product ) {
			$this->productChanged( $product->get_id(), $product );
		}
	}

	/**
	 * The owner's boost or exclude flag changed on a product, post or page, or a custom field the owner searches
	 * changed on a product (D-119).
	 *
	 * @param mixed     $metaId  Meta id.
	 * @param int|mixed $postId  Post id.
	 * @param mixed     $metaKey Meta key.
	 */
	public function metaChanged( $metaId, $postId, $metaKey ): void {
		if ( in_array( $metaKey, Cast::strings( Settings::get( 'custom_fields' ) ), true ) && 'product' === get_post_type( Cast::int( $postId ) ) ) {
			$this->queue->product( Cast::int( $postId ) );
			return;
		}
		if ( ! in_array( $metaKey, \CreativeBranding\Search\Support\Options::metaKeys(), true ) ) {
			return;
		}
		$type = get_post_type( Cast::int( $postId ) );
		if ( is_string( $type ) && null !== ContentReader::typeOf( $type ) ) {
			$this->content( $type, Cast::int( $postId ) );
			return;
		}
		$this->queue->product( Cast::int( $postId ) );
	}

	/**
	 * Whether a taxonomy's terms are indexed text on a product: categories, tags, brands, global attributes.
	 * `product_visibility` is not: WooCommerce rewrites it on every stock change, and a catalogue visibility
	 * change reaches the index through the product save that made it.
	 *
	 * @param string $taxonomy Taxonomy.
	 */
	public static function isProductTaxonomy( string $taxonomy ): bool {
		return null !== TermReader::typeOf( $taxonomy ) || str_starts_with( $taxonomy, 'pa_' );
	}
}
