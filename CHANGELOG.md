# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html). One entry per milestone; `v1.0.0` closes milestone 8.

## [Unreleased]

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
