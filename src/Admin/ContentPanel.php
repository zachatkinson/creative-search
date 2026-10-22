<?php
/**
 * The post and page editor's Search box.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Admin;

use CreativeBranding\Search\Index\ContentReader;
use CreativeBranding\Search\Support\Options;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Exclude from search" on a post or page (1.1, Skatehouse handoff item 7), the same `_csearch_exclude` flag a
 * product carries. Shown only while that type is searched; saved with its own nonce and the post's edit
 * capability, and the meta change queues the post's reindex.
 */
final class ContentPanel {

	public const NONCE = 'csearch_content_exclude';

	/**
	 * Add the box to the types switched on.
	 */
	public function add(): void {
		foreach ( ContentReader::enabled() as $postType ) {
			add_meta_box( 'csearch-content', __( 'Search', 'creative-search' ), array( $this, 'render' ), $postType, 'side', 'low' );
		}
	}

	/**
	 * The box.
	 *
	 * @param WP_Post $post The post.
	 */
	public function render( WP_Post $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE );
		printf(
			'<label><input type="checkbox" name="csearch_exclude" value="yes"%1$s /> %2$s</label>',
			checked( 'yes', get_post_meta( $post->ID, Options::META_EXCLUDE, true ), false ),
			esc_html__( 'Exclude from search', 'creative-search' )
		);
	}

	/**
	 * Save the flag.
	 *
	 * @param int|mixed $postId Post id.
	 * @param mixed     $post   The post.
	 */
	public function save( $postId, $post = null ): void {
		if ( ! $post instanceof WP_Post || null === ContentReader::typeOf( $post->post_type ) || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		$nonce = isset( $_POST[ self::NONCE ] ) && is_string( $_POST[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		if ( isset( $_POST['csearch_exclude'] ) ) {
			update_post_meta( $post->ID, Options::META_EXCLUDE, 'yes' );
		} else {
			delete_post_meta( $post->ID, Options::META_EXCLUDE );
		}
	}
}
