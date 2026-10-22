<?php
/**
 * What a document is to the search.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The three kinds of document the index keeps apart (D-093): the categories, tags and brands the panel
 * suggests, the products it ranks, and the posts and pages searched beside them (1.1). Each posting carries
 * its document's kind, so a search of one kind never reads another's postings, and the content's term counts
 * are kept apart from the catalogue's: turning posts on changes no product's rank.
 */
final class Kind {

	public const TERM    = 0;
	public const PRODUCT = 1;
	public const CONTENT = 2;

	/**
	 * The kind of a document.
	 *
	 * @param Document $document The document.
	 */
	public static function of( Document $document ): int {
		return self::ofType( $document->type );
	}

	/**
	 * The kind of a document type.
	 *
	 * @param string $type A {@see Document} type.
	 */
	public static function ofType( string $type ): int {
		if ( Document::PRODUCT === $type ) {
			return self::PRODUCT;
		}
		return Document::POST === $type || Document::PAGE === $type ? self::CONTENT : self::TERM;
	}
}
