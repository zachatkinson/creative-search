#!/usr/bin/env bash
# Runs the integration suite inside wp-env's tests container on PHPUnit 9.6, the newest version the WordPress
# core test suite supports (docs/DECISIONS.md D-021). The unit suite stays on the spec's PHPUnit 11 through
# Composer. The phar is pinned by version and checksum and cached in tools/bin/ (gitignored).
set -euo pipefail
cd "$(dirname "$0")/.."
version="9.6.38"
sha256="6b5235f282c6ccab3674ac27c5b1ddbd1da838e74bf07002ddcfd030d31298d7"
phar="tools/bin/phpunit-9.phar"
mkdir -p tools/bin
if [ ! -f "$phar" ] || ! printf '%s  %s\n' "$sha256" "$phar" | shasum -a 256 -c --status; then
	curl -sSL -o "$phar" "https://phar.phpunit.de/phpunit-${version}.phar"
	printf '%s  %s\n' "$sha256" "$phar" | shasum -a 256 -c --status || { echo "phpunit phar checksum mismatch" >&2; exit 1; }
fi
exec npx wp-env run tests-cli --env-cwd=wp-content/plugins/creative-search -- php tools/bin/phpunit-9.phar -c phpunit-integration.xml.dist "$@"
