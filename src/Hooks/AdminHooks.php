<?php
/**
 * The admin.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Hooks;

use CreativeBranding\Search\Admin\Actions;
use CreativeBranding\Search\Admin\AdminPage;
use CreativeBranding\Search\Admin\ContentPanel;
use CreativeBranding\Search\Admin\ProductPanel;
use CreativeBranding\Search\Hookable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce → Search, its actions, the warnings on every admin screen, the product editor's Search panel and
 * the product CSV columns.
 */
final class AdminHooks implements Hookable {

	/**
	 * Constructor.
	 *
	 * @param AdminPage    $page    The screen.
	 * @param Actions      $actions The forms' handlers.
	 * @param ProductPanel $panel   The product editor's panel.
	 * @param ContentPanel $content The post and page editor's box.
	 */
	public function __construct( private AdminPage $page, private Actions $actions, private ProductPanel $panel, private ContentPanel $content ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this->page, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( $this->page, 'enqueue' ) );
		add_action( 'admin_notices', array( $this->page, 'warnings' ) );
		foreach ( array_keys( Actions::handlers() ) as $action ) {
			add_action( 'admin_post_' . $action, fn() => $this->actions->handle( $action ) );
		}
		add_action( 'add_meta_boxes', array( $this->content, 'add' ) );
		add_action( 'save_post', array( $this->content, 'save' ), 10, 2 );
		add_filter( 'woocommerce_product_data_tabs', array( $this->panel, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this->panel, 'panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this->panel, 'save' ) );
		add_filter( 'woocommerce_product_export_product_default_columns', array( $this->panel, 'exportColumns' ) );
		foreach ( array_keys( ProductPanel::COLUMNS ) as $column ) {
			add_filter( 'woocommerce_product_export_product_column_' . $column, array( $this->panel, 'exportValue' ), 10, 3 );
		}
		add_filter( 'woocommerce_product_export_skip_meta_keys', array( $this->panel, 'skipMeta' ) );
		add_filter( 'woocommerce_product_import_pre_insert_product_object', array( $this->panel, 'import' ) );
	}
}
