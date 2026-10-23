<?php
/**
 * The values of the attributes the shop filters by.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Index;

use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Cast;
use CreativeBranding\Search\Support\Options;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The words a query may use for a value of an attribute the owner chose under Tools (D-101): each term of those
 * attributes, by the folded word its name reads as (`8.25″` and `8.25 in` both read `8.25`), to its attribute and
 * slug. Names of more than one word are left out: a query word names one value. Kept in one option, forgotten
 * when a term of those attributes changes or the choice of attributes does, and rebuilt on the next use.
 */
final class AttributeTerms {

	/**
	 * Constructor.
	 *
	 * @param AnalyzerFactory $analyzers The tokenizer that reads a name as a query reads it.
	 */
	public function __construct( private AnalyzerFactory $analyzers ) {
	}

	/**
	 * The attribute taxonomies chosen that exist.
	 *
	 * @return string[]
	 */
	public static function taxonomies(): array {
		return array_values( array_filter( Cast::strings( Settings::get( 'filter_attributes' ) ), 'taxonomy_exists' ) );
	}

	/**
	 * A folded word to the values it names: attribute taxonomy to term slug.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function values(): array {
		if ( array() === Cast::strings( Settings::get( 'filter_attributes' ) ) ) {
			return array(); // None chosen: nothing to read.
		}
		$stored = get_option( Options::ATTRIBUTE_TERMS );
		if ( ! is_array( $stored ) ) {
			return $this->rebuild();
		}
		$out = array();
		foreach ( $stored as $word => $named ) {
			if ( is_array( $named ) ) {
				$out[ (string) $word ] = array_map( array( Cast::class, 'str' ), array_filter( $named, 'is_string', ARRAY_FILTER_USE_KEY ) );
			}
		}
		return $out;
	}

	/**
	 * Read every term of the chosen attributes again and keep the map.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function rebuild(): array {
		$tokenizer = $this->analyzers->tokenizer();
		$lang      = Languages::siteCode();
		$out       = array();
		foreach ( self::taxonomies() as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				if ( ! $term instanceof WP_Term ) {
					continue;
				}
				$word = $tokenizer->key( $term->name, $lang );
				// One word only, and the first term of an attribute to read as it keeps it.
				if ( '' !== $word && ! str_contains( $word, ' ' ) && ! isset( $out[ $word ][ $taxonomy ] ) ) {
					$out[ $word ][ $taxonomy ] = $term->slug;
				}
			}
		}
		update_option( Options::ATTRIBUTE_TERMS, $out, true );
		return $out;
	}

	/**
	 * A term was created, edited or deleted: when it is a value of a chosen attribute, forget the map, which the
	 * next search rebuilds (nothing on the request that saved it, and once however many terms it saved).
	 *
	 * @param int|mixed    $termId   Term id.
	 * @param int|mixed    $ttId     Term taxonomy id.
	 * @param string|mixed $taxonomy Taxonomy.
	 */
	public function termChanged( $termId = 0, $ttId = 0, $taxonomy = '' ): void {
		if ( in_array( Cast::str( $taxonomy ), self::taxonomies(), true ) ) {
			delete_option( Options::ATTRIBUTE_TERMS );
		}
	}

	/**
	 * The settings changed, by any path: when the chosen attributes did, forget the map.
	 *
	 * @param mixed $before The settings before.
	 * @param mixed $after  The settings after.
	 */
	public function settingsChanged( $before, $after ): void {
		$was = is_array( $before ) ? ( $before['filter_attributes'] ?? array() ) : array();
		$now = is_array( $after ) ? ( $after['filter_attributes'] ?? array() ) : array();
		if ( $was !== $now ) {
			delete_option( Options::ATTRIBUTE_TERMS );
		}
	}
}
