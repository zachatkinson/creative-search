<?php
/**
 * Reads a category, tag or brand for the index.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Index;

use CreativeBranding\Search\Domain\Document;
use CreativeBranding\Search\Domain\Fields;
use WP_Term;
use CreativeBranding\Search\Support\Cast;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Categories, tags and brands become documents of their own so a suggestion can offer "Decks (122)"
 * (spec: Indexing → What goes in). One document per term and language: its name (and description) to
 * tokenise, its archive link, its image, its product count as the tie-break.
 */
final class TermReader {

	/**
	 * Taxonomy to document type.
	 */
	public const TYPES = array(
		ProductReader::CATEGORY_TAXONOMY => Document::CATEGORY,
		ProductReader::TAG_TAXONOMY      => Document::TAG,
		ProductReader::BRAND_TAXONOMY    => Document::BRAND,
	);

	/**
	 * Constructor.
	 *
	 * @param Languages $languages The languages to index.
	 */
	public function __construct( private Languages $languages ) {
	}

	/**
	 * The document type of a taxonomy, or null when it is not indexed.
	 *
	 * @param string $taxonomy Taxonomy.
	 */
	public static function typeOf( string $taxonomy ): ?string {
		return self::TYPES[ $taxonomy ] ?? null;
	}

	/**
	 * The taxonomies that exist on this site, with their types.
	 *
	 * @return array<string, string>
	 */
	public static function taxonomies(): array {
		return array_filter( self::TYPES, static fn( string $taxonomy ): bool => taxonomy_exists( $taxonomy ), ARRAY_FILTER_USE_KEY );
	}

	/**
	 * The term in every language.
	 *
	 * @param WP_Term $term The term.
	 * @return ProductText[] By language code.
	 */
	public function read( WP_Term $term ): array {
		$link = get_term_link( $term );
		$url  = is_string( $link ) ? $link : '';
		$out  = array();
		foreach ( $this->languages->all() as $code => $readers ) {
			$name = $term->name;
			if ( null !== $readers['term'] ) {
				$read = call_user_func( $readers['term'], $term, $term->name, $code );
				if ( is_string( $read ) && '' !== $read ) {
					$name = $read;
				}
			}
			$image        = Cast::int( get_term_meta( $term->term_id, 'thumbnail_id', true ) );
			$out[ $code ] = new ProductText(
				$code,
				$name,
				array(
					Fields::NAME  => $name,
					Fields::SHORT => ProductReader::plain( $term->description, ProductReader::LONG_DESCRIPTION_CHARS ),
				),
				array(),
				$url,
				$image > 0 ? $image : null,
				null,
				null,
				true,
				true,
				0,
				Cast::int( $term->count )
			);
		}
		return $out;
	}
}
