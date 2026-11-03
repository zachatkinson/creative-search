<?php
/**
 * WP-CLI registration.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Hooks;

use CreativeBranding\Search\Cli\IndexCommand;
use CreativeBranding\Search\Cli\MaintenanceCommand;
use CreativeBranding\Search\Cli\RulesCommand;
use CreativeBranding\Search\Cli\SearchCommand;
use CreativeBranding\Search\Cli\SynonymsCommand;
use CreativeBranding\Search\Hookable;
use CreativeBranding\Search\Install\Upgrader;
use CreativeBranding\Search\Plugin;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers `wp csearch` when WP-CLI runs; the schema upgrade runs before any of its commands.
 */
final class CliHooks implements Hookable {

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( WP_CLI::class ) ) {
			return;
		}
		WP_CLI::add_command(
			'csearch',
			Plugin::container()->get( IndexCommand::class ),
			array(
				'shortdesc'     => 'Creative Search: index, rebuild and inspect the search index.',
				'before_invoke' => array( Upgrader::class, 'maybeUpgrade' ),
			)
		);
		WP_CLI::add_command(
			'csearch explain',
			array( Plugin::container()->get( SearchCommand::class ), 'explain' ),
			array( 'shortdesc' => 'Show how a query ranks its candidates.' )
		);
		WP_CLI::add_command(
			'csearch search',
			array( Plugin::container()->get( SearchCommand::class ), 'search' ),
			array( 'shortdesc' => 'Search as the shop does, without logging.' )
		);
		WP_CLI::add_command(
			'csearch synonyms',
			Plugin::container()->get( SynonymsCommand::class ),
			array( 'shortdesc' => 'List, add, delete, import and export synonyms.' )
		);
		$rules = Plugin::container()->get( RulesCommand::class );
		foreach (
			array(
				'csearch redirect list'   => array( 'redirects', 'List the searches sent straight to a page.' ),
				'csearch redirect add'    => array( 'redirect', 'Send a search to a page, or change where it goes.' ),
				'csearch redirect remove' => array( 'unredirect', 'Remove redirects by their index.' ),
				'csearch pin list'        => array( 'pins', 'List the products pinned to searches.' ),
				'csearch pin add'         => array( 'pin', 'Pin products to a search, in order.' ),
				'csearch pin remove'      => array( 'unpin', 'Remove pinned products by their index.' ),
			) as $name => [ $method, $description ]
		) {
			WP_CLI::add_command( $name, array( $rules, $method ), array( 'shortdesc' => $description ) );
		}
		$maintenance = Plugin::container()->get( MaintenanceCommand::class );
		WP_CLI::add_command(
			'csearch log purge',
			array( $maintenance, 'purge' ),
			array( 'shortdesc' => 'Delete search-log rows older than the retention period.' )
		);
		WP_CLI::add_command(
			'csearch analytics clear',
			array( $maintenance, 'clear' ),
			array( 'shortdesc' => 'Delete every logged search and daily count.' )
		);
		WP_CLI::add_command(
			'csearch cache flush',
			array( $maintenance, 'flush' ),
			array( 'shortdesc' => 'Drop every cached search answer.' )
		);
	}
}
