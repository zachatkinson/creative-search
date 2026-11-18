# Queries

`EXPLAIN` output for the search statements, kept current whenever the schema changes (spec: Performance → How). The schema they run against is in `src/Install/Schema.php`; the keys that make them cheap:

| Statement | Key it uses |
| --- | --- |
| Term lookup: terms for a list of exact tokens and stems | `csearch_terms.term` (unique) |
| Prefix: `term LIKE 'kaz%'` limited by `df` | `csearch_terms.term` (range scan) |
| Typo candidates: trigram rows for the token, grouped and limited | `csearch_trigrams` PRIMARY `(trigram, term_id)` |
| Term counts: each term's df in the query's language and the live generation (D-087) | `csearch_term_stats` PRIMARY `(term_id, generation, lang)` |
| Ranking statement and the category, tag and brand lookups: postings for the term ids in the live generation, the query's language and one kind (D-086, D-087) | `csearch_postings` PRIMARY `(term_id, generation, lang, product, document_id, field)`, a range of the clustered key with no read of `csearch_documents` |
| Suggestion rows: the documents by id | `csearch_documents` PRIMARY |
| A search kept to a category (D-117): the ranking statement joined to the category's term-taxonomy ids | `wp_term_relationships` by `term_taxonomy_id` (or its PRIMARY `(object_id, term_taxonomy_id)`), an index-only lookup per ranked document |
| Why a product matched (D-104, D-105): the query's terms each shown product carries, field by field, then its SKUs and attribute values | `csearch_postings` PRIMARY (the terms' range); `csearch_facts` PRIMARY `(document_id, position)` |
| Suggested searches (D-112): earlier searches that start with what is typed | `csearch_log.created_at` or `csearch_log.normalised` (the optimiser picks the narrower range) |
| A value with nothing in stock where a search lands (D-154): the attribute's values in stock among the landing's products | WooCommerce's `wc_product_attributes_lookup` key `(taxonomy, term_id, in_stock, product_or_parent_id)`; without the lookup table, `wp_term_relationships` PRIMARY by `object_id` |
| Whether the products a search lands on carry an attribute at all (D-156), judged by its strong matches and leaving out the products the page hides (D-157), `LIMIT 1` | `wp_term_relationships` PRIMARY `(object_id, term_taxonomy_id)` joined to `wp_term_taxonomy` by `term_taxonomy_id`, the category's own relationships by `term_taxonomy_id`, and the hidden products' `NOT EXISTS` on the same PRIMARY |
| Whether a value's word is part of a strong match's name (D-157): the word's postings in the first ten strong matches, then the named products' terms of the attribute | `csearch_postings` PRIMARY (the term's range), as for D-105; `wp_term_relationships` PRIMARY by `object_id` |
| "Matches Width: 9.00″" only in stock (D-155): the shown products' in-stock lookup rows for the values they match | WooCommerce's `wc_product_attributes_lookup` PRIMARY `(product_or_parent_id, term_id, …)` |
| Whether schema 3's rebuild is still owed (D-107), read once a request where matches are explained | `wp_options.option_name` (unique) |
| Cleanup of an old generation in batches | `csearch_postings.generation`, `csearch_documents.object` (generation first); the term counts by `generation` once its documents are gone |

## How it stays current

`tests/Integration/QueriesExplainTest.php` (group `benchmark`, run with `composer test:integration -- --group benchmark`) loads the 300-product catalogue in English and French, runs a suggestion for an exact word, a prefix, a typo, a SKU with a number, a French word and a search kept to a category with subcategories, a results-page search, and the strong-match ranking a sorted page runs (D-118), alone and kept to a category, catches every statement that touches a plugin table, and `EXPLAIN`s each one. It fails when a read of a plugin table has no key it could use. Before it explains, it waits for InnoDB to refresh the tables' statistics after the load, as a shop's database has them after a reindex (D-089). It writes the plans to `tmp/explain.md`; the plans below are copied from it and are refreshed whenever the schema or a statement changes. The ranking statement (D-086) is included: its `q`, `w`, `f`, `s`, `x` and `ph` are tables the statement builds for itself, so they have no keys and are not checked; every read of a plugin table in it is.

On MariaDB with a table this small, the optimiser sometimes reads `csearch_documents` or `csearch_synonyms` whole even though the primary key could serve, because the id list covers much of the table. That is the optimiser's cost choice, not a missing key; the test allows it only when `possible_keys` names a key.

## Plans, 2026-10-23 (UTC), 300 products in two languages

```sql
SELECT option_value FROM `wp_options` WHERE option_name = 'csearch_index_state' LIMIT 1
```

| table | type | possible keys | key | rows | Extra |
| --- | --- | --- | --- | --- | --- |
| wp_options | const | option_name | option_name | 1 |  |

```sql
SELECT lang, word, means, both_ways FROM `wp_csearch_synonyms` WHERE lang IN ('en') ORDER BY id
```

| table | type | possible keys | key | rows | Extra |
| --- | --- | --- | --- | --- | --- |
| wp_csearch_synonyms | index | lang_word,lang_means | PRIMARY | 1 | Using where |

```sql
SELECT term, df FROM `wp_csearch_terms` WHERE df > 0 AND term LIKE 'deck%' ORDER BY df DESC, term ASC LIMIT 20
```

| table | type | possible keys | key | rows | Extra |
| --- | --- | --- | --- | --- | --- |
| wp_csearch_terms | range | term | term | 2 | Using index condition; Using where; Using filesort |

```sql
SELECT t.term, s.df FROM `wp_csearch_terms` t INNER JOIN `wp_csearch_term_stats` s ON s.term_id = t.id AND s.generation = 2 AND s.lang = 'en' WHERE t.term IN ('kazumi')
```

| table | type | possible keys | key | rows | Extra |
| --- | --- | --- | --- | --- | --- |
| t | const | PRIMARY,term | term | 1 | Using index |
| s | const | PRIMARY | PRIMARY | 1 |  |

```sql
SELECT t.term, p.document_id, p.field, p.tf, p.first_pos FROM `wp_csearch_terms` t INNER JOIN `wp_csearch_postings` p ON p.term_id = t.id AND p.generation = 2 AND p.lang = 'en' AND p.product = 0 WHERE t.term IN ('kazumi') ORDER BY p.document_id, p.field
```

| table | type | possible keys | key | rows | Extra |
| --- | --- | --- | --- | --- | --- |
| t | const | PRIMARY,term | term | 1 | Using index |
| p | ref | PRIMARY,generation | PRIMARY | 1 | Using where |

```sql
SELECT COUNT(*) FROM `wp_csearch_documents` WHERE generation = 2 AND lang = 'en' AND type NOT IN ('post', 'page')
```

| table | type | possible keys | key | rows | Extra |
| --- | --- | --- | --- | --- | --- |
| wp_csearch_documents | range | object,generation_lang | object | 704 | Using where; Using index |

```sql
SELECT id, term FROM `wp_csearch_terms` WHERE term IN ('kazumi')
```

| table | type | possible keys | key | rows | Extra |
| --- | --- | --- | --- | --- | --- |
| wp_csearch_terms | const | term | term | 1 | Using index |

```sql
WITH q AS (SELECT 1 AS term_id, 0 AS tok, '0.5' + 0E0 AS m, '2.775425603574981' + 0E0 AS idf), w AS (SELECT 1 AS field, 10 AS w UNION ALL SELECT 2 AS field, 10 AS w UNION ALL SELECT 3 AS field, 2 AS w UNION ALL SELECT 4 AS field, 1 AS w UNION ALL SELECT 5 AS field, 5 AS w UNION ALL SELECT 6 AS field, 3 AS w UNION ALL SELECT 7 AS field, 6 AS w UNION ALL SELECT 8 AS field, 5 AS w UNION ALL SELECT 9 AS field, 4 AS w UNION ALL SELECT 0 AS field, 0 AS w), f AS (SELECT p.document_id AS doc, q.tok, p.field, MAX(COALESCE(w.w, 0) * q.m * (1 + LN(1 + p.tf)) * q.idf) AS best, MIN(p.first_pos) AS pos FROM q INNER JOIN `wp_csearch_postings` p ON p.term_id = q.term_id AND p.generation = 2 AND p.lang = 'en' AND p.product = 1 LEFT JOIN w ON w.field = p.field GROUP BY p.document_id, q.tok, p.field), s AS (SELECT t.doc, SUM(t.best) AS base, GROUP_CONCAT(t.tok, ':', t.best ORDER BY t.tok SEPARATOR ',') AS bests FROM (SELECT doc, tok, MAX(best) AS best FROM f GROUP BY doc, tok) t GROUP BY t.doc HAVING COUNT(*) = 1) SELECT d.id, d.type, d.object_id, d.lang, d.title, d.name_key, d.in_stock, d.visible, d.boost, d.total_sales, s.bests, 0 AS phrase, (CASE WHEN d.name_key IN ('kazmui') THEN 12 ELSE 0 END) + (CASE WHEN sku.doc IS NULL THEN 0 ELSE 6 END) AS exact, (CASE WHEN d.in_stock = 1 THEN 0 ELSE -6 END) AS stock, (d.boost) AS owner, (sku.doc IS NOT NULL) AS sku_hit, ROUND(s.base + 0 + (CASE WHEN d.name_key IN ('kazmui') THEN 12 ELSE 0 END) + (CASE WHEN sku.doc IS NULL THEN 0 ELSE 6 END) + (CASE WHEN d.in_stock = 1 THEN 0 ELSE -6 END) + (d.boost), 6) AS total FROM s INNER JOIN `wp_csearch_documents` d ON d.id = s.doc LEFT JOIN (SELECT ps.document_id AS doc FROM `wp_csearch_terms` ts INNER JOIN `wp_csearch_postings` ps ON ps.term_id = ts.id AND ps.generation = 2 AND ps.lang = 'en' AND ps.product = 1 WHERE ts.term = '=kazmui' AND ps.field = 2) sku ON sku.doc = s.doc WHERE (d.visible = 1 OR (0 = 1 AND sku.doc IS NOT NULL)) ORDER BY total DESC, d.in_stock DESC, d.total_sales DESC, d.object_id DESC, d.id ASC LIMIT 500
```

| table | type | possible keys | key | rows | Extra |
| --- | --- | --- | --- | --- | --- |
| <derived14> | ALL |  |  | 5 | Using temporary; Using filesort |
| d | eq_ref | PRIMARY | PRIMARY | 1 | Using where |
| ts | const | PRIMARY,term | term | 1 | Using where; Using index |
| ps | eq_ref | PRIMARY,document_id,generation | document_id | 1 | Using where; Using index |
| <derived15> | ALL |  |  | 5 | Using filesort |
| <derived13> | ALL |  |  | 5 | Using temporary; Using filesort |
| <derived2> | system |  |  | 1 | Using temporary; Using filesort |
| p | ref | PRIMARY,generation | PRIMARY | 5 | Using where |
| <derived3> | ref | key0 | key0 | 1 | Using where |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |

```sql
SELECT * FROM `wp_csearch_documents` WHERE id IN (611)
```

| table | type | possible keys | key | rows | Extra |
| --- | --- | --- | --- | --- | --- |
| wp_csearch_documents | const | PRIMARY | PRIMARY | 1 |  |

```sql
SELECT p.document_id, t.term FROM `wp_csearch_postings` p INNER JOIN `wp_csearch_terms` t ON t.id = p.term_id WHERE p.document_id IN (611) AND p.field = 2 AND t.term LIKE '=%'
```

| table | type | possible keys | key | rows | Extra |
| --- | --- | --- | --- | --- | --- |
| p | ref | PRIMARY,document_id | document_id | 1 | Using where; Using index |
| t | eq_ref | PRIMARY,term | PRIMARY | 1 | Using where |

```sql
SELECT normalised, COUNT(*) AS searches FROM `wp_csearch_log` WHERE normalised LIKE 'kazumi%' AND lang = 'en' AND created_at >= '2026-07-25 02:45:41' AND results > 0 AND (source = 'page' OR (clicked_type IS NOT NULL AND source = 'suggest')) GROUP BY normalised HAVING COUNT(*) >= 3 ORDER BY searches DESC, normalised ASC LIMIT 12
```

| table | type | possible keys | key | rows | Extra |
| --- | --- | --- | --- | --- | --- |
| wp_csearch_log | range | created_at,normalised | created_at | 1 | Using index condition; Using where; Using temporary; Using filesort |

```sql
SELECT option_value FROM wp_options WHERE option_name = 'csearch_rebuild_owed' LIMIT 1
```

| table | type | possible keys | key | rows | Extra |
| --- | --- | --- | --- | --- | --- |
|  |  |  |  |  | Impossible WHERE noticed after reading const tables |

```sql
SELECT p.document_id, p.field, t.term FROM `wp_csearch_terms` t INNER JOIN `wp_csearch_postings` p ON p.term_id = t.id AND p.generation = 2 AND p.lang = 'en' AND p.product = 1 WHERE t.term IN ('kazumi','=kazumi') AND p.document_id IN (1,301,295,201,115,289)
```

| table | type | possible keys | key | rows | Extra |
| --- | --- | --- | --- | --- | --- |
| t | range | PRIMARY,term | term | 2 | Using where; Using index |
| p | ref | PRIMARY,document_id,generation | PRIMARY | 5 | Using where |

```sql
SELECT * FROM `wp_csearch_facts` WHERE document_id IN (1,301,295,201,115,289) ORDER BY document_id, position
```

| table | type | possible keys | key | rows | Extra |
| --- | --- | --- | --- | --- | --- |
| wp_csearch_facts | range | PRIMARY | PRIMARY | 47 | Using where |

```sql
WITH q AS (SELECT 59 AS term_id, 0 AS tok, '1.0' + 0E0 AS m, '0.646112231854972' + 0E0 AS idf UNION ALL SELECT 41 AS term_id, 0 AS tok, '0.9' + 0E0 AS m, '0.646112231854972' + 0E0 AS idf), w AS (SELECT 1 AS field, 10 AS w UNION ALL SELECT 2 AS field, 10 AS w UNION ALL SELECT 3 AS field, 2 AS w UNION ALL SELECT 4 AS field, 1 AS w UNION ALL SELECT 5 AS field, 5 AS w UNION ALL SELECT 6 AS field, 3 AS w UNION ALL SELECT 7 AS field, 6 AS w UNION ALL SELECT 8 AS field, 5 AS w UNION ALL SELECT 9 AS field, 4 AS w UNION ALL SELECT 0 AS field, 0 AS w), f AS (SELECT p.document_id AS doc, q.tok, p.field, MAX(COALESCE(w.w, 0) * q.m * (1 + LN(1 + p.tf)) * q.idf) AS best, MIN(p.first_pos) AS pos FROM q INNER JOIN `wp_csearch_postings` p ON p.term_id = q.term_id AND p.generation = 2 AND p.lang = 'fr' AND p.product = 1 LEFT JOIN w ON w.field = p.field GROUP BY p.document_id, q.tok, p.field), s AS (SELECT t.doc, SUM(t.best) AS base, GROUP_CONCAT(t.tok, ':', t.best ORDER BY t.tok SEPARATOR ',') AS bests FROM (SELECT doc, tok, MAX(best) AS best FROM f GROUP BY doc, tok) t GROUP BY t.doc HAVING COUNT(*) = 1) SELECT d.id, d.type, d.object_id, d.lang, d.title, d.name_key, d.in_stock, d.visible, d.boost, d.total_sales, s.bests, 0 AS phrase, (CASE WHEN d.name_key IN ('planches') THEN 12 ELSE 0 END) + (CASE WHEN sku.doc IS NULL THEN 0 ELSE 6 END) AS exact, (CASE WHEN d.in_stock = 1 THEN 0 ELSE -6 END) AS stock, (d.boost) AS owner, (sku.doc IS NOT NULL) AS sku_hit, ROUND(s.base + 0 + (CASE WHEN d.name_key IN ('planches') THEN 12 ELSE 0 END) + (CASE WHEN sku.doc IS NULL THEN 0 ELSE 6 END) + (CASE WHEN d.in_stock = 1 THEN 0 ELSE -6 END) + (d.boost), 6) AS total FROM s INNER JOIN `wp_csearch_documents` d ON d.id = s.doc LEFT JOIN (SELECT ps.document_id AS doc FROM `wp_csearch_terms` ts INNER JOIN `wp_csearch_postings` ps ON ps.term_id = ts.id AND ps.generation = 2 AND ps.lang = 'fr' AND ps.product = 1 WHERE ts.term = '=planches' AND ps.field = 2) sku ON sku.doc = s.doc WHERE (d.visible = 1 OR (0 = 1 AND sku.doc IS NOT NULL)) ORDER BY total DESC, d.in_stock DESC, d.total_sales DESC, d.object_id DESC, d.id ASC LIMIT 500
```

| table | type | possible keys | key | rows | Extra |
| --- | --- | --- | --- | --- | --- |
| <derived15> | ALL |  |  | 10 | Using temporary; Using filesort |
| d | eq_ref | PRIMARY | PRIMARY | 1 | Using where |
| ts | const | PRIMARY,term | term | 1 | Using where; Using index |
| ps | eq_ref | PRIMARY,document_id,generation | document_id | 1 | Using where; Using index |
| <derived16> | ALL |  |  | 10 | Using filesort |
| <derived14> | ALL |  |  | 10 | Using temporary; Using filesort |
| <derived2> | ALL |  |  | 2 | Using temporary; Using filesort |
| p | ref | PRIMARY,generation | PRIMARY | 5 | Using where |
| <derived4> | ref | key0 | key0 | 1 | Using where |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |

```sql
SELECT t.term, COUNT(*) AS shared FROM `wp_csearch_trigrams` g INNER JOIN `wp_csearch_terms` t ON t.id = g.term_id WHERE g.trigram IN ('  k',' ka','kaz','azm','zmu','mui','ui ') AND t.df > 0 GROUP BY g.term_id, t.term HAVING shared >= 3 ORDER BY shared DESC, t.term ASC LIMIT 200
```

| table | type | possible keys | key | rows | Extra |
| --- | --- | --- | --- | --- | --- |
| g | range | PRIMARY | PRIMARY | 18 | Using where; Using temporary; Using filesort |
| t | eq_ref | PRIMARY | PRIMARY | 1 | Using where |

```sql
WITH q AS (SELECT 1 AS term_id, 0 AS tok, '1.0' + 0E0 AS m, '2.775425603574981' + 0E0 AS idf UNION ALL SELECT 3 AS term_id, 1 AS tok, '1.0' + 0E0 AS m, '1.4238168004429803' + 0E0 AS idf), w AS (SELECT 1 AS field, 10 AS w UNION ALL SELECT 2 AS field, 10 AS w UNION ALL SELECT 3 AS field, 2 AS w UNION ALL SELECT 4 AS field, 1 AS w UNION ALL SELECT 5 AS field, 5 AS w UNION ALL SELECT 6 AS field, 3 AS w UNION ALL SELECT 7 AS field, 6 AS w UNION ALL SELECT 8 AS field, 5 AS w UNION ALL SELECT 9 AS field, 4 AS w UNION ALL SELECT 0 AS field, 0 AS w), f AS (SELECT p.document_id AS doc, q.tok, p.field, MAX(COALESCE(w.w, 0) * q.m * (1 + LN(1 + p.tf)) * q.idf) AS best, MIN(p.first_pos) AS pos FROM q INNER JOIN `wp_csearch_postings` p ON p.term_id = q.term_id AND p.generation = 2 AND p.lang = 'en' AND p.product = 1 LEFT JOIN w ON w.field = p.field GROUP BY p.document_id, q.tok, p.field), s AS (SELECT t.doc, SUM(t.best) AS base, GROUP_CONCAT(t.tok, ':', t.best ORDER BY t.tok SEPARATOR ',') AS bests FROM (SELECT doc, tok, MAX(best) AS best FROM f GROUP BY doc, tok) t GROUP BY t.doc HAVING COUNT(*) = 2) , x AS (SELECT doc, field, MIN(CASE WHEN tok = 0 THEN pos END) AS p0, MIN(CASE WHEN tok = 1 THEN pos END) AS p1 FROM f GROUP BY doc, field), ph AS (SELECT doc, MAX(CASE WHEN p0 < p1 THEN (CASE WHEN p1 = p0 + 1 THEN 8 ELSE 4 END) ELSE 0 END) AS bonus FROM x GROUP BY doc) SELECT d.id, d.type, d.object_id, d.lang, d.title, d.name_key, d.in_stock, d.visible, d.boost, d.total_sales, s.bests, COALESCE(ph.bonus, 0) AS phrase, (CASE WHEN d.name_key IN ('kazumi deck') THEN 12 ELSE 0 END) + (CASE WHEN sku.doc IS NULL THEN 0 ELSE 6 END) AS exact, (CASE WHEN d.in_stock = 1 THEN 0 ELSE -6 END) AS stock, (d.boost) AS owner, (sku.doc IS NOT NULL) AS sku_hit, ROUND(s.base + COALESCE(ph.bonus, 0) + (CASE WHEN d.name_key IN ('kazumi deck') THEN 12 ELSE 0 END) + (CASE WHEN sku.doc IS NULL THEN 0 ELSE 6 END) + (CASE WHEN d.in_stock = 1 THEN 0 ELSE -6 END) + (d.boost), 6) AS total FROM s INNER JOIN `wp_csearch_documents` d ON d.id = s.doc LEFT JOIN ph ON ph.doc = s.doc LEFT JOIN (SELECT ps.document_id AS doc FROM `wp_csearch_terms` ts INNER JOIN `wp_csearch_postings` ps ON ps.term_id = ts.id AND ps.generation = 2 AND ps.lang = 'en' AND ps.product = 1 WHERE ts.term = '=kazumi deck' AND ps.field = 2) sku ON sku.doc = s.doc WHERE (d.visible = 1 OR (0 = 1 AND sku.doc IS NOT NULL)) ORDER BY total DESC, d.in_stock DESC, d.total_sales DESC, d.object_id DESC, d.id ASC LIMIT 500
```

| table | type | possible keys | key | rows | Extra |
| --- | --- | --- | --- | --- | --- |
| <derived15> | ALL |  |  | 10 | Using temporary; Using filesort |
| d | eq_ref | PRIMARY | PRIMARY | 1 | Using where |
| <derived18> | ref | key0 | key0 | 1 |  |
| ts | const | PRIMARY,term | term | 1 | Using where; Using index |
| ps | eq_ref | PRIMARY,document_id,generation | document_id | 1 | Using where; Using index |
| <derived16> | ALL |  |  | 10 | Using filesort |
| <derived14> | ALL |  |  | 10 | Using temporary; Using filesort |
| <derived2> | ALL |  |  | 2 | Using temporary; Using filesort |
| p | ref | PRIMARY,generation | PRIMARY | 5 | Using where |
| <derived4> | ref | key0 | key0 | 1 | Using where |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
| <derived17> | ALL |  |  | 10 | Using temporary; Using filesort |
| <derived20> | ALL |  |  | 10 | Using temporary; Using filesort |
| <derived21> | ALL |  |  | 2 | Using temporary; Using filesort |
| p | ref | PRIMARY,generation | PRIMARY | 5 | Using where |
| <derived23> | ref | key0 | key0 | 1 | Using where |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |

```sql
WITH q AS (SELECT 1 AS term_id, 0 AS tok, '1.0' + 0E0 AS m, '2.775425603574981' + 0E0 AS idf), w AS (SELECT 1 AS field, 10 AS w UNION ALL SELECT 2 AS field, 10 AS w UNION ALL SELECT 3 AS field, 2 AS w UNION ALL SELECT 4 AS field, 1 AS w UNION ALL SELECT 5 AS field, 5 AS w UNION ALL SELECT 6 AS field, 3 AS w UNION ALL SELECT 7 AS field, 6 AS w UNION ALL SELECT 8 AS field, 5 AS w UNION ALL SELECT 9 AS field, 4 AS w UNION ALL SELECT 0 AS field, 0 AS w), f AS (SELECT p.document_id AS doc, q.tok, p.field, MAX(COALESCE(w.w, 0) * q.m * (1 + LN(1 + p.tf)) * q.idf) AS best, MIN(p.first_pos) AS pos FROM q INNER JOIN `wp_csearch_postings` p ON p.term_id = q.term_id AND p.generation = 2 AND p.lang = 'en' AND p.product = 1 LEFT JOIN w ON w.field = p.field GROUP BY p.document_id, q.tok, p.field), s AS (SELECT t.doc, SUM(t.best) AS base, GROUP_CONCAT(t.tok, ':', t.best ORDER BY t.tok SEPARATOR ',') AS bests FROM (SELECT doc, tok, MAX(best) AS best FROM f GROUP BY doc, tok) t GROUP BY t.doc HAVING COUNT(*) = 1) SELECT d.id, d.type, d.object_id, d.lang, d.title, d.name_key, d.in_stock, d.visible, d.boost, d.total_sales, s.bests, 0 AS phrase, (CASE WHEN d.name_key IN ('kazumi') THEN 12 ELSE 0 END) + (CASE WHEN sku.doc IS NULL THEN 0 ELSE 6 END) AS exact, (CASE WHEN d.in_stock = 1 THEN 0 ELSE -6 END) AS stock, (d.boost) AS owner, (sku.doc IS NOT NULL) AS sku_hit, ROUND(s.base + 0 + (CASE WHEN d.name_key IN ('kazumi') THEN 12 ELSE 0 END) + (CASE WHEN sku.doc IS NULL THEN 0 ELSE 6 END) + (CASE WHEN d.in_stock = 1 THEN 0 ELSE -6 END) + (d.boost), 6) AS total FROM s INNER JOIN `wp_csearch_documents` d ON d.id = s.doc LEFT JOIN (SELECT ps.document_id AS doc FROM `wp_csearch_terms` ts INNER JOIN `wp_csearch_postings` ps ON ps.term_id = ts.id AND ps.generation = 2 AND ps.lang = 'en' AND ps.product = 1 WHERE ts.term = '=kazumi' AND ps.field = 2) sku ON sku.doc = s.doc WHERE (d.visible = 1 OR (0 = 1 AND sku.doc IS NOT NULL)) AND EXISTS (SELECT 1 FROM `wp_term_relationships` tr WHERE tr.object_id = d.object_id AND tr.term_taxonomy_id IN (17)) ORDER BY total DESC, d.in_stock DESC, d.total_sales DESC, d.object_id DESC, d.id ASC LIMIT 500
```

| table | type | possible keys | key | rows | Extra |
| --- | --- | --- | --- | --- | --- |
| <derived14> | ALL |  |  | 5 | Using temporary; Using filesort |
| d | eq_ref | PRIMARY | PRIMARY | 1 | Using where |
| tr | eq_ref | PRIMARY,term_taxonomy_id | term_taxonomy_id | 1 | Using index |
| ts | const | PRIMARY,term | term | 1 | Using where; Using index |
| ps | eq_ref | PRIMARY,document_id,generation | document_id | 1 | Using where; Using index |
| <derived15> | ALL |  |  | 5 | Using filesort |
| <derived13> | ALL |  |  | 5 | Using temporary; Using filesort |
| <derived2> | system |  |  | 1 | Using temporary; Using filesort |
| p | ref | PRIMARY,generation | PRIMARY | 5 | Using where |
| <derived3> | ref | key0 | key0 | 1 | Using where |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |

```sql
WITH q AS (SELECT 1 AS term_id, 0 AS tok, '1.0' + 0E0 AS m, '2.775425603574981' + 0E0 AS idf UNION ALL SELECT 3 AS term_id, 1 AS tok, '1.0' + 0E0 AS m, '1.4238168004429803' + 0E0 AS idf UNION ALL SELECT 33 AS term_id, 1 AS tok, '0.7' + 0E0 AS m, '1.6323615523360373' + 0E0 AS idf), w AS (SELECT 1 AS field, 10 AS w UNION ALL SELECT 2 AS field, 10 AS w UNION ALL SELECT 3 AS field, 2 AS w UNION ALL SELECT 4 AS field, 1 AS w UNION ALL SELECT 5 AS field, 5 AS w UNION ALL SELECT 6 AS field, 3 AS w UNION ALL SELECT 7 AS field, 6 AS w UNION ALL SELECT 8 AS field, 5 AS w UNION ALL SELECT 9 AS field, 4 AS w UNION ALL SELECT 0 AS field, 0 AS w), f AS (SELECT p.document_id AS doc, q.tok, p.field, MAX(COALESCE(w.w, 0) * q.m * (1 + LN(1 + p.tf)) * q.idf) AS best, MIN(p.first_pos) AS pos FROM q INNER JOIN `wp_csearch_postings` p ON p.term_id = q.term_id AND p.generation = 2 AND p.lang = 'en' AND p.product = 1 LEFT JOIN w ON w.field = p.field GROUP BY p.document_id, q.tok, p.field), s AS (SELECT t.doc, SUM(t.best) AS base, GROUP_CONCAT(t.tok, ':', t.best ORDER BY t.tok SEPARATOR ',') AS bests FROM (SELECT doc, tok, MAX(best) AS best FROM f GROUP BY doc, tok) t GROUP BY t.doc HAVING COUNT(*) = 2) , x AS (SELECT doc, field, MIN(CASE WHEN tok = 0 THEN pos END) AS p0, MIN(CASE WHEN tok = 1 THEN pos END) AS p1 FROM f GROUP BY doc, field), ph AS (SELECT doc, MAX(CASE WHEN p0 < p1 THEN (CASE WHEN p1 = p0 + 1 THEN 8 ELSE 4 END) ELSE 0 END) AS bonus FROM x GROUP BY doc) SELECT d.id, d.type, d.object_id, d.lang, d.title, d.name_key, d.in_stock, d.visible, d.boost, d.total_sales, s.bests, COALESCE(ph.bonus, 0) AS phrase, (CASE WHEN d.name_key IN ('kazumi deck','kazumi decks') THEN 12 ELSE 0 END) + (CASE WHEN sku.doc IS NULL THEN 0 ELSE 6 END) AS exact, (CASE WHEN d.in_stock = 1 THEN 0 ELSE -6 END) AS stock, (d.boost) AS owner, (sku.doc IS NOT NULL) AS sku_hit, ROUND(s.base + COALESCE(ph.bonus, 0) + (CASE WHEN d.name_key IN ('kazumi deck','kazumi decks') THEN 12 ELSE 0 END) + (CASE WHEN sku.doc IS NULL THEN 0 ELSE 6 END) + (CASE WHEN d.in_stock = 1 THEN 0 ELSE -6 END) + (d.boost), 6) AS total FROM s INNER JOIN `wp_csearch_documents` d ON d.id = s.doc LEFT JOIN ph ON ph.doc = s.doc LEFT JOIN (SELECT ps.document_id AS doc FROM `wp_csearch_terms` ts INNER JOIN `wp_csearch_postings` ps ON ps.term_id = ts.id AND ps.generation = 2 AND ps.lang = 'en' AND ps.product = 1 WHERE ts.term = '=kazumi deck' AND ps.field = 2) sku ON sku.doc = s.doc WHERE (d.visible = 1 OR (0 = 1 AND sku.doc IS NOT NULL)) ORDER BY total DESC, d.in_stock DESC, d.total_sales DESC, d.object_id DESC, d.id ASC LIMIT 500
```

| table | type | possible keys | key | rows | Extra |
| --- | --- | --- | --- | --- | --- |
| <derived16> | ALL |  |  | 10 | Using temporary; Using filesort |
| d | eq_ref | PRIMARY | PRIMARY | 1 | Using where |
| <derived19> | ref | key0 | key0 | 1 |  |
| ts | const | PRIMARY,term | term | 1 | Using where; Using index |
| ps | eq_ref | PRIMARY,document_id,generation | document_id | 1 | Using where; Using index |
| <derived17> | ALL |  |  | 10 | Using filesort |
| <derived15> | ALL |  |  | 10 | Using temporary; Using filesort |
| <derived2> | ALL |  |  | 2 | Using temporary; Using filesort |
| p | ref | PRIMARY,generation | PRIMARY | 5 | Using where |
| <derived5> | ref | key0 | key0 | 1 | Using where |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
| <derived18> | ALL |  |  | 10 | Using temporary; Using filesort |
| <derived21> | ALL |  |  | 10 | Using temporary; Using filesort |
| <derived22> | ALL |  |  | 2 | Using temporary; Using filesort |
| p | ref | PRIMARY,generation | PRIMARY | 5 | Using where |
| <derived25> | ref | key0 | key0 | 1 | Using where |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
|  |  |  |  |  | No tables used |
