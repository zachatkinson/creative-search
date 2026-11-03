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

	private function source( ?Manifest $manifest ): ManifestSource {
		return new class( $manifest ) implements ManifestSource {
			public function __construct( private ?Manifest $manifest ) {}
			public function latest(): ?Manifest {
				return $this->manifest;
			}
		};
	}

	private function manifest( string $sha = '' ): Manifest {
		return new Manifest( '9.0.0', self::PACKAGE, '' !== $sha ? $sha : str_repeat( 'a', 64 ), '7.0', '8.5', '7.1', 'https://github.com/zachatkinson/creative-releases', array( 'New <b>things</b>.' ) );
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
}
