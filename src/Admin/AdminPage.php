<?php
/**
 * WooCommerce → Search.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin's one admin screen (spec: Admin): a submenu of WooCommerce with four tabs, WordPress's own markup,
 * every action a form posted to `admin-post.php` with a nonce ({@see Actions}), every change answered by a notice.
 */
final class AdminPage {

	public const SLUG   = 'csearch';
	public const SCRIPT = 'csearch-admin';

	/**
	 * The screen's hook suffix, once the menu is added.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Constructor.
	 *
	 * @param AnalyticsTab $analytics The Analytics tab.
	 * @param SynonymsTab  $synonyms  The Synonyms tab.
	 * @param BoostsTab    $boosts    The Boosts and exclusions tab.
	 * @param RulesTab     $rules     The Redirects and pins tab.
	 * @param ToolsTab     $tools     The Tools tab.
	 * @param Notices      $notices   The warnings.
	 */
	public function __construct(
		private AnalyticsTab $analytics,
		private SynonymsTab $synonyms,
		private BoostsTab $boosts,
		private RulesTab $rules,
		private ToolsTab $tools,
		private Notices $notices
	) {
	}

	/**
	 * The tabs, slug to label.
	 *
	 * @return array<string, string>
	 */
	public static function tabs(): array {
		return array(
			'analytics' => __( 'Analytics', 'creative-search' ),
			'synonyms'  => __( 'Synonyms', 'creative-search' ),
			'boosts'    => __( 'Boosts and exclusions', 'creative-search' ),
			'rules'     => __( 'Redirects and pins', 'creative-search' ),
			'tools'     => __( 'Tools', 'creative-search' ),
		);
	}

	/**
	 * The URL of a tab.
	 *
	 * @param string               $tab  Tab slug.
	 * @param array<string, mixed> $args More query arguments.
	 */
	public static function url( string $tab = 'analytics', array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::SLUG,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The tab the request asks for.
	 */
	public static function currentTab(): string {
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'analytics'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing a tab changes nothing.
		return array_key_exists( $tab, self::tabs() ) ? $tab : 'analytics';
	}

	/**
	 * The opening of a form posted to `admin-post.php`, with its action and nonce.
	 *
	 * @param string $action  The action (its nonce has the same name).
	 * @param string $classes More classes.
	 * @param bool   $upload  Whether the form sends a file.
	 */
	public static function formStart( string $action, string $classes = '', bool $upload = false ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="csearch-form ' . esc_attr( $classes ) . '"' . ( $upload ? ' enctype="multipart/form-data"' : '' ) . '>'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">'
			. wp_nonce_field( $action, '_wpnonce', true, false );
	}

	/**
	 * The Plugins screen's links for this plugin, with Settings first, as the other Creative plugins have it.
	 *
	 * @param mixed $links The links WordPress gives (Deactivate, …).
	 * @return array<int|string, mixed>
	 */
	public function actionLinks( mixed $links ): array {
		$links = is_array( $links ) ? $links : array();
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $links;
		}
		return array( 'settings' => '<a href="' . esc_url( self::url( 'tools' ) ) . '">' . esc_html__( 'Settings', 'creative-search' ) . '</a>' ) + $links;
	}

	/**
	 * Add the submenu under WooCommerce.
	 */
	public function menu(): void {
		$hook = add_submenu_page(
			'woocommerce',
			__( 'Search', 'creative-search' ),
			__( 'Search', 'creative-search' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
		if ( is_string( $hook ) ) {
			$this->hook = $hook;
			add_action( 'load-' . $hook, array( $this, 'load' ) );
		}
	}

	/**
	 * Before the screen prints: the list table's bulk actions, which post back to the screen itself.
	 */
	public function load(): void {
		if ( 'synonyms' === self::currentTab() ) {
			$this->synonyms->load();
		}
	}

	/**
	 * Scripts and styles of the screen.
	 *
	 * @param string $hook The screen's hook suffix.
	 */
	public function enqueue( string $hook ): void {
		if ( '' === $this->hook || $hook !== $this->hook ) {
			return;
		}
		$base = CSEARCH_URL . 'assets/admin/';
		wp_enqueue_style( self::SCRIPT, $base . 'admin.css', array(), CSEARCH_VERSION );
		if ( 'tools' === self::currentTab() ) {
			wp_enqueue_script( self::SCRIPT, $base . 'tools.js', array( 'wp-api-fetch', 'wp-i18n' ), CSEARCH_VERSION, array( 'in_footer' => true ) );
			wp_set_script_translations( self::SCRIPT, 'creative-search', CSEARCH_PATH . 'languages' );
		}
	}

	/**
	 * The warnings, on every admin screen, for those who can act on them.
	 */
	public function warnings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		foreach ( $this->notices->warnings() as $id => $details ) {
			$type = 'stale' === $id || 'errors' === $id ? 'warning' : 'error';
						/* translators: %s: a warning about the search index */
			echo '<div class="notice notice-' . esc_attr( $type ) . ' csearch-notice"><p>' . esc_html( sprintf( __( 'Creative Search: %s', 'creative-search' ), Notices::message( $id, $details ) ) ) . '</p>';
			if ( isset( $details['errors'] ) ) {
				echo '<ul class="ul-disc">';
				foreach ( array_slice( $details['errors'], 0, 10 ) as $line ) {
					echo '<li>' . esc_html( $line ) . '</li>';
				}
				echo '</ul>';
			}
			echo '<p>';
			if ( 'stale' !== $id ) {
				echo self::formStart( Actions::REINDEX, 'csearch-inline' ) . get_submit_button( __( 'Rebuild the index', 'creative-search' ), 'primary small', 'submit', false ) . '</form> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			}
			echo self::formStart( Actions::DISMISS, 'csearch-inline' ) . '<input type="hidden" name="notice" value="' . esc_attr( $id ) . '">' . get_submit_button( __( 'Dismiss', 'creative-search' ), 'secondary small', 'submit', false ) . '</form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			echo '</p></div>';
		}
	}

	/**
	 * The screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage the shop’s search.', 'creative-search' ), 403 );
		}
		$current = self::currentTab();
		echo '<div class="wrap csearch-admin">';
		echo '<h1>' . esc_html__( 'Search', 'creative-search' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Search settings', 'creative-search' ) . '">';
		foreach ( self::tabs() as $slug => $label ) {
			echo '<a href="' . esc_url( self::url( $slug ) ) . '" class="nav-tab' . ( $slug === $current ? ' nav-tab-active" aria-current="page' : '' ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		$flash = Notices::takeFlash();
		if ( null !== $flash ) {
			echo '<div class="notice notice-' . esc_attr( $flash[0] ) . ' is-dismissible" role="' . ( 'error' === $flash[0] ? 'alert' : 'status' ) . '"><p>' . esc_html( $flash[1] ) . '</p></div>';
		}
		switch ( $current ) {
			case 'synonyms':
				$this->synonyms->render();
				break;
			case 'boosts':
				$this->boosts->render();
				break;
			case 'rules':
				$this->rules->render();
				break;
			case 'tools':
				$this->tools->render();
				break;
			default:
				$this->analytics->render();
		}
		echo '</div>';
	}
}
