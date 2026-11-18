=== Creative Search ===
Contributors: creativebranding, zachatkinson
Tags: woocommerce, search, ajax search, product search, autocomplete
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.5
Stable tag: 1.7.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Search for WooCommerce: live suggestions, typo tolerance, SKU and attribute matching, its own index, analytics, an accessible search bar.

== Description ==

Creative Search keeps its own index of every product a shopper may see (names, SKUs and variation SKUs, descriptions, categories, tags, brands and attribute values) and answers a query from it in milliseconds: prefix matches as the shopper types, a typo or two forgiven, phrases above scattered words, names above descriptions, in-stock above sold-out, with the synonyms the owner sets.

The search bar is a real combobox (keyboard, screen reader and touch all work), the results page stays the shop's own template with relevance as the default sort, and an Analytics screen shows the owner what people search for and what finds nothing, with no personal data.

No external service and no API key; the only request the plugin makes is its own update check (see the FAQ). French and English stem and fold accents; the French site's product fields index alongside the English ones.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` and activate it (WooCommerce 10.0 or newer must be active).
2. Go to WooCommerce → Search → Tools and press Rebuild index (or run `wp csearch reindex`).
3. Add the Search block to the header, or let the plugin take over the theme's search form on the Tools tab.

== Frequently Asked Questions ==

= Does it send anything off the site? =

Nothing about the shop or its shoppers. The index, the suggestions and the analytics are all on the server. The one request is the update check: at most twice a day, when WordPress checks for plugin updates, the plugin reads its release notes from the agency's public releases on GitHub, sending no site address. Turn it off with `define( 'CSEARCH_DISABLE_UPDATES', true );` in wp-config.php. With "Analytics events" on (the default), the search bar also tells the page's own tag manager (its `dataLayer`, when the page has one) what was searched and chosen, with a search that looks like an email address, a phone number, a card number or a postal code sent as "[redacted]" instead; turn it off under WooCommerce → Search → Tools.

= What does it keep about shoppers? =

Nothing. The search log holds the words typed, the result count, the language, where the search came from and the time, for 90 days by default. Searches another plugin asks Creative Search to rank (such as a product builder) are logged the same way, under that plugin's source, when it names one. No IP address, user id, session or page URL; queries that look like personal details are redacted.

= Which languages does it speak? =

English and French are built in: stop words, stemming (Porter and Snowball French) and accent folding, so `vetements` finds Vêtements and `roues` finds roue. The plugin's screens and the search bar ship in English and Canadian French (`fr_CA`). A shop with a French site adds its French product text through the `csearch_languages` filter (documented in the plugin's repository, docs/HOOKS.md); a French search that finds nothing falls back to the site language, because a brand name is the same in both.

= Is the search bar accessible? =

It is an ARIA combobox built to the WAI-ARIA Authoring Practices list-autocomplete pattern, checked with axe on every state in Chromium, Firefox and WebKit at desktop and phone widths, in English and in French. The manual pass with VoiceOver and NVDA by an independent tester has not been run yet; the script for it is in the plugin's repository (docs/A11Y.md).

= How does it compare with FiboSearch? =

The plugin's repository (docs/BENCHMARK.md) has a feature-by-feature comparison with FiboSearch free and Pro, built from FiboSearch's own documentation. A measured run against FiboSearch free on a generated catalogue is there too; it is interim until it is repeated on a real shop's catalogue.

= How do I change the search bar's colours? =

Set any of these CSS custom properties, on `:root`, your header or the block (Appearance → Editor → Styles → Additional CSS, or your theme's stylesheet). Each falls back to the block's own look when unset:

`--csearch-field-bg` and `--csearch-field-fg` (the field), `--csearch-panel-bg` and `--csearch-panel-fg` (the suggestions), `--csearch-submit-bg` and `--csearch-submit-fg` (the search button), and `--csearch-focus-ring` (the focus outline).

For example: `.site-header { --csearch-field-bg: #111; --csearch-field-fg: #fff; --csearch-focus-ring: #f5c400; }`

= How do I see why a product ranks where it does? =

WooCommerce → Search → Tools → Test search, or `wp csearch explain "kazumi deck"`.

== Upgrade Notice ==

= 1.7.1 =
For developers: `csearch_ranked_ids` accepts product ids and its limit as numeric strings, as `$wpdb->get_col()` returns them. No index rebuild is needed.

= 1.7.0 =
Other plugins can ask for Creative Search's ranking from PHP, kept to their own products, and their searches are logged under their own source, apart from the shop's numbers. No index rebuild is needed.

= 1.6.6 =
A number becomes a size only when the products that really match the search have sizes, not products that only mention its words in their descriptions; French spacing fixed before colons and inside « ». No index rebuild is needed.

= 1.6.5 =
A size with nothing in stock where a search lands is no longer applied: the page and the suggestions say so, with the closest sizes in stock; a number becomes a size only for products that have sizes. No index rebuild is needed.

= 1.6.4 =
"See all" leaves out products whose size is sold out when the shop hides sold-out products, as the filtered page does; a misspelling no longer suggests a brand or category that only mentions a near spelling in its description. No index rebuild is needed.

= 1.6.3 =
"See all" counts what its page lists when a size becomes a filter; a misspelling finds what the word finds; the "in a category" row follows the products that match by name; no false "not tested" warning on WordPress maintenance releases. No index rebuild is needed.

= 1.6.2 =
With "Show products that can be bought first" on, products named for the search stay ahead of products that only mention it; the page a search names gets its row; updates are checked even when the release changed since WordPress last looked. No index rebuild is needed.

= 1.6.1 =
Updates now arrive through Dashboard → Updates like any other plugin's. Install this version by hand once; no index rebuild is needed.

= 1.6.0 =
A shorter suggestion panel, pages suggested by title, an optional in-stock-first order, and analytics that leave out staff and test runs. No index rebuild is needed.

= 1.5.3 =
The plugin's description only.

= 1.5.2 =
The search field's clear button no longer turns into a dark block on hover in themes that style every button's hover, such as Kadence.

= 1.5.1 =
Product and category names stored with HTML entities (such as Philly&#8217;s) are now searched and shown as the text they display, so the index is rebuilt once in the background after the update. Searches keep working meanwhile.

= 1.5.0 =
On a category page the search box no longer has an "Only in" box: it searches the whole shop, and its suggestions offer searching within that category first.

= 1.4.0 =
The search field holds its own look inside a theme's header, and its suggestions keep to the screen in a sticky header and above a phone's keyboard. No settings change; to stop the page dimming behind the suggestions, untick it on the block.

= 1.3.0 =
Adds a table for each product's variations and SKUs, so the index is rebuilt once in the background after the update. Searches keep working meanwhile; the variation photos and "Matches" lines appear once it is done.

= 1.2.0 =
A search that names a category now opens it, and sizes can become your shop's filters (Tools tab). Turn the first off under WooCommerce → Search → Tools if you prefer the results page.

= 1.1.0 =
Sizes are now read one way however they are written, so the index is rebuilt once in the background after the update. Searches keep working meanwhile.

= 1.0.0 =
Updates the search index's tables in the background. Searches keep working during the update; the index is then rebuilt once. Large shops: allow a few minutes.

== Changelog ==

= 1.7.1 =
* For developers: `csearch_ranked_ids` reads product ids and the limit given as numeric strings ("42") as the integers they spell, so ids passed straight from a database read no longer give an empty answer. Signs, decimals, spaces and other values are still ignored.

= 1.7.0 =
* For developers: `csearch_ranked_ids` gives another plugin the ranked product ids for a query on the server, as the results page ranks them but without your pinned products, kept to a list of products when it passes one.
* Searches another plugin asks for are logged under their own source (a product builder, a source it registers, or other plugins). Analytics lists every source in its totals and can show one on its own; the daily chart, top queries and suggested searches count only your shop's own searches.

= 1.6.6 =
* A number becomes a size filter only when the products that match by more than their description have that attribute: "9 hoodie" opens the results for the words, not hoodies filtered by a deck width because a few decks mention a hoodie.
* A product hidden from the catalogue or from search no longer makes a number filter a page that can't list it, and a number that is part of a product's name ("cloud 9") is searched as a word when that product doesn't have the size.
* French (Canada): a no-break space before colons and inside « » in nine strings, and a check that keeps it so.

= 1.6.5 =
* A size or other value in a search that has nothing in stock where the search lands is no longer applied: "9 deck" opens Decks and says "Nothing in Width 9.00″ is in stock right now.", with links to the closest widths in stock. "See all" counts that page, and Analytics still counts the search among those that found nothing.
* The suggestions say the same, outside the list and in what screen readers hear; when the page applies a size, they suggest only products that page lists; and "Matches Width: 9.00″" shows only on a product that has 9.00″ in stock.
* A number becomes a size only when the products the search lands on have sizes at all: "9 hoodie" opens the hoodies, not an empty page.

= 1.6.4 =
* "See all" in the suggestions counts what the filtered page lists when the shop hides sold-out products: a product whose variation in the size searched is sold out is no longer counted ("8.25 deck" on Decks in 8.25″).
* A misspelling no longer suggests a category, tag or brand that only mentions a near spelling in its description.

= 1.6.3 =
* "See all" in the suggestions counts what the page it opens lists: when a size in the search becomes the shop's filter ("8.25 deck" opens Decks in 8.25″), the number is that page's.
* A misspelling finds what the word it is read as finds: "hody", read as "hoody", finds every hoodie and sweater. Other near spellings found only in a description come last, and for words of four letters or fewer don't count there.
* When the suggestions show a page named for the search, products that only mention its words in their description stay out of the suggestions.
* The "in a category" suggestion is chosen from the products that match by more than their description, and the category row stays ahead of one built only on description matches when space is short.
* The update details window no longer warns that the plugin hasn't been tested with a maintenance release of the WordPress version it was tested with.

= 1.6.2 =
* "Show products that can be bought first" now applies among products that match as well: products found by their name, SKU, attributes, brand or category come before products that only mention the search in their description, each group with what can be bought first.
* A page whose title holds every word searched gets a row in the suggestions, after the products named for the search and before those that only mention it, on a phone too.
* An update is installed only when it matches the published release: the release notes are read again when they have changed since WordPress last checked, and an update that still doesn't match is refused with a request to check again.

= 1.6.1 =
* Updates through wp-admin: the plugin checks the agency's releases on GitHub and installs a new version only when its checksum matches. `CSEARCH_DISABLE_UPDATES` turns the check off.

= 1.6.0 =
* The suggestions have one length: at most 8 rows on a computer and 5 on a phone by default (Tools → Suggestions), with a suggested search, the first products and a category before anything else.
* Pages are suggested by their title only, and not at all on a phone unless you allow it.
* New "Show products that can be bought first" setting (Boosts and exclusions): sold-out products after the ones in stock or on backorder.
* Analytics leave out your staff's searches and test runs (the `X-Creative-Test: 1` header or the `csearch_log_search` filter), and can be cleared on launch day.
* A Settings link on the Plugins screen.

= 1.5.3 =
* A short description on the Plugins screen, under the 140 characters the Plugin Handbook asks for.

= 1.5.2 =
* The search block's buttons (clear, submit, and Button mode's open and close) keep their own look on hover and focus in themes that style every button, such as Kadence; the clear button no longer turns into a dark block.

= 1.5.1 =
* Names stored with HTML entities, such as an imported "Philly&#8217;s Hoodie" or a "Rock &amp; Roll" category, are found by every spelling of the apostrophe and shown correctly in the suggestions. The index is rebuilt once after the update.

= 1.5.0 =
* On a category page the search box searches the whole shop, and its suggestions offer "kazumi in Decks" first; the "Only in Decks" box is gone.
* Faster ranking on large catalogues.

= 1.4.0 =
* The search field keeps its own look inside a theme's header: no second box from the theme's input styles, one focus ring, and no zoom on iPhone.
* In a sticky header, and on phones, the suggestions keep to the screen above the keyboard; on a very short screen they scroll inside themselves.
* "Dim the page behind the suggestions" can be switched off on each block, and the dimming covers the page inside sticky headers that slide away.
* Colour the field, the suggestions, the search button and the focus ring with seven CSS custom properties (see the FAQ).

= 1.3.0 =
* A search that names one design, colour or size of a product shows that variation's photo and opens the product with it chosen.
* A result found by its SKU, an attribute or its description says so: "Matches SKU KZ-825".
* On a category page the search box offers "Only in Decks", and the panel suggests searching within the categories most results are in.
* The panel suggests searches other shoppers made that found products, says when it corrected a typo, and can add a simple product to the cart (off by default).
* Sorting by price, popularity, rating or date lists only the products that match every word.
* Redirect a search to a page, pin products to the top of a search, and synonyms of several words ("ss" means "stainless steel"): the new Redirects and pins tab.
* Search your products' custom fields, ACF's included (Tools tab).
* Search events for Google Tag Manager, in the page only.

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
