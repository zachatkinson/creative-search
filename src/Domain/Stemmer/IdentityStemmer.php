<?php
/**
 * The stemmer for languages without one.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain\Stemmer;

defined( 'ABSPATH' ) || exit;

/**
 * Leaves the word alone: the stem of a word in a language the plugin has no stemmer for is the word itself.
 */
final class IdentityStemmer implements Stemmer {

	/**
	 * The word, unchanged.
	 *
	 * @param string $word A lower-cased word.
	 */
	public function stem( string $word ): string {
		return $word;
	}
}
