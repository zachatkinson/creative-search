<?php
/**
 * The ranking, in the database.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Repository;

use CreativeBranding\Search\Domain\Fields;
use CreativeBranding\Search\Domain\RankPlan;
use CreativeBranding\Search\Domain\Scorer;
use CreativeBranding\Search\Support\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs a `Domain\RankPlan` as one statement (spec: "the widening plan to one SQL statement"; D-086): the
 * postings of the plan's terms, scored per field as the Domain's `Scorer` does (w · m · (1 + ln(1 + tf)) · idf,
 * the idf worked out in PHP), the best field per token, AND across tokens, the phrase bonus and the quoted
 * phrases from first positions, the exactness bonuses from the name key and the SKU key, the stock penalty, the
 * owner's boost and rules, the minus words, hidden-by-SKU, and the order and limit. Only the page of results
 * comes back to PHP, so memory no longer grows with the number of matching products.
 *
 * The term ids are read first (a unique-key lookup), so the statement joins integers and never compares a
 * literal's collation with a column's. Every postings read names the term, generation, language and kind, the
 * primary key's lead (D-087), so it is a range scan of the key with no lookup into the documents.
 */
final class RankRepository extends Repository {

	/**
	 * The ranked rows of a plan.
	 *
	 * @param RankPlan              $plan       The plan.
	 * @param int                   $generation Generation.
	 * @param array<string, string> $taxonomies Taxonomy to document type, for the owner's rules.
	 * @return array<int, array<string, string|null>> In rank order.
	 */
	public function rank( RankPlan $plan, int $generation, array $taxonomies ): array {
		$ids = $this->termIds( $plan->terms() );
		$q   = array();
		$sql = array();
		foreach ( $plan->tokens as $i => $matches ) {
			$found = false;
			foreach ( $matches as $match ) {
				if ( isset( $ids[ $match->term ] ) ) {
					$q[]   = $this->prepare( 'SELECT %d AS term_id, %d AS tok, %s + 0E0 AS m, %s + 0E0 AS idf', $ids[ $match->term ], $i, self::number( $match->weight() ), self::number( $plan->idf( $match->df ) ) );
					$found = true;
				}
			}
			if ( ! $found ) {
				return array(); // A token with no term in the dictionary: the AND finds nothing.
			}
		}
		$w = array();
		foreach ( $plan->weights as $field => $weight ) {
			$w[] = $this->prepare( 'SELECT %d AS field, %d AS w', $field, $weight );
		}
		$w[]   = 'SELECT 0 AS field, 0 AS w'; // Never empty; field 0 is no field.
		$count = count( $plan->tokens );

		$sql[]   = 'WITH q AS (' . implode( ' UNION ALL ', $q ) . '), w AS (' . implode( ' UNION ALL ', $w ) . '),';
		$sql[]   = $this->prepare(
			'f AS (SELECT p.document_id AS doc, q.tok, p.field, MAX(COALESCE(w.w, 0) * q.m * (1 + LN(1 + p.tf)) * q.idf) AS best, MIN(p.first_pos) AS pos FROM q INNER JOIN %i p ON p.term_id = q.term_id AND p.generation = %d AND p.lang = %s AND p.product = %d LEFT JOIN w ON w.field = p.field GROUP BY p.document_id, q.tok, p.field),',
			Tables::postings(),
			$generation,
			$plan->lang,
			$plan->kind
		);
		$sql[]   = $this->prepare(
			"s AS (SELECT t.doc, SUM(t.best) AS base, GROUP_CONCAT(t.tok, ':', t.best ORDER BY t.tok SEPARATOR ',') AS bests FROM (SELECT doc, tok, MAX(best) AS best FROM f GROUP BY doc, tok) t GROUP BY t.doc HAVING COUNT(*) = %d)",
			$count
		);
		$phrased = $count >= 2;
		if ( $phrased ) {
			$sql[] = ', ' . self::phraseTables( $count, $plan->phrases );
		}

		$rules  = $this->rulePoints( $plan->boostRules, $taxonomies );
		$names  = $plan->nameKeys();
		$exact  = $this->prepare( '(CASE WHEN d.name_key IN (' . self::strings( count( $names ) ) . ') THEN %d ELSE 0 END) + (CASE WHEN sku.doc IS NULL THEN 0 ELSE %d END)', ...array_merge( $names, array( (int) Scorer::EXACT_NAME, (int) Scorer::EXACT_SKU ) ) );
		$stock  = $this->prepare( '(CASE WHEN d.in_stock = 1 THEN 0 ELSE %d END)', (int) Scorer::SOLD_OUT );
		$owner  = '(d.boost' . ( array() === $rules ? '' : ' + COALESCE(r.points, 0)' ) . ')';
		$phrase = $phrased ? 'COALESCE(ph.bonus, 0)' : '0';
		$sql[]  = "SELECT d.id, d.type, d.object_id, d.lang, d.title, d.name_key, d.in_stock, d.visible, d.boost, d.total_sales, s.bests, {$phrase} AS phrase, {$exact} AS exact, {$stock} AS stock, {$owner} AS owner, (sku.doc IS NOT NULL) AS sku_hit, ROUND(s.base + {$phrase} + {$exact} + {$stock} + {$owner}, 6) AS total";
		$sql[]  = $this->prepare( 'FROM s INNER JOIN %i d ON d.id = s.doc', Tables::documents() );
		if ( $phrased ) {
			$sql[] = 'LEFT JOIN ph ON ph.doc = s.doc';
		}
		$sql[] = $this->prepare(
			'LEFT JOIN (SELECT ps.document_id AS doc FROM %i ts INNER JOIN %i ps ON ps.term_id = ts.id AND ps.generation = %d AND ps.lang = %s AND ps.product = %d WHERE ts.term = %s AND ps.field = %d) sku ON sku.doc = s.doc',
			Tables::terms(),
			Tables::postings(),
			$generation,
			$plan->lang,
			$plan->kind,
			'=' . $plan->key,
			Fields::SKU
		);
		if ( array() !== $rules ) {
			$cases = array();
			foreach ( $rules as $ttId => $points ) {
				$cases[] = $this->prepare( 'WHEN %d THEN %d', $ttId, $points );
			}
			global $wpdb;
			$sql[] = $this->prepare( 'LEFT JOIN (SELECT object_id, SUM(CASE term_taxonomy_id ' . implode( ' ', $cases ) . ' ELSE 0 END) AS points FROM %i WHERE term_taxonomy_id IN (' . self::ints( array_keys( $rules ) ) . ') GROUP BY object_id) r ON r.object_id = d.object_id', $wpdb->term_relationships );
		}
		$sql[] = $this->prepare( 'WHERE (d.visible = 1 OR (%d = 1 AND sku.doc IS NOT NULL))', $plan->hiddenBySku ? 1 : 0 );
		if ( array() !== $plan->excluded ) {
			$sql[] = $this->prepare(
				'AND NOT EXISTS (SELECT 1 FROM %i tx INNER JOIN %i px ON px.term_id = tx.id AND px.generation = %d AND px.lang = %s AND px.product = %d AND px.document_id = d.id WHERE tx.term IN (' . self::strings( count( $plan->excluded ) ) . '))',
				Tables::terms(),
				Tables::postings(),
				$generation,
				$plan->lang,
				$plan->kind,
				...$plan->excluded
			);
		}
		foreach ( array_keys( $plan->phrases ) as $n ) {
			$sql[] = 'AND ph.q' . (int) $n . ' = 1';
		}
		$sql[] = $this->prepare( 'ORDER BY total DESC, d.in_stock DESC, d.total_sales DESC, d.object_id DESC, d.id ASC LIMIT %d', $plan->limit );

		return $this->rows( implode( ' ', $sql ) );
	}

	/**
	 * The tables of first positions per field (`x`) and of the phrase bonus and the quoted phrases per document
	 * (`ph`), as `Scorer::phraseBonus()` and the quoted-phrase rule read them.
	 *
	 * @param int               $count   Tokens.
	 * @param array<int, int[]> $phrases Quoted phrases' token indexes.
	 */
	private static function phraseTables( int $count, array $phrases ): string {
		$columns  = array();
		$ordered  = array();
		$adjacent = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$columns[] = "MIN(CASE WHEN tok = {$i} THEN pos END) AS p{$i}";
			if ( $i > 0 ) {
				$previous   = $i - 1;
				$ordered[]  = "p{$previous} < p{$i}";
				$adjacent[] = "p{$i} = p{$previous} + 1";
			}
		}
		$bonus = sprintf(
			'MAX(CASE WHEN %s THEN (CASE WHEN %s THEN %d ELSE %d END) ELSE 0 END) AS bonus',
			implode( ' AND ', $ordered ),
			implode( ' AND ', $adjacent ),
			(int) Scorer::PHRASE_ADJACENT,
			(int) Scorer::PHRASE_ORDERED
		);
		$flags = array();
		foreach ( $phrases as $n => $indexes ) {
			$tests   = array();
			$first   = (int) $indexes[0];
			$tests[] = "p{$first} IS NOT NULL";
			foreach ( array_slice( $indexes, 1 ) as $k => $index ) {
				$before  = (int) $indexes[ $k ];
				$index   = (int) $index;
				$tests[] = "p{$index} = p{$before} + 1";
			}
			$flags[] = 'MAX(CASE WHEN ' . implode( ' AND ', $tests ) . ' THEN 1 ELSE 0 END) AS q' . (int) $n;
		}
		return 'x AS (SELECT doc, field, ' . implode( ', ', $columns ) . ' FROM f GROUP BY doc, field), ph AS (SELECT doc, ' . implode( ', ', array_merge( array( $bonus ), $flags ) ) . ' FROM x GROUP BY doc)';
	}

	/**
	 * The ids of some terms.
	 *
	 * @param string[] $terms Terms.
	 * @return array<string, int>
	 */
	private function termIds( array $terms ): array {
		if ( array() === $terms ) {
			return array();
		}
		$out = array();
		foreach ( $this->rows( $this->prepare( 'SELECT id, term FROM %i WHERE term IN (' . self::strings( count( $terms ) ) . ')', Tables::terms(), ...$terms ) ) as $row ) {
			$out[ (string) $row['term'] ] = (int) $row['id'];
		}
		return $out;
	}

	/**
	 * The owner's rules as term-taxonomy ids and points: `category:12` is the `product_cat` term 12.
	 *
	 * @param array<string, int>    $rules      Reference to points.
	 * @param array<string, string> $taxonomies Taxonomy to document type.
	 * @return array<int, int> Term-taxonomy id to points.
	 */
	private function rulePoints( array $rules, array $taxonomies ): array {
		$byType = array_flip( $taxonomies );
		$wanted = array();
		foreach ( $rules as $ref => $points ) {
			[ $type, $termId ] = array_pad( explode( ':', (string) $ref, 2 ), 2, '' );
			if ( isset( $byType[ $type ] ) && 0 !== (int) $points ) {
				$wanted[ $byType[ $type ] . ':' . (int) $termId ] = (int) $points;
			}
		}
		if ( array() === $wanted ) {
			return array();
		}
		global $wpdb;
		$ids  = array_map( static fn( string $key ): int => (int) explode( ':', $key )[1], array_keys( $wanted ) );
		$rows = $this->rows( $this->prepare( 'SELECT term_taxonomy_id, taxonomy, term_id FROM %i WHERE term_id IN (' . self::ints( $ids ) . ')', $wpdb->term_taxonomy ) );
		$out  = array();
		foreach ( $rows as $row ) {
			$key = (string) $row['taxonomy'] . ':' . (int) $row['term_id'];
			if ( isset( $wanted[ $key ] ) ) {
				$out[ (int) $row['term_taxonomy_id'] ] = $wanted[ $key ];
			}
		}
		return $out;
	}

	/**
	 * A float as SQL reads it back exactly: PHP's round-trip form, whatever the locale.
	 *
	 * @param float $value Value.
	 */
	private static function number( float $value ): string {
		return var_export( $value, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- a number literal for SQL, not debug output.
	}
}
