# Queries

`EXPLAIN` output for the four search statements, kept current whenever the schema changes (spec: Performance → How). Filled in milestone 4 when the search repository exists. The schema they run against is in `src/Install/Schema.php`; the keys that make them cheap:

| Statement | Key it uses |
| --- | --- |
| Term lookup: terms for a list of exact tokens and stems | `csearch_terms.term` (unique) |
| Prefix: `term LIKE 'kaz%'` limited by `df` | `csearch_terms.term` (range scan) |
| Typo candidates: trigram rows for the token, grouped and limited | `csearch_trigrams` PRIMARY `(trigram, term_id)` |
| Postings join: postings for the term ids, grouped per document, filtered to the live generation | `csearch_postings` PRIMARY `(term_id, document_id, field)` |
| Suggestion rows: the documents by id | `csearch_documents` PRIMARY |
| Cleanup of an old generation in batches | `csearch_postings.generation`, `csearch_documents.object` (generation first) |
