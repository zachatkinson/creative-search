<?php
/**
 * Which variation a query named and why a product matched, for the products shown.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Search;

use CreativeBranding\Search\Domain\MatchDetail;
use CreativeBranding\Search\Domain\MatchExplainer;
use CreativeBranding\Search\Index\Indexer;
use CreativeBranding\Search\Repository\IndexRepository;
use CreativeBranding\Search\Support\Cast;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * For the few products a panel or a results page shows (D-104, D-105): which of a query's terms each one's
 * document carries, field by field, and its SKUs and attribute values, two reads by document id, handed to the
 * Domain's `MatchExplainer`. Nothing is read for a product a page does not show, so the search's own budgets
 * are untouched.
 */
final class MatchDetails {

	/**
	 * Constructor.
	 *
	 * @param IndexRepository $index     The index.
	 * @param MatchExplainer  $explainer The rules.
	 * @param Cache           $cache     Answers.
	 */
	public function __construct( private IndexRepository $index, private MatchExplainer $explainer, private Cache $cache ) {
	}

	/**
	 * The details of some product documents, cached; a product with nothing to show is left out. A document id
	 * belongs to one generation and is never reused, so the ids key the answer without the generation, and a warm
	 * results page reads nothing more.
	 *
	 * @param int[]                $documentIds Product document ids, in one language.
	 * @param array<int, string[]> $tokens      The terms each query token reached.
	 * @param string               $key         The query's folded tokens joined by a space.
	 * @param string               $lang        The documents' language.
	 * @return array<int, MatchDetail> Document id to its details.
	 */
	public function of( array $documentIds, array $tokens, string $key, string $lang ): array {
		if ( array() === $documentIds || array() === $tokens ) {
			return array();
		}
		$cacheKey = Cache::key( 0, 'match', array( $documentIds, $tokens, $key, $lang ) );
		$cached   = $this->cache->get( $cacheKey );
		if ( null === $cached ) {
			$cached = array();
			foreach ( $this->explain( $documentIds, $tokens, $key, $lang ) as $id => $detail ) {
				$cached[ $id ] = array( $detail->variationId, $detail->imageId, $detail->args, $detail->reason, $detail->name, $detail->label );
			}
			$this->cache->set( $cacheKey, $cached );
		}
		$out = array();
		foreach ( $cached as $id => $row ) {
			if ( is_array( $row ) && 6 === count( $row ) ) {
				$image      = Cast::int( $row[1] );
				$out[ $id ] = new MatchDetail( Cast::int( $row[0] ), $image > 0 ? $image : null, self::args( $row[2] ), Cast::str( $row[3] ), Cast::str( $row[4] ), Cast::str( $row[5] ) );
			}
		}
		return $out;
	}

	/**
	 * Query arguments back from the cache.
	 *
	 * @param mixed $args The cached arguments.
	 * @return array<string, string>
	 */
	private static function args( mixed $args ): array {
		$out = array();
		foreach ( is_array( $args ) ? $args : array() as $name => $value ) {
			$out[ (string) $name ] = Cast::str( $value );
		}
		return $out;
	}

	/**
	 * The details, read and worked out.
	 *
	 * @param int[]                $documentIds Product document ids, in one language.
	 * @param array<int, string[]> $tokens      The terms each query token reached.
	 * @param string               $key         The query's folded tokens joined by a space.
	 * @param string               $lang        The documents' language.
	 * @return array<int, MatchDetail> Document id to its details.
	 */
	private function explain( array $documentIds, array $tokens, string $key, string $lang ): array {
		$skuKey = '' === $key ? '' : Indexer::SKU_KEY_PREFIX . mb_substr( $key, 0, 63, 'UTF-8' );
		$terms  = array_merge( ...array_values( $tokens ) );
		if ( '' !== $skuKey ) {
			$terms[] = $skuKey;
		}
		$fields = $this->index->fieldsOf( $documentIds, array_values( array_unique( $terms ) ), $lang );
		$facts  = $this->index->facts( $documentIds );
		$out    = array();
		foreach ( $documentIds as $id ) {
			$detail = $this->explainer->explain( $tokens, $skuKey, $fields[ $id ] ?? array(), $facts[ $id ] ?? array() );
			if ( ! $detail->isEmpty() ) {
				$out[ $id ] = $detail;
			}
		}
		return $out;
	}

	/**
	 * A product's URL with the arguments that preselect the variation a query named.
	 *
	 * @param string      $url    The product's URL.
	 * @param MatchDetail $detail Its details.
	 */
	public static function url( string $url, MatchDetail $detail ): string {
		return array() === $detail->args ? $url : add_query_arg( array_map( 'rawurlencode', $detail->args ), $url );
	}

	/**
	 * The line saying what matched ("Matches SKU KZ-825"), or an empty string.
	 *
	 * @param MatchDetail $detail The details.
	 */
	public static function line( MatchDetail $detail ): string {
		switch ( $detail->reason ) {
			case MatchDetail::SKU:
				/* translators: %s: a product's or variation's SKU */
				return sprintf( __( 'Matches SKU %s', 'creative-search' ), $detail->label );
			case MatchDetail::ATTRIBUTE:
				return '' === $detail->name
					/* translators: %s: an attribute value, such as 8.25 */
					? sprintf( __( 'Matches %s', 'creative-search' ), $detail->label )
					/* translators: 1: an attribute's name, such as Width, 2: its value, such as 8.25 */
					: sprintf( _x( 'Matches %1$s: %2$s', 'an attribute and its value', 'creative-search' ), $detail->name, $detail->label );
			case MatchDetail::DESCRIPTION:
				return __( 'Matches the description', 'creative-search' );
			default:
				return '';
		}
	}
}
