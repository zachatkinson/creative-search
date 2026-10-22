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
use CreativeBranding\Search\Search\Cache;

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
		// Any plugin that changes what a suggestion shows drops the cached answers (Skatehouse handoff item 3).
		add_action( 'csearch_flush_cache', array( Cache::class, 'bump' ), 10, 0 );
		// Mature Content for WooCommerce, when a product's artwork flag changes (its row classes with it).
		add_action( 'mcw_flags_changed', array( Cache::class, 'bump' ), 10, 0 );
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
