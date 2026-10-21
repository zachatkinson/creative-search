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
exec npx wp-env run tests-cli --env-cwd=wp-content/plugins/creative-search -- php tools/integration/vendor/bin/phpunit -c phpunit-integration.xml.dist "$@"
