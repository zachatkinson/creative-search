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

	public const MAX_ROWS = 50000;

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

	/**
	 * A page of rows, newest first, optionally one language and a word filter.
	 *
	 * @param string $lang   Language code, or empty for all.
	 * @param string $search Part of the word or what it means, or empty.
	 * @param int    $limit  At most.
	 * @param int    $offset Skip.
	 * @return array<int, array<string, string|null>>
	 */
	public function page( string $lang, string $search, int $limit, int $offset ): array {
		[ $where, $args ] = $this->filter( $lang, $search );
		return $this->rows( $this->prepare( "SELECT * FROM %i WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", Tables::synonyms(), ...array_merge( $args, array( $limit, $offset ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the where clause holds placeholders only.
	}

	/**
	 * How many rows match.
	 *
	 * @param string $lang   Language code, or empty for all.
	 * @param string $search Part of the word or what it means, or empty.
	 */
	public function count( string $lang = '', string $search = '' ): int {
		[ $where, $args ] = $this->filter( $lang, $search );
		return (int) $this->value( $this->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", Tables::synonyms(), ...$args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the where clause holds placeholders only.
	}

	/**
	 * One row.
	 *
	 * @param int $id Row id.
	 * @return array<string, string|null>|null
	 */
	public function find( int $id ): ?array {
		$rows = $this->rows( $this->prepare( 'SELECT * FROM %i WHERE id = %d', Tables::synonyms(), $id ) );
		return $rows[0] ?? null;
	}

	/**
	 * Change a row.
	 *
	 * @param int     $id      Row id.
	 * @param Synonym $synonym The new values.
	 * @return bool Whether a row changed.
	 */
	public function update( int $id, Synonym $synonym ): bool {
		return 1 === $this->run( $this->prepare( 'UPDATE %i SET lang = %s, word = %s, means = %s, both_ways = %d WHERE id = %d', Tables::synonyms(), $synonym->lang, mb_substr( $synonym->word, 0, 64, 'UTF-8' ), mb_substr( $synonym->means, 0, 64, 'UTF-8' ), (int) $synonym->bothWays, $id ) );
	}

	/**
	 * Delete rows.
	 *
	 * @param int[] $ids Row ids.
	 * @return int Rows deleted.
	 */
	public function delete( array $ids ): int {
		if ( array() === $ids ) {
			return 0;
		}
		return $this->run( $this->prepare( 'DELETE FROM %i WHERE id IN (' . self::ints( $ids ) . ')', Tables::synonyms() ) );
	}

	/**
	 * Whether the same synonym exists.
	 *
	 * @param Synonym $synonym The synonym.
	 */
	public function exists( Synonym $synonym ): bool {
		return null !== $this->value( $this->prepare( 'SELECT id FROM %i WHERE lang = %s AND word = %s AND means = %s LIMIT 1', Tables::synonyms(), $synonym->lang, $synonym->word, $synonym->means ) );
	}

	/**
	 * Every row, for the export.
	 *
	 * @return array<int, array<string, string|null>>
	 */
	public function all(): array {
		return $this->rows( $this->prepare( 'SELECT lang, word, means, both_ways FROM %i ORDER BY lang, word, id LIMIT %d', Tables::synonyms(), self::MAX_ROWS ) );
	}

	/**
	 * The where clause of a filter and its values.
	 *
	 * @param string $lang   Language code, or empty.
	 * @param string $search Part of a word, or empty.
	 * @return array{0: string, 1: array<int, string>}
	 */
	private function filter( string $lang, string $search ): array {
		global $wpdb;
		$where = array( '1=1' );
		$args  = array();
		if ( '' !== $lang ) {
			$where[] = 'lang = %s';
			$args[]  = $lang;
		}
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(word LIKE %s OR means LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
		}
		return array( implode( ' AND ', $where ), $args );
	}
}
