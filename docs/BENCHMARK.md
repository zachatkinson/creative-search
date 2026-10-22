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

## v0.4.0 and v0.6.0

Machine: Apple Silicon Mac, wp-env (PHP 8.5, WordPress 7.1.3, WooCommerce 11.2.1, MariaDB in Docker). Times are measured around the work itself (`rest_do_request()`, the reindex loop), without WordPress's bootstrap. The 10,000-product runs are the CI job `index-budgets`; they were not run on this Mac because loading 10,000 products with their variations through WooCommerce takes longer than the reindex (D-047).

| What | Result | Budget |
| --- | --- | --- |
| Full reindex, 300 products in two languages | 1.24 s, 4.1 ms a product | 9 s (scaled) |
| Full reindex, 2,000 products | 9.06 s, 4.5 ms a product; 113,591 postings, 13,732 terms | 60 s (scaled) |
| Full reindex, 10,000 products | not measured here; about 45 s extrapolated from the flat rate | 5 minutes |
| Indexing one product (a 22-variation shoe, a 12-variation tee, a deck, a complete) | under 50 ms each (asserted) | 50 ms |
| Extra queries on a product save request | 0 (asserted with a query spy) | 0 |
| `/suggest` p95, cold (cache flushed before each of the 50 golden queries, 300 products, two languages) | 6.3 ms | 100 ms |
| `/suggest` p95, warm | 0.4 ms | 60 ms |
| `/search` p95 | 5.6 ms | 100 ms |
| Memory, p95 of a cold suggest | 0.55 MB | 4 MB |
| Extra queries on a results page, warm | 1 (asserted, every table counted) | 1 |
| Extra queries on a results page, cold | 15 to 18 (D-055) | 1 |
| The block's script | 2.6 kB compressed (6.0 kB minified) | 12 kB |
| The block's CSS | 1.1 kB compressed | 4 kB |
