<?php
/**
 * The ranking other plugins ask for from PHP.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers `csearch_ranked_ids` (D-161): reads the caller's arguments, ignoring a value of the wrong type and any key
 * it doesn't know, and asks the search service. The ranking, the cache and the log stay in the service.
 */
final class CallerSearch {

	/**
	 * Constructor.
	 *
	 * @param SearchService $search The search service.
	 */
	public function __construct( private SearchService $search ) {
	}

	/**
	 * The filter's callback.
	 *
	 * @param mixed $answer Another plugin's answer, or null.
	 * @param mixed $query  The words to rank by.
	 * @param mixed $args   `lang`, `limit`, `source`, `exact`, `product_ids`.
	 * @return mixed Another plugin's answer untouched; else ours, or null while the index isn't ready.
	 */
	public function answer( mixed $answer, mixed $query = '', mixed $args = array() ): mixed {
		if ( null !== $answer ) {
			return $answer;
		}
		$args = is_array( $args ) ? $args : array();
		return $this->search->forCaller(
			is_string( $query ) ? $query : '',
			isset( $args['lang'] ) && is_string( $args['lang'] ) ? $args['lang'] : null,
			isset( $args['limit'] ) && is_int( $args['limit'] ) ? $args['limit'] : SearchService::MAX_IDS,
			isset( $args['source'] ) && is_string( $args['source'] ) ? $args['source'] : '',
			true === ( $args['exact'] ?? false ),
			isset( $args['product_ids'] ) && is_array( $args['product_ids'] ) ? self::ids( $args['product_ids'] ) : null
		);
	}

	/**
	 * The positive integer ids of a list, the first 2,000 different ones (D-162); anything else in it is dropped.
	 *
	 * @param array<mixed> $given As given.
	 * @return int[]
	 */
	private static function ids( array $given ): array {
		$ids = array();
		foreach ( $given as $item ) {
			if ( is_int( $item ) && $item > 0 ) {
				$ids[ $item ] = $item;
			}
		}
		return array_slice( array_values( $ids ), 0, SearchService::MAX_PRODUCTS );
	}
}
