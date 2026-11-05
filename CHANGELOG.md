# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html). One entry per milestone; `v1.0.0` closes milestone 8.

## [Unreleased]

### Changed
- CI spends fewer minutes: a version tag publishes once CI has passed on the commit's push to main instead of running it again, the 10,000-product catalogue is cached between runs (the reindex and every budget still run), the oldest-versions leg runs two browser projects, Infection on a pull request mutates only its changed lines, and changes to the docs alone run nothing (D-148).

### Fixed
- The update details window no longer warns that the plugin hasn't been tested with a maintenance release of the WordPress version it was tested with ("Tested up to: 7.1" on 7.1.4) (D-149).
- A misspelling finds what the word it is read as finds: "hody", read as "hoody", finds every hoodie and sweater through the word's stem and the owner's synonyms (D-146).
- Other typo candidates found only in a description rank after every other product, and for words of four letters or fewer don't count there at all; when the panel shows a page named for the search, products that only mention its words in their description stay out of the panel (D-146).
- The panel's "in a category" row is chosen from the products that match by more than their description: "beanie" no longer offers "beanie in Decks" because five decks mention a beanie, and when the cap cuts rows a category row stays ahead of a scope built only on description matches (D-147).
- "See all" in the panel counts what the page it opens lists: when a size in the query becomes the shop's filter ("8.25 deck" opens Decks in 8.25″), the count is the filtered page's, not every product the words find (D-145).

## [1.6.2] - 2026-11-03

Names before descriptions, the page asked for, and only checked packages installed, from the 3 November check of 1.6.1 on the demo.

### Changed
- `in_stock_first` now orders among products that match alike: products every searched word reaches outside the description first, then products some word reaches only in the short or long description; in each group what can be bought first, each part in order of relevance. Pinned products keep their place (D-142).
- The page the shopper asked for (the panel's best page, its title holding every word searched) gets a row after the first products named for the search and before products found only by their description, also on a phone whatever `suggest_pages_phone` says. The other pages keep their place at the end (D-143).
- Each product in `/suggest` carries `described` (D-142, D-143).

### Fixed
- An update package the cached release manifest doesn't name is no longer installed unchecked: the manifest is read again once, and the package is installed only when it is named there and its SHA-256 matches; otherwise `csearch_package_unknown` asks to check for updates again (D-144).

## [1.6.1] - 2026-11-03

Updates through wp-admin, from the agency's releases.

### Added
- The plugin checks `zachatkinson/creative-releases` for a new version when WordPress checks for plugin updates (at most every 12 hours), shows its changelog in the details popup, and installs it only when the zip's SHA-256 matches the manifest. The `CSEARCH_DISABLE_UPDATES` constant and the `csearch_updates_enabled` filter turn it off (D-140).
- A version tag publishes the release: every CI job on the tagged commit, then the zip and its manifest to creative-releases (D-140).

### Changed
- Uninstall also deletes the cached update manifest.
- The browser tests send `X-Creative-Test: 1` to the shop on every request, so a run against a real shop stays out of its analytics (D-141).

## [1.6.0] - 2026-11-03

A shorter panel, in stock first as the store's choice, and analytics without test traffic, from the 3 November UX review against Baymard's research.

### Added
- One cap on the whole suggestion panel: `panel_max` (8 rows, 4 to 12) on a desktop and `panel_max_phone` (5, 3 to 8) on a phone, on the Tools tab under Suggestions; a block or shortcode can change both. The panel fills in this order until the cap: one suggested search, three products, one category or "in a category" row, the other products, and pages last. "See all" is not counted and always shows (D-136).
- `suggest_pages_phone` (off): posts and pages stay out of the panel on a phone, where its rows go to products (D-137).
- `in_stock_first` (off), on the Boosts and exclusions tab: products that can be bought, backorders included, come before sold-out ones in the panel, on the results page and in `/search`, each part in order of relevance; pinned products keep their place (D-138).
- `log_staff_searches` (off): searches and suggestion clicks by signed-in users who can edit products are not logged or counted (D-139).
- The `csearch_log_search` filter, and the `X-Creative-Test: 1` request header, which keep a search out of the log (D-139).
- Clear analytics on the Analytics tab, after a confirmation that says what goes, and `wp csearch analytics clear` (D-139).
- A Settings link for the plugin on the Plugins screen.

### Changed
- The panel suggests a post or page only when the query matches its title; the results page still matches their content too (D-137).
- The panel's row budget (D-092) is replaced by the cap: a phone's products are bounded by its cap, not a fixed four (D-136).

## [1.5.3] - 2026-10-28

The plugin's description only.

### Changed
- A short description on the Plugins screen, under the 140 characters the Plugin Handbook asks for: the header's Description and readme.txt's short description are now the same 137-character sentence, with its Canadian French translation, and a unit test fails when the header's Description reaches 140 characters (D-135).

## [1.5.2] - 2026-10-27

The search block's buttons keep their own look inside a theme's styles.

### Fixed
- In a theme that colours every `button:hover`, `:focus` and `:active` (Kadence), hovering the field's clear button (the X) once something was typed turned it into a dark block: the theme's dark background with, in the Skatehouse header, a dark X. The clear and submit buttons, and Button mode's open and close buttons, now keep their own colours, no background (the submit its `--csearch-submit-*` colours) and no shadow in every state. A site's own rules for them, such as `.site-header .csearch-clear:hover`, still win; the focus ring is unchanged (D-134).

## [1.5.1] - 2026-10-27

HTML entities in indexed text and in the query.

### Fixed
- A name, attribute value, category, tag or brand stored with HTML entities (`Philly&#8217;s Hoodie` from an import or a feed, `Rock &amp; Roll` as WordPress saves a term name) is indexed as the text it shows: "phillys", "Philly's" and "Philly’s" find it by its name as they find a name written with a real apostrophe, and "8217" or "amp" are no longer words in the index. A query is decoded the same way. Text encoded twice reads as text encoded once (D-133).
- The panel showed such names with their entities (`Rock &amp; Roll` as a category row and a scope row); suggestion titles, "Matches" lines and scope rows are now text, and the results page escapes them once (D-133).

### Fixed before release (build)
- The zip keeps `composer.json` beside `vendor/` (Composer's autoloader only), so Plugin Check run on the installed zip no longer warns `missing_composer_json_file`.

### Upgrade
- The tokenizer reads text differently (`Tokenizer::VERSION` 3), so the index is rebuilt once in the background after the update, as after 1.1.0. Searches keep working meanwhile. Ranking is otherwise unchanged.

## [1.5.0] - 2026-10-23

Search within a category without being kept in it, and a faster ranking.

### Changed
- On a category page, and on a search kept to one, the search form no longer shows 1.3's ticked "Only in Decks" box: the form searches the whole shop, and its panel offers "kazumi in Decks" first among the category rows whenever that category holds a match. The row leads to the search kept to the category, as before. `/suggest` takes `here`; `cat` still works (D-130, superseding D-117's box).

### Performance
- The ranking starts from the query's rarest word and reads the index once: at 10,000 products, the cold suggestions' p95 falls from 52.5 ms to 27.3 ms and the results page's from 54 ms to 23.4 ms, with the worst query from 148.5 ms to 27.7 ms; the ranking is unchanged (D-132).

### Security
- Development only (nothing in the zip changes): patched versions of `qs`, `serialize-javascript`, `postcss-selector-parser`, `js-yaml`, `smol-toml` and `katex` are forced under the WordPress build tools through npm `overrides`; the built assets are byte-identical (D-131).

### Fixed before release (tests and CI)
- The SQL builders' mutation score is complete (D-088): new tests for the facts table, adjacent and two-phrase queries in the ranking parity test, an empty product count, and the equivalent mutants ignored line by line with their reasons. The new ranking plan is held by a test that names which word leads and when (D-132).
- The 10,000-product budget job, red on the v1.4.0 tag and on D-088's pull request by a few milliseconds, now has room (D-132).

## [1.4.0] - 2026-10-23

Field mode in a sticky site header (the Skatehouse header moving from Button mode to an always-visible field).

### Added
- Colour hooks: `--csearch-field-bg`, `--csearch-field-fg`, `--csearch-panel-bg`, `--csearch-panel-fg`, `--csearch-submit-bg`, `--csearch-submit-fg` and `--csearch-focus-ring`, set anywhere (`:root`, a header, the block); with none set the block looks as before (D-128).
- The block's "Dim the page behind the suggestions" toggle (`dimPage`, on by default; `dim_page="no"` on the shortcode). The `csearch_dim_page` filter still has the last word (D-127).

### Changed
- In a fixed or sticky header, or on a touch screen, the suggestions are capped to the room left in the visible viewport, following the keyboard as it opens and closes. As on a desktop, the last rows are dropped until the rest fit; only when two suggestions and "See all" do not fit does the list scroll inside itself, and then arrowing moves the list, not the page. On `auto`, the panel opens above the field only when there is more room above than below. A desktop page that scrolls keeps the natural height with no inner scrollbar (D-126, refining D-092).

### Fixed
- Theme input styles (Kadence's background, border, radius, padding and focus shadow, a site's own `input:focus` outline) no longer draw a second box inside the field or stack focus rings: in Field mode the field shows the one focus indicator, and on a touch screen the input is at least 16px so iOS does not zoom (D-125).
- Inside a header with a transform (Kadence's sticky header), the dimmed page covered only the header; it now covers the viewport, and a click on it still closes the suggestions (D-127).

### Fixed before release (tests and CI)
- The browser tests log in through one helper that waits for wp-login.php's own focus and checks both fields before it submits; on a slow runner the password had landed in the username field (the 1.2 merge's failed CI run).
- The 10,000-product budgets keep the fastest of three cold timings per query, so a shared runner's noise no longer fails identical code (D-129).
- New browser tests on Kadence's own sticky header (desktop and phone) and on a fixture header with clashing theme input styles.

## [1.3.0] - 2026-10-22

The rest of docs/research: Baymard's remaining gaps and the competitor scan's, as planned in DECISIONS (Zach on the Skatehouse handoff).

### Added
- A query that names one design, colour or size of a variable product (by an attribute value only some of its variations carry, or by a variation's whole SKU) shows that variation's own photo in the panel and on the results page, and opens the product with it chosen (`?attribute_design=Fire`). Each product's SKUs and attribute values are kept beside its document in a new `facts` table (D-104).
- "Why this matched": a suggestion row and a results-page card whose name does not show the matched words say what did, "Matches SKU MUM-FUR-ICE", "Matches Design: Fire" or "Matches the description"; nothing for a name, category, tag or brand match. Worked out for the shown products only and cached (D-105). The `/suggest` answer's products carry `matched`.
- Documents carry `cart`: a simple product that is purchasable and in stock, for the panel's add to cart (D-106).
- Search within the current category (Baymard): on a category page, and on a search already kept to one, the search form offers "Only in Decks", ticked. The search then sends `product_cat=decks`, the suggestions and the results keep to Decks and its subcategories (ranked within them, not cut from the shop's ranking), and the results page reads "3 results for “kazumi” in Decks" with a link to search every product. Such a search is never sent to another category; a size in it becomes the filter in the same category. `/suggest` takes `cat` (D-117).
- Custom fields, ACF's among them: product meta keys ticked or typed under Tools → What to index (`custom_fields`, none by default) are searched as their own field, weight 4. Lists are flattened; ACF choices add their labels, post and taxonomy fields their titles and names; a product's custom text is capped at 2,000 characters; a change to one of those fields reindexes the product (D-119, D-120).
- Suggested searches in the panel: up to three past searches that start with what is typed, each searched at least three times, found something and every word still in the catalogue (two on a phone). Setting `suggest_queries` (on), filter `csearch_suggested_searches` (D-112).
- Category scopes in the panel: "kazumi in Decks", up to two categories holding most of the first ten products, each the search within that category (`product_cat`). Setting `suggest_scopes` (on) (D-113).
- Add to cart from the panel for products that need no choice: a pointer "Add" on the row and Shift+Enter from the keyboard, through WooCommerce's own `wc-ajax=add_to_cart`. Setting `suggest_cart` (off) (D-115).
- Analytics events: `csearch:search`, `csearch:select`, `csearch:no-results` and `csearch:add-to-cart` on the form, and the GA4 `search`, `select_item`, `select_content`, `add_to_cart` and `csearch_no_results` on an existing `dataLayer`, with a search that looks like personal details sent as `[redacted]`, and no-results once per submitted search. Setting `analytics_events` (on) (D-116).
- The suggest answer carries `queries`, `scopes`, and each product's `price` and `cart`.
- A "Suggestions" section on the Tools tab for the four settings.
- Query redirects: the owner sends a search's words to a page of this site instead of the results ("size guide" to the size guides), on the new Redirects and pins tab or with `wp csearch redirect add|list|remove`. Same-site only; checked before categories and filters; the search is still logged with what it would have found. Setting `query_redirects`, filter `csearch_query_redirect` (D-108, D-111).
- Synonyms of more than one word on either side: "ss" means "stainless steel", "skate shoes" means "sneakers". A search holding the words side by side also searches what they mean, as a whole, and each product is ranked once at its better score (D-109).
- Pinned products: up to five products, by SKU or ID, first for a search's words in the panel, on the results page and in `/search`, on the Redirects and pins tab or with `wp csearch pin add|list|remove`. Hidden and unpublished products are still left out; a pinned search is not sent to a category. Setting `pinned_products`, filter `csearch_pinned_products` (D-110, D-111).

### Changed
- A sort other than Relevance (price, popularity, rating, date) lists the strong matches only: every word as typed, its spellings, stem or synonyms, never a completion of an unfinished word or a typo's neighbour, so a sort never brings a weak match to the top (Baymard). When nothing is strong, every match is listed. The count and filters follow (D-118, which replaces D-057's "any other sort keeps the match set").
- A search found through a typo says "Showing results for “kazumi dealer”." on the results page, in the panel, on the Tools tab and in `wp csearch search`, with no "search instead" link unless a word was also left out. `SearchResult` gains `dropped` (D-114).
- A synonym whose meaning is several words now means that phrase as a whole, not each of its words: "deck" meaning "skateboard deck" no longer makes "skateboard" alone match "deck" (D-109, superseding D-029).
- The Synonyms tab's test box is "Test a search" and takes several words.
- Schema 3: the `facts` table and the documents' `cart` column. A site on schema 2 upgrades in place and owes a rebuild; until it is done, the live index is read as before and no variation or reason is shown (D-107).
- `csearch_document` gains `cart` and `facts`; `csearch_suggestion_classes` receives the image the row shows, the variation's when one is named.

### Fixed
- A product search with no results showed WooCommerce's "No products were found matching your selection." above the plugin's no-results state, two status messages for one state; on searches the plugin answers, WooCommerce's notice is left out and its other callbacks still run (D-122).
- On the chosen row, the price, stock and other secondary text fell to 4.34:1 contrast on the row's tint with the default colours (Storefront); it is darker there now, above 4.5:1.
- In Button mode, Forward after Back reloaded the page: the dialog's history step dropped the Interactivity API's own key, so the runtime treated it as foreign. The step now keeps the page's state and adds its own key; a stale key left by a reload is removed on its own (D-124).
- WooCommerce's result count said "Sorted by" the shop's default sort (for example "Sorted by latest") on search results that were in Relevance order; it now says "Sorted by relevance" there, and a search sorted another way keeps WooCommerce's own line (D-123).
- On WooCommerce 11.2, a search the page does not treat as a product archive had WooCommerce's "hidden from search" rule added outside `woocommerce_product_query_tax_query`, so `hidden_by_sku` never brought a hidden product's exact SKU to the results page; the ranked search now lifts it there too.

## [1.2.0] - 2026-10-22

Items 9 to 11 of the Skatehouse handoff (docs/research/SKATEHOUSE-HANDOFF-2026-10.md).

### Added
- A product search whose words name a category with products, every word of its name and nothing else (by stem, synonyms counting), opens the category instead of the results page; not when a product is named exactly as the query reads. The category page says "Showing Wheels. Search all products for “wheels”." When two categories share the name, the results page leads with "Categories: A and B". Setting `category_redirect` (on), filter `csearch_category_redirect` (D-102).
- A size or other attribute value in a search becomes WooCommerce's own filter for the attributes ticked on the Tools tab (`filter_attributes`, none by default): "deck 8.25" opens Decks filtered to Width 8.25, "philly 8.75" searches "philly" filtered to 8.75, with a line saying so and a link back to every product. A word two attributes share stays a word. Filter `csearch_query_filters`; option `csearch_attribute_terms` (D-101).
- Query arguments `csearch_from` with its signature `csearch_sig` (the page a query was sent to: not logged again, not routed again, the field keeps the query as typed, WooCommerce's single-result redirect off) and `csearch_all=1` (the results page for the words as typed).
- `tests/Support/SkatehouseCopy`, `SkatehouseCopyTest` and the `skatehouse` test group (`composer test:skatehouse`): the handoff's golden queries on a copy of the shop exported into the gitignored `tests/fixtures/skatehouse/` (D-103). docs/BENCHMARK.md explains loading the same copy for D-081's FiboSearch run.

### Changed
- `pre_get_posts` gains the router at priority 4 and `template_redirect` a 302 at priority 5; a routed query is logged once, as typed, with what the page it lands on lists; the routed request runs no search of its own.
- The owner's synonyms are read once a request and kept with the cached answers.
- The 10,000-product CI job loads its catalogue quietly: WordPress's term counts deferred to the end and the plugin's index hooks detached, so the cost a product stays flat as the catalogue grows (about 63 ms locally, where it rose from 77 ms to 105 ms over 1,000). Its timeout drops from 300 to 90 minutes, and a new push to a branch or pull request cancels the CI run it replaces.

### Not yet done
- Handoff item 11 needs Zach's export: the `skatehouse` group skips until `tests/fixtures/skatehouse/` holds it, and D-081's FiboSearch run on the copy waits on the same files.

## [1.1.0] - 2026-10-22

Posts and pages in the search (the spec's 1.1), items 1 to 8 of the Skatehouse handoff (docs/research/SKATEHOUSE-HANDOFF-2026-10.md), and the Baymard autocomplete fixes.

### Added
- Posts and pages, each switched on separately on the Tools tab (off by default). They are searched apart from the products, so switching them on changes no product's rank. In the panel they come after the products, two at most, under a "Pages", "Posts" or "Pages and posts" heading. On the results page up to three show above the products, or after them when a product's name matches exactly, and a page leads when no product matches. The shop, cart, checkout and account pages, the front page, private and password-protected posts, and any post whose editor's new "Exclude from search" box is ticked are never indexed (D-093).
- Button mode for the block and the shortcode (`mode="button"`): a search icon that opens the same form in a modal dialog, full screen on a phone. Escape, the close button, a click outside and the browser's Back close it, and focus returns to the icon (D-099).
- The no-results page's links: a help page and a second page the owner picks on the Boosts and exclusions tab, each with its own wording (translated defaults), then "Try one word:" with the words of the query that each find something (D-096).
- Hooks: `csearch_index_content`, `csearch_content_document`, `csearch_content_limit`, `csearch_suggestion_classes` (classes on a product's suggestion row, for Mature Content's blur), `csearch_flush_cache` (and the plugin listens to `mcw_flags_changed`), `csearch_no_results_links`, `csearch_alternative_queries`, `csearch_dim_page`; a `content` reader in `csearch_languages`. See docs/HOOKS.md.
- `content` in the `/suggest` and `/search` answers, and `classes` on each suggested product.

### Changed
- The arrow keys write the chosen suggestion into the field, and the field returns to what was typed above the first row and past the last (D-091). SPEC.md's keyboard paragraph was updated with Zach's leave.
- The panel never scrolls: at most 10 suggestions on a desktop and 4 products and 2 terms on a phone, then trimmed until it fits the screen (D-092, superseding D-079's axe exemption).
- Each suggestion marks in bold the part it adds to what was typed (D-095), and the page dims behind the open panel (D-097, `csearch_dim_page` turns it off).
- On a search page the field keeps the query, and the panel says when a word was left out (D-094).
- Sizes are read one way however they are written: `8.25in`, `8.25 inch`, `8.25''` and `8.25″` are `8.25`; `8.50` is `8.5`; `54 mm` is `54mm` and finds `54`; `9x33` and `110*38mm` split; in French, `8,25` and `8,25 po`. Updating owes one full rebuild of the index, run in the background (D-098).
- A name being typed counts as exact once its last word completes it: "size guide" ranks "Size guides" first (D-100).
- The block's render holds nothing per visitor and no nonce, so a page cache can keep it (Skatehouse handoff item 4; an integration test holds it).

### Fixed
- The panel could be measured before its rows rendered or its font loaded, and end below the screen; and trimming it could leave the field pointing at a removed row (D-092).

## [1.0.0] - 2026-10-22

Milestone 8, Languages and 1.0. The shop works in French end to end, the plugin speaks Canadian French, and the documentation is complete.

### Added
- Canadian French (`fr_CA`) translations in `languages/`: the PHP strings (`.po`, `.mo`) and the editor and Tools scripts (JSON), loaded by WordPress from the plugin's `Domain Path` (D-076). `composer i18n:pot`, `i18n:po` and `i18n:mo` rebuild them.
- A French run of the browser tests (`tests/e2e/french.spec.ts`, 14 tests on three engines and two viewports): French suggestions with French prices, accent folding, French stems on the results page, the fallback to English, no results, and the admin in French, with axe on each state. It uses a must-use fixture that makes the shop bilingual the way skatehouse-core will (D-080).
- `QueriesExplainTest` (group `benchmark`): every search statement `EXPLAIN`ed on the 300-product bilingual catalogue, failing when a plugin table has no key to use; docs/QUERIES.md holds its plans (D-082).
- docs/BENCHMARK.md: the FiboSearch comparison, documented (free and Pro, each row with its source) and measured against FiboSearch free on the generated catalogue, labelled interim until it is run on a copy of Skatehouse (D-081).
- readme.txt: FAQ on languages, accessibility, the FiboSearch comparison and explaining a ranking.

### Fixed
- The fallback to the site language failed when a visitor's locale was French: the site language is now the one set under Settings → General, not the request's locale (D-076).
- Parent categories suggested "(0)" products: counts are WooCommerce's, subcategories included, and parents are re-indexed when a child's products change (D-078).
- "See all 1 results": the panel's last row has a singular (D-077).
- Up from the field with no suggestion chosen went to the second-to-last row; it goes to the last, as the APG pattern says (D-079).

### Changed
- A search reads only the SKU key it compares (the query's), not every SKU key of every matching product, and lets go of wpdb's copy of each result (D-085).
- Products are ranked by one statement in the database, as the spec describes: only the page of results reaches PHP, so memory no longer grows with the number of matches. `explain` keeps the ranking in PHP for its full breakdown, and a parity test holds the two to the same ids, order and totals over about 870 queries (D-086).
- Each term's document count is stored per generation and language (`csearch_term_stats`, a seventh table) instead of counted on every search, and each posting carries its document's language and kind under a primary key that leads with them, so the search reads its postings from the index alone (D-087).
- At 10,000 products, `/suggest` p95 cold went from 214 ms to 44 ms, `/search` from 209 ms to 43 ms, and a cold suggestion's memory from 14.5 MB to 1.7 MB, all within the spec's budgets.
- Schema 2: `csearch_documents.name_key`, the postings' `lang` and `product`, and `csearch_term_stats`. An index built before it is migrated in place by a background job (or the first admin request, `wp csearch` command or activation) after the update, then rebuilt in the background. Until the migration is done, searches answer as 0.7.0 did and index writes wait; a migration cut off halfway resumes (D-090).
- The mutation testing reaches the SQL builders: `composer infection:sql` runs Infection inside wp-env's tests container against the integration tests that hold them (D-088).

## [0.7.0] - 2026-10-21

Milestone 7, Admin. The owner can now see what shoppers search for, teach the search their words, tune the ranking and look after the index without a terminal.

### Added
- WooCommerce → Search with four tabs, in WordPress's own admin markup (D-068):
  - **Analytics:** the last 7, 30 or 90 days; searches a day as a chart, with the same numbers in a table (D-072); totals, click-through and suggestions against results-page use; the top 50 queries and the top 50 with no results, each with an Explain link; the most clicked suggestions; CSV export of the log's own columns, streamed.
  - **Synonyms:** add, edit and delete in a list table with search, language filter, 20 a page and bulk Delete; a test box that shows what a word expands to; CSV import and export. A change applies to searches at once.
  - **Boosts and exclusions:** rules that add −10 to 10 to a category, brand or tag; the excluded products, each of which can be let back in; fallback products chosen by SKU or ID (D-069); hidden products by exact SKU.
  - **Tools:** the index's status, Rebuild with live progress and Cancel, a test search that shows the ranking and why (not logged, D-073), stop words per language, retention, what to index and the search bar's defaults.
- A Search tab on the product edit screen: boost, Exclude from search, "Last indexed … ago" and Reindex now; `Meta: _csearch_boost` and `Meta: _csearch_exclude` in WooCommerce's product CSV export, normalised on import (D-070).
- Notices for an empty index, more than 10 % of products missing, a queue that has not run for 15 minutes and a rebuild with errors, each dismissible until its condition clears (D-071).
- `wp csearch search`, `wp csearch synonyms list|add|delete|import|export`, `wp csearch log purge [--days]` and `wp csearch cache flush` (D-074).
- Tests: 15 integration tests for the admin and 3 more for the CLI; 9 browser tests with axe, by keyboard, on three engines and two viewports (45 runs).

### Changed
- `GET /csearch/v1/status` reads `Index\IndexHealth`, shared with the Tools tab and the notices; its JSON is unchanged.
- `wp csearch explain`'s rows come from `Search\Explanation`, shared with the test search.
- Uninstall also removes the `csearch_dismissed_notices` user meta.

### Fixed before release (independent review of milestone 7)
- `log purge` rolls up the days it deletes first; `synonyms delete` and `export` take `--format=json`; screens prime their users, products and terms; the log export streams; CSV is built in memory only; plurals and sentences are whole strings; reads are bounded (D-075).

## [0.6.0] - 2026-10-21

Milestones 5 and 6, Results page and Search block, released together (D-066; there is no 0.5.0). A shopper's product search now comes back in the plugin's order with the words around it the spec asks for, and the search bar suggests products, categories and brands as they type.

### Added
- `Results\ProductSearch`: the main product search on `?s=` gets the ranked ids as `post__in`, WordPress's search clause removed, "Relevance" in the plugin's order and every other sort over the same match set; WooCommerce's filters (categories, price, attributes) narrow the ranked set; other searches are left to WordPress; `csearch_rank_query` filter.
- `Results\ResultsView`: "23 results for “kazumi”", "Showing results for *kazumi*; search instead for *kazumi wheels*" (`csearch_exact=1`), and a no-results state with the three nearest categories and the owner's fallback products, in classic templates and block templates (D-057, D-058).
- `SearchOptions::$retry` in the Domain, so the shopper's exact words can be searched.
- The `creative-search/search` block: a server-rendered search form that works with no script and becomes the ARIA list-autocomplete combobox with the Interactivity API (suggestions after 150 ms of quiet, one request in flight, stale answers dropped, 20 answers cached, keyboard parity, a polite live region, "Searching…" after 400 ms, silent failures, recent searches off by default, the click beacon); the `[creative_search]` shortcode, a widget and the theme-form takeover render the same form; 2.6 kB of script and 1.1 kB of CSS compressed; `csearch_min_chars` and `csearch_client_ip` filters.
- Tests: 15 integration tests on the archive query and 6 on the block's rendering; 13 state-machine tests on Node's runner (D-060); browser tests on Storefront, Kadence and Twenty Twenty-Five, and the block's APG keyboard script, network delay, error and no-script runs, across three engines and two viewports, with axe on every state (85 runs).

### Fixed before release (review of milestones 4 and 5)
- The results page searches the shopper's words unslashed, so `Philly's` is searched as typed (D-061).
- The ETag is the generation and the answer, so revalidation cannot keep a stale suggestion (D-062).
- A forged WooCommerce session cookie no longer buys a fresh rate-limit bucket; `csearch_client_ip` for proxies (D-051).
- The count line and the no-results state follow what WooCommerce shows after its filters; hidden-by-SKU products reach the results page; the classic filter widgets count the ranked set; the count line prints once on block themes (D-061).
- Emails and phone numbers are redacted while they are being typed (D-063).
- Empty searches and feeds are left to WordPress; a second page or a re-sort is not logged again; the "search instead" link keeps the page's language and filters; the nearest categories are three categories even when brands rank higher, and are cached; `show_prices` is part of the cache key; REST and the results page clean queries the same way; the index state is read straight from its table without writing to the object cache.

### Changed
- `IndexState::live()` reads one option, so a warm results page costs one extra query.
- wp-env installs Storefront 4.6.2 and Kadence 1.5.2; Playwright's global setup launches the store and loads the hero products (D-059).

## [0.4.0] - 2026-10-21

Milestones 3 and 4, Index and Query and API, released together (D-056; there is no 0.3.0). The Domain gets a database: products, categories, tags and brands are written to the four index tables in the background whenever they change, a full reindex builds the next generation beside the live one and switches when complete, and WP-CLI can index, rebuild and report. The searcher answers four REST routes from that index, cached, rate-limited and logged without the shopper. Nothing a shopper sees yet; the block in milestone 6 calls these routes.

### Added
- Repositories for the four index tables (`DocumentRepository`, `TermRepository`, `PostingRepository`, `TrigramRepository`, on a shared `Repository` base that is the only caller of `$wpdb`, every statement prepared) and `IndexRepository`, the SQL implementation of `Domain\IndexStore`, reading the live generation only; the container binds the interface to it.
- `Index\Indexer`: a product becomes one document per language with its name, every SKU (variations included), short and long description (shortcodes and HTML stripped, 2,000 characters), category names with parents, tags, brands, every attribute value (global and custom), price range, stock roll-up, image, URL, visibility, the owner's boost and sales; categories, tags and brands become documents of their own; removal decrements `df` and keeps the row at 0; SKU keys stored as dictionary terms (D-041).
- `Index\ProductReader`, `TermReader`, `Languages` and `AnalyzerFactory`, with the public filters `csearch_index_product`, `csearch_product_fields` (D-050), `csearch_document`, `csearch_tokens`, `csearch_stop_words` and `csearch_languages` (D-045), and the action `csearch_index_switched`.
- The hook set: product, variation, stock, stock status, term assignment, trash, untrash, delete, status change, term edit, create and delete, CSV import and the boost and exclude meta, each queuing the product once per request in memory and handing Action Scheduler one unique job at shutdown (D-043); stock-only saves flip `in_stock` without re-tokenising; a renamed term re-queues its products 100 a job; under WP-CLI the work runs inline at shutdown.
- `Index\Reindex`: walks products 100 a job in id order into generation N+1, then categories, tags and brands, then switches `csearch_index_state.live`, flushes the cache group and deletes the old generation 500 documents a job; Cancel gives the half-built generation up; a reindex already running is not started twice; a product that throws is recorded and skipped. Live changes during a reindex reach both generations.
- `wp csearch index <id>...`, `wp csearch reindex [--inline] [--cancel]`, `wp csearch status`.
- `Install\Upgrader`: schema upgrades on `admin_init` and before WP-CLI commands, behind a lock (D-046).
- `Support\Cast` for hook arguments and filter results that arrive untyped; `Support\PluginException`.
- `Search\SearchService` (the one place a query becomes a ranked answer: cleaned, language-checked, the owner's synonyms, weights through `csearch_fields`, boost rules, hidden-by-SKU, the fallback language), `Search\Suggestions` (the panel's answer from the documents table alone: name, URL, thumbnail with `srcset`, price through `wc_price()`, stock), `Search\Cache` (by generation, `csearch_cache_ttl`), `Search\RateLimiter` (sliding window per client, `csearch_rate_limit`), `Search\SearchLog` (one row per search written at shutdown, scrubbed), `Search\Clicks` and `Search\LogMaintenance` (`csearch_purge_log`: daily roll-up into `stats`, retention purge in batches of 1,000).
- `GET /csearch/v1/suggest`, `GET /csearch/v1/search`, `GET /csearch/v1/status` and `POST /csearch/v1/click`, with argument and response schemas, `Cache-Control` and an ETag of the generation and the normalised query (a 304 costs no search), 429 with `Retry-After`.
- `wp csearch explain "<query>"`.
- `SynonymRepository`, `LogRepository`, `ProductIdRepository`.
- Tests: 30 integration tests for the indexer, the hooks, the reindex and the CLI against WooCommerce 11.2.1, 11 for the REST API, the golden set's 50 queries through `/suggest` and `/search` in English and French with the p95 budgets, a `WooCommerceCatalogue` loader that writes the fixture catalogue through WooCommerce's CRUD, and a timing test (`composer test:integration:benchmark`, D-047).

### Fixed before release (milestone 3 review)
- `df` is decremented once per removed document, not once per term, so a cleanup batch or a two-language product leaves exact counts; the score counts `df` from the postings of the query's language (D-044, D-050).
- Jobs are deduplicated on pending jobs only, so the oldest supported Action Scheduler does not drop other products' jobs or the reindex's next batch, and a save during a product's running job is not lost (D-043).
- The index state is read fresh and the cancel flag has its own option, so a cancel is never overwritten by a running batch (D-053); a cancelled generation's number is never reused; a reindex whose chain died can be cancelled; a queued reindex can be cancelled before it starts and an inline one removes it.
- The reindex walks product ids by keyset, so a product trashed mid-walk does not drop another from the new generation (D-054).
- A quantity change that keeps the stock flag no longer re-tokenises the product; a renamed global attribute value or parent category reaches its products; a deleted term's products are queued 100 a job; the queue flushes after WooCommerce's deferred product sync.
- A failed document insert throws instead of attaching postings to another document.
- Uninstall deletes the plugin's Action Scheduler rows of every status; a deleted network site drops the six tables.
- WP-CLI messages are translatable, `index` exits non-zero when a product cannot be indexed, and every command takes `--format=json`.
- The texts filter is `csearch_product_fields`; `csearch_fields` is the spec's weights filter (D-050).
- A CI job runs the reindex and API budgets at 10,000 products.

### Changed
- Activation queues the first build as one Action Scheduler job and indexes nothing on the request; network activation sets up every site (M1 review items 2 and 3).
- `Schema::maybeUpgrade()` no longer runs on every request; it is replaced by `Install\Upgrader`.
- `Domain\Analyzer` takes an optional token filter, the seam the `csearch_tokens` filter uses.
- The per-product job is `csearch_job_index_product`, not `csearch_index_product`, which the spec also gives to the filter (D-042).
- `src/Index`, `src/Hooks`, `src/Cli` and `src/Repository` leave the core Infection run for the integration suite (D-048).

## [0.2.0] - 2026-10-21

Milestone 2, Domain. The whole search algorithm as pure PHP under `src/Domain`, with no WordPress import, run in the tests against an in-memory index built from the catalogue fixture. Nothing a shopper or owner sees yet; milestone 3 gives it a database.

### Added
- `Domain\Tokenizer` with `Folding` (lower-case, accents, ligatures) and `StopWords` (Snowball's English and French lists): one token per word, inch marks read with the number and dropped (D-024), apostrophes indexed as the joined form and both sides at one position (D-025), stop words out and positions counted over the tokens kept (D-026, D-027), tokens capped at 64 characters.
- `Domain\Stemmer`: exact ports of Snowball's `porter.sbl` and `french.sbl` (`PorterStemmer`, `FrenchStemmer`), an `IdentityStemmer` for other languages, and `Stemmers::for()`; both ports pass every vector in Snowball's distribution, vendored under `tests/Fixtures/snowball/` (D-031).
- `Domain\Trigrams` (padded, distinct, half-shared minimum, D-035) and `Domain\Distance` (bounded Damerau–Levenshtein with transpositions; one edit for 4 to 7 characters, two from 8).
- `Domain\QueryParser` and `Query`: quoted phrases, `-word` exclusion, a prefix flag on the unfinished last token, curly quotes straightened, 200 characters and 8 tokens, synonyms attached (`Synonym`, `Synonyms`, D-029), an all-stop-word query that runs and finds nothing.
- `Domain\Analyzer`: a product or term as fields to the postings an index stores (term, field, frequency, first position), the stem as a second posting at the same position, the SKU field counted once (D-038).
- `Domain\IndexStore`, the interface the searcher reads through (D-028), with `Document`, `TermMatch`, `TokenWidening`, `SearchOptions`, `SearchResult`, `Hit` and `Score` as the value objects around it.
- `Domain\Searcher`: the widening plan as calls on the store (exact, variants, stem, synonyms and prefix together, D-033; typo candidates only when those find nothing), exclusions, the AND with phrase adjacency, the hidden-product rule, the retry that drops the least-matched token when nothing matched (D-034) and the term suggestions (D-037); a fallback language when a language finds nothing.
- `Domain\Scorer` and `Score`: the spec's formula (field weight × match kind × `1 + ln(1 + tf)` × `ln((N + 1) / (df + 1))`, best field per token), phrase bonus, exact-name and exact-SKU bonuses, the stock penalty, owner boosts; every figure kept on the `Score` for `explain` in milestone 4.
- `Domain\Scrub`: emails, phone numbers, card numbers and postal codes replaced before anything reaches the log (D-039).
- Tests: a case table for every tokenizer, parser, scrub, distance and trigram rule; xorshift property tests on the tokenizer; Snowball's 64,256 vectors; scorer tests against hand-built documents; a searcher test on a hand-built index; and the golden set scored in memory against the catalogue at 300 products (`tests/Support/MemoryIndex`, `CatalogueIndexer`), with a benchmark that runs the fifty queries inside a second.
- A second Infection configuration, `infection-domain.json5`, with a 100 % floor on `src/Domain`; `composer infection` runs both.

### Changed
- Four golden expectations rewritten to match the algorithm the spec describes (D-032: `dck` → `dek`, the missing-SKU correction, `the` finds nothing, the exclusion query asserts absence). Generated products in the catalogue fixture now sell under 15 units so the hero products are the deterministic tie-break winners (D-036); SKUs, names and prices are unchanged.
- `Support\Clock` joins the core Infection run; `Plugin`, `Hookable`, `Support\Options` and `Support\Tables` leave it as WordPress-bound (M1 review).
- `phpcs.xml` says `minimum_supported_wp_version` 7.0, matching the floor (M1 review item 4).
- Floors raised: WordPress 7.0 or later and PHP 8.5 or later (were 6.8 and 8.2; D-023). The toolchain moves to PHPUnit 13 and Infection 0.35; the WordPress integration suite keeps PHPUnit 9.6 in `tools/integration/`.
- Firefox launches under Playwright on macOS 27 (D-022); the FiboSearch benchmark plan no longer needs a Pro licence (D-005).

### Fixed
- The activation integration test no longer depends on Action Scheduler rows left by earlier runs; the harness clears the plugin's group before each test.

## [0.1.0] - 2026-10-21

Milestone 1, Foundation. Nothing a shopper or owner sees yet: the skeleton the next seven milestones build on, with every CI gate wired and green against it.

### Added
- The plugin bootstrap (`creative-search.php`: headers, constants, autoloader check, activation hook, HPOS and cart-block compatibility declarations, boot on `plugins_loaded`), a hand-written service container with interface bindings and a `Hookable` contract, and `Plugin::boot()` with the WooCommerce 10.0 version check.
- `Install\Schema`: the six InnoDB utf8mb4 tables (`csearch_documents`, `csearch_terms`, `csearch_postings`, `csearch_trigrams`, `csearch_synonyms`, `csearch_log`) with the spec's keys, `generation` on documents and postings, `dbDelta()` idempotent (a second run reports no change), `csearch_db_version` autoloaded and `maybeUpgrade()` on boot.
- `Settings\Settings`: the one `csearch_settings` option with every default the spec names (`docs/SETTINGS.md`), typed readers, and a `sanitize()` that drops unknown keys and clamps every value.
- `Install\Activator` (schema and defaults, no work), `Install\Uninstaller` (tables, options, transients, product meta, Action Scheduler group, cache group, per site on a network) and `uninstall.php` with an inline fallback; no deactivation hook, by design.
- `Support\Tables`, `Support\Options` (every stored name in one place) and `Support\Clock` (UTC, freezable).
- The catalogue generator (`tests/Fixtures/Catalogue.php`): deterministic products as arrays from a seed, 24 hand-written Skatehouse-shaped heroes first, realistic brands, series, widths, diameters, sizes, colours, SKUs and French names; 10,000 products with 48,000 variations in under a second.
- The golden query set (`tests/Fixtures/queries.yml`, 50 queries covering every matching rule, with the synonyms they seed) and its loader.
- Tests: unit (Settings, Container, Clock, Options, the generator, the golden set), integration in wp-env (schema idempotence, columns, keys, uniqueness, upgrade, activation, headers, HPOS declaration, uninstall leaves nothing), Playwright smoke with axe on three engines at desktop and phone widths, and the fixture benchmark.
- CI (`.github/workflows/ci.yml`): PHPCS (WordPress-Extra, WordPress-Docs, WordPress-VIP-Go, PHPCompatibilityWP, zero warnings), PHPStan level 9, the grep gates (`tools/gates.sh`: SQL outside repositories, banned functions, `date()`, `strict_types`, outbound requests), PHPUnit with the coverage floors (`tools/coverage-floor.php`), Infection, the JavaScript and CSS standards, benchmarks, and integration plus Plugin Check plus browser tests on the newest and the oldest supported WordPress and WooCommerce.
- Docs: `DECISIONS.md` (the five pre-milestone answers and D-010 to D-020), `HOOKS.md`, `SETTINGS.md`, `SECURITY.md`, `A11Y.md`, `BENCHMARK.md`, `QUERIES.md`, `reviews/M1.md`.
