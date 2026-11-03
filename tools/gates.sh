#!/usr/bin/env bash
# The spec's grep gates (Quality bar → Security, Anti-patterns). Fails on:
#   1. SQL built from strings outside the repositories, the schema and the uninstaller.
#   2. Banned functions anywhere under src/.
#   3. date() without a timezone (gmdate() and wp_date() are the replacements).
# Run: composer gates
set -euo pipefail
cd "$(dirname "$0")/.."
status=0

# 1. $wpdb calls outside src/Repository/ and src/Install/ (the only places direct SQL may live).
hits="$(grep -rnE '\$wpdb->(query|get_results|get_row|get_var|get_col|insert|update|delete|replace)\(' src --include='*.php' | grep -vE '^src/(Repository|Install)/' || true)"
if [ -n "$hits" ]; then
	echo "Gate 1: direct SQL outside src/Repository/ and src/Install/:"; echo "$hits"; status=1
fi

# 1b. Interpolated or concatenated SQL handed to $wpdb without prepare(): a string literal with a variable or a
# concatenation inside the call. Identifier lists built from integers are allowed through implode() only.
# The gate only sees SQL built inside the $wpdb call; a statement assembled in a variable first passes here, so
# Repository::run()/rows()/value() take only prepare()d strings and PHPCS and the review carry the rule.
hits="$(grep -rnE '\$wpdb->(query|get_results|get_row|get_var|get_col)\(\s*("[^"]*\$|'"'"'[^'"'"']*'"'"'\s*\.)' src --include='*.php' | grep -v 'prepare(' || true)"
if [ -n "$hits" ]; then
	echo "Gate 1b: string-built SQL (use \$wpdb->prepare()):"; echo "$hits"; status=1
fi

# 2. Banned functions.
hits="$(grep -rnE '(^|[^a-zA-Z_>])(eval|extract|unserialize|maybe_unserialize|query_posts|create_function|base64_decode|exec|shell_exec|system|passthru|popen|proc_open)\s*\(' src creative-search.php uninstall.php --include='*.php' || true)"
if [ -n "$hits" ]; then
	echo "Gate 2: banned function:"; echo "$hits"; status=1
fi

# 3. date() without a timezone.
hits="$(grep -rnE '(^|[^a-zA-Z_>])date\s*\(' src creative-search.php uninstall.php --include='*.php' | grep -vE '^[^:]+:[0-9]+:\s*(\*|//|#)' || true)"
if [ -n "$hits" ]; then
	echo "Gate 3: date() has no timezone; use gmdate() or wp_date():"; echo "$hits"; status=1
fi

# 4. declare(strict_types=1) in every PHP file the plugin ships.
missing="$(grep -rL 'declare( strict_types=1 );' src creative-search.php uninstall.php --include='*.php' | grep -v '/index.php$' || true)"
if [ -n "$missing" ]; then
	echo "Gate 4: missing declare( strict_types=1 ):"; echo "$missing"; status=1
fi

# 5. No remote requests but one: the update manifest, read by Updates\HttpManifestSource (D-140). The package itself is
#    downloaded by WordPress's upgrader (download_url() through Updates\Updater's injected downloader).
hits="$(grep -rnE 'wp_(safe_)?remote_(get|post|request|head)|curl_init|fsockopen|download_url\(|file_get_contents\(\s*["'"'"']https?:' src --include='*.php' \
	| grep -vE '^src/Updates/HttpManifestSource\.php:[0-9]+:.*wp_safe_remote_get\(|^src/Updates/Updater\.php:[0-9]+:.*return download_url\(' || true)"
if [ -n "$hits" ]; then
	echo "Gate 5: outbound request:"; echo "$hits"; status=1
fi

if [ "$status" -eq 0 ]; then echo "Gates: clean."; fi
exit "$status"
