<?php
/**
 * WP-CLI registration.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Hooks;

use CreativeBranding\Search\Cli\IndexCommand;
use CreativeBranding\Search\Cli\SearchCommand;
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
	}
}
