<?php
/**
 * The owner's synonyms.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Repository;

use CreativeBranding\Search\Domain\Synonym;
use CreativeBranding\Search\Support\Clock;
use CreativeBranding\Search\Support\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns `csearch_synonyms`: rows per language, applied to queries at search time (spec: Matching → Parsing).
 * Written from the admin screen and WP-CLI in milestone 7.
 */
final class SynonymRepository extends Repository {

	/**
	 * The synonyms of some languages.
	 *
	 * @param string[] $langs Language codes.
	 * @return Synonym[]
	 */
	public function forLanguages( array $langs ): array {
		if ( array() === $langs ) {
			return array();
		}
		$list = self::strings( count( $langs ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the list holds placeholders only.
		$rows = $this->rows( $this->prepare( "SELECT lang, word, means, both_ways FROM %i WHERE lang IN ({$list}) ORDER BY id", Tables::synonyms(), ...array_values( $langs ) ) );
		return array_map(
			static fn( array $row ): Synonym => new Synonym( (string) $row['lang'], (string) $row['word'], (string) $row['means'], '1' === $row['both_ways'] ),
			$rows
		);
	}

	/**
	 * Add a synonym.
	 *
	 * @param Synonym $synonym   The synonym.
	 * @param int     $createdBy User id, 0 for WP-CLI or an import.
	 * @return int The new id.
	 */
	public function add( Synonym $synonym, int $createdBy = 0 ): int {
		$this->run(
			$this->prepare(
				'INSERT INTO %i (lang, word, means, both_ways, created_by, created_at) VALUES (%s, %s, %s, %d, ' . ( $createdBy > 0 ? '%d' : 'NULL' ) . ', %s)',
				Tables::synonyms(),
				...array_merge(
					array( $synonym->lang, mb_substr( $synonym->word, 0, 64, 'UTF-8' ), mb_substr( $synonym->means, 0, 64, 'UTF-8' ), (int) $synonym->bothWays ),
					$createdBy > 0 ? array( $createdBy ) : array(),
					array( Clock::now() )
				)
			)
		);
		return $this->insertId();
	}
}
