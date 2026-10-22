<?php
/**
 * Plugin bootstrap.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires services to hooks. Hook callbacks live in one class per concern; the work is in services.
 */
final class Plugin {

	/**
	 * Whether boot() ran.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * The service container, built once.
	 *
	 * @var Container|null
	 */
	private static ?Container $container = null;

	/**
	 * Boot on plugins_loaded.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		if ( ! self::woocommerceOk() ) {
			add_action( 'admin_notices', array( self::class, 'missingWooCommerceNotice' ) );
			return;
		}

		// The schema upgrade runs on admin_init and before WP-CLI commands (Install\Upgrader), never here.
		foreach ( self::container()->hookables() as $hookable ) {
			$hookable->register();
		}
	}

	/**
	 * The container (lazily built).
	 */
	public static function container(): Container {
		if ( null === self::$container ) {
			self::$container = new Container();
		}
		return self::$container;
	}

	/**
	 * Whether WooCommerce is active and new enough.
	 */
	private static function woocommerceOk(): bool {
		if ( ! class_exists( 'WooCommerce' ) || ! defined( 'WC_VERSION' ) ) {
			return false;
		}
		return version_compare( (string) WC_VERSION, CSEARCH_MIN_WC, '>=' );
	}

	/**
	 * Admin notice when WooCommerce is missing or too old.
	 */
	public static function missingWooCommerceNotice(): void {
		wp_admin_notice(
			sprintf(
				/* translators: %s: minimum WooCommerce version */
				esc_html__( 'Creative Search needs WooCommerce %s or newer and loads nothing until it is active.', 'creative-search' ),
				esc_html( CSEARCH_MIN_WC )
			),
			array( 'type' => 'error' )
		);
	}
}
