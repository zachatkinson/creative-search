# Decisions

Every decision made outside the spec, with its reason. The spec (`docs/SPEC.md`) wins where the two disagree; a decision here that contradicts it is a bug in this file. Dates are the day the decision was taken.

## Before milestone 1: the five questions for Zach

The spec left five questions for Zach before milestone 1 (`docs/SPEC.md`, "Open questions"). Milestone 1 started before they were answered, so the four the spec already names a default for were taken at that default, and the fifth is left open. **Zach: confirm or change these; each is one line to edit.**

### D-001 · Name and repo · 2026-10-21

`creative-search` under `web-projects`, like the Stockroom. Namespace `CreativeBranding\Search`, slug `creative-search`, prefix `csearch_`, text domain `creative-search`, WP-CLI `wp csearch`, exactly as the spec's "Name" paragraph says.

**Why:** the folder already carried the name, and the spec derives everything else from it.

### D-002 · Licence and sale · 2026-10-21

GPL-2.0-or-later. Built as a product (readme.txt, Plugin Check clean, no agency-only assumptions) but **not** sold in 1.0: it ships on the agency's own shops. Selling it with support is a 1.1 question.

**Why:** GPL is required for WordPress.org and matches the rest of the agency's plugins. Nothing in the code changes between "agency only" and "sold", so the decision can wait without cost; the spec's own wording ("or kept to the agency's shops for 1.0") was read as the default.

### D-003 · Posts and pages · 2026-10-21

Not in 1.0. The `type` column on `csearch_documents` and the `csearch_document` filter leave room for them in 1.1.

**Why:** the spec says 1.1 in both the non-goals and the question.

### D-004 · Recent searches · 2026-10-21

Off by default. The block setting `recent_searches` defaults to `no`.

**Why:** the spec's "The front end" and "Analytics and privacy" sections both say "off by default for privacy"; the question only asked whether to override that.

### D-005 · FiboSearch benchmark licence · 2026-10-21 · **Decided: no purchase**

Zach declined to buy FiboSearch Pro. Milestone 8's benchmark is built from two public sources instead:

1. **Documented behaviour.** FiboSearch publishes the Pro feature list (fibosearch.com/pricing and the "Should I go Pro?" article), the fuzzy-search algorithm in full (prefix of 2 letters, up to 50/200/400 candidate expansions, Levenshtein distance 1/2/3 for soft/normal/hard), the synonyms format (comma-separated groups, one per line, phrases allowed, the index rebuilt on save, matches via a synonym not highlighted), the analytics feature set (critical phrases after three no-result searches, 30-day retention, CSV export, dashboard widget, a filter to exclude phrases and IPs), the index design (three tables: index, inverted-index wordlist, doclist; SHORTINIT request path; "products found in less than 0.2 second"; 5 to 10 % database growth, about 10 MB per 1,000 products) and their own reliability figure (1.5 % of Pro indexes fail to build, down from 5 to 8 % in 2020, per the FiboSearch 2.0 post of 27 April 2026). These become a feature-by-feature matrix in `docs/BENCHMARK.md` with the source and date for each row, and the fuzzy rules become golden-query expectations our engine must at least match.
2. **Measured behaviour against the free version.** FiboSearch free is installed on the copy of Skatehouse for the afternoon: the same 50 queries, timing, ranking and suggestions. The free engine is WooCommerce's own `WP_Query` search, so this is a floor, not a peer; the write-up says so.

What this cannot give: Pro's actual suggestion content, ranking and timing on our catalogue. FiboSearch offers no trial or public Pro demo (pricing FAQ: "We don't offer a demo version of FiboSearch Pro for trial"; refund within 30 days only). The spec's "better" claims against Pro are therefore made against its documentation, and the benchmark says which rows are documented and which are measured.

**Why:** Zach's call. The documentation is unusually specific about how Pro works, which covers most of what a licence would have shown; the one thing lost is a side-by-side on the same catalogue.

## Milestone 1: Foundation

### D-010 · Tooling versions · 2026-10-21

PHP floor 8.2 (`composer.json` platform pinned to 8.2.99 so dependencies stay installable on the floor), PHPUnit 11 (the spec's version; 13 exists but the WordPress test suite and the polyfills are known good on 11), Infection 0.32 (0.33 and later require PHP 8.3, above the spec's 8.2 floor), PHPStan 2.3 **(superseded the same day by D-023: floors 8.5 and WordPress 7.0, PHPUnit 13, Infection 0.35)** at **level 9** as the gate table says (PHPStan 2 has a level 10; the spec wrote "level 9 (max)" when 9 was the maximum; moving to 10 is a one-line change to try once the domain exists), PHPCS with WordPress-Extra, WordPress-Docs, WordPress-VIP-Go (`automattic/vipwpcs`) and PHPCompatibilityWP, `@wordpress/scripts` 37 for the JavaScript and CSS standards, `@wordpress/env` 12, Playwright 1.64 with `@axe-core/playwright`.

**Why:** the newest release of each that satisfies the spec's named version and the PHP 8.2 floor. Everything in `composer.json` and `package.json` is the current release as of 21 October 2026 (`composer outdated --direct` and `npm outdated` report nothing newer); the only deliberately older choices were Infection 0.32 (PHP floor, lifted by D-023) and PHPUnit 9.6 for integration tests (D-021, still true). PHPCompatibility is 9.3.5 because 10.0 is still alpha. The wp-env zips (WordPress 7.1.3, WooCommerce 11.2.1, Plugin Check 2.1.0) are exact pins so a run is reproducible, bumped by hand when a release lands; they were the current releases on that date.

### D-011 · wp-env pins and the compatibility matrix · 2026-10-21

`.wp-env.json` pins the newest supported pair (WordPress 7.1.3, WooCommerce 11.2.1, Plugin Check 2.1.0) on PHP 8.5. The oldest supported pair (WordPress 7.0, WooCommerce 10.0, PHP 8.5 since D-023; it was 6.8 and 8.2) is a second CI leg that writes `.wp-env.override.json` (`tools/wp-env-oldest.sh`) before `wp-env start`. wp-env's database is MariaDB (its default image); a MySQL 8.0 leg needs a Docker Compose override that wp-env does not offer in configuration, so it is **deferred to milestone 8** and listed here so it is not forgotten.

**Why:** the spec names both ends of the matrix; wp-env can pin core, plugins and PHP but not the database image.

### D-012 · The unique key on `csearch_documents` includes `generation` · 2026-10-21

The spec's table lists `UNIQUE (type, object_id, lang)` and then, under "Generations", adds a `generation` column so a reindex writes N+1 beside N. Both cannot hold: the same product exists in N and N+1 at once. The key is `UNIQUE (generation, type, object_id, lang)`, with `generation` first so the live generation's rows cluster in the index.

**Why:** generations are the mechanism that keeps the shop searching a complete index; the unique key has to allow two generations of the same document.

### D-013 · `total_sales` on the document row · 2026-10-21

`csearch_documents` carries `total_sales int unsigned` beside the columns the spec's table lists.

**Why:** the spec's tie-break rule reads "sales count (WooCommerce's `total_sales` meta, copied to the document)" and the data-model rule is that nothing on the search path loads a product, so the copy needs a column.

### D-014 · Uninstall is a class · 2026-10-21

`uninstall.php` loads the Composer autoloader and calls `Install\Uninstaller::run()`, which drops the six tables, deletes every option and transient, the two product meta keys, the Action Scheduler actions in the `csearch` group and the object-cache group, per site on multisite. If the autoloader is missing it falls back to the same list inline.

**Why:** the integration suite can then prove "uninstall leaves nothing" by calling the class; a bare `uninstall.php` cannot be tested without a WordPress uninstall simulation.

### D-015 · No deactivation hook · 2026-10-21

There is none. Deactivation leaves the tables, options and scheduled actions in place; Action Scheduler ignores actions whose hook has no callback.

**Why:** the spec says "deactivation leaves everything in place"; a hook that does nothing is noise.

### D-016 · The catalogue generator · 2026-10-21

`tests/Fixtures/Catalogue.php` (PSR-4 class; the spec's `tests/fixtures/catalogue.php` is the same path on this case-insensitive filesystem) generates products as plain arrays from a seed with its own xorshift PRNG, so product *i* is the same on every PHP version and at every catalogue size: the first 24 products are hand-written Skatehouse-shaped "hero" products (KAZUMI Skate Deck, Dealer, 8.25″ among them) that the golden queries anchor on, and the rest are generated. Writing the arrays into WooCommerce is a separate loader that arrives with milestone 3, the first milestone that needs products in a database.

**Why:** the under-a-minute budget is for generating the fixture; inserting 10,000 products through WooCommerce's CRUD takes far longer than a minute and belongs to the reindex benchmark, where it is measured separately.

### D-017 · Golden queries · 2026-10-21

`tests/Fixtures/queries.yml` holds the 50 queries with `expect.top` (a SKU, a term, or `none`), `expect.suggestions` and the synonyms the test seeds (`board` = `deck`). Milestone 1 checks the file is well-formed and every SKU and term it names exists in the catalogue at 300 and at 10,000 products; milestone 2 scores it in memory; milestone 4 runs it through the API. `symfony/yaml` reads it (dev dependency only).

**Why:** the spec names a YAML file and PHP has no YAML parser built in.

### D-018 · Settings defaults the spec does not name · 2026-10-21

`hidden_by_sku` is `no` until the owner answers the hidden-products question (safer default: a dealer-only line stays invisible). `fallback_to_default_language` is `yes` (the spec says on by default). `boost_rules`, `fallback_products` and `stats` are arrays in `csearch_settings` because the spec gives them no table.

**Why:** listed so the owner's answers in milestone 7 have a place to land.

### D-019 · Infection scope in milestone 1 · 2026-10-21

With `src/Domain` empty, `infection.json5` covers `src/Settings` and `src/Container.php` at the 90 % floor. Milestone 2 adds `infection-domain.json5` at 100 % for `src/Domain`, and `composer infection` runs both.

**Why:** Infection fails on an empty source list, and the two floors differ.

### D-020 · Three grep gates · 2026-10-21

`tools/gates.sh` fails CI on string-built SQL outside `src/Repository/` and `src/Install/`, on `eval(`, `extract(`, `unserialize(`, `query_posts(`, `create_function(`, `base64_decode(` anywhere in `src/`, and on `date(` (the spec bans `date()` without a timezone; `gmdate()` and `wp_date()` are the replacements).

**Why:** the spec asks for a grep gate beside PHPStan; a shell script is the simplest thing CI and a developer run the same way.

### D-021 · Two PHPUnit versions · 2026-10-21

The unit suite runs on the current PHPUnit through Composer (11 at first, 13 since D-023). The integration suite runs on PHPUnit 9.6 with the Yoast polyfills from a second Composer project, `tools/integration/` (its lock committed, its vendor gitignored, installed by `tools/integration.sh`; until D-023 it was a checksummed phar), because the WordPress core test suite that wp-env ships, for WordPress 7.1 as for 6.8, still calls `PHPUnit\Util\Test::parseTestMethodAnnotations()`, which PHPUnit 10 removed. Composer cannot install two versions of one package. Integration tests therefore use `set_up()`/`tear_down()` and no PHPUnit 10+ attributes.

**Why:** the first integration run under PHPUnit 11 failed every test with that undefined method; the WordPress suite's PHPUnit ceiling is core's to lift, not ours.

### D-022 · Firefox under Playwright on macOS 27 · 2026-10-21

macOS 27 puts `~/Library/Application Support/Firefox` behind a permission prompt. Playwright's Firefox build still resolves that directory for `profiles.ini` bookkeeping even when launched with its own `-profile`, so a launch from a terminal without Full Disk Access exits with "Could not find profile folder" (microsoft/playwright#42768, open; Chromium and WebKit are unaffected because Playwright hands them a complete user-data directory). `playwright.config.ts` sets `CFFIXED_USER_HOME` to a fresh temporary directory for the Firefox project on macOS only, which is the workaround confirmed in that issue; Firefox then launches and the two smoke tests pass locally. Linux CI and Windows get the default launch. Remove the branch when Playwright ships a build that owns its app-data directory.

**Why:** the browser gate names three engines; "run it in CI" was the only alternative and left the Firefox column unverified before each push.

### D-023 · Floors raised to WordPress 7.0 and PHP 8.5 · 2026-10-21

Zach raised the floors from WordPress 6.8 and PHP 8.2. The chain: Infection 0.33+ needs PHP 8.3 and PHPUnit 13 needs 8.4.1, so the 8.2 floor held the toolchain back; WordPress 6.8 is documented as supporting PHP 8.4 but not 8.5 (core handbook, "PHP Compatibility and WordPress Versions"), so 8.5 meant dropping 6.x; the agency's shops run WordPress 7, so 6.x support had no customer. WooCommerce 11.2 itself requires WordPress 7.0, so 7.0 is the natural WordPress floor; the WooCommerce floor stays 10.0 for the features the spec names. The compatibility matrix is now WordPress 7.0 + WooCommerce 10.0 and WordPress 7.1 + WooCommerce 11.2, both on PHP 8.5; the static job runs on 8.5 only until 8.6 ships.

What changed: plugin headers and readme (Requires at least 7.0, Requires PHP 8.5), `composer.json` (`php >=8.5`, platform 8.5.99, PHPUnit ^13.4, Infection ^0.35, the polyfills moved out), `phpstan.neon` (phpVersion 80500), `phpcs.xml` (testVersion 8.5-), CI, both wp-env legs, and `tools/integration/` as its own Composer project for PHPUnit 9.6 plus the polyfills, which PHPUnit 13 cannot share a tree with (the polyfills' constraint stops at ^12).

Two things the change surfaced. The integration bootstrap must register the plugin's Composer loader *behind* PHPUnit 9.6's (Composer prepends by default), or PHPUnit 9.6 loads PHPUnit 13's classes from the plugin's vendor tree and dies before the first test. And the activation test was depending on leftover state in Action Scheduler's tables, which the WordPress suite never reinstalls; see the addendum in `reviews/M1.md`.

Trade-off stated once: a host without PHP 8.5 cannot run the plugin. PHP 8.5 has been stable since 20 November 2025 and WordPress 7.0 and 7.1 support it fully; the agency controls the hosting of the shops it ships to.

**Why:** Zach's call, with the reasoning above; the floors now follow what the agency runs rather than what the market still installs.

