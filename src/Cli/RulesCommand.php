<?php
/**
 * WP-CLI: the owner's redirects and pinned products.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Cli;

use CreativeBranding\Search\Search\OwnerRules;
use CreativeBranding\Search\Support\Cast;
use CreativeBranding\Search\Support\PluginException;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp csearch redirect list|add|remove` and `wp csearch pin list|add|remove` (D-108, D-110): the same service as
 * the Redirects and pins tab, so a change applies to searches at once.
 */
final class RulesCommand {

	/**
	 * Constructor.
	 *
	 * @param OwnerRules $rules Redirects and pinned products.
	 */
	public function __construct( private OwnerRules $rules ) {
	}

	/**
	 * List the redirects.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]              $args  Positional arguments.
	 * @param array<string, string> $assoc Options.
	 */
	public function redirects( array $args, array $assoc ): void {
		$rows = array();
		foreach ( OwnerRules::redirects() as $i => $rule ) {
			$rows[] = array( 'index' => $i ) + $rule;
		}
		WP_CLI\Utils\format_items( Cast::str( $assoc['format'] ?? 'table' ), $rows, array( 'index', 'query', 'lang', 'url' ) );
	}

	/**
	 * Send a search to a page, or change where it goes.
	 *
	 * ## OPTIONS
	 *
	 * <query>
	 * : The search.
	 *
	 * <url>
	 * : An address on this site, or a path such as /size-guide/.
	 *
	 * [--lang=<lang>]
	 * : One language only; every language without it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp csearch redirect add "size guide" /size-guide/
	 *
	 * @param string[]              $args  Positional arguments.
	 * @param array<string, string> $assoc Options.
	 */
	public function redirect( array $args, array $assoc ): void {
		try {
			$this->rules->addRedirect( Cast::str( $args[0] ?? '' ), Cast::str( $assoc['lang'] ?? '' ), Cast::str( $args[1] ?? '' ) );
		} catch ( PluginException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::success( __( 'Redirect saved.', 'creative-search' ) );
	}

	/**
	 * List the pinned products.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]              $args  Positional arguments.
	 * @param array<string, string> $assoc Options.
	 */
	public function pins( array $args, array $assoc ): void {
		$rows = array();
		foreach ( OwnerRules::pinned() as $i => $rule ) {
			$rows[] = array(
				'index' => $i,
				'query' => $rule['query'],
				'lang'  => $rule['lang'],
				'ids'   => implode( ',', $rule['ids'] ),
			);
		}
		WP_CLI\Utils\format_items( Cast::str( $assoc['format'] ?? 'table' ), $rows, array( 'index', 'query', 'lang', 'ids' ) );
	}

	/**
	 * Pin products to a search, in order, or replace those pinned to it.
	 *
	 * ## OPTIONS
	 *
	 * <query>
	 * : The search.
	 *
	 * <products>...
	 * : SKUs or product ids, first first; five at most.
	 *
	 * [--lang=<lang>]
	 * : One language only; every language without it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp csearch pin add "grip tape" 512 GRIP-MOB
	 *
	 * @param string[]              $args  Positional arguments.
	 * @param array<string, string> $assoc Options.
	 */
	public function pin( array $args, array $assoc ): void {
		$found = OwnerRules::products( array_map( Cast::str( ... ), array_slice( $args, 1 ) ) );
		if ( array() !== $found['unknown'] ) {
			/* translators: %s: the SKUs or ids not found */
			WP_CLI::warning( sprintf( __( 'No product has the SKU or ID %s.', 'creative-search' ), wp_sprintf_l( '%l', $found['unknown'] ) ) );
		}
		try {
			$this->rules->addPins( Cast::str( $args[0] ?? '' ), Cast::str( $assoc['lang'] ?? '' ), $found['ids'] );
		} catch ( PluginException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::success( __( 'Products pinned.', 'creative-search' ) );
	}

	/**
	 * Remove redirects by their index in `wp csearch redirect list`.
	 *
	 * ## OPTIONS
	 *
	 * <index>...
	 * : Indexes.
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function unredirect( array $args ): void {
		self::removed( $this->rules->remove( OwnerRules::REDIRECTS, self::indexes( $args ) ) );
	}

	/**
	 * Remove pinned products by their index in `wp csearch pin list`.
	 *
	 * ## OPTIONS
	 *
	 * <index>...
	 * : Indexes.
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function unpin( array $args ): void {
		self::removed( $this->rules->remove( OwnerRules::PINS, self::indexes( $args ) ) );
	}

	/**
	 * The indexes given, each a whole number; anything else stops the command before a rule is removed.
	 *
	 * @param string[] $args Positional arguments.
	 * @return int[]
	 */
	private static function indexes( array $args ): array {
		$indexes = array();
		foreach ( $args as $arg ) {
			$arg = Cast::str( $arg );
			if ( ! ctype_digit( $arg ) ) {
				/* translators: %s: what was typed */
				WP_CLI::error( sprintf( __( '%s is not an index from the list.', 'creative-search' ), $arg ) );
			}
			$indexes[] = (int) $arg;
		}
		return $indexes;
	}

	/**
	 * Say how many rules went; none is an error.
	 *
	 * @param int $count Rules removed.
	 */
	private static function removed( int $count ): void {
		if ( 0 === $count ) {
			WP_CLI::error( __( 'No rule has that index.', 'creative-search' ) );
		}
		/* translators: %s: number of rules */
		WP_CLI::success( sprintf( _n( '%s rule removed.', '%s rules removed.', $count, 'creative-search' ), number_format_i18n( $count ) ) );
	}
}
