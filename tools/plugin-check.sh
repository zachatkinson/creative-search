#!/usr/bin/env bash
# Runs Plugin Check on the plugin and fails on any error or warning. Plugin Check itself exits 0 when it finds some.
# plugin_updater_detected flags the `Update URI` header, a WordPress.org hosting rule. The plugin is installed from a
# zip and updates from the agency's releases (Updates\Updater, D-140); the header is what points core at them and stops
# WordPress.org offering a same-slug plugin over it. Every other error or warning fails.
set -euo pipefail
cd "$(dirname "$0")/.."
out="$(npx wp-env run cli -- wp plugin check creative-search --exclude-directories=tests,tools,node_modules,vendor,tmp,docs,test-results,playwright-report,.claude,.github --exclude-files=.wp-env.json,.wp-env.override.json,.gitignore,.distignore,.editorconfig,.nvmrc,CLAUDE.md,composer.json,composer.lock,package.json,package-lock.json,phpcs.xml,phpstan.neon,phpunit.xml.dist,phpunit-integration.xml.dist,infection.json5,infection-domain.json5,playwright.config.ts,vitest.config.mjs,eslint.config.js,.stylelintrc.json,.prettierrc.js --format=csv --fields=line,column,type,code,message 2>&1)" || { echo "$out"; exit 1; }
echo "$out"
if grep -E ',(ERROR|WARNING),' <<<"$out" | grep -qvE ',plugin_updater_detected,'; then
	echo "Plugin Check found problems." >&2
	exit 1
fi
echo "Plugin Check: no errors or warnings (apart from the Update URI header, allowed above)."
