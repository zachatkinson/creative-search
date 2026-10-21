# Security checklist

Walked before each tag, by someone other than the author of the milestone. OWASP's top ten mapped to a WordPress plugin, plus the spec's own rules. Each line is ticked with the tag it was walked for and a word on how.

## Rules the code is held to (spec: Security, performance and compatibility)

1. The REST routes are the plugin's only public surface, and they read. `POST /click` writes one log column from a nonce-free beacon and nothing else.
2. Every input is typed by the route schema and sanitised at the edge: `sanitize_text_field`, length caps (200 characters, 8 tokens), allow-lists for `lang`, `orderby`, `type`, `source`.
3. Every SQL statement is `$wpdb->prepare()`d, identifier lists built from integers only. CI: PHPStan, PHPCS `WordPress.DB`, `tools/gates.sh`.
4. Every admin action checks a nonce and `manage_woocommerce`. WP-CLI commands trust the shell.
5. Output is escaped at the sink: `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` for price HTML.
6. No user data is serialised; no PHP object is stored anywhere; `unserialize` is banned (gate 2).
7. The log never stores identity: no IP, user id, session, user agent or page URL; queries that look like an email, phone, card or postal code are logged as `[redacted]`.
8. Rate limits on `/suggest` (30 per 10 s) and `/search` (20 per 10 s) per client, counters in the object cache only.
9. No file is written; no remote request is made, ever (gate 5).
10. `uninstall.php` removes every table, option, transient, meta, scheduled action and cache entry.

## Walk, per tag

| # | OWASP | Check | v0.1.0 |
| --- | --- | --- | --- |
| A01 | Broken access control | Every `admin_post_`/AJAX/REST handler has a capability check; REST routes have `permission_callback`; `/status` requires `manage_woocommerce`. | n/a (no handlers yet); the container's hookable list is empty |
| A02 | Cryptographic failures | Nothing secret is stored; rate-limit keys hash the IP. | n/a |
| A03 | Injection | `composer gates` clean; PHPCS `WordPress.DB` clean; no `%i`-less identifiers; HTML escaped at the sink. | ✅ gates and PHPCS clean |
| A04 | Insecure design | The index holds only what a shopper may see; hidden products need the setting and an exact SKU. | ✅ `visible` column and `hidden_by_sku` default `no` |
| A05 | Security misconfiguration | `WP_DEBUG` output never reaches a response; errors are `WP_Error` with `csearch_` codes. | n/a |
| A06 | Vulnerable components | `composer audit` and `npm audit` clean; no runtime dependencies. | ✅ `composer audit`: no advisories, runtime `require` is PHP only; `npm audit --omit=dev`: 0; dev-only advisories in `@wordpress/scripts`' tree noted in docs/reviews/M1.md |
| A07 | Identification failures | No login surface; nothing per user. | ✅ |
| A08 | Integrity failures | No remote code, no remote calls, no `eval` (gates 2 and 5). | ✅ |
| A09 | Logging failures | The search log is the plugin's only log, anonymous by design; failures go to `error_log` through `Support\Logger` (milestone 3). | n/a |
| A10 | SSRF | No outbound requests (gate 5). | ✅ |
| — | Uninstall | `UninstallTest::test_uninstall_leaves_nothing` green. | ✅ |
| — | Plugin Check | `wp plugin check creative-search` zero errors and warnings. | ✅ "Checks complete. No errors found." |
