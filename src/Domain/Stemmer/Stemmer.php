<?php
/**
 * The stemmer contract.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain\Stemmer;

defined( 'ABSPATH' ) || exit;

/**
 * Reduces a lower-cased word to its stem (spec: Indexing → Stems). A stem is a help, never a replacement: the
 * caller stores the original token too.
 */
interface Stemmer {

	/**
	 * The stem of one lower-cased word.
	 *
	 * @param string $word A lower-cased word in UTF-8.
	 */
	public function stem( string $word ): string;
}
