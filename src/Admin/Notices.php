<?php
/**
 * The admin's notices.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Admin;

use CreativeBranding\Search\Index\IndexHealth;
use CreativeBranding\Search\Support\Cast;
use CreativeBranding\Search\Support\Clock;
use CreativeBranding\Search\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two kinds (spec: Admin → Notices): the persistent warnings from {@see IndexHealth} on every admin screen for
 * those who can act on them, each dismissible until its condition clears and comes back; and the one-time answer
 * to an action ("3 synonyms added"), carried across the redirect in a short transient for the user.
 */
final class Notices {

	public const FLASH_PREFIX = 'csearch_notice_';
	public const DISMISS      = 'csearch_dismiss_notice';

	/**
	 * Constructor.
	 *
	 * @param IndexHealth $health The index's status.
	 */
	public function __construct( private IndexHealth $health ) {
	}

	/**
	 * Keep an action's answer for the next screen the user sees.
	 *
	 * @param string $type    success, error, warning or info.
	 * @param string $message Plain text.
	 */
	public static function flash( string $type, string $message ): void {
		set_transient( self::FLASH_PREFIX . get_current_user_id(), array( $type, $message ), MINUTE_IN_SECONDS );
	}

	/**
	 * The kept answer, once.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	public static function takeFlash(): ?array {
		$key   = self::FLASH_PREFIX . get_current_user_id();
		$flash = get_transient( $key );
		if ( ! is_array( $flash ) ) {
			return null;
		}
		delete_transient( $key );
		return array( Cast::str( $flash[0] ?? 'info' ), Cast::str( $flash[1] ?? '' ) );
	}

	/**
	 * The warnings this user has not dismissed, forgetting dismissals whose condition has cleared.
	 *
	 * @return array<string, array{count?: int, errors?: string[]}>
	 */
	public function warnings(): array {
		$warnings  = $this->health->warnings();
		$dismissed = self::dismissed();
		$kept      = array_intersect_key( $dismissed, $warnings );
		if ( $kept !== $dismissed ) {
			update_user_meta( get_current_user_id(), Options::USER_DISMISSED, $kept );
		}
		return array_diff_key( $warnings, $kept );
	}

	/**
	 * Dismiss a warning for this user until its condition clears.
	 *
	 * @param string $id The warning.
	 */
	public static function dismiss( string $id ): void {
		$dismissed        = self::dismissed();
		$dismissed[ $id ] = Clock::timestamp();
		update_user_meta( get_current_user_id(), Options::USER_DISMISSED, $dismissed );
	}

	/**
	 * The text of a warning.
	 *
	 * @param string                                $id      The warning.
	 * @param array{count?: int, errors?: string[]} $details Its details.
	 */
	public static function message( string $id, array $details ): string {
		switch ( $id ) {
			case 'empty':
				return __( 'The search index is empty, so the shop’s search finds nothing.', 'creative-search' );
			case 'missing':
				$count = $details['count'] ?? 0;
				/* translators: %s: number of products */
				return sprintf( _n( '%s product is missing from the search index.', '%s products are missing from the search index.', $count, 'creative-search' ), number_format_i18n( $count ) );
			case 'stale':
				return __( 'Search indexing jobs have waited more than 15 minutes: WordPress’s scheduled tasks do not seem to be running, so product changes are not reaching the search.', 'creative-search' );
			case 'errors':
				return __( 'The last index rebuild finished with errors:', 'creative-search' );
		}
		return '';
	}

	/**
	 * The dismissals stored for this user.
	 *
	 * @return array<string, int>
	 */
	private static function dismissed(): array {
		$stored = get_user_meta( get_current_user_id(), Options::USER_DISMISSED, true );
		$out    = array();
		foreach ( is_array( $stored ) ? $stored : array() as $id => $at ) {
			if ( is_string( $id ) ) {
				$out[ $id ] = Cast::int( $at );
			}
		}
		return $out;
	}
}
