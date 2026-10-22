# Security checklist

Walked before each tag, by someone other than the author of the milestone. OWASP's top ten mapped to a WordPress plugin, plus the spec's own rules. Each line is ticked with the tag it was walked for and a word on how.

## Rules the code is held to (spec: Security, performance and compatibility)

1. The REST routes are the plugin's only public surface, and they read. `POST /click` writes one log column from a nonce-free beacon and nothing else.
2. Every input is typed by the route schema and cleaned at the edge by the same function the results page uses (`SearchService::clean()`: control characters removed, 200 characters; the parser caps 8 tokens), with allow-lists for `lang`, `orderby`, `type`; the query is only ever output escaped.
3. Every SQL statement is `$wpdb->prepare()`d, identifier lists built from integers only. CI: PHPStan, PHPCS `WordPress.DB`, `tools/gates.sh`.
4. Every admin action checks a nonce and `manage_woocommerce`. WP-CLI commands trust the shell.
5. Output is escaped at the sink: `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` for price HTML.
6. No user data is serialised; no PHP object is stored anywhere; `unserialize` is banned (gate 2).
7. The log never stores identity: no IP, user id, session, user agent or page URL; queries that look like an email, phone, card or postal code are logged as `[redacted]`.
8. Rate limits on `/suggest` (30 per 10 s) and `/search` (20 per 10 s) per client, counters in the object cache only.
9. No file is written; no remote request is made, ever (gate 5).
10. `uninstall.php` removes every table, option, transient, meta, scheduled action and cache entry.

## Walk, per tag

| # | OWASP | Check | v0.1.0 | v0.6.0 |
| --- | --- | --- | --- | --- |
| A01 | Broken access control | Every `admin_post_`/AJAX/REST handler has a capability check; REST routes have `permission_callback`; `/status` requires `manage_woocommerce`. | n/a (no handlers yet); the container's hookable list is empty | ✅ `/status` `permission_callback` `manage_woocommerce` (401 and 403 tested); `/suggest`, `/search`, `/click` public by design; no admin handlers yet (milestone 7) |
| A02 | Cryptographic failures | Nothing secret is stored; rate-limit keys hash the IP. | n/a | ✅ rate-limit keys are a salted SHA-256 of the IP or of a WooCommerce session cookie whose HMAC is verified (forged cookies tested) |
| A03 | Injection | `composer gates` clean; PHPCS `WordPress.DB` clean; no `%i`-less identifiers; HTML escaped at the sink. | ✅ gates and PHPCS clean | ✅ gates, PHPCS and PHPStan clean; every repository statement prepared; the results page adds `ID IN (…)` from integers only; `ResultsView` escapes every argument, placeholders escaped (tested with a script tag) |
| A04 | Insecure design | The index holds only what a shopper may see; hidden products need the setting and an exact SKU. | ✅ `visible` column and `hidden_by_sku` default `no` | ✅ hidden products only for an exact SKU with `hidden_by_sku` on, on the API and the results page (tested); the log is redacted, including emails and phone numbers still being typed |
| A05 | Security misconfiguration | `WP_DEBUG` output never reaches a response; errors are `WP_Error` with `csearch_` codes. | n/a | ✅ errors are `WP_Error` with `csearch_` codes; 429 carries `Retry-After` |
| A06 | Vulnerable components | `composer audit` and `npm audit` clean; no runtime dependencies. | ✅ `composer audit`: no advisories, runtime `require` is PHP only; `npm audit --omit=dev`: 0; dev-only advisories in `@wordpress/scripts`' tree noted in docs/reviews/M1.md | ✅ `composer audit`: no advisories; `npm audit --omit=dev`: 0; no runtime dependency added; the block's packages are WordPress's own, external at build |
| A07 | Identification failures | No login surface; nothing per user. | ✅ | ✅ no login surface |
| A08 | Integrity failures | No remote code, no remote calls, no `eval` (gates 2 and 5). | ✅ | ✅ no remote code or calls (gates 2, 5); the block's price HTML comes from the plugin's own same-origin route |
| A09 | Logging failures | The search log is the plugin's only log, anonymous by design; failures go to `error_log` through `Support\Logger` (milestone 3). | n/a | ✅ the search log keeps no identity (columns tested); job failures recorded in the index state |
| A10 | SSRF | No outbound requests (gate 5). | ✅ | ✅ gate 5 clean |
| — | Uninstall | `UninstallTest::test_uninstall_leaves_nothing` green. | ✅ |  |
| — | Plugin Check | `wp plugin check creative-search` zero errors and warnings. | ✅ "Checks complete. No errors found." |  |


The v0.6.0 column covers milestones 3 to 6 (v0.4.0 was tagged without its walk; this one includes it). Walked by the author with two independent spec reviews (docs/reviews/M3.md, docs/reviews/M5.md), not by a second person: Zach's review is the second walk.
