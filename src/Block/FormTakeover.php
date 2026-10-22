<?php
/**
 * The theme-form takeover.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Block;

use CreativeBranding\Search\Hookable;
use CreativeBranding\Search\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * When `theme_form_takeover` is on, every `get_search_form()` call (a classic theme's header, its
 * `searchform.php`, the search widget) returns the block's form with the owner's defaults.
 */
final class FormTakeover implements Hookable {

	/**
	 * Constructor.
	 *
	 * @param SearchForm $form The markup.
	 */
	public function __construct( private readonly SearchForm $form ) {
	}

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		add_filter( 'get_search_form', array( $this, 'filter' ) );
	}

	/**
	 * `get_search_form` filter.
	 *
	 * @param mixed $form The theme's form markup.
	 * @return mixed
	 */
	public function filter( $form ) {
		if ( ! Settings::isOn( 'theme_form_takeover' ) ) {
			return $form;
		}
		return $this->form->render( FormOptions::fromSettings() );
	}
}
