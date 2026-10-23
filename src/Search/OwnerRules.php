<?php
/**
 * The owner's rules for single queries.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Search;

use CreativeBranding\Search\Domain\QueryRules;
use CreativeBranding\Search\Index\AnalyzerFactory;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Cast;
use CreativeBranding\Search\Support\PluginException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Query redirects (D-108) and pinned products (D-110): what the owner set for one query, read from the settings
 * and matched by `Domain\QueryRules` with the index's tokenizer, and the checks the Redirects and pins tab and
 * WP-CLI share when a rule is added. A rule replaces one for the same words and language.
 */
final class OwnerRules {

	public const REDIRECTS = 'query_redirects';
	public const PINS      = 'pinned_products';

	/**
	 * Constructor.
	 *
	 * @param AnalyzerFactory $analyzers The analyzer, for the index's tokenizer and stop words.
	 */
	public function __construct( private AnalyzerFactory $analyzers ) {
	}

	/**
	 * The page a query is sent to, or null: the owner's redirect for its words, when the address is one of the
	 * shop's own (or a host `allowed_redirect_hosts` allows).
	 *
	 * @param string $words The query.
	 * @param string $lang  Its language.
	 */
	public function redirect( string $words, string $lang ): ?string {
		$rules = self::redirects();
		$at    = $this->matcher()->match( $rules, $words, $lang );
		$url   = null === $at ? null : $rules[ $at ]['url'];
		/**
		 * Where a results-page query is sent (D-108), or null to search it as usual. Applied to every product
		 * search on its first page; an address that is not one of the shop's own (`wp_validate_redirect()`) is
		 * ignored.
		 *
		 * @since 1.3.0
		 *
		 * @param string|null $url   The owner's redirect for the query's words, or null.
		 * @param string      $words The query.
		 * @param string      $lang  Its language.
		 */
		$url = apply_filters( 'csearch_query_redirect', $url, $words, $lang );
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}
		$safe = wp_validate_redirect( $url, '' );
		return '' === $safe ? null : $safe;
	}

	/**
	 * The products pinned to a query (D-110), in the owner's order, five at most; none when no rule answers.
	 *
	 * @param string $words The query.
	 * @param string $lang  Its language.
	 * @return int[]
	 */
	public function pins( string $words, string $lang ): array {
		$rules = self::pinned();
		$at    = '' === $words ? null : $this->matcher()->match( $rules, $words, $lang );
		$ids   = null === $at ? array() : $rules[ $at ]['ids'];
		/**
		 * The products shown first for a query (D-110), in order, before the ranked ones, in the panel and on the
		 * results page. A product that is not published, or hidden from search, is still left out. The answers
		 * are cached: a plugin whose answer changes calls `do_action( 'csearch_flush_cache' )`.
		 *
		 * @since 1.3.0
		 *
		 * @param int[]  $ids   The owner's pinned products for the query's words, or none.
		 * @param string $words The query.
		 * @param string $lang  Its language.
		 */
		$filtered = apply_filters( 'csearch_pinned_products', $ids, $words, $lang );
		return array_slice( Settings::sanitizeIds( is_array( $filtered ) ? $filtered : $ids ), 0, Settings::PINS_MAX );
	}

	/**
	 * The redirects, as stored.
	 *
	 * @return array<int, array{query: string, lang: string, url: string}>
	 */
	public static function redirects(): array {
		$stored = Settings::get( self::REDIRECTS );
		return Settings::sanitizeRedirects( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * The pinned products, as stored.
	 *
	 * @return array<int, array{query: string, lang: string, ids: int[]}>
	 */
	public static function pinned(): array {
		$stored = Settings::get( self::PINS );
		return Settings::sanitizePins( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Add a redirect, or change the one for the same words and language.
	 *
	 * @param string $query The query.
	 * @param string $lang  A language code, or empty for every language.
	 * @param string $url   The page: an address on the shop, or a path such as `/size-guide/`.
	 * @throws PluginException With a message for the owner when the rule cannot be kept.
	 */
	public function addRedirect( string $query, string $lang, string $url ): void {
		$url = trim( $url );
		if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
			$url = home_url( $url );
		}
		$url = esc_url_raw( $url );
		if ( '' === $url || '' === wp_validate_redirect( $url, '' ) ) {
			throw new PluginException( esc_html__( 'A redirect goes to a page of this site: enter its address, or a path such as /size-guide/.', 'creative-search' ) );
		}
		$this->save( self::REDIRECTS, self::redirects(), $this->rule( $query, $lang ) + array( 'url' => $url ) );
	}

	/**
	 * Pin products to a query, or change those pinned to the same words and language.
	 *
	 * @param string $query The query.
	 * @param string $lang  A language code, or empty for every language.
	 * @param int[]  $ids   Product ids, in order; five at most are kept.
	 * @throws PluginException With a message for the owner when the rule cannot be kept.
	 */
	public function addPins( string $query, string $lang, array $ids ): void {
		$ids = array_slice( Settings::sanitizeIds( $ids ), 0, Settings::PINS_MAX );
		if ( array() === $ids ) {
			throw new PluginException( esc_html__( 'Choose at least one product to pin.', 'creative-search' ) );
		}
		$this->save( self::PINS, self::pinned(), $this->rule( $query, $lang ) + array( 'ids' => $ids ) );
	}

	/**
	 * Remove rules by their place in the list.
	 *
	 * @param string $kind    {@see OwnerRules::REDIRECTS} or {@see OwnerRules::PINS}.
	 * @param int[]  $indexes Places, from 0.
	 * @return int How many were removed.
	 */
	public function remove( string $kind, array $indexes ): int {
		if ( array() === $indexes ) {
			return 0;
		}
		$rules = self::REDIRECTS === $kind ? self::redirects() : self::pinned();
		$kept  = array_values( array_diff_key( $rules, array_flip( $indexes ) ) );
		Settings::set( $kind, $kept );
		Cache::bump();
		return count( $rules ) - count( $kept );
	}

	/**
	 * A rule's query and language, checked.
	 *
	 * @param string $query The query.
	 * @param string $lang  A language code, or empty.
	 * @return array{query: string, lang: string}
	 * @throws PluginException With a message for the owner.
	 */
	private function rule( string $query, string $lang ): array {
		$query = mb_substr( trim( sanitize_text_field( $query ) ), 0, Settings::RULE_QUERY_MAX, 'UTF-8' );
		$lang  = Settings::sanitizeLanguages( array( $lang ) )[0] ?? '';
		if ( '' === $this->matcher()->key( $query, '' === $lang ? get_locale() : $lang ) ) {
			throw new PluginException( esc_html__( 'Enter the words of the search, not only short words such as “the”.', 'creative-search' ) );
		}
		return array(
			'query' => $query,
			'lang'  => $lang,
		);
	}

	/**
	 * Keep a rule, in place of one for the same words and language, and drop the cached answers.
	 *
	 * @param string                           $kind  The setting.
	 * @param array<int, array<string, mixed>> $rules The rules now.
	 * @param array<string, mixed>             $rule  The new rule.
	 * @throws PluginException When the list is full.
	 */
	private function save( string $kind, array $rules, array $rule ): void {
		$query = Cast::str( $rule['query'] );
		$lang  = Cast::str( $rule['lang'] );
		$key   = $this->matcher()->key( $query, '' === $lang ? get_locale() : $lang );
		$same  = null;
		foreach ( $rules as $i => $existing ) {
			$code = Cast::str( $existing['lang'] ?? '' );
			if ( $code === $lang && $this->matcher()->key( Cast::str( $existing['query'] ?? '' ), '' === $code ? get_locale() : $code ) === $key ) {
				$same = $i;
				break;
			}
		}
		if ( null === $same && count( $rules ) >= Settings::QUERY_RULES_MAX ) {
			/* translators: %s: the most rules of one kind */
			throw new PluginException( esc_html( sprintf( __( 'There are already %s rules of this kind; remove one first.', 'creative-search' ), number_format_i18n( Settings::QUERY_RULES_MAX ) ) ) );
		}
		$rules[ $same ?? count( $rules ) ] = $rule;
		Settings::set( $kind, array_values( $rules ) );
		Cache::bump();
	}

	/**
	 * The matcher, with the index's tokenizer (the owner's stop words).
	 */
	private function matcher(): QueryRules {
		return new QueryRules( $this->analyzers->tokenizer() );
	}
}
