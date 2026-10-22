<?php
/**
 * One token.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * One searchable unit cut from text, with its position (spec: Vocabulary → Token). Two tokens can share a
 * position: `Philly’s` gives `phillys` and `philly` at the same place. The source keeps the lower-cased word
 * before accent folding, because the stemmers read accents (`qualité` → `qualit`); the stem is folded after.
 */
final class Token {

	/**
	 * Constructor.
	 *
	 * @param string $text     The folded token, as indexed and searched.
	 * @param int    $position Zero-based position among the kept tokens of the text.
	 * @param string $source   The lower-cased word before folding; the token itself when nothing differs.
	 */
	public function __construct( public readonly string $text, public readonly int $position, public readonly string $source = '' ) {
	}

	/**
	 * The lower-cased, unfolded word the stemmer reads.
	 */
	public function source(): string {
		return '' === $this->source ? $this->text : $this->source;
	}
}
