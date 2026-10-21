#!/usr/bin/env bash
# Writes .wp-env.override.json for the oldest supported pair (docs/DECISIONS.md D-011):
# WordPress 6.8, WooCommerce 10.0, PHP 8.2. Run before `wp-env start`; delete the file to return to the pins.
set -euo pipefail
cd "$(dirname "$0")/.."
cat > .wp-env.override.json <<'JSON'
{
	"core": "https://wordpress.org/wordpress-6.8.11.zip",
	"phpVersion": "8.2",
	"mappings": {
		"wp-content/plugins/woocommerce": "https://downloads.wordpress.org/plugin/woocommerce.10.0.6.zip"
	}
}
JSON
echo ".wp-env.override.json written (WordPress 6.8.11, WooCommerce 10.0.6, PHP 8.2)."
