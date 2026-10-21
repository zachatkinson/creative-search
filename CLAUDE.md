# Creative Search

Spec: docs/SPEC.md (read the milestone you are on under "Milestones for Claude Code" before touching code). Decisions: docs/DECISIONS.md. Workflow: docs/START.md. One milestone per pull request, reviewed against the agency's seven-dimension matrix before the next starts; do not start milestone N+1 until Zach says so.

## Commands
- composer install && npm install
- composer lint            # phpcs: WordPress-Extra, WordPress-Docs, WordPress-VIP-Go, PHPCompatibilityWP, zero warnings
- composer analyse         # phpstan level 9 with the WordPress and WooCommerce stubs
- composer gates           # tools/gates.sh: SQL outside repositories, banned functions, date(), strict_types, outbound requests
- composer test:unit       # phpunit 13, no WordPress (src/Domain must need no shim)
- composer test:coverage && php tools/coverage-floor.php tmp/coverage.xml   # 100 % of src/Domain, 90 % overall
- composer test:benchmark  # the budget tests (group benchmark)
- composer infection       # mutation testing (90 %; src/Domain at 100 % from milestone 2)
- composer check           # lint, analyse, gates, unit
- npm run check            # eslint, stylelint, prettier through @wordpress/scripts
- npx wp-env start         # WordPress 7.1.3 + WooCommerce 11.2.1 + Plugin Check 2.1.0, PHP 8.5, on :8890 (tests site :8891)
- npm run env:oldest && npx wp-env start   # the oldest supported pair (WordPress 7.0, WooCommerce 10.0, PHP 8.5)
- composer test:integration   # phpunit 9.6 from tools/integration/ inside the wp-env tests container
- npm run plugin-check     # wp plugin check creative-search inside wp-env
- npx playwright test      # browser tests with axe (chromium, firefox, webkit; desktop and phone)
- composer build           # tmp/creative-search-<version>.zip without dev files

## Rules
- PSR-4 under src/, one class per concern; repositories own the tables, services own the rules; nothing in the root file but the bootstrap; hooks call services and hold no logic.
- src/Domain imports nothing from WordPress. If a Domain class needs a shim in tests/Support/wp-shims.php, it is wrong.
- Direct SQL only in src/Repository/ and src/Install/, always through $wpdb->prepare(); products through WooCommerce CRUD, and never on the search path.
- Every string through the text domain with context where it helps; no concatenated sentences; _n() for plurals; prices through wc_price().
- Times through Support\Clock (UTC), never date() or MySQL NOW().
- Every public hook in docs/HOOKS.md, every setting in docs/SETTINGS.md, every decision you make yourself in docs/DECISIONS.md with its reason, a CHANGELOG.md entry per milestone, the seven-dimension review in docs/reviews/.
- Ask before: adding a dependency, changing the schema after milestone 1, renaming a public hook, moving the PHP or WordPress floor.

## Quirks
- Docker runtime on this Mac is OrbStack (`open -a OrbStack`), not Docker Desktop.
- The Stockroom's wp-env uses :8888/:8889; this plugin uses :8890/:8891 so both can run.
- PHP floor 8.5 and WordPress floor 7.0 (D-023), so Composer runs the current PHPUnit 13 and Infection 0.35; the WordPress core test suite still needs PHPUnit 9.6, which lives with the Yoast polyfills in tools/integration/ (own composer.json and lock). The WP-CLI stubs package does not support WordPress 7 stubs, so WP-CLI symbols are stubbed by hand in tools/ when needed.
- composer.json pins platform php 8.5.99 so a dependency that needs a newer PHP than the floor cannot slip in.
