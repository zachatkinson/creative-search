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
	 * User meta: the admin notices a user dismissed, notice id to the moment (`Admin\Notices`).
	 */
	public const USER_DISMISSED = 'csearch_dismissed_notices';

	/**
	 * The generation a reindex was asked to stop building (`Index\IndexState`, D-053).
	 */
	public const REINDEX_CANCEL = 'csearch_reindex_cancel';

	/**
	 * The schema upgrade's lock (a timestamp; `Install\Upgrader`).
	 */
	public const UPGRADE_LOCK = 'csearch_upgrade_lock';

	/**
	 * Set when a schema upgrade needs a full rebuild: 0, then the generation of the reindex begun to settle it; cleared when that generation goes live (D-090).
	 */
	public const REBUILD_OWED = 'csearch_rebuild_owed';

	/**
	 * The `Domain\Tokenizer::VERSION` the index was built with: an older one owes a full rebuild (D-098).
	 */
	public const ANALYSIS_VERSION = 'csearch_analysis_version';

	/**
	 * The values of the attributes the shop filters by, a folded word to its attribute and term slug, for the
	 * results page (`Index\AttributeTerms`, D-101). Autoloaded, so a search page reads nothing for it.
	 */
	public const ATTRIBUTE_TERMS = 'csearch_attribute_terms';

	/**
	 * Each category name's stems by language, for the results page (`Search\QueryRoutes`, D-102). Autoloaded, so a
	 * search page reads nothing for it; deleted when a category changes or a rebuild goes live.
	 */
	public const CATEGORY_STEMS = 'csearch_category_stems';

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
		return array( self::SETTINGS, self::DB_VERSION, self::INDEX_STATE, self::UPGRADE_LOCK, self::REINDEX_CANCEL, self::REBUILD_OWED, self::ANALYSIS_VERSION, self::ATTRIBUTE_TERMS, self::CATEGORY_STEMS );
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
