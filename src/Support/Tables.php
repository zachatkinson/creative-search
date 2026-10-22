<?php
/**
 * Table name helper.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one place table names are built: `{$wpdb->prefix}csearch_{table}`.
 */
final class Tables {

	public const DOCUMENTS = 'documents';
	public const TERMS     = 'terms';
	public const POSTINGS  = 'postings';
	public const TRIGRAMS  = 'trigrams';
	public const STATS     = 'term_stats';
	public const SYNONYMS  = 'synonyms';
	public const LOG       = 'log';

	public const PREFIX = 'csearch_';

	/**
	 * Every plugin table, in creation order (the index's five, then the owner's two).
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::DOCUMENTS, self::TERMS, self::POSTINGS, self::TRIGRAMS, self::STATS, self::SYNONYMS, self::LOG );
	}

	/**
	 * Fully prefixed table name.
	 *
	 * @param string $table One of the class constants.
	 */
	public static function name( string $table ): string {
		global $wpdb;
		return $wpdb->prefix . self::PREFIX . $table;
	}

	/**
	 * The documents table name.
	 */
	public static function documents(): string {
		return self::name( self::DOCUMENTS );
	}

	/**
	 * The terms table name.
	 */
	public static function terms(): string {
		return self::name( self::TERMS );
	}

	/**
	 * The postings table name.
	 */
	public static function postings(): string {
		return self::name( self::POSTINGS );
	}

	/**
	 * The trigrams table name.
	 */
	public static function trigrams(): string {
		return self::name( self::TRIGRAMS );
	}

	/**
	 * The per-language term statistics table name.
	 */
	public static function stats(): string {
		return self::name( self::STATS );
	}

	/**
	 * The synonyms table name.
	 */
	public static function synonyms(): string {
		return self::name( self::SYNONYMS );
	}

	/**
	 * The log table name.
	 */
	public static function log(): string {
		return self::name( self::LOG );
	}
}
