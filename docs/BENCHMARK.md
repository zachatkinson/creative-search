# Benchmarks

The spec's budgets are tests: a build fails when one is missed. The numbers from each tag are appended here. The FiboSearch comparison is added in milestone 8 in two parts (D-005): a documented-behaviour matrix built from FiboSearch's public feature pages, documentation and changelog (fuzzy rules, synonyms, analytics, index design, reliability figures, each row with its source and date), and a measured run of the same 50 queries, their timing, ranking and suggestions against FiboSearch **free** on a copy of Skatehouse. No Pro licence is bought and there is no public Pro demo, so Pro's own suggestions and timings on our catalogue are not measured; every row says whether it is documented or measured.

## Budgets (spec: Performance)

| What | Budget | Suite |
| --- | --- | --- |
| `/suggest` server time, p95, warm cache | 60 ms | integration, milestone 4 |
| `/suggest` server time, p95, cold | 100 ms | integration, milestone 4 |
| `/search` for a results page, p95 | 100 ms | integration, milestone 4 |
| Indexing one product (tokenise and write) | 50 ms | integration, milestone 3 |
| Full reindex of 10,000 products | 5 minutes, in jobs of 100 | integration, milestone 3 |
| Extra queries on a product save request | 0 | integration, milestone 3 |
| Extra queries on a results page | 1 | integration, milestone 5 |
| The block's script | 12 kB compressed; CSS 4 kB | build, milestone 6 |
| Memory for a suggest call | 4 MB above baseline | integration, milestone 4 |
| Generating the 10,000-product fixture | 60 s | unit, `composer test:benchmark`, milestone 1 |

## v0.1.0

Machine: Apple Silicon Mac, PHP 8.5.4 CLI (CI: ubuntu-latest, PHP 8.4).

| What | Result |
| --- | --- |
| Generating 10,000 products (48,482 variations) as arrays | 0.4 s |
