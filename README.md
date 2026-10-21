# Creative Search

Search for WooCommerce: live suggestions as the shopper types, typo tolerance, SKU and attribute matching, an index of its own, search analytics and an accessible search bar. Built by Creative Branding; Skatehouse Distribution is the first shop.

- The spec: [docs/SPEC.md](docs/SPEC.md)
- Decisions: [docs/DECISIONS.md](docs/DECISIONS.md)
- Settings: [docs/SETTINGS.md](docs/SETTINGS.md) · Hooks: [docs/HOOKS.md](docs/HOOKS.md)
- Security: [docs/SECURITY.md](docs/SECURITY.md) · Accessibility: [docs/A11Y.md](docs/A11Y.md)
- Benchmarks: [docs/BENCHMARK.md](docs/BENCHMARK.md) · Queries: [docs/QUERIES.md](docs/QUERIES.md)
- Milestone reviews: [docs/reviews/](docs/reviews/)

Requires WordPress 6.8+, WooCommerce 10.0+, PHP 8.2+, MySQL 8.0 or MariaDB 10.6+. GPL-2.0-or-later.

## Developing

```
composer install && npm install
composer check && npm run check
npx wp-env start && composer test:integration && npm run plugin-check && npx playwright test
```

See [CLAUDE.md](CLAUDE.md) for every command and the rules the code is held to.
