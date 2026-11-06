<?php
/**
 * The results page's cards: the variation a query named, and what matched.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Results;

use CreativeBranding\Search\Domain\MatchDetail;
use CreativeBranding\Search\Search\MatchDetails;
use WC_Product;
use WP_Block;
use WP_Post;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * On a ranked results page, a product card whose variation the query named shows that variation's image and links
 * to the product with it chosen (D-104), and a card that matched by something it does not show carries one line
 * under its title saying what (D-105). Worked out once, for the products the page shows, the first time a card
 * asks; through WooCommerce's own image and link (so classic and block templates both follow) and after the
 * title (the classic loop's hook, the block theme's post title).
 */
final class MatchDecorations {

	/**
	 * Product id to its details, for the products worked out so far on this page.
	 *
	 * @var array<int, MatchDetail|null>
	 */
	private array $details = array();

	/**
	 * The answer the details are for: another search in the same process starts afresh.
	 *
	 * @var string
	 */
	private string $for = '';

	/**
	 * Constructor.
	 *
	 * @param ProductSearch $products The ranked search.
	 * @param MatchDetails  $matches  The details.
	 */
	public function __construct( private ProductSearch $products, private MatchDetails $matches ) {
	}

	/**
	 * A product's details on this page, or null when it has none (or the page is not a ranked search).
	 *
	 * @param int $productId Product id.
	 */
	public function detail( int $productId ): ?MatchDetail {
		$answer = $this->products->answer();
		if ( null === $answer || ! isset( $answer['docs'][ $productId ] ) ) {
			return null;
		}
		$for = implode( "\n", array( $answer['query'], $answer['lang'], $answer['key'], $answer['from'] ) );
		if ( $for !== $this->for ) {
			$this->details = array();
			$this->for     = $for;
		}
		if ( ! array_key_exists( $productId, $this->details ) ) {
			global $wp_query;
			$shown = array( $productId );
			if ( $wp_query instanceof WP_Query && is_array( $wp_query->posts ) ) {
				foreach ( $wp_query->posts as $post ) {
					$shown[] = $post instanceof WP_Post ? $post->ID : (int) $post;
				}
			}
			$docs = array();
			foreach ( array_unique( $shown ) as $id ) {
				if ( isset( $answer['docs'][ $id ] ) && ! array_key_exists( $id, $this->details ) ) {
					$docs[ $answer['docs'][ $id ] ] = $id;
				}
			}
			$found = $this->matches->of( $docs, $answer['tokens'], $answer['key'], $answer['from'] );
			foreach ( $docs as $doc => $id ) {
				$this->details[ $id ] = $found[ $doc ] ?? null;
			}
		}
		return $this->details[ $productId ];
	}

	/**
	 * `woocommerce_product_get_image_id`: the named variation's own image.
	 *
	 * @param mixed $imageId The product's image.
	 * @param mixed $product The product.
	 * @return mixed
	 */
	public function imageId( $imageId, $product = null ) {
		if ( ! $product instanceof WC_Product ) {
			return $imageId;
		}
		return $this->detail( $product->get_id() )->imageId ?? $imageId;
	}

	/**
	 * `post_type_link`: a product's link with the named variation chosen.
	 *
	 * @param mixed $link The link.
	 * @param mixed $post The post.
	 * @return mixed
	 */
	public function link( $link, $post = null ) {
		if ( ! is_string( $link ) || ! $post instanceof WP_Post || 'product' !== $post->post_type ) {
			return $link;
		}
		$detail = $this->detail( $post->ID );
		return null === $detail ? $link : MatchDetails::url( $link, $detail );
	}

	/**
	 * The line under a classic card's title.
	 */
	public function printLine(): void {
		global $product;
		if ( $product instanceof WC_Product ) {
			echo $this->line( $product->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in line().
		}
	}

	/**
	 * The line after a block theme's product title.
	 *
	 * @param string|mixed  $content  The block's HTML.
	 * @param array<mixed>  $block    The parsed block.
	 * @param WP_Block|null $instance The block, with its context.
	 * @return string|mixed
	 */
	public function blockLine( $content, $block = array(), $instance = null ) {
		if ( ! is_string( $content ) || ! $instance instanceof WP_Block || 'product' !== ( $instance->context['postType'] ?? '' ) ) {
			return $content;
		}
		$id = $instance->context['postId'] ?? 0;
		return $content . $this->line( is_numeric( $id ) ? (int) $id : 0 );
	}

	/**
	 * The line for one product, or an empty string.
	 *
	 * @param int $productId Product id.
	 */
	public function line( int $productId ): string {
		$detail = $this->detail( $productId );
		$text   = null === $detail ? '' : MatchDetails::line( $detail );
		return '' === $text ? '' : '<p class="csearch-matched"><small>' . esc_html( $text ) . '</small></p>';
	}

	/**
	 * Forget the page's details (tests render several pages in one process).
	 */
	public function reset(): void {
		$this->details = array();
		$this->for     = '';
	}
}
