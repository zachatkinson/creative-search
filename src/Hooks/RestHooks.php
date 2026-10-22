<?php
/**
 * REST registration.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Hooks;

use CreativeBranding\Search\Hookable;
use CreativeBranding\Search\Rest\ClickRoute;
use CreativeBranding\Search\Rest\SearchRoute;
use CreativeBranding\Search\Rest\StatusRoute;
use CreativeBranding\Search\Rest\SuggestRoute;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers `csearch/v1`.
 */
final class RestHooks implements Hookable {

	/**
	 * Constructor.
	 *
	 * @param SuggestRoute $suggest Suggest.
	 * @param SearchRoute  $search  Search.
	 * @param StatusRoute  $status  Status.
	 * @param ClickRoute   $click   Click.
	 */
	public function __construct( private SuggestRoute $suggest, private SearchRoute $search, private StatusRoute $status, private ClickRoute $click ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Register the routes.
	 */
	public function routes(): void {
		$this->suggest->register();
		$this->search->register();
		$this->status->register();
		$this->click->register();
	}
}
