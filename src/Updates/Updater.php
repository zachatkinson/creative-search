<?php
/**
 * Updates from the agency's releases.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Updates;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Offers new releases on the Plugins and Updates screens through WordPress core's `update_plugins_{hostname}`
 * filter for the `Update URI` header, so updates and auto-updates work as for any plugin. The zip is checked against
 * the manifest's SHA-256 before WordPress unpacks it. Registered before the WooCommerce check, so a fix still
 * arrives while WooCommerce is missing or too old. The one outbound request the plugin makes (D-140). Turned off by `define( 'CSEARCH_DISABLE_UPDATES', true )` or the
 * `csearch_updates_enabled` filter.
 */
final class Updater {

	/**
	 * The manifest's address; its hostname must be the `Update URI` header's.
	 */
	public const MANIFEST_URL = 'https://raw.githubusercontent.com/zachatkinson/creative-releases/main/creative-search/info.json'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- The update manifest, read server side; no asset is loaded from it.

	public const SLUG = 'creative-search';

	/**
	 * How a package is downloaded to a temporary file.
	 *
	 * @var callable(string): (string|WP_Error)
	 */
	private $download;

	/**
	 * Constructor.
	 *
	 * @param ManifestSource                             $source   The latest release.
	 * @param string                                     $basename The plugin's basename (creative-search/creative-search.php).
	 * @param (callable(string): (string|WP_Error))|null $download Downloader; WordPress's own when null.
	 */
	public function __construct( private ManifestSource $source, private string $basename, ?callable $download = null ) {
		$this->download = $download ?? static function ( string $url ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			return download_url( $url );
		};
	}

	/**
	 * Attach hooks, unless updates are turned off.
	 */
	public function register(): void {
		if ( ! self::enabled() ) {
			return;
		}
		add_filter( 'update_plugins_' . (string) wp_parse_url( self::MANIFEST_URL, PHP_URL_HOST ), array( $this, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'verify' ), 10, 2 );
		add_action( 'load-update-core.php', array( self::class, 'forceCheck' ) );
	}

	/**
	 * Whether update checks are on.
	 */
	public static function enabled(): bool {
		$on = ! ( defined( 'CSEARCH_DISABLE_UPDATES' ) && CSEARCH_DISABLE_UPDATES );
		/**
		 * Filter whether Creative Search checks the agency's releases for updates.
		 *
		 * @param bool $on Whether update checks are on.
		 */
		return (bool) apply_filters( 'csearch_updates_enabled', $on );
	}

	/**
	 * The update core asks about, for this plugin only.
	 *
	 * @param mixed $update      What another filter returned, or false.
	 * @param mixed $plugin_data The plugin's header data.
	 * @param mixed $plugin_file The plugin's basename.
	 * @return mixed
	 */
	public function check( $update, $plugin_data, $plugin_file ) {
		unset( $plugin_data );
		if ( $this->basename !== $plugin_file ) {
			return $update;
		}
		$manifest = $this->source->latest();
		if ( null === $manifest ) {
			return $update;
		}
		return array(
			'id'           => self::MANIFEST_URL,
			'slug'         => self::SLUG,
			'plugin'       => $this->basename,
			'version'      => $manifest->version,
			'new_version'  => $manifest->version,
			'url'          => $manifest->url,
			'package'      => $manifest->package,
			'tested'       => $manifest->tested,
			'requires'     => $manifest->requires,
			'requires_php' => $manifest->requires_php,
		);
	}

	/**
	 * The "View details" popup.
	 *
	 * @param mixed $result What another filter returned, or false.
	 * @param mixed $action The plugins_api action.
	 * @param mixed $args   Its arguments.
	 * @return mixed
	 */
	public function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$manifest = $this->source->latest();
		if ( null === $manifest ) {
			return $result;
		}
		$changelog = array() !== $manifest->changelog
			? '<h4>' . esc_html( $manifest->version ) . '</h4><ul><li>' . implode( '</li><li>', array_map( 'esc_html', $manifest->changelog ) ) . '</li></ul>'
			: '';
		return (object) array(
			'name'          => 'Creative Search',
			'slug'          => self::SLUG,
			'version'       => $manifest->version,
			'author'        => '<a href="https://creativebranding.ca">Creative Branding</a>',
			'homepage'      => $manifest->url,
			'requires'      => $manifest->requires,
			'requires_php'  => $manifest->requires_php,
			'tested'        => $manifest->tested,
			'download_link' => $manifest->package,
			'sections'      => array(
				'description' => '<p>' . esc_html__( 'Search for WooCommerce: live suggestions, typo tolerance, SKU and attribute matching, its own index, analytics, an accessible search bar.', 'creative-search' ) . '</p>',
				'changelog'   => $changelog,
			),
		);
	}

	/**
	 * Download the package and check its SHA-256 against the manifest before WordPress unpacks it.
	 *
	 * @param mixed $reply   False, or what another filter returned.
	 * @param mixed $package The package address.
	 * @return mixed The downloaded file's path, a WP_Error, or $reply for other packages.
	 */
	public function verify( $reply, $package ) {
		if ( false !== $reply || ! is_string( $package ) ) {
			return $reply;
		}
		$manifest = $this->source->latest();
		if ( null === $manifest || $manifest->package !== $package ) {
			return $reply;
		}
		$file = ( $this->download )( $package );
		if ( ! is_string( $file ) ) {
			return $file;
		}
		$hash = hash_file( 'sha256', $file );
		if ( ! is_string( $hash ) || ! hash_equals( $manifest->sha256, $hash ) ) {
			wp_delete_file( $file );
			return new WP_Error( 'csearch_package_mismatch', __( 'The Creative Search update did not match its published checksum, so it was not installed.', 'creative-search' ) );
		}
		return $file;
	}

	/**
	 * Dashboard → Updates → Check again: read the manifest afresh.
	 */
	public static function forceCheck(): void {
		if ( isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only clears a cache, as core's own check does.
			HttpManifestSource::flush();
		}
	}
}
