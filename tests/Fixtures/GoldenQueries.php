<?php
/**
 * The golden query set.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Fixtures;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads tests/Fixtures/queries.yml: 50 queries with the top result and suggestions they must produce against
 * the generated catalogue, checked on every build (spec: Testing and quality → Fixtures). Changing an
 * expectation needs a reason in the commit.
 */
final class GoldenQueries {

	public const FILE = __DIR__ . '/queries.yml';

	/**
	 * The kinds a query can be tagged with; each spec rule has at least one query.
	 *
	 * @var string[]
	 */
	public const KINDS = array( 'exact', 'prefix', 'typo', 'phrase', 'minus', 'number', 'sku', 'variation-sku', 'synonym', 'stem', 'accent', 'french', 'fallback', 'zero', 'stopword', 'hidden', 'category', 'brand', 'tag', 'attribute', 'stock', 'drop-token', 'cap', 'apostrophe' );

	/**
	 * The whole file: `synonyms` and `queries`.
	 *
	 * @return array{synonyms: array<int, array{lang: string, word: string, means: string, both_ways: bool}>, queries: array<int, array<string, mixed>>}
	 * @throws RuntimeException When the file is missing or malformed.
	 */
	public static function load(): array {
		if ( ! is_readable( self::FILE ) ) {
			throw new RuntimeException( 'queries.yml is missing.' );
		}
		$data = Yaml::parseFile( self::FILE );
		if ( ! is_array( $data ) || ! isset( $data['queries'], $data['synonyms'] ) || ! is_array( $data['queries'] ) || ! is_array( $data['synonyms'] ) ) {
			throw new RuntimeException( 'queries.yml needs top-level synonyms and queries lists.' );
		}
		$synonyms = array();
		foreach ( $data['synonyms'] as $row ) {
			if ( ! is_array( $row ) ) {
				throw new RuntimeException( 'A synonym must be a map.' );
			}
			$synonyms[] = array(
				'lang'      => (string) ( $row['lang'] ?? 'en' ),
				'word'      => (string) ( $row['word'] ?? '' ),
				'means'     => (string) ( $row['means'] ?? '' ),
				'both_ways' => (bool) ( $row['both_ways'] ?? false ),
			);
		}
		$queries = array();
		foreach ( $data['queries'] as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['q'], $row['expect'] ) || ! is_array( $row['expect'] ) ) {
				throw new RuntimeException( 'A query needs q and expect.' );
			}
			$queries[] = array(
				'q'      => (string) $row['q'],
				'lang'   => (string) ( $row['lang'] ?? 'en' ),
				'kind'   => (string) ( $row['kind'] ?? 'exact' ),
				'why'    => (string) ( $row['why'] ?? '' ),
				'expect' => array(
					'top'         => isset( $row['expect']['top'] ) ? (string) $row['expect']['top'] : null,
					'products'    => array_map( 'strval', (array) ( $row['expect']['products'] ?? array() ) ),
					'absent'      => array_map( 'strval', (array) ( $row['expect']['absent'] ?? array() ) ),
					'terms'       => array_map( 'strval', (array) ( $row['expect']['terms'] ?? array() ) ),
					'corrected'   => isset( $row['expect']['corrected'] ) ? (string) $row['expect']['corrected'] : null,
					'zero'        => (bool) ( $row['expect']['zero'] ?? false ),
					'hidden_only' => (bool) ( $row['expect']['hidden_only'] ?? false ),
				),
			);
		}
		return array(
			'synonyms' => $synonyms,
			'queries'  => $queries,
		);
	}

	/**
	 * The queries alone.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function queries(): array {
		return self::load()['queries'];
	}
}
