# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html). One entry per milestone; `v1.0.0` closes milestone 8.

## [Unreleased]

### Changed
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
