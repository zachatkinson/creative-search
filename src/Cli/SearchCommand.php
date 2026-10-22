<?php
/**
 * `wp csearch explain`.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Cli;

use CreativeBranding\Search\Domain\Fields;
use CreativeBranding\Search\Domain\Hit;
use CreativeBranding\Search\Search\SearchService;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Explains a ranking: each candidate with its tokens, fields, weights and bonuses (spec: Matching → Explain).
 */
final class SearchCommand {

	/**
	 * Constructor.
	 *
	 * @param SearchService $search The search service.
	 */
	public function __construct( private SearchService $search ) {
	}

	/**
	 * Show how a query ranks its candidates.
	 *
	 * ## OPTIONS
	 *
	 * <query>
	 * : The query, quoted.
	 *
	 * [--lang=<lang>]
	 * : Language code; the site's by default.
	 *
	 * [--limit=<n>]
	 * : Candidates to show.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--format=<format>]
	 * : table, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp csearch explain "kazumi deck 8.25"
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function explain( array $args, array $assoc ): void {
		$query  = SearchService::clean( $args[0] ?? '' );
		$lang   = $this->search->language( isset( $assoc['lang'] ) && is_string( $assoc['lang'] ) ? $assoc['lang'] : null );
		$limit  = max( 1, isset( $assoc['limit'] ) && is_numeric( $assoc['limit'] ) ? (int) $assoc['limit'] : 10 );
		$result = $this->search->run( $query, $lang, $limit, 3 );
		$found  = sprintf(
			/* translators: 1: products found, 2: categories, tags and brands found */
			__( '%1$s, %2$s', 'creative-search' ),
			/* translators: %d: products found */
			sprintf( _n( '%d product', '%d products', count( $result->products ), 'creative-search' ), count( $result->products ) ),
			/* translators: %d: categories, tags and brands found */
			sprintf( _n( '%d term', '%d terms', count( $result->terms ), 'creative-search' ), count( $result->terms ) )
		);
		WP_CLI::log(
			null === $result->corrected
				/* translators: 1: query, 2: language code, 3: what was found */
				? sprintf( __( 'Query "%1$s" in %2$s: %3$s.', 'creative-search' ), $query, $result->lang, $found )
				/* translators: 1: query, 2: language code, 3: the query after a dropped word, 4: what was found */
				: sprintf( __( 'Query "%1$s" in %2$s, showing results for "%3$s": %4$s.', 'creative-search' ), $query, $result->lang, $result->corrected, $found )
		);
		$format = isset( $assoc['format'] ) && is_string( $assoc['format'] ) ? $assoc['format'] : 'table';
		WP_CLI\Utils\format_items( $format, array_merge( self::rows( $result->products ), self::rows( $result->terms ) ), array( 'rank', 'type', 'id', 'title', 'total', 'tokens', 'phrase', 'exact', 'stock', 'owner' ) );
	}

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
