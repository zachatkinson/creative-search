<?php
/**
 * Redaction of personal-looking queries.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A query that looks like an email address, a phone number, a card number or a postal code is logged as
 * `[redacted]` (spec: Analytics and privacy), so a shopper typing their own details into the wrong box leaves
 * no trace. Numbers a shop sells by (`8.25`, `54mm`, `149`, a five-digit SKU) are left alone.
 */
final class Scrub {

	public const REDACTED = '[redacted]';

	/**
	 * The patterns, each on the whole query.
	 *
	 * @var string[]
	 */
	private const PATTERNS = array(
		// An email address, or the start of one: text on both sides of an @ (the suggest route logs as the
		// shopper types, so `jean.tremblay@g` must go too).
		'/[^\s@]+@[^\s@]/u',
		// A North American phone number being typed: an area code and an exchange (each starting 2 to 9) and at
		// least one more digit, at the end of the query. Truck sizes such as 139 144 149 start with 1.
		'/(?<!\d)\(?[2-9]\d{2}\)?[\s.-]?[2-9]\d{2}[\s.-]?\d{1,3}$/',
		// A North American phone number, with or without the country code.
		'/(?<!\d)(?:\+?1[\s.-]?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}(?!\d)/',
		// An international phone number: a plus and seven or more digits.
		'/\+\d[\d\s.-]{5,}\d/',
		// A payment card: 13 to 19 digits with optional spaces or dashes.
		'/(?<!\d)\d(?:[ -]?\d){12,18}(?!\d)/',
		// A Canadian postal code (H2X 1Y4) and a ZIP+4 (12345-6789).
		'/\b[A-Za-z]\d[A-Za-z][ -]?\d[A-Za-z]\d\b/',
		'/(?<!\d)\d{5}-\d{4}(?!\d)/',
	);

	/**
	 * Whether a query looks like personal data.
	 *
	 * @param string $query The query as typed.
	 */
	public static function looksPersonal( string $query ): bool {
		foreach ( self::PATTERNS as $pattern ) {
			if ( 1 === preg_match( $pattern, $query ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The query, or `[redacted]` when it looks personal.
	 *
	 * @param string $query The query as typed.
	 */
	public static function redact( string $query ): string {
		return self::looksPersonal( $query ) ? self::REDACTED : $query;
	}
}
