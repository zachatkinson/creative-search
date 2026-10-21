=== Creative Search ===
Contributors: creativebranding, zachatkinson
Tags: woocommerce, search, ajax search, product search, autocomplete
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.5
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Search for WooCommerce: live suggestions, typo tolerance, SKU and attribute matching, an index of its own, analytics, an accessible search bar.

== Description ==

Creative Search keeps its own index of every product a shopper may see (names, SKUs and variation SKUs, descriptions, categories, tags, brands and attribute values) and answers a query from it in milliseconds: prefix matches as the shopper types, a typo or two forgiven, phrases above scattered words, names above descriptions, in-stock above sold-out, with the synonyms the owner sets.

The search bar is a real combobox (keyboard, screen reader and touch all work), the results page stays the shop's own template with relevance as the default sort, and an Analytics screen shows the owner what people search for and what finds nothing, with no personal data.

No external service, no API key, no outbound requests. French and English stem and fold accents; the French site's product fields index alongside the English ones.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` and activate it (WooCommerce 10.0 or newer must be active).
2. Go to WooCommerce → Search → Tools and press Rebuild index (or run `wp csearch reindex`).
3. Add the Search block to the header, or let the plugin take over the theme's search form on the Tools tab.

== Frequently Asked Questions ==

= Does it send anything off the site? =

No. The index, the suggestions and the analytics are all on the server; nothing leaves it.

= What does it keep about shoppers? =

Nothing. The search log holds the words typed, the result count, the language and the time, for 90 days by default. No IP address, user id, session or page URL; queries that look like personal details are redacted.

== Changelog ==

= 0.1.0 =
* Foundation: plugin skeleton, the six index tables, settings and defaults, uninstall, the test harness and the CI gates. Nothing a shopper or owner sees yet.
