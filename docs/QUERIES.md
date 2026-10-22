# Queries

`EXPLAIN` output for the search statements, kept current whenever the schema changes (spec: Performance → How). The schema they run against is in `src/Install/Schema.php`; the keys that make them cheap:

| Statement | Key it uses |
| --- | --- |
| Term lookup: terms for a list of exact tokens and stems | `csearch_terms.term` (unique) |
| Prefix: `term LIKE 'kaz%'` limited by `df` | `csearch_terms.term` (range scan) |
| Typo candidates: trigram rows for the token, grouped and limited | `csearch_trigrams` PRIMARY `(trigram, term_id)` |
| Postings join: postings for the term ids, grouped per document, filtered to the live generation | `csearch_postings` PRIMARY `(term_id, document_id, field)` |
| Suggestion rows: the documents by id | `csearch_documents` PRIMARY |
| Cleanup of an old generation in batches | `csearch_postings.generation`, `csearch_documents.object` (generation first) |

## How it stays current

`tests/Integration/QueriesExplainTest.php` (group `benchmark`, run with `composer test:integration -- --group benchmark`) loads the 300-product catalogue in English and French, runs a suggestion for an exact word, a prefix, a typo, a SKU with a number and a French word, and a results-page search, catches every statement that touches a plugin table, and `EXPLAIN`s each one. It fails when a read of a plugin table has no key it could use. It writes the plans to `tmp/explain.md`; the plans below are copied from it (long id lists shortened) and are refreshed whenever the schema or a statement changes.

On MariaDB with a table this small, the optimiser sometimes reads `csearch_documents` or `csearch_synonyms` whole even though the primary key could serve, because the id list covers much of the table. That is the optimiser's cost choice, not a missing key; the test allows it only when `possible_keys` names a key.

## Plans, 2026-10-22, 300 products in two languages

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
SELECT t.term, p.document_id, p.field, p.tf, p.first_pos FROM `wp_csearch_postings` p INNER JOIN `wp_csearch_terms` t ON t.id = p.term_id INNER JOIN `wp_csearch_documents` d ON d.id = p.document_id WHERE t.term IN ('deck','decks') AND p.generation = 2 AND d.lang = 'en' AND d.type = 'product' ORDER BY p.document_id, p.field
```

| table | type | possible keys | key | rows | Extra |
| --- | --- | --- | --- | --- | --- |
| t | range | PRIMARY,term | term | 2 | Using where; Using index; Using temporary; Using filesort |
| p | ref | PRIMARY,document_id,generation | PRIMARY | 1 | Using where |
| d | eq_ref | PRIMARY | PRIMARY | 1 | Using where |

```sql
SELECT t.term, p.document_id, p.field, p.tf, p.first_pos FROM `wp_csearch_postings` p INNER JOIN `wp_csearch_terms` t ON t.id = p.term_id INNER JOIN `wp_csearch_documents` d ON d.id = p.document_id WHERE t.term IN ('deck','decks') AND p.generation = 2 AND d.lang = 'en' AND d.type <> 'product' ORDER BY p.document_id, p.field
```

| table | type | possible keys | key | rows | Extra |
| --- | --- | --- | --- | --- | --- |
| t | range | PRIMARY,term | term | 2 | Using where; Using index; Using temporary; Using filesort |
| p | ref | PRIMARY,document_id,generation | PRIMARY | 1 | Using where |
| d | eq_ref | PRIMARY | PRIMARY | 1 | Using where |

```sql
SELECT COUNT(*) FROM `wp_csearch_documents` WHERE generation = 2 AND lang = 'en'
```

| table | type | possible keys | key | rows | Extra |
| --- | --- | --- | --- | --- | --- |
| wp_csearch_documents | ref | object,generation_lang | generation_lang | 352 | Using where; Using index |

```sql
SELECT * FROM `wp_csearch_documents` WHERE id IN (611,605)
```

| table | type | possible keys | key | rows | Extra |
| --- | --- | --- | --- | --- | --- |
| wp_csearch_documents | range | PRIMARY | PRIMARY | 2 | Using where |

```sql
SELECT p.document_id, t.term FROM `wp_csearch_postings` p INNER JOIN `wp_csearch_terms` t ON t.id = p.term_id WHERE p.document_id IN (605,611) AND p.field = 2 AND t.term LIKE '=%'
```

| table | type | possible keys | key | rows | Extra |
| --- | --- | --- | --- | --- | --- |
| p | range | PRIMARY,document_id | document_id | 3 | Using where; Using index |
| t | eq_ref | PRIMARY,term | PRIMARY | 1 | Using where |

```sql
SELECT t.term, COUNT(*) AS shared FROM `wp_csearch_trigrams` g INNER JOIN `wp_csearch_terms` t ON t.id = g.term_id WHERE g.trigram IN ('  k',' ka','kaz','azm','zmu','mui','ui ') AND t.df > 0 GROUP BY g.term_id, t.term HAVING shared >= 3 ORDER BY shared DESC, t.term ASC LIMIT 200
```

| table | type | possible keys | key | rows | Extra |
| --- | --- | --- | --- | --- | --- |
| g | range | PRIMARY | PRIMARY | 18 | Using where; Using temporary; Using filesort |
| t | eq_ref | PRIMARY | PRIMARY | 1 | Using where |
