<?php
/**
 * The languages to index.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Index;

use CreativeBranding\Search\Domain\StopWords;
use CreativeBranding\Search\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `csearch_languages` filter (spec: French and other languages): the codes to index, each with how to
 * read a product's text and a term's name in that language. The default is the site's language read from the
 * product itself; the `languages` setting adds codes; a plugin such as skatehouse-core adds readers.
 */
final class Languages {

	/**
	 * The languages, resolved once per request.
	 *
	 * @var array<string, array{product: ?callable, term: ?callable, content: ?callable}>|null
	 */
	private ?array $languages = null;

	/**
	 * The site's language code (`en` for `en_US`): the language set under Settings → General, not this request's
	 * locale, which a multilingual plugin or a language switcher changes per visitor (D-076).
	 */
	public static function siteCode(): string {
		// `get_locale()`'s order without its `locale` filter: the constant, overridden by the site's option when
		// it exists (an empty string is the site choosing English), on multisite the network's when it does not.
		$locale = defined( 'WPLANG' ) ? constant( 'WPLANG' ) : '';
		$stored = get_option( 'WPLANG' );
		if ( false === $stored && is_multisite() ) {
			$stored = get_site_option( 'WPLANG' );
		}
		if ( false !== $stored ) {
			$locale = $stored;
		}
		return StopWords::code( is_string( $locale ) && '' !== $locale ? $locale : 'en_US' );
	}

	/**
	 * Codes with their readers.
	 *
	 * @return array<string, array{product: ?callable, term: ?callable, content: ?callable}>
	 */
	public function all(): array {
		if ( null !== $this->languages ) {
			return $this->languages;
		}
		$codes = array( self::siteCode() );
		$set   = Settings::get( 'languages' );
		foreach ( is_array( $set ) ? $set : array() as $code ) {
			if ( is_string( $code ) ) {
				$codes[] = StopWords::code( $code );
			}
		}
		$defaults = array();
		foreach ( array_unique( $codes ) as $code ) {
			$defaults[ $code ] = array();
		}
		/**
		 * The languages to index, with how to read a product in each.
		 *
		 * @since 0.4.0
		 *
		 * @param array<string, array{product?: callable, term?: callable, content?: callable}> $languages Code to
		 *     readers. `product` receives the WC_Product, the field texts read from the product itself (Domain\Fields
		 *     ids to text) and the code, and returns the field texts in that language; `term` receives the WP_Term,
		 *     its name and the code, and returns the name in that language; `content` (since 1.1.0) receives the
		 *     WP_Post, its field texts and the code, and returns the field texts in that language. Any may be left
		 *     out to read the object as is.
		 */
		$filtered = apply_filters( 'csearch_languages', $defaults );
		$out      = array();
		foreach ( is_array( $filtered ) ? $filtered : $defaults as $code => $readers ) {
			if ( ! is_string( $code ) || 1 !== preg_match( '/^[a-z]{2,8}$/', StopWords::code( $code ) ) ) {
				continue;
			}
			$readers                         = is_array( $readers ) ? $readers : array();
			$out[ StopWords::code( $code ) ] = array(
				'product' => isset( $readers['product'] ) && is_callable( $readers['product'] ) ? $readers['product'] : null,
				'term'    => isset( $readers['term'] ) && is_callable( $readers['term'] ) ? $readers['term'] : null,
				'content' => isset( $readers['content'] ) && is_callable( $readers['content'] ) ? $readers['content'] : null,
			);
		}
		if ( array() === $out ) {
			$out[ self::siteCode() ] = array(
				'product' => null,
				'term'    => null,
				'content' => null,
			);
		}
		$this->languages = $out;
		return $out;
	}

	/**
	 * The codes.
	 *
	 * @return string[]
	 */
	public function codes(): array {
		return array_keys( $this->all() );
	}

	/**
	 * Forget the resolved list (tests change the filter between cases).
	 */
	public function flush(): void {
		$this->languages = null;
	}
}
