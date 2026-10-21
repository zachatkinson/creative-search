---
name: verify
description: Run every local gate (lint, analyse, grep gates, unit tests with the coverage floors, the JS and CSS standards), plus the integration tests, Plugin Check and the browser tests when wp-env is up. Invoke before every commit.
---

Run these in order and stop at the first failure; report the failing output verbatim.

1. `composer lint`
2. `composer analyse`
3. `composer gates`
4. `composer test:coverage && php tools/coverage-floor.php tmp/coverage.xml`
5. `npm run check`
6. If `npx wp-env status 2>/dev/null | grep -q running`: `composer test:integration`, then `npm run plugin-check`, then `npx playwright test --project=chromium-desktop`.

Only report "verified" when every step that ran passed. If step 6 was skipped because wp-env is down, say so.
