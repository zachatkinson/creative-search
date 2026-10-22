<?php
/**
 * `wp csearch synonyms`.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Cli;

use CreativeBranding\Search\Admin\Csv;
use CreativeBranding\Search\Admin\SynonymService;
use CreativeBranding\Search\Repository\SynonymRepository;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Cast;
use CreativeBranding\Search\Support\PluginException;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists, adds, deletes, imports and exports the owner's synonyms (spec: Admin → WP-CLI). The same service as the
 * Synonyms tab, so a change applies to searches at once.
 */
final class SynonymsCommand {

	/**
	 * Constructor.
	 *
	 * @param SynonymService    $synonyms   Synonyms.
	 * @param SynonymRepository $repository The table, for listing.
	 */
	public function __construct( private SynonymService $synonyms, private SynonymRepository $repository ) {
	}

	/**
	 * List synonyms.
	 *
	 * ## OPTIONS
	 *
	 * [--lang=<lang>]
	 * : One language only.
	 *
	 * [--search=<text>]
	 * : Only those whose word or meaning contains the text.
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp csearch synonyms list --lang=en
	 *
	 * @subcommand list
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function list_( array $args, array $assoc ): void {
		unset( $args );
		$lang   = sanitize_key( Cast::str( $assoc['lang'] ?? '' ) );
		$search = sanitize_text_field( Cast::str( $assoc['search'] ?? '' ) );
		$rows   = array_map(
			static fn( array $row ): array => array(
				'id'        => Cast::int( $row['id'] ),
				'lang'      => Cast::str( $row['lang'] ),
				'word'      => Cast::str( $row['word'] ),
				'means'     => Cast::str( $row['means'] ),
				'both_ways' => '1' === $row['both_ways'] ? 'yes' : 'no',
			),
			$this->repository->page( $lang, $search, SynonymRepository::MAX_ROWS, 0 )
		);
		WP_CLI\Utils\format_items( Cast::str( $assoc['format'] ?? 'table', 'table' ), $rows, array( 'id', 'lang', 'word', 'means', 'both_ways' ) );
	}

	/**
	 * Add a synonym.
	 *
	 * ## OPTIONS
	 *
	 * <word>
	 * : What a shopper types.
	 *
	 * <means>
	 * : What it means.
	 *
	 * [--lang=<lang>]
	 * : Language code; the site's by default.
	 *
	 * [--one-way]
	 * : Only the word finds what it means, not the other way round.
	 *
	 * [--format=<format>]
	 * : text or json.
	 * ---
	 * default: text
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp csearch synonyms add board deck
	 *     wp csearch synonyms add planche deck --lang=fr --one-way
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function add( array $args, array $assoc ): void {
		$lang = Cast::str( $assoc['lang'] ?? \CreativeBranding\Search\Index\Languages::siteCode() );
		try {
			$synonym = $this->synonyms->make( $lang, Cast::str( $args[0] ?? '' ), Cast::str( $args[1] ?? '' ), ! isset( $assoc['one-way'] ) );
		} catch ( PluginException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		$id = $this->synonyms->add( $synonym, 0 );
		if ( 'json' === ( $assoc['format'] ?? '' ) ) {
			WP_CLI::line( (string) wp_json_encode( array( 'id' => $id ) ) );
			return;
		}
		if ( 0 === $id ) {
			/* translators: 1: a word, 2: what it means */
			WP_CLI::warning( sprintf( __( '"%1$s" already means "%2$s"; nothing was added.', 'creative-search' ), $synonym->word, $synonym->means ) );
			return;
		}
		/* translators: 1: row id, 2: a word, 3: what it means */
		WP_CLI::success( sprintf( __( 'Synonym %1$d added: "%2$s" means "%3$s".', 'creative-search' ), $id, $synonym->word, $synonym->means ) );
	}

	/**
	 * Delete synonyms by id.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Row ids, from `wp csearch synonyms list`.
	 *
	 * [--format=<format>]
	 * : text or json.
	 * ---
	 * default: text
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp csearch synonyms delete 4 5
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function delete( array $args, array $assoc ): void {
		$ids     = Settings::sanitizeIds( $args );
		$deleted = $this->synonyms->delete( $ids );
		/* translators: 1: synonyms deleted, 2: ids given */
		$message = sprintf( _n( '%1$d of %2$d synonym deleted.', '%1$d of %2$d synonyms deleted.', count( $ids ), 'creative-search' ), $deleted, count( $ids ) );
		if ( 0 === $deleted || $deleted < count( $ids ) ) {
			WP_CLI::error( $message );
		}
		if ( 'json' === ( $assoc['format'] ?? '' ) ) {
			WP_CLI::line( (string) wp_json_encode( array( 'deleted' => $deleted ) ) );
			return;
		}
		WP_CLI::success( $message );
	}

	/**
	 * Import synonyms from a CSV file (columns lang, word, means, both_ways).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The CSV file, or - for standard input.
	 *
	 * [--format=<format>]
	 * : text or json.
	 * ---
	 * default: text
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp csearch synonyms import synonyms.csv
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function import( array $args, array $assoc ): void {
		$file = Cast::str( $args[0] ?? '' );
		$csv  = '-' === $file ? file_get_contents( 'php://stdin' ) : ( is_readable( $file ) ? file_get_contents( $file ) : false ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsRemoteFile -- standard input or a local file the operator names.
		if ( ! is_string( $csv ) ) {
			/* translators: %s: a file path */
			WP_CLI::error( sprintf( __( 'Cannot read %s.', 'creative-search' ), $file ) );
		}
		$done = $this->synonyms->import( $csv, 0 );
		if ( 'json' === ( $assoc['format'] ?? '' ) ) {
			WP_CLI::line( (string) wp_json_encode( $done ) );
			return;
		}
		/* translators: 1: synonyms added, 2: rows already present, 3: rows that were not synonyms */
		WP_CLI::success( sprintf( __( 'Import finished. Added: %1$d. Already there: %2$d. Skipped: %3$d.', 'creative-search' ), $done['added'], $done['existing'], $done['skipped'] ) );
	}

	/**
	 * Print every synonym, as CSV (the import's columns) or JSON.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : csv or json.
	 * ---
	 * default: csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp csearch synonyms export > synonyms.csv
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string, mixed> $assoc Flags.
	 */
	public function export( array $args, array $assoc ): void {
		unset( $args );
		if ( 'json' === ( $assoc['format'] ?? '' ) ) {
			WP_CLI::line( (string) wp_json_encode( Csv::parse( $this->synonyms->export() ) ) );
			return;
		}
		WP_CLI::line( rtrim( $this->synonyms->export(), "\n" ) );
	}
}
