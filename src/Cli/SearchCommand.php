<?php
/**
 * `wp csearch search` and `wp csearch explain`.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Cli;

use CreativeBranding\Search\Search\Explanation;
use CreativeBranding\Search\Search\SearchService;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Searches as the shop does (not logged), and explains a ranking: each candidate with its tokens, fields, weights and bonuses (spec: Matching → Explain).
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
	 * Search as the shop does, without logging.
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
	 * : Products to show.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp csearch search "kazumi deck"
	 *     wp csearch search planche --lang=fr --format=json
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function search( array $args, array $assoc ): void {
		$query = SearchService::clean( $args[0] ?? '' );
		if ( '' === $query ) {
			WP_CLI::error( __( 'Give a query to search for.', 'creative-search' ) );
		}
		$lang   = $this->search->language( isset( $assoc['lang'] ) && is_string( $assoc['lang'] ) ? $assoc['lang'] : null );
		$limit  = max( 1, isset( $assoc['limit'] ) && is_numeric( $assoc['limit'] ) ? (int) $assoc['limit'] : 10 );
		$result = $this->search->run( $query, $lang, $limit, 3 );
		$format = isset( $assoc['format'] ) && is_string( $assoc['format'] ) ? $assoc['format'] : 'table';
		if ( 'table' === $format && null !== $result->corrected ) {
			WP_CLI::log(
				$result->dropped
					/* translators: 1: the query, 2: the query the results are for */
					? sprintf( __( 'Nothing matched every word of "%1$s"; showing results for "%2$s".', 'creative-search' ), $query, $result->corrected )
					/* translators: %s: the query the results are for, with a typo corrected */
					: sprintf( __( 'Showing results for "%s".', 'creative-search' ), $result->corrected )
			);
		}
		$rows = array();
		foreach ( array_merge( array_values( $result->products ), array_values( $result->terms ) ) as $i => $hit ) {
			$rows[] = array(
				'rank'  => $i + 1,
				'type'  => $hit->document->type,
				'id'    => $hit->document->objectId,
				'title' => $hit->document->title,
				'score' => round( $hit->score->total(), 2 ),
			);
		}
		WP_CLI\Utils\format_items( $format, $rows, array( 'rank', 'type', 'id', 'title', 'score' ) );
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
		$result = $this->search->run( $query, $lang, $limit, 3, true, true );
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
				/* translators: 1: query, 2: language code, 3: the query after a dropped word or a corrected typo, 4: what was found */
				: sprintf( __( 'Query "%1$s" in %2$s, showing results for "%3$s": %4$s.', 'creative-search' ), $query, $result->lang, $result->corrected, $found )
		);
		$format = isset( $assoc['format'] ) && is_string( $assoc['format'] ) ? $assoc['format'] : 'table';
		WP_CLI\Utils\format_items( $format, array_merge( Explanation::rows( $result->products ), Explanation::rows( $result->terms ) ), array( 'rank', 'type', 'id', 'title', 'total', 'tokens', 'phrase', 'exact', 'stock', 'owner' ) );
	}
}
