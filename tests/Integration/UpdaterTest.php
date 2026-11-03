<?php
/**
 * Updates from the agency's releases (D-140).
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Tests\Support\IntegrationTestCase;
use CreativeBranding\Search\Updates\HttpManifestSource;
use CreativeBranding\Search\Updates\Manifest;
use CreativeBranding\Search\Updates\ManifestSource;
use CreativeBranding\Search\Updates\Updater;
use WP_Error;

final class UpdaterTest extends IntegrationTestCase {

	private const BASENAME = 'creative-search/creative-search.php';
	private const PACKAGE  = 'https://github.com/zachatkinson/creative-releases/releases/download/creative-search-9.0.0/creative-search-9.0.0.zip';

	/**
	 * A manifest source: `latest()` answers the cached manifest, `fresh()` the one read again (the same unless given).
	 *
	 * @param Manifest|null $manifest The cached manifest.
	 * @param Manifest|null $fresh    The manifest read again; false for the cached one.
	 */
	private function source( ?Manifest $manifest, Manifest|null|false $fresh = false ): ManifestSource {
		return new class( $manifest, false === $fresh ? $manifest : $fresh ) implements ManifestSource {
			public int $fresh = 0;
			public function __construct( private ?Manifest $manifest, private ?Manifest $again ) {}
			public function latest(): ?Manifest {
				return $this->manifest;
			}
			public function fresh(): ?Manifest {
				++$this->fresh;
				$this->manifest = $this->again;
				return $this->again;
			}
		};
	}

	/**
	 * A downloader that copies some bytes to a temporary file, counting its calls.
	 *
	 * @param string $bytes The package's bytes.
	 * @param int    $calls Set to the number of downloads.
	 */
	private static function downloader( string $bytes, int &$calls ): callable {
		return static function ( string $url ) use ( $bytes, &$calls ): string {
			++$calls;
			$file = wp_tempnam( 'csearch' );
			file_put_contents( $file, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return $file;
		};
	}

	private function manifest( string $sha = '', string $package = self::PACKAGE ): Manifest {
		return new Manifest( '9.0.0', $package, '' !== $sha ? $sha : str_repeat( 'a', 64 ), '7.0', '8.5', '7.1', 'https://github.com/zachatkinson/creative-releases', array( 'New <b>things</b>.' ) );
	}

	public function test_header_and_filter_host_agree(): void {
		$header = get_file_data( CSEARCH_FILE, array( 'uri' => 'Update URI' ) )['uri'];
		$this->assertSame( Updater::MANIFEST_URL, $header );
		$this->assertNotFalse( has_filter( 'update_plugins_raw.githubusercontent.com' ) );
	}

	public function test_core_sees_the_update(): void {
		$updater = new Updater( $this->source( $this->manifest() ), self::BASENAME );
		$update  = $updater->check( false, array(), self::BASENAME );
		$this->assertIsArray( $update );
		$this->assertSame( '9.0.0', $update['version'] );
		$this->assertSame( self::PACKAGE, $update['package'] );
		$this->assertSame( '8.5', $update['requires_php'] );
		$this->assertFalse( $updater->check( false, array(), 'other/other.php' ), 'other plugins on the same host are left alone' );
		$this->assertFalse( ( new Updater( $this->source( null ), self::BASENAME ) )->check( false, array(), self::BASENAME ) );
	}

	public function test_details_popup_escapes_the_changelog(): void {
		$updater = new Updater( $this->source( $this->manifest() ), self::BASENAME );
		$info    = $updater->details( false, 'plugin_information', (object) array( 'slug' => 'creative-search' ) );
		$this->assertIsObject( $info );
		$this->assertSame( '9.0.0', $info->version );
		$this->assertStringContainsString( 'New &lt;b&gt;things&lt;/b&gt;.', $info->sections['changelog'] );
		$this->assertFalse( $updater->details( false, 'plugin_information', (object) array( 'slug' => 'woocommerce' ) ) );
		$this->assertFalse( $updater->details( false, 'query_plugins', (object) array( 'slug' => 'creative-search' ) ) );
	}

	public function test_package_checked_against_its_sha256(): void {
		$file = wp_tempnam( 'csearch' );
		file_put_contents( $file, 'zip bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$download = static function ( string $url ) use ( $file ): string {
			$copy = $file . '.dl';
			copy( $file, $copy );
			return $copy;
		};

		$good = new Updater( $this->source( $this->manifest( hash( 'sha256', 'zip bytes' ) ) ), self::BASENAME, $download );
		$path = $good->verify( false, self::PACKAGE );
		$this->assertIsString( $path );
		$this->assertFileExists( $path );
		wp_delete_file( $path );

		$bad    = new Updater( $this->source( $this->manifest( hash( 'sha256', 'other bytes' ) ) ), self::BASENAME, $download );
		$result = $bad->verify( false, self::PACKAGE );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'csearch_package_mismatch', $result->get_error_code() );
		$this->assertFileDoesNotExist( $file . '.dl', 'a mismatched download is deleted' );

		$this->assertFalse( $good->verify( false, 'https://downloads.wordpress.org/plugin/woocommerce.zip' ), 'other packages are left to core' );
		wp_delete_file( $file );
	}

	public function test_turned_off(): void {
		add_filter( 'csearch_updates_enabled', '__return_false' );
		$this->assertFalse( Updater::enabled() );
	}

	public function test_http_source_caches_and_retries_after_failure(): void {
		HttpManifestSource::flush();
		$calls = 0;
		$body  = '';
		$agent = '';
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$calls, &$body, &$agent ) {
				++$calls;
				$agent = $args['user-agent'];
				return array(
					'headers'  => array(),
					'body'     => $body,
					'response' => array( 'code' => '' === $body ? 500 : 200, 'message' => '' ),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
		$source = new HttpManifestSource( Updater::MANIFEST_URL );
		$this->assertNull( $source->latest() );
		$this->assertNull( $source->latest() );
		$this->assertSame( 1, $calls, 'a failure is cached too' );
		$this->assertSame( 'Creative Search/' . CSEARCH_VERSION, $agent, 'the request does not name the site' );

		HttpManifestSource::flush();
		$body = (string) wp_json_encode( array( 'version' => '9.0.0', 'package' => self::PACKAGE, 'sha256' => str_repeat( 'b', 64 ) ) );
		$this->assertSame( '9.0.0', $source->latest()?->version );
		$this->assertSame( '9.0.0', $source->latest()?->version );
		$this->assertSame( 2, $calls );
		HttpManifestSource::flush();
	}

	public function test_a_package_the_manifest_no_longer_names_is_checked_against_it_read_again(): void {
		$newer = 'https://github.com/zachatkinson/creative-releases/releases/download/creative-search-9.0.1/creative-search-9.0.1.zip';
		$calls = 0;
		$stale = $this->source( $this->manifest( hash( 'sha256', 'zip bytes' ) ), $this->manifest( hash( 'sha256', 'zip bytes' ), $newer ) );
		$path  = ( new Updater( $stale, self::BASENAME, self::downloader( 'zip bytes', $calls ) ) )->verify( false, $newer );
		$this->assertIsString( $path, 'published since the last check: the manifest read again names it, and its hash matches' );
		$this->assertSame( 1, $stale->fresh );
		wp_delete_file( $path );

		$calls   = 0;
		$unknown = $this->source( $this->manifest() );
		$result  = ( new Updater( $unknown, self::BASENAME, self::downloader( 'zip bytes', $calls ) ) )->verify( false, $newer );
		$this->assertInstanceOf( WP_Error::class, $result, 'a package the manifest does not name is refused, not passed through (D-144)' );
		$this->assertSame( 'csearch_package_unknown', $result->get_error_code() );
		$this->assertStringContainsString( 'Check for updates again', $result->get_error_message() );
		$this->assertSame( 1, $unknown->fresh, 'read again once' );
		$this->assertSame( 0, $calls, 'nothing downloaded' );
	}

	public function test_a_sibling_plugins_release_is_left_to_its_own_updater(): void {
		$calls  = 0;
		$source = $this->source( null );
		$sister = 'https://github.com/zachatkinson/creative-releases/releases/download/creative-restock-1.0.0/creative-restock-1.0.0.zip';
		$this->assertFalse( ( new Updater( $source, self::BASENAME, self::downloader( 'zip bytes', $calls ) ) )->verify( false, $sister ), 'the same releases repository, another plugin (D-144)' );
		$this->assertSame( 0, $source->fresh, 'its manifest is not read again for it' );
		$this->assertSame( 0, $calls );
	}

	public function test_an_unreadable_manifest_refuses_the_package(): void {
		$calls  = 0;
		$source = $this->source( null );
		$result = ( new Updater( $source, self::BASENAME, self::downloader( 'zip bytes', $calls ) ) )->verify( false, self::PACKAGE );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'csearch_package_unknown', $result->get_error_code() );
		$this->assertSame( 1, $source->fresh );
		$this->assertSame( 0, $calls );
	}

	public function test_the_package_core_offers_for_this_plugin_is_checked_wherever_it_points(): void {
		$elsewhere = 'https://example.com/creative-search.zip';
		$updates   = (object) array(
			'response' => array(
				self::BASENAME => (object) array( 'package' => $elsewhere ),
			),
		);
		set_site_transient( 'update_plugins', $updates );
		$calls  = 0;
		$result = ( new Updater( $this->source( $this->manifest() ), self::BASENAME, self::downloader( 'zip bytes', $calls ) ) )->verify( false, $elsewhere );
		$this->assertInstanceOf( WP_Error::class, $result, 'offered for this plugin, so it must match the manifest' );
		$this->assertSame( 'csearch_package_unknown', $result->get_error_code() );
		$this->assertFalse( ( new Updater( $this->source( $this->manifest() ), 'other/other.php' ) )->verify( false, $elsewhere ), "another plugin's package is left to core" );
		delete_site_transient( 'update_plugins' );
	}

	public function test_a_matching_package_and_hash_is_installed_without_reading_again(): void {
		$calls  = 0;
		$source = $this->source( $this->manifest( hash( 'sha256', 'zip bytes' ) ) );
		$path   = ( new Updater( $source, self::BASENAME, self::downloader( 'zip bytes', $calls ) ) )->verify( false, self::PACKAGE );
		$this->assertIsString( $path );
		$this->assertSame( 0, $source->fresh, 'the cached manifest names it' );
		$this->assertSame( 1, $calls );
		wp_delete_file( $path );
	}

	public function test_the_manifest_read_again_replaces_the_cached_one(): void {
		HttpManifestSource::flush();
		$body = (string) wp_json_encode( array( 'version' => '9.0.0', 'package' => self::PACKAGE, 'sha256' => str_repeat( 'b', 64 ) ) );
		$hits = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$hits, &$body ) {
				++$hits;
				return array(
					'headers'  => array(),
					'body'     => $body,
					'response' => array( 'code' => 200, 'message' => '' ),
					'cookies'  => array(),
				);
			}
		);
		$source = new HttpManifestSource( Updater::MANIFEST_URL );
		$this->assertSame( '9.0.0', $source->latest()?->version );
		$body = (string) wp_json_encode( array( 'version' => '9.0.1', 'package' => self::PACKAGE, 'sha256' => str_repeat( 'b', 64 ) ) );
		$this->assertSame( '9.0.0', $source->latest()?->version, 'cached' );
		$this->assertSame( '9.0.1', $source->fresh()?->version, 'read again, past the cache' );
		$this->assertSame( '9.0.1', $source->latest()?->version, 'and cached in its place' );
		$this->assertSame( 2, $hits );
		HttpManifestSource::flush();
	}
}
