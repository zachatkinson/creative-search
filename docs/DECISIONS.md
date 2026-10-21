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

### D-005 · FiboSearch benchmark licence · 2026-10-21 · **OPEN**

Not decided. The benchmark day is in milestone 8, so nothing in milestones 1 to 7 depends on it. Recommendation when the time comes: buy one Pro Personal licence ($59) for the afternoon, because the comparison table is built against Pro, and a benchmark against the free version would not test typo tolerance, synonyms or the own index.

## Milestone 1: Foundation

### D-010 · Tooling versions · 2026-10-21

PHP floor 8.2 (`composer.json` platform pinned to 8.2.99 so dependencies stay installable on the floor), PHPUnit 11 (the spec's version; 13 exists but the WordPress test suite and the polyfills are known good on 11), Infection 0.35, PHPStan 2.3 at **level 9** as the gate table says (PHPStan 2 has a level 10; the spec wrote "level 9 (max)" when 9 was the maximum; moving to 10 is a one-line change to try once the domain exists), PHPCS with WordPress-Extra, WordPress-Docs, WordPress-VIP-Go (`automattic/vipwpcs`) and PHPCompatibilityWP, `@wordpress/scripts` 37 for the JavaScript and CSS standards, `@wordpress/env` 12, Playwright 1.64 with `@axe-core/playwright`.

**Why:** the newest release of each that satisfies the spec's named version.

### D-011 · wp-env pins and the compatibility matrix · 2026-10-21

`.wp-env.json` pins the newest supported pair (WordPress 7.1.3, WooCommerce 11.2.1, PHP 8.4, Plugin Check 2.1.0). The oldest supported pair (WordPress 6.8, WooCommerce 10.0, PHP 8.2) is a second CI leg that writes `.wp-env.override.json` (`tools/wp-env-oldest.sh`) before `wp-env start`. wp-env's database is MariaDB (its default image); a MySQL 8.0 leg needs a Docker Compose override that wp-env does not offer in configuration, so it is **deferred to milestone 8** and listed here so it is not forgotten.

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

The unit suite runs on PHPUnit 11 through Composer, as the spec says. The integration suite runs on PHPUnit 9.6 (a pinned, checksummed phar fetched by `tools/integration.sh` into the gitignored `tools/bin/`), because the WordPress core test suite that wp-env ships, for WordPress 7.1 as for 6.8, still calls `PHPUnit\Util\Test::parseTestMethodAnnotations()`, which PHPUnit 10 removed. Composer cannot install two versions of one package. Integration tests therefore use `set_up()`/`tear_down()` and no PHPUnit 10+ attributes.

**Why:** the first integration run under PHPUnit 11 failed every test with that undefined method; the WordPress suite's PHPUnit ceiling is core's to lift, not ours.
