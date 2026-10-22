<?php
/**
 * A ranking, explained.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Search;

use CreativeBranding\Search\Domain\Fields;
use CreativeBranding\Search\Domain\Hit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Each candidate with its tokens, fields, weights and bonuses (spec: Matching → Explain), as `wp csearch explain`
 * prints it and the Tools tab's test search shows it.
 */
final class Explanation {

	/**
	 * Explain rows.
	 *
	 * @param Hit[] $hits Hits.
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows( array $hits ): array {
		$out = array();
		foreach ( array_values( $hits ) as $i => $hit ) {
			$tokens = array();
			foreach ( $hit->score->tokens as $t ) {
				$tokens[] = sprintf( '%s→%s %s %s w%d×m%.1f tf%d df%d = %.2f', $t['token'], $t['term'], $t['kind'], Fields::NAMES[ $t['field'] ] ?? (string) $t['field'], $t['weight'], $t['m'], $t['tf'], $t['df'], $t['score'] );
			}
			$out[] = array(
				'rank'   => $i + 1,
				'type'   => $hit->document->type,
				'id'     => $hit->document->objectId,
				'title'  => $hit->document->title,
				'total'  => round( $hit->score->total(), 2 ),
				'tokens' => implode( '; ', $tokens ),
				'phrase' => $hit->score->phrase,
				'exact'  => $hit->score->exact,
				'stock'  => $hit->score->stock,
				'owner'  => $hit->score->owner,
			);
		}
		return $out;
	}
}
