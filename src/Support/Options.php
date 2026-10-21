<?php
/**
 * The names of everything the plugin stores outside its tables.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option, meta, transient, cache-group and queue-group names in one place, so the uninstaller and the
 * tests read the same list the code writes to.
 */
final class Options {

	/**
	 * The one settings array (docs/SETTINGS.md).
	 */
	public const SETTINGS = 'csearch_settings';

	/**
	 * The installed schema version.
	 */
	public const DB_VERSION = 'csearch_db_version';

	/**
	 * The running reindex's progress and the live generation number.
	 */
	public const INDEX_STATE = 'csearch_index_state';

	/**
	 * Prefix of the per-language stop-word additions (`csearch_stopwords_en`, `csearch_stopwords_fr`).
	 */
	public const STOPWORDS_PREFIX = 'csearch_stopwords_';

	/**
	 * Prefix of every transient the plugin sets.
	 */
	public const TRANSIENT_PREFIX = 'csearch_';

	/**
	 * Product meta: the owner's boost, -10 to 10.
	 */
	public const META_BOOST = '_csearch_boost';

	/**
	 * Product meta: keep this product out of search.
	 */
	public const META_EXCLUDE = '_csearch_exclude';

	/**
	 * Object-cache group for answers and rate-limit counters.
	 */
	public const CACHE_GROUP = 'csearch';

	/**
	 * Action Scheduler group for every job.
	 */
	public const QUEUE_GROUP = 'csearch';

	/**
	 * Every fixed-name option.
	 *
	 * @return string[]
	 */
	public static function names(): array {
		return array( self::SETTINGS, self::DB_VERSION, self::INDEX_STATE );
	}

	/**
	 * Every product meta key.
	 *
	 * @return string[]
	 */
	public static function metaKeys(): array {
		return array( self::META_BOOST, self::META_EXCLUDE );
	}
}
