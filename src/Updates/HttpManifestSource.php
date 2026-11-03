<?php
/**
 * The release manifest over https.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Updates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches the manifest with WordPress's HTTP API, at most once every 12 hours (once an hour after a failure), and
 * sends nothing about the site beyond what any https request carries.
 */
final class HttpManifestSource implements ManifestSource {

	public const CACHE    = 'csearch_update_manifest';
	private const TTL     = 12 * HOUR_IN_SECONDS;
	private const RETRY   = HOUR_IN_SECONDS;
	private const TIMEOUT = 10;

	/**
	 * Constructor.
	 *
	 * @param string $url The manifest's https address.
	 */
	public function __construct( private string $url ) {}

	/**
	 * The latest release, cached.
	 */
	public function latest(): ?Manifest {
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) && array_key_exists( 'data', $cached ) ) {
			return Manifest::fromArray( $cached['data'] );
		}
		$data = $this->fetch();
		set_site_transient( self::CACHE, array( 'data' => $data ), null !== Manifest::fromArray( $data ) ? self::TTL : self::RETRY );
		return Manifest::fromArray( $data );
	}

	/**
	 * The latest release read again now; the answer replaces the cached one (D-144).
	 */
	public function fresh(): ?Manifest {
		self::flush();
		return $this->latest();
	}

	/**
	 * Forget the cached manifest (Dashboard → Updates → Check again).
	 */
	public static function flush(): void {
		delete_site_transient( self::CACHE );
	}

	/**
	 * The decoded manifest, or null.
	 *
	 * @return mixed
	 */
	private function fetch() {
		$response = wp_safe_remote_get(
			$this->url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 2,
				// WordPress's default user agent names the site; ours names only the plugin.
				'user-agent'  => 'Creative Search/' . CSEARCH_VERSION,
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		return json_decode( wp_remote_retrieve_body( $response ), true );
	}
}
