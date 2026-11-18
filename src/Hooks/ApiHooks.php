<?php
/**
 * What other plugins ask from PHP.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Hooks;

use CreativeBranding\Search\Hookable;
use CreativeBranding\Search\Search\CallerSearch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers `csearch_ranked_ids` (D-161): the ranked product ids for another plugin's query, on the server, for any
 * visitor, as the results page is.
 */
final class ApiHooks implements Hookable {

	/**
	 * Constructor.
	 *
	 * @param CallerSearch $caller The answer.
	 */
	public function __construct( private CallerSearch $caller ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_filter( 'csearch_ranked_ids', array( $this->caller, 'answer' ), 10, 3 );
	}
}
