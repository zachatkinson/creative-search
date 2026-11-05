#!/usr/bin/env bash
# Runs the integration suite inside wp-env's tests container on PHPUnit 9.6, the newest version the WordPress
# core test suite supports (docs/DECISIONS.md D-021). The unit suite runs on PHPUnit 13 through the root
# composer.json; PHPUnit 9.6 and the Yoast polyfills live in their own Composer project under tools/integration/
# (lock committed, vendor gitignored) because Composer cannot install two versions of one package.
set -euo pipefail
cd "$(dirname "$0")/.."
if [ ! -f tools/integration/vendor/bin/phpunit ]; then
	composer install --working-dir=tools/integration --no-interaction --quiet
fi
# The matrix's switches pass into the container (D-083): CSEARCH_HPOS=on|off, CSEARCH_OBJECT_CACHE=persistent,
# CSEARCH_DB_HOST (another database server on wp-env's network), CSEARCH_BENCH_PRODUCTS and CSEARCH_BENCH_SNAPSHOT (D-148).
pass=()
for name in CSEARCH_HPOS CSEARCH_OBJECT_CACHE CSEARCH_BENCH_PRODUCTS CSEARCH_BENCH_SNAPSHOT; do
	if [ -n "${!name:-}" ]; then
		pass+=("$name=${!name}")
	fi
done
if [ -n "${CSEARCH_DB_HOST:-}" ]; then
	pass+=("WORDPRESS_DB_HOST=$CSEARCH_DB_HOST")
fi
exec npx wp-env run tests-cli --env-cwd=wp-content/plugins/creative-search -- env ${pass[@]+"${pass[@]}"} php tools/integration/vendor/bin/phpunit -c phpunit-integration.xml.dist "$@"
