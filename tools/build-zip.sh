#!/usr/bin/env bash
# Builds tmp/creative-search-<version>.zip without dev files, with a production vendor/ autoloader.
# (tmp/ is outside what Plugin Check scans; a zip inside the plugin folder would be flagged.)
set -euo pipefail
cd "$(dirname "$0")/.."
version="$(grep -E '^ \* Version:' creative-search.php | awk '{print $3}')"
stage="$(mktemp -d)/creative-search"
mkdir -p "$stage"
rsync -a --exclude-from=.distignore ./ "$stage/"
cp composer.json composer.lock "$stage/"
( cd "$stage" && composer install --no-dev --no-interaction --quiet --optimize-autoloader && rm -f composer.json composer.lock )
mkdir -p tmp
rm -f "tmp/creative-search-${version}.zip"
( cd "$(dirname "$stage")" && zip -qr "$OLDPWD/tmp/creative-search-${version}.zip" creative-search )
rm -rf "$(dirname "$stage")"
echo "tmp/creative-search-${version}.zip"
