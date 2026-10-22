<?php
/**
 * One synonym row.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The owner's "word means other words" in one language (spec: Data model → csearch_synonyms).
 */
final class Synonym {

	/**
	 * Constructor.
	 *
	 * @param string $lang     Language code.
	 * @param string $word     The word the shopper types.
	 * @param string $means    What it means: a word or a few.
	 * @param bool   $bothWays Whether the meaning's words also find the word.
	 */
	public function __construct(
		public readonly string $lang,
		public readonly string $word,
		public readonly string $means,
		public readonly bool $bothWays = false
	) {
	}
}
