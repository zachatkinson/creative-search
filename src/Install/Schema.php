<?php
/**
 * Database schema.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Install;

use CreativeBranding\Search\Support\Options;
use CreativeBranding\Search\Support\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the seven plugin tables with dbDelta() (spec: Data model).
 *
 * Keys are written exactly as dbDelta() parses them (two spaces after PRIMARY KEY, `UNIQUE KEY name (cols)`,
 * `KEY name (cols)`). Integer types carry the display width SHOW COLUMNS reports (bigint(20) unsigned,
 * int(10) unsigned, int(11), smallint(5) unsigned, tinyint(3) unsigned, tinyint(1)) so a second dbDelta()
 * reports no changes. Column types are lower case, as core writes its own schema. No JSON or ENUM columns:
 * every value is a column that can be indexed and queried.
 *
 * `csearch_documents` and `csearch_postings` carry `generation`: a full reindex writes N+1 beside N and
 * switches the live generation when complete, so the shop never searches a half-built index. The unique key
 * on documents therefore includes the generation (docs/DECISIONS.md D-012).
 */
final class Schema {

	public const DB_VERSION = '2';

	/**
	 * Create or upgrade every table. Idempotent.
	 *
	 * @return string[] dbDelta's report lines; empty when nothing changed.
	 */
	public static function install(): array {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$toTwo = version_compare( Upgrader::stored(), '2', '<' );
		if ( $toTwo ) {
			Migration::postingsToTwo();
		}
		$result = dbDelta( self::sql() );
		if ( $toTwo ) {
			Migration::statsToTwo();
		}
		// Autoloaded: Install\Upgrader compares it on every request. Recorded only once the tables are what the
		// version says, so an upgrade cut off halfway runs again (D-090).
		if ( Migration::complete() ) {
			update_option( Options::DB_VERSION, self::DB_VERSION, true );
		}
		return array_values( $result );
	}

	/**
	 * The full CREATE TABLE set as one string, dbDelta style.
	 */
	public static function sql(): string {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$documents = Tables::documents();
		$terms     = Tables::terms();
		$postings  = Tables::postings();
		$trigrams  = Tables::trigrams();
		$stats     = Tables::stats();
		$synonyms  = Tables::synonyms();
		$log       = Tables::log();

		return "
CREATE TABLE {$documents} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  generation int(10) unsigned NOT NULL DEFAULT 1,
  type varchar(16) NOT NULL,
  object_id bigint(20) unsigned NOT NULL,
  lang varchar(8) NOT NULL DEFAULT 'en',
  title varchar(255) NOT NULL,
  name_key varchar(255) NOT NULL DEFAULT '',
  url varchar(1024) NOT NULL,
  image_id bigint(20) unsigned NULL,
  price_min decimal(19,4) NULL,
  price_max decimal(19,4) NULL,
  in_stock tinyint(1) NOT NULL DEFAULT 1,
  visible tinyint(1) NOT NULL DEFAULT 1,
  boost smallint(6) NOT NULL DEFAULT 0,
  total_sales int(10) unsigned NOT NULL DEFAULT 0,
  indexed_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY object (generation,type,object_id,lang),
  KEY generation_lang (generation,lang)
) {$charset};
CREATE TABLE {$terms} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  term varchar(64) NOT NULL,
  df int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY term (term)
) {$charset};
CREATE TABLE {$postings} (
  term_id bigint(20) unsigned NOT NULL,
  document_id bigint(20) unsigned NOT NULL,
  field tinyint(3) unsigned NOT NULL,
  generation int(10) unsigned NOT NULL DEFAULT 1,
  lang varchar(8) NOT NULL DEFAULT '',
  product tinyint(1) NOT NULL DEFAULT 0,
  tf smallint(5) unsigned NOT NULL DEFAULT 1,
  first_pos smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (term_id,generation,lang,product,document_id,field),
  KEY document_id (document_id),
  KEY generation (generation)
) {$charset};
CREATE TABLE {$trigrams} (
  trigram char(3) NOT NULL,
  term_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (trigram,term_id)
) {$charset};
CREATE TABLE {$stats} (
  term_id bigint(20) unsigned NOT NULL,
  generation int(10) unsigned NOT NULL,
  lang varchar(8) NOT NULL,
  df int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (term_id,generation,lang)
) {$charset};
CREATE TABLE {$synonyms} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  lang varchar(8) NOT NULL DEFAULT 'en',
  word varchar(64) NOT NULL,
  means varchar(64) NOT NULL,
  both_ways tinyint(1) NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY lang_word (lang,word),
  KEY lang_means (lang,means)
) {$charset};
CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  query varchar(200) NOT NULL,
  normalised varchar(200) NOT NULL,
  lang varchar(8) NOT NULL DEFAULT 'en',
  results smallint(5) unsigned NOT NULL DEFAULT 0,
  clicked_type varchar(16) NULL,
  clicked_id bigint(20) unsigned NULL,
  source varchar(8) NOT NULL DEFAULT 'suggest',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY normalised (normalised)
) {$charset};
";
	}
}
