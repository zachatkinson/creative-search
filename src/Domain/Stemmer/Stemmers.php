<?php
/**
 * The stemmer for a language.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain\Stemmer;

use CreativeBranding\Search\Domain\StopWords;

defined( 'ABSPATH' ) || exit;

/**
 * Porter for English, Snowball French for French, the identity for everything else (spec: French and other
 * languages → Per language). `fr_CA` is French.
 */
final class Stemmers {

	/**
	 * Instances by short language code.
	 *
	 * @var array<string, Stemmer>
	 */
	private array $cache = array();

	/**
	 * The stemmer for a language.
	 *
	 * @param string $lang Language code.
	 */
	public function for( string $lang ): Stemmer {
		$code = StopWords::code( $lang );
		if ( ! isset( $this->cache[ $code ] ) ) {
			$this->cache[ $code ] = match ( $code ) {
				'en'    => new PorterStemmer(),
				'fr'    => new FrenchStemmer(),
				default => new IdentityStemmer(),
			};
		}
		return $this->cache[ $code ];
	}
}
