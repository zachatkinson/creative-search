# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html). One entry per milestone; `v1.0.0` closes milestone 8.

## [Unreleased]

## [0.4.0] - 2026-10-21

Milestones 3 and 4, Index and Query and API, released together (D-056; there is no 0.3.0). The Domain gets a database: products, categories, tags and brands are written to the four index tables in the background whenever they change, a full reindex builds the next generation beside the live one and switches when complete, and WP-CLI can index, rebuild and report. The searcher answers four REST routes from that index, cached, rate-limited and logged without the shopper. Nothing a shopper sees yet; the block in milestone 6 calls these routes.

### Added
- Repositories for the four index tables (`DocumentRepository`, `TermRepository`, `PostingRepository`, `TrigramRepository`, on a shared `Repository` base that is the only caller of `$wpdb`, every statement prepared) and `IndexRepository`, the SQL implementation of `Domain\IndexStore`, reading the live generation only; the container binds the interface to it.
- `Index\Indexer`: a product becomes one document per language with its name, every SKU (variations included), short and long description (shortcodes and HTML stripped, 2,000 characters), category names with parents, tags, brands, every attribute value (global and custom), price range, stock roll-up, image, URL, visibility, the owner's boost and sales; categories, tags and brands become documents of their own; removal decrements `df` and keeps the row at 0; SKU keys stored as dictionary terms (D-041).
- `Index\ProductReader`, `TermReader`, `Languages` and `AnalyzerFactory`, with the public filters `csearch_index_product`, `csearch_product_fields` (D-050), `csearch_document`, `csearch_tokens`, `csearch_stop_words` and `csearch_languages` (D-045), and the action `csearch_index_switched`.
- The hook set: product, variation, stock, stock status, term assignment, trash, untrash, delete, status change, term edit, create and delete, CSV import and the boost and exclude meta, each queuing the product once per request in memory and handing Action Scheduler one unique job at shutdown (D-043); stock-only saves flip `in_stock` without re-tokenising; a renamed term re-queues its products 100 a job; under WP-CLI the work runs inline at shutdown.
- `Index\Reindex`: walks products 100 a job in id order into generation N+1, then categories, tags and brands, then switches `csearch_index_state.live`, flushes the cache group and deletes the old generation 500 documents a job; Cancel gives the half-built generation up; a reindex already running is not started twice; a product that throws is recorded and skipped. Live changes during a reindex reach both generations.
- `wp csearch index <id>...`, `wp csearch reindex [--inline] [--cancel]`, `wp csearch status`.
- `Install\Upgrader`: schema upgrades on `admin_init` and before WP-CLI commands, behind a lock (D-046).
- `Support\Cast` for hook arguments and filter results that arrive untyped; `Support\PluginException`.
- `Search\SearchService` (the one place a query becomes a ranked answer: cleaned, language-checked, the owner's synonyms, weights through `csearch_fields`, boost rules, hidden-by-SKU, the fallback language), `Search\Suggestions` (the panel's answer from the documents table alone: name, URL, thumbnail with `srcset`, price through `wc_price()`, stock), `Search\Cache` (by generation, `csearch_cache_ttl`), `Search\RateLimiter` (sliding window per client, `csearch_rate_limit`), `Search\SearchLog` (one row per search written at shutdown, scrubbed), `Search\Clicks` and `Search\LogMaintenance` (`csearch_purge_log`: daily roll-up into `stats`, retention purge in batches of 1,000).
- `GET /csearch/v1/suggest`, `GET /csearch/v1/search`, `GET /csearch/v1/status` and `POST /csearch/v1/click`, with argument and response schemas, `Cache-Control` and an ETag of the generation and the normalised query (a 304 costs no search), 429 with `Retry-After`.
- `wp csearch explain "<query>"`.
- `SynonymRepository`, `LogRepository`, `ProductIdRepository`.
- Tests: 30 integration tests for the indexer, the hooks, the reindex and the CLI against WooCommerce 11.2.1, 11 for the REST API, the golden set's 50 queries through `/suggest` and `/search` in English and French with the p95 budgets, a `WooCommerceCatalogue` loader that writes the fixture catalogue through WooCommerce's CRUD, and a timing test (`composer test:integration:benchmark`, D-047).

### Fixed before release (milestone 3 review)
- `df` is decremented once per removed document, not once per term, so a cleanup batch or a two-language product leaves exact counts; the score counts `df` from the postings of the query's language (D-044, D-050).
- Jobs are deduplicated on pending jobs only, so the oldest supported Action Scheduler does not drop other products' jobs or the reindex's next batch, and a save during a product's running job is not lost (D-043).
- The index state is read fresh and the cancel flag has its own option, so a cancel is never overwritten by a running batch (D-053); a cancelled generation's number is never reused; a reindex whose chain died can be cancelled; a queued reindex can be cancelled before it starts and an inline one removes it.
- The reindex walks product ids by keyset, so a product trashed mid-walk does not drop another from the new generation (D-054).
- A quantity change that keeps the stock flag no longer re-tokenises the product; a renamed global attribute value or parent category reaches its products; a deleted term's products are queued 100 a job; the queue flushes after WooCommerce's deferred product sync.
- A failed document insert throws instead of attaching postings to another document.
- Uninstall deletes the plugin's Action Scheduler rows of every status; a deleted network site drops the six tables.
- WP-CLI messages are translatable, `index` exits non-zero when a product cannot be indexed, and every command takes `--format=json`.
- The texts filter is `csearch_product_fields`; `csearch_fields` is the spec's weights filter (D-050).
- A CI job runs the reindex and API budgets at 10,000 products.

### Changed
- Activation queues the first build as one Action Scheduler job and indexes nothing on the request; network activation sets up every site (M1 review items 2 and 3).
- `Schema::maybeUpgrade()` no longer runs on every request; it is replaced by `Install\Upgrader`.
- `Domain\Analyzer` takes an optional token filter, the seam the `csearch_tokens` filter uses.
- The per-product job is `csearch_job_index_product`, not `csearch_index_product`, which the spec also gives to the filter (D-042).
- `src/Index`, `src/Hooks`, `src/Cli` and `src/Repository` leave the core Infection run for the integration suite (D-048).

## [0.2.0] - 2026-10-21

Milestone 2, Domain. The whole search algorithm as pure PHP under `src/Domain`, with no WordPress import, run in the tests against an in-memory index built from the catalogue fixture. Nothing a shopper or owner sees yet; milestone 3 gives it a database.

### Added
- `Domain\Tokenizer` with `Folding` (lower-case, accents, ligatures) and `StopWords` (Snowball's English and French lists): one token per word, inch marks read with the number and dropped (D-024), apostrophes indexed as the joined form and both sides at one position (D-025), stop words out and positions counted over the tokens kept (D-026, D-027), tokens capped at 64 characters.
- `Domain\Stemmer`: exact ports of Snowball's `porter.sbl` and `french.sbl` (`PorterStemmer`, `FrenchStemmer`), an `IdentityStemmer` for other languages, and `Stemmers::for()`; both ports pass every vector in Snowball's distribution, vendored under `tests/Fixtures/snowball/` (D-031).
- `Domain\Trigrams` (padded, distinct, half-shared minimum, D-035) and `Domain\Distance` (bounded Damerau–Levenshtein with transpositions; one edit for 4 to 7 characters, two from 8).
- `Domain\QueryParser` and `Query`: quoted phrases, `-word` exclusion, a prefix flag on the unfinished last token, curly quotes straightened, 200 characters and 8 tokens, synonyms attached (`Synonym`, `Synonyms`, D-029), an all-stop-word query that runs and finds nothing.
- `Domain\Analyzer`: a product or term as fields to the postings an index stores (term, field, frequency, first position), the stem as a second posting at the same position, the SKU field counted once (D-038).
- `Domain\IndexStore`, the interface the searcher reads through (D-028), with `Document`, `TermMatch`, `TokenWidening`, `SearchOptions`, `SearchResult`, `Hit` and `Score` as the value objects around it.
- `Domain\Searcher`: the widening plan as calls on the store (exact, variants, stem, synonyms and prefix together, D-033; typo candidates only when those find nothing), exclusions, the AND with phrase adjacency, the hidden-product rule, the retry that drops the least-matched token when nothing matched (D-034) and the term suggestions (D-037); a fallback language when a language finds nothing.
- `Domain\Scorer` and `Score`: the spec's formula (field weight × match kind × `1 + ln(1 + tf)` × `ln((N + 1) / (df + 1))`, best field per token), phrase bonus, exact-name and exact-SKU bonuses, the stock penalty, owner boosts; every figure kept on the `Score` for `explain` in milestone 4.
- `Domain\Scrub`: emails, phone numbers, card numbers and postal codes replaced before anything reaches the log (D-039).
- Tests: a case table for every tokenizer, parser, scrub, distance and trigram rule; xorshift property tests on the tokenizer; Snowball's 64,256 vectors; scorer tests against hand-built documents; a searcher test on a hand-built index; and the golden set scored in memory against the catalogue at 300 products (`tests/Support/MemoryIndex`, `CatalogueIndexer`), with a benchmark that runs the fifty queries inside a second.
- A second Infection configuration, `infection-domain.json5`, with a 100 % floor on `src/Domain`; `composer infection` runs both.

### Changed
- Four golden expectations rewritten to match the algorithm the spec describes (D-032: `dck` → `dek`, the missing-SKU correction, `the` finds nothing, the exclusion query asserts absence). Generated products in the catalogue fixture now sell under 15 units so the hero products are the deterministic tie-break winners (D-036); SKUs, names and prices are unchanged.
- `Support\Clock` joins the core Infection run; `Plugin`, `Hookable`, `Support\Options` and `Support\Tables` leave it as WordPress-bound (M1 review).
- `phpcs.xml` says `minimum_supported_wp_version` 7.0, matching the floor (M1 review item 4).
- Floors raised: WordPress 7.0 or later and PHP 8.5 or later (were 6.8 and 8.2; D-023). The toolchain moves to PHPUnit 13 and Infection 0.35; the WordPress integration suite keeps PHPUnit 9.6 in `tools/integration/`.
- Firefox launches under Playwright on macOS 27 (D-022); the FiboSearch benchmark plan no longer needs a Pro licence (D-005).

### Fixed
- The activation integration test no longer depends on Action Scheduler rows left by earlier runs; the harness clears the plugin's group before each test.

## [0.1.0] - 2026-10-21

Milestone 1, Foundation. Nothing a shopper or owner sees yet: the skeleton the next seven milestones build on, with every CI gate wired and green against it.

### Added
- The plugin bootstrap (`creative-search.php`: headers, constants, autoloader check, activation hook, HPOS and cart-block compatibility declarations, boot on `plugins_loaded`), a hand-written service container with interface bindings and a `Hookable` contract, and `Plugin::boot()` with the WooCommerce 10.0 version check.
- `Install\Schema`: the six InnoDB utf8mb4 tables (`csearch_documents`, `csearch_terms`, `csearch_postings`, `csearch_trigrams`, `csearch_synonyms`, `csearch_log`) with the spec's keys, `generation` on documents and postings, `dbDelta()` idempotent (a second run reports no change), `csearch_db_version` autoloaded and `maybeUpgrade()` on boot.
- `Settings\Settings`: the one `csearch_settings` option with every default the spec names (`docs/SETTINGS.md`), typed readers, and a `sanitize()` that drops unknown keys and clamps every value.
- `Install\Activator` (schema and defaults, no work), `Install\Uninstaller` (tables, options, transients, product meta, Action Scheduler group, cache group, per site on a network) and `uninstall.php` with an inline fallback; no deactivation hook, by design.
- `Support\Tables`, `Support\Options` (every stored name in one place) and `Support\Clock` (UTC, freezable).
- The catalogue generator (`tests/Fixtures/Catalogue.php`): deterministic products as arrays from a seed, 24 hand-written Skatehouse-shaped heroes first, realistic brands, series, widths, diameters, sizes, colours, SKUs and French names; 10,000 products with 48,000 variations in under a second.
- The golden query set (`tests/Fixtures/queries.yml`, 50 queries covering every matching rule, with the synonyms they seed) and its loader.
- Tests: unit (Settings, Container, Clock, Options, the generator, the golden set), integration in wp-env (schema idempotence, columns, keys, uniqueness, upgrade, activation, headers, HPOS declaration, uninstall leaves nothing), Playwright smoke with axe on three engines at desktop and phone widths, and the fixture benchmark.
- CI (`.github/workflows/ci.yml`): PHPCS (WordPress-Extra, WordPress-Docs, WordPress-VIP-Go, PHPCompatibilityWP, zero warnings), PHPStan level 9, the grep gates (`tools/gates.sh`: SQL outside repositories, banned functions, `date()`, `strict_types`, outbound requests), PHPUnit with the coverage floors (`tools/coverage-floor.php`), Infection, the JavaScript and CSS standards, benchmarks, and integration plus Plugin Check plus browser tests on the newest and the oldest supported WordPress and WooCommerce.
- Docs: `DECISIONS.md` (the five pre-milestone answers and D-010 to D-020), `HOOKS.md`, `SETTINGS.md`, `SECURITY.md`, `A11Y.md`, `BENCHMARK.md`, `QUERIES.md`, `reviews/M1.md`.
