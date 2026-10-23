=== Creative Search ===
Contributors: creativebranding, zachatkinson
Tags: woocommerce, search, ajax search, product search, autocomplete
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.5
Stable tag: 1.2.0
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

= Which languages does it speak? =

English and French are built in: stop words, stemming (Porter and Snowball French) and accent folding, so `vetements` finds Vêtements and `roues` finds roue. The plugin's screens and the search bar ship in English and Canadian French (`fr_CA`). A shop with a French site adds its French product text through the `csearch_languages` filter (documented in the plugin's repository, docs/HOOKS.md); a French search that finds nothing falls back to the site language, because a brand name is the same in both.

= Is the search bar accessible? =

It is an ARIA combobox built to the WAI-ARIA Authoring Practices list-autocomplete pattern, checked with axe on every state in Chromium, Firefox and WebKit at desktop and phone widths, in English and in French. The manual pass with VoiceOver and NVDA by an independent tester has not been run yet; the script for it is in the plugin's repository (docs/A11Y.md).

= How does it compare with FiboSearch? =

The plugin's repository (docs/BENCHMARK.md) has a feature-by-feature comparison with FiboSearch free and Pro, built from FiboSearch's own documentation. A measured run against FiboSearch free on a generated catalogue is there too; it is interim until it is repeated on a real shop's catalogue.

= How do I see why a product ranks where it does? =

WooCommerce → Search → Tools → Test search, or `wp csearch explain "kazumi deck"`.

== Upgrade Notice ==

= 1.2.0 =
A search that names a category now opens it, and sizes can become your shop's filters (Tools tab). Turn the first off under WooCommerce → Search → Tools if you prefer the results page.

= 1.1.0 =
Sizes are now read one way however they are written, so the index is rebuilt once in the background after the update. Searches keep working meanwhile.

= 1.0.0 =
Updates the search index's tables in the background. Searches keep working during the update; the index is then rebuilt once. Large shops: allow a few minutes.

== Changelog ==

= 1.2.0 =
* A search that names a category, such as "wheels", opens that category, with a line to search every product instead (on by default; Tools tab).
* A size in a search, such as "deck 8.25", becomes WooCommerce's own filter for the attributes you choose on the Tools tab, so shoppers see it and can remove it.
* When two categories share the name searched, the results page links to both.
* Tests on a copy of your shop: products and pages exported from WooCommerce and WordPress load into the test site.

= 1.1.0 =
* Posts and pages can be searched (off by default; Tools tab), shown after the products in the suggestions and above them on the results page, with an "Exclude from search" box in the editor.
* Button mode: a search icon that opens the search in a dialog, full screen on a phone.
* The no-results page links to a help page and a second page of your choice, and offers the words of the query that find something.
* The arrow keys copy the suggestion into the field; suggestions mark what they add; the page dims behind them; the panel never scrolls.
* Sizes are read one way however they are written (8.25in, 8.25", 8.25 inch; 54 mm; 9x33).
* New hooks for themes and plugins (docs/HOOKS.md in the repository).

= 1.0.0 =
* Languages: French end to end, with the plugin translated into Canadian French; a French search falls back to the site language (Settings → General) when it finds nothing.
* Category and tag suggestions count their subcategories' products, as the shop does.
* The suggestion panel says "See 1 result" for one result, and Up from the field goes to the last suggestion.
* Documentation complete: hooks, settings, security, accessibility, benchmarks against FiboSearch, query plans and decisions.

= 0.7.0 =
* Admin: WooCommerce → Search, with Analytics (searches a day, top queries, searches with no results, most clicked, CSV export), Synonyms (add, edit, delete, test, CSV import and export), Boosts and exclusions, and Tools (index status, rebuild with progress, a test search that explains its ranking, stop words and settings).
* A Search tab on the product edit screen (boost, exclude, last indexed, reindex now), and the two values in WooCommerce's product CSV.
* Notices when the index is empty or missing products, when scheduled tasks are not running, and when a rebuild had errors.
* WP-CLI: `wp csearch search`, `synonyms`, `log purge` and `cache flush`.

= 0.6.0 =
* Results page: product searches come back in relevance order, with the result count, the corrected query and a helpful no-results page.
* Search block: suggestions with photos, prices and stock as the shopper types, by keyboard or touch, with a form that works without JavaScript; also as a shortcode, a widget or in place of the theme's search form.

= 0.4.0 =
* Index: products, categories, tags and brands written to the plugin's own index in the background whenever they change, with a full rebuild into a new generation that goes live when complete, and `wp csearch index`, `reindex`, `status` and `explain`.
* Query and API: suggestions and ranked results from four REST routes, cached, rate-limited, with an anonymous search log. Nothing a shopper sees yet.

= 0.2.0 =
* Domain: the tokenizer, the Porter and Snowball French stemmers, trigrams and edit distance, the query parser, the scorer, the searcher and the log scrubber as pure PHP, tested against an in-memory index. Nothing a shopper or owner sees yet.

= 0.1.0 =
* Foundation: plugin skeleton, the six index tables, settings and defaults, uninstall, the test harness and the CI gates. Nothing a shopper or owner sees yet.
