# Creative Search: research and build spec

Creative Branding · 21 October 2026 · the living copy is the Claude doc "Creative Search: research and build spec"; this file is its export for the repo.

## Goal and scope

Build a WooCommerce search plugin of our own, good enough that a shop never needs FiboSearch Pro: live suggestions as the shopper types, typo-tolerant matching across names, SKUs, descriptions, categories, brands and attributes, an index the plugin owns, a results page that keeps the shop's template, search analytics for the owner, and a search bar that passes an accessibility audit. Built generic, like Creative Locator and Creative Stockroom; Skatehouse is the first shop.

**Name:** Creative Search. Plugin slug `creative-search`, code prefix `csearch_` on tables, options, hooks and actions, namespace `CreativeBranding\Search`, text domain `creative-search`, WP-CLI command `wp csearch`.

**What it does**

- Keeps its own index of every product a shopper may see: name, SKU (and variation SKUs), short and long description, categories, tags, brands, attribute values (a deck's widths, a tee's sizes), and a stock flag. Updated in the background on every product change; rebuilt from a Tools screen or the command line.
- Answers a query in under 100 ms from the index: prefix matches as the shopper types, a typo or two forgiven, phrases ranked above scattered words, names above descriptions, in-stock above sold-out, with synonyms the owner sets ("board" finds decks).
- Shows suggestions in a panel under the search bar: products with photo, price and stock, then categories and brands that match, then "See all results". The bar is a real combobox: keyboard, screen reader and touch all work.
- Takes over the shop's search results page: WooCommerce's own archive template and filters stay, the plugin supplies the ranking.
- Records what people search for and what finds nothing, with no personal data, on an Analytics screen the owner reads.
- Speaks the shop's languages: accents fold, French and English stem, the French site's product fields index alongside the English ones.

**Who it's for.** Skatehouse Distribution first (300 products, 1,600 variations, two languages), then any WooCommerce shop Creative Branding builds, and shops that buy it. The owner uses the Analytics and Synonyms screens; a shop manager never needs the settings.

**Requirements.** WordPress 7.0 or later (Interactivity API, block bindings, HTML API; the floor was 6.8 until 21 October 2026, D-023), WooCommerce 10.0 or later (HPOS, the Store API, the product attributes lookup table), PHP 8.5 or later (was 8.2, D-023), MySQL 8.0 or MariaDB 10.6 or later (InnoDB, utf8mb4). Tested against the versions pinned in `.wp-env.json`; WordPress 7.x and WooCommerce 11.2 at the time of writing. No external service, no API key, no outbound requests.

**Non-goals for 1.0.** No semantic or AI search; no posts and pages in results (a setting for 1.1); no replacement of the results template; no faceted filtering of its own (WooCommerce's filter blocks do that and they read the plugin's ranking); no image search; no multi-store index. Nothing that only Skatehouse needs: what is Skatehouse's goes in skatehouse-core, through the plugin's hooks.

### Working with Claude Code

- The same shape as Creative Stockroom: milestones in order, each ending in green tests, a commit and a tag; nothing merged that lowers coverage or the mutation score.
- Agency standard on every milestone: DRY and SOLID, no WordPress anti-patterns (no direct SQL outside repositories, prepared statements everywhere, no `query_posts`, no global state), security (nonces, capabilities, escaping late, sanitising early), accessibility (WCAG 2.2 AA on everything a shopper or admin sees), i18n (every string through the text domain, RTL-safe CSS, no concatenated sentences), tests (PHPUnit unit and wp-env integration, jsdom for the block's script, Playwright for the combobox, axe on every screen), performance (budgets below are tests, not hopes).
- Tooling: `composer` (PHPUnit 11, Infection, PHPCS with WordPress-Extra and WordPress-VIP-Go rulesets, PHPStan level 8 with the WordPress and WooCommerce stubs), `npm` (`@wordpress/scripts` for the block, jsdom, Playwright, axe-core). `.wp-env.json` pins WordPress and WooCommerce for the integration suite.
- Conventions: PSR-4 under `src/`, one class per concern, repositories own the tables, services own the rules, nothing in the plugin's root file but the bootstrap. Public hooks are documented in `docs/HOOKS.md` as they are added. A `CHANGELOG.md` entry per milestone.
- Decisions Claude Code makes on its own are written into `docs/DECISIONS.md` with the reason; decisions that belong to the owner (what counts as a brand, how long analytics are kept) are settings with the default this spec names.

## The bar to clear

The paid plugins all sell the same six things: live suggestions with photos and prices, an index of their own for speed, SKU and attribute matching, typo tolerance, synonyms, and analytics. FiboSearch Pro is the one shops reach for; 1.0 has to match its Personal plan feature for feature, and beat it on accessibility and on what the owner learns from the analytics.

| Plugin | Live suggestions | Typos | Synonyms | SKU, variations | Attributes, brands | Analytics | Own index | Price (1 site) |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| WooCommerce alone | No | No | No | No (shop search), yes (Store API) | No | No | No (`LIKE` on title, excerpt, content) | Included |
| [FiboSearch](https://fibosearch.com/pricing/) free | Yes, with photo and price | No | No | SKU yes, variation SKU no | No | No | No (indexer log only) | Free |
| [FiboSearch Pro](https://fibosearch.com/pricing/) | Yes | Yes (fuzzy) | Yes | Yes | Yes | Yes | Yes, "up to 10× faster", 0.2 s per request | $59 first year, $50.15 renewal; Agency 25 sites $249 |
| [SearchWP](https://yaycommerce.com/?p=53914) | Yes (extension) | No | Not stated | Yes | Taxonomies, custom fields | Yes | Yes | about $99 a year |
| [Relevanssi Premium](https://www.elegantthemes.com/blog/wordpress/best-wordpress-search-plugins) | No (results page only) | Yes | Yes | Through custom fields | Through custom fields | Logging | Yes | $109 a year |
| [Advanced Woo Search Pro](https://yaycommerce.com/?p=53914) | Yes | Yes | Yes | Yes | Limited | No | Yes | $69 a year |
| [YITH Ajax Search](https://yaycommerce.com/?p=53914) | Yes | No | Yes | SKU (premium) | No | No | Not stated | $49.99 a year |
| [Premmerce Product Search](https://yaycommerce.com/?p=53914) | Yes | Yes (spell correction) | Not stated | Yes | Not stated | No | Not stated | $49.99 |
| [WooCommerce Product Search](https://yaycommerce.com/?p=53914) (itthinx) | Yes, with add to cart | Not stated | Not stated | Yes | Yes | Yes | Yes | $59 a year |

What WooCommerce does on its own, which is the floor: the shop's search box runs WordPress's search, a `LIKE '%word%'` on title, excerpt and content for each word, ordered by relevance of title matches then date, with no SKU, attribute, brand or category matching, no typo tolerance and no suggestions. The Store API's `search` parameter is better (it adds SKU matching through the product meta lookup table) but nothing in the shop's front end uses it. Industry guides put the zero-result rate of an untouched WooCommerce search at [15 to 30 % of searches](https://motive.co/woocommerce-search).

Where 1.0 beats the table: a combobox that passes an accessibility audit (none of the plugins above claim one; FiboSearch's changelog shows WCAG fixes arriving piecemeal), analytics that answer the owner's questions (what people want that the shop doesn't stock, what finds nothing, what gets clicked), stock-aware ranking that the Stockroom's numbers feed, and no per-site licence: the agency ships it on every shop it builds. Sources opened 21 October 2026: FiboSearch pricing and plugin pages, the YayCommerce comparison (updated 26 May 2026), Elegant Themes' plugin round-up (updated 13 January 2025), Motive's guide.

## Quality bar

Creative Search is coded to a higher standard than any plugin in the table above, and the standard is measured, not asserted. Two layers: the agency's audit matrix, which every milestone is reviewed against and written up in its pull request, and the CI gates under it, which run on every commit; a milestone is not done while one is red.

**The agency's audit matrix, applied to search**

| Dimension | What it means here | Evidence a reviewer looks for |
| --- | --- | --- |
| DRY and SOLID | One tokeniser, one scorer, one query parser, used by the suggest route, the results hook and WP-CLI alike; repositories own tables, services own rules, the domain layer has no WordPress in it; every class has one reason to change; extension through hooks and interfaces, never by editing the core | `src/Domain` imports nothing from WordPress; no duplicated SQL; interfaces for the index store, the queue and the clock so tests swap them |
| Anti-patterns | No direct SQL outside repositories, no `query_posts`, no globals but `$wpdb` inside repositories, no logic in hooks (hooks call services), no work on the save request, no unbounded queries, no transients as a database, no `date()` without a timezone | PHPStan rules for `$wpdb` and the banned functions; the grep gate; the review |
| Security | Nonces and capabilities on every admin action and WP-CLI guard; prepared SQL; sanitised input at the edge and escaped output at the sink; the REST route public, read-only, rate-limited, with a hard cap on query length and result size; no PHP object in any stored value; uninstall leaves nothing | `docs/SECURITY.md` walked before each tag; `composer audit`; the CI grep for string-built queries |
| Accessibility | The search bar is an APG combobox: label, `aria-expanded`, `aria-controls`, `aria-activedescendant`, options with names; keyboard parity; a live region that says how many suggestions; focus never trapped; 24 px targets; visible focus; reduced motion honoured; every admin screen keyboard-complete; colour never the only signal | axe clean in CI on every screen; the manual VoiceOver and NVDA script per release in `docs/A11Y.md` |
| Internationalisation | Every string translatable with context, no concatenated sentences, plurals through `_n()`, numbers and prices through WooCommerce's formatters, RTL-safe CSS (logical properties), accent folding and stemming per language, the index per language, French shipped | The i18n check in CI; a French run of the browser tests |
| Testing | Unit for the domain, integration for everything that touches WordPress, browser for the block, mutation testing to prove the tests bite, benchmarks for the budgets; a bug fixed is a test added first | The coverage and MSI floors below; a failing-first commit per bug |
| Performance | Nothing on the request that saves a product; indexing in jobs; one query per suggest call plus the cache; no N+1 anywhere (the suggestion rows come from the documents table alone); the block's script tiny and deferred; the results page one extra query | The budgets below, measured in CI against a generated 10,000-product catalogue |

**CI gates**

| Gate | Floor | How it is checked |
| --- | --- | --- |
| Static analysis | PHPStan level 9 (max) with the WordPress, WooCommerce and Action Scheduler stubs, zero baseline | `composer analyse` in CI |
| Coding standard | Every WordPress standard, in full: the WordPress Coding Standards for PHP (the WordPress-Extra, WordPress-Docs and WordPress-VIP-Go rulesets, zero warnings), the JavaScript and CSS standards through @wordpress/eslint-plugin, @wordpress/stylelint-config and @wordpress/prettier-config, the inline documentation standards on every function, the Accessibility Coding Standards, the block editor standards (block.json, @wordpress/scripts, the Interactivity API, no jQuery), the REST API handbook's conventions (namespaced routes, schema, permission callbacks, versioned), the Plugin Handbook's security and data-handling practices, and the Plugin Directory guidelines (GPL-compatible, no tracking without consent, no remote code, no external calls, readme.txt valid); `declare(strict_types=1)` in every file | `composer lint` |
| Unit tests | 100 % line and branch coverage of `src/Domain` (tokeniser, stemmers, trigrams, scorer, query parser); 90 % overall | PHPUnit with pcov |
| Mutation testing | Infection MSI 100 % on `src/Domain`, 90 % on the rest, no escaped mutants in the scorer or the SQL builders | `composer infection` |
| Integration tests | Every hook, every REST route, every admin action and every WP-CLI command against a real WordPress and WooCommerce in wp-env, on the oldest and newest supported versions | PHPUnit with the WordPress test suite |
| Browser tests | The combobox on Chromium, WebKit and Firefox at phone and desktop widths: typing, keyboard, screen-reader attributes, slow network, no JavaScript | Playwright |
| Accessibility | axe-core clean on the search bar, the suggestions panel, the results page and every admin screen; one manual pass per release with VoiceOver on Safari and NVDA on Firefox, scripted and recorded in `docs/A11Y.md` | Playwright + axe, and the manual script |
| Performance | p95 server time for `/suggest` under 60 ms and `/search` under 100 ms on a 10,000-product catalogue; indexing a product under 50 ms; full reindex of 10,000 products under 5 minutes on a shared host; the block's script under 12 kB compressed, no framework | Benchmarks in CI against a generated catalogue, failing the build when a budget is missed |
| Security | Nonces and capability checks on every admin action, prepared SQL everywhere (PHPStan's `wpdb` rules and a grep gate for string-built queries), all output escaped late, input sanitised at the edge, the REST route rate-limited, no `eval`, no `extract`, no serialised input; one dependency audit per release (`composer audit`, `npm audit`) | CI gates plus a review list in `docs/SECURITY.md` walked before each tag |
| WordPress.org fitness | Plugin Check (`wp plugin check`) with zero errors or warnings on every category it checks (security, performance, i18n, plugin repo guidelines, code obfuscation, trademarks), the readme validator clean, `Requires at least`, `Tested up to` and `Requires PHP` kept current, uninstall.php removing every table, option, transient, cron hook and meta the plugin made, so the plugin would be accepted into the WordPress.org directory as it stands | CI |
| Documentation | Every public hook in `docs/HOOKS.md`, every setting in `docs/SETTINGS.md`, every decision in `docs/DECISIONS.md`, a changelog entry per milestone | Reviewed at each milestone |
| Compatibility | No PHP deprecations on PHP 8.4, no console errors with Kadence, Storefront and Twenty Twenty-Five, no conflict with the Stockroom or the colour cards | The wp-env matrix |

Two habits beside the gates: the agency's seven-dimension review (DRY and SOLID, anti-patterns, security, accessibility, i18n, testing, performance) is run by a second pair of eyes on every milestone and written into the pull request, and FiboSearch Pro is installed on a copy of the shop once, for one afternoon, to benchmark against: the same 50 queries, their timing, their ranking and their suggestions, kept in `docs/BENCHMARK.md` so "better" has numbers.

## Vocabulary

Classes, tables, settings and the docs use these words and no others for these things.

| Word | Meaning |
| --- | --- |
| Document | One thing the index can return: a product (its variations fold into it), a category, a tag or a brand. Each has a type and a WordPress id. |
| Field | One named piece of a document's text: `name`, `sku`, `short`, `long`, `category`, `tag`, `brand`, `attribute`. Each field has a weight. |
| Token | One searchable unit cut from a field's text: lower-cased, accents folded, punctuation dropped, numbers kept whole (`8.25`). |
| Stem | A token reduced to its root (`decks` → `deck`, `roues` → `rou`), by language. Stored beside the token, never instead of it. |
| Term | A distinct token or stem in the index, with an id. The index is a list of terms and, for each, the documents it appears in. |
| Posting | One row saying term T appears in document D's field F, N times, at position P. The index is made of postings. |
| Trigram | Three consecutive characters of a term (`dec`, `eck`); terms share trigrams with their misspellings, which is how a typo finds its term. |
| Query | What the shopper typed, after the same cutting as a field: a list of tokens, the last one perhaps unfinished. |
| Prefix match | The unfinished last token matches any term that starts with it (`kaz` finds `kazumi`). |
| Fuzzy match | A token matches a term within an edit distance (one letter for short words, two for long) chosen among trigram candidates. |
| Synonym | A word the owner says means another in this shop (`board` = `deck`). One way or both. |
| Stop word | A word too common to carry meaning (`the`, `and`, `de`, `les`); dropped from queries, kept out of the index. |
| Score | A number per document per query, higher for better matches: field weights, phrase bonus, exactness, stock and the owner's boosts. |
| Suggestion | One row in the panel under the search bar: a document with its photo, price, stock and link, or a category, tag or brand. |
| Results page | WooCommerce's shop archive for `?s=`, listing the products the index ranks, in that order, with the shop's own filters. |
| Search log | One row per query served: the words, the number of results, the language, the time, which suggestion was clicked if any. No shopper identity. |
| Zero result | A query the index answered with nothing. The Analytics screen's first list. |
| Reindex | Rebuilding every document from WooCommerce, in the background, in batches; the old index serves until the new one is complete. |

## How it fits

*(Drawing in the Claude doc: architecture · four sources, four services, four stores.)*

WooCommerce never searches: it reports changes (hooks) and renders results (its archive template). The indexer turns products into postings in the background; the query service answers the search bar's REST calls and the results hook from the same index, through the object cache; the owner's rules and the search log sit beside the index. Nothing leaves the server.

- **Catalogue changes** reach the indexer through WooCommerce's hooks (`woocommerce_update_product`, `woocommerce_new_product`, the variation and term hooks, `woocommerce_product_set_stock`, `woocommerce_variation_set_stock`, `set_object_terms` on product taxonomies), each queued once per product per request and run by Action Scheduler within the minute. A full reindex walks products 100 at a time, the old rows serving until the new ones replace them.
- **The search bar block** calls `GET /wp-json/csearch/v1/suggest?q=` as the shopper types, debounced at 150 ms, and renders the suggestions it gets back. It never queries WordPress for a page load.
- **The results page** stays WooCommerce's: on a product search (`?s=` on the shop, or `post_type=product`) the results hook asks the query service for the ranked ids and writes them into `WP_Query` (`post__in` and `orderby post__in`), so the archive template, pagination, the filter blocks and the sort menu work as they always did, with relevance as the default sort.
- **The owner** edits synonyms, boosts and stop words, starts a reindex and reads the analytics on three screens under WooCommerce in wp-admin; `wp csearch` does the same from the command line.
- **Skatehouse-specific behaviour** (French fields, the colour cards' listing rules, the Stockroom's stock) comes in through the plugin's hooks from skatehouse-core and Creative Stockroom; the plugin knows none of them by name.

## Data model

Six InnoDB tables under `{$wpdb->prefix}csearch_`, created with `dbDelta()` on activation and upgraded by `Install\Schema` whenever `csearch_db_version` is behind, all utf8mb4, integer ids, no JSON columns (every value is a column that can be indexed and queried). The index is the plugin's own inverted index; MySQL's full-text search is not used, because InnoDB full-text drops tokens shorter than three characters by default, cannot match a prefix inside a boolean phrase, has no typo tolerance and no per-field weights, and its ranking cannot be explained to the owner.

| Table | Columns | What it holds |
| --- | --- | --- |
| `csearch_documents` | `id` (PK), `type` (product, category, tag, brand), `object_id`, `lang` (`en`, `fr`), `title`, `url`, `image_id`, `price_min`, `price_max`, `in_stock` (tinyint), `visible` (tinyint), `boost` (smallint, 0), `total_sales` (int, copied from WooCommerce for the tie-break), `generation` (int), `indexed_at`; UNIQUE (`generation`, `type`, `object_id`, `lang`), so a reindex can hold the same document in two generations (D-012) | One row per thing the index can return, with what a suggestion needs so no WooCommerce call is made to render one |
| `csearch_terms` | `id` (PK), `term` (varchar 64, UNIQUE), `df` (int, the number of documents containing it) | The dictionary: every distinct token and stem |
| `csearch_postings` | `term_id`, `document_id`, `field` (tinyint: name 1, sku 2, short 3, long 4, category 5, tag 6, brand 7, attribute 8), `tf` (smallint), `first_pos` (smallint); PK (`term_id`, `document_id`, `field`); KEY (`document_id`) | The inverted index: which terms are in which document's fields, how often and where |
| `csearch_trigrams` | `trigram` (char 3), `term_id`; PK (`trigram`, `term_id`) | Typo candidates: a misspelt token shares most of its trigrams with the term it meant |
| `csearch_synonyms` | `id` (PK), `lang`, `word`, `means`, `both_ways` (tinyint), `created_by`, `created_at` | The owner's synonyms, applied to queries at search time |
| `csearch_log` | `id` (PK), `query` (varchar 200), `normalised` (varchar 200), `lang`, `results` (smallint), `clicked_type`, `clicked_id`, `source` (suggest, page), `created_at`; KEY (`created_at`), KEY (`normalised`) | One row per search, no shopper identity, purged after the retention period |

For Skatehouse's size (300 products, 1,650 variations, two languages) the postings table holds about 60,000 rows; for a 10,000-product shop about 2 million, which InnoDB handles in a few hundred megabytes with the primary key doing the work. Variations are folded into their parent's document: their SKUs and attribute values become postings on the parent, their stock flags roll up into `in_stock` (true when any purchasable variation is in stock), so a search for a width or a size finds the deck or the tee once.

**Product meta** (WooCommerce meta on the product, read by the indexer): `_csearch_boost` (an integer the owner sets on a product, -10 to 10) and `_csearch_exclude` (keep this product out of search). Both are shown on the product edit screen in a Search panel and exported with the product.

**Options:** `csearch_settings` (one array: what to index, which fields, the suggestion panel's layout, retention days, the languages), `csearch_db_version`, `csearch_index_state` (the running reindex's progress and the generation number the live index carries), `csearch_stopwords_{lang}` (the owner's additions to the built-in lists).

**Generations.** `csearch_documents` and `csearch_postings` carry a `generation` column; a full reindex writes generation N+1 beside N and switches `csearch_index_state.live` when complete, then deletes N in batches. The shop never searches a half-built index.

## Indexing

A product becomes one document per language, cut into tokens field by field, within 50 ms, in the background, within a minute of the change; nothing is indexed on the request that saved it.

**What goes in.** Products that are published and not excluded (visible or hidden from the catalogue; a hidden product is indexed with `visible` 0 so a search by SKU can still find it when the setting allows). Per product: name, SKU and every variation's SKU, short description and long description (shortcodes stripped, HTML stripped, 2,000 characters at most of the long one), the names of its categories (with parents), tags, brands, and every attribute value it or its variations carry (global terms and custom values alike), plus price range, stock flag, main image and URL for the suggestion row. Categories, tags and brands become documents of their own (name, description, count) so a suggestion can offer "Decks (122)" or "KAZUMI (33)".

**Cutting text into tokens** (`Domain\Tokenizer`, pure PHP, no WordPress): lower-case; fold accents (`é` → `e`, `œ` → `oe`) with a table, not `iconv`, so it is the same on every host; keep digits with their decimal point and inch mark as one token (`8.25`, `8.25″`, `54mm`); split on everything else that is not a letter or digit; drop tokens of one character except digits; drop stop words per language (built-in lists, plus the owner's); keep each token's position. A product named `Philly’s Skate Deck, Rascals` gives `phillys` (the apostrophe folded, with `philly` as a second token so both spellings match), `skate`, `deck`, `rascals`.

**Stems** (`Domain\Stemmer`): English by Porter, French by Snowball's French stemmer, both as vendored, tested PHP (no extension), chosen by the document's language; stored as a second posting with the same field and position so a search for `decks` finds `deck` and `roues` finds `roue`. The original token is always stored too: a stem is a help, never a replacement.

**Trigrams** (`Domain\Trigrams`): each term padded (`  deck `) and cut into overlapping threes; written once per term when the term is first seen. A misspelling shares most of them (`dekc` and `deck` share 2 of 5), which is how a typo finds candidates without scanning the dictionary.

**When.** WooCommerce's hooks, each queuing the product once per request, run by Action Scheduler as `csearch_index_product` (unique per product id): `woocommerce_new_product`, `woocommerce_update_product`, `woocommerce_new_product_variation`, `woocommerce_update_product_variation`, `woocommerce_delete_product_variation`, `woocommerce_trash_product_variation`, `woocommerce_product_set_stock`, `woocommerce_variation_set_stock`, `woocommerce_product_set_stock_status`, `woocommerce_variation_set_stock_status`, `set_object_terms` on product taxonomies, `wp_trash_post`, `untrashed_post` and `before_delete_post` for products, `edited_term` and `delete_term` for product taxonomies (re-queuing their products in batches). The CSV importer fires `woocommerce_product_import_inserted_product_object`, queued the same way. Under WP-CLI, indexing runs inline at shutdown, once per product. A stock-only change updates `in_stock` on the document row directly, without re-tokenising.

**The first build** is queued on activation (one Action Scheduler job, no work on the request), so the index fills within minutes of installing with nothing for the owner to find; activation itself only creates the tables and the defaults.

**Full reindex** (`csearch_reindex`): walks product ids 100 at a time in id order, one Action Scheduler job per batch, writing the next generation; categories, tags and brands at the end; then the switch and the cleanup. Progress is kept in `csearch_index_state` and shown on the Tools screen with a Cancel button; a reindex already running is not started twice. The old index serves throughout.

**Removal.** Trashed, deleted, drafted or excluded products lose their documents and postings in the same job; `df` on their terms is decremented; a term at `df` 0 keeps its row (dictionary rows are cheap and churn is not).

**Public hooks:** `csearch_document` (the array about to be written, with the product: add a field, change the title the suggestion shows, set `visible`), `csearch_index_product` (a boolean: index this product at all), `csearch_fields` (the field list and weights), `csearch_tokens` (the tokens cut from a field), `csearch_stop_words` (the list per language), `csearch_languages` (the languages to index, with how to read a product's text in each; skatehouse-core answers this one for its French fields).

## Matching and ranking

A query is cut like a field, each token is widened to the terms it may mean, the postings of those terms are joined per document, and every document gets a score the owner could follow with a pencil. `Domain\Query` (parsing and widening) and `Domain\Scorer` (the score) are pure PHP; the repository turns their plan into one SQL statement.

**Parsing.** The same tokeniser as indexing, then: synonyms applied (the owner's, per language, both ways where set, each adding an alternative term, never replacing the typed one); stop words dropped unless every token is one (then kept, so "the" still searches); a quoted phrase kept as a phrase; a minus word (`-blind`) excluded; a token of two or more characters at the end of the query, with no space after it, treated as a prefix. Queries are capped at 200 characters and 8 tokens; the rest is ignored, never an error.

**Widening each token, in this order, stopping at the first level that finds anything:**

1. The exact term, and its stem.
2. Prefix terms, for the unfinished last token: `term LIKE 'kaz%'`, at most 20 terms by `df`.
3. Typo candidates, for tokens of four or more characters: the terms sharing the most trigrams (at least half of the token's), then filtered by Damerau–Levenshtein distance, 1 for 4 to 7 characters, 2 for 8 or more, transpositions counted as one; at most 10 terms. A number is never fuzzed (`8.25` must not find `8.5`), nor a SKU-shaped token (letters and digits mixed).

**Combining tokens.** AND across tokens by default: a document must match every token (through any of its widenings). When that finds fewer than 3 documents and the query has 2 or more tokens, the search is repeated with the least-matched token dropped, once, and the suggestions say so ("showing results for kazumi deck"). OR is never silent.

**The score** of a document for a query, summed over its matched tokens:

```latex
score(d, q) = \sum_{t \in q} w_{field(t,d)} \cdot m_t \cdot \left(1 + \log(1 + tf_{t,d})\right) \cdot \log\frac{N + 1}{df_t + 1} + B_{phrase} + B_{exact} + B_{stock} + B_{owner}
```

- Field weights `w`: name 10, SKU 10, brand 6, category 5, attribute 5, tag 3, short description 2, long description 1 (`csearch_fields` filter). A token matched in several fields scores its best field once.
- Match weights `m`: exact 1.0, stem 0.9, synonym 0.8, prefix 0.7, typo at distance 1 0.5, distance 2 0.3.
- `tf` and `df` are the posting's count and the term's document frequency; `N` the number of documents in the language. The log terms keep a word repeated twenty times in a description from outranking a name.
- Phrase bonus `B_phrase`: +8 when the query's tokens appear in order and adjacent in one field (first positions compared), +4 when in order but not adjacent.
- Exactness `B_exact`: +12 when the whole query equals a document's name after folding; +6 when it equals its SKU.
- Stock `B_stock`: 0 for in stock, −6 for sold out (sold-out products still appear, below the ones that can be bought; the Stockroom's write-backs keep the flag right within the minute). Hidden products (`visible` 0) only appear for an exact SKU match, and only when the setting allows.
- Owner's boost `B_owner`: the product's `_csearch_boost`, −10 to 10, and the owner's boost rules (a category, brand or tag with a number).
- Ties break by `in_stock`, then by sales count (WooCommerce's `total_sales` meta, copied to the document), then by newest.

**Categories, tags and brands** score by their name alone (exact 12, prefix 7, typo 4), and never above a product with an exact name match. The suggestion panel shows at most 3 of them after the products.

**The results page** gets every matching product id in score order (capped at 500 for a page; a query matching more is already too broad to rank usefully), written into `WP_Query` as `post__in` with `orderby => 'post__in'` when the sort is Relevance (the default on a search, selectable in the sort menu as "Relevance"); any other sort keeps the match set and WooCommerce's ordering. WooCommerce's own `posts_search` clause is removed for product searches so the two do not disagree; non-product searches (pages, posts) are left to WordPress.

**Explain.** `wp csearch explain "kazumi deck 8.25"` prints each candidate with its tokens, fields, weights and bonuses, and the admin Analytics screen has the same for any logged query, so a ranking the owner questions has an answer.

## The search API

One public, read-only REST namespace, `csearch/v1`, answered from the index and the object cache, rate-limited, with a schema, so the block, the results page, WP-CLI and anyone building on the plugin get the same answers.

| Route | Returns | Notes |
| --- | --- | --- |
| `GET /csearch/v1/suggest?q=&lang=&limit=` | `{ products: [...], terms: [...], total, query, corrected }` | For the panel. `limit` 1 to 10 products (default 6) and up to 3 terms (categories, tags, brands). Each product: `id`, `name`, `url`, `image` (the `woocommerce_thumbnail` size, with `srcset`), `price_html`, `in_stock`, `type`; each term: `taxonomy`, `name`, `count`, `url`. `corrected` is the query after a dropped token, or null. |
| `GET /csearch/v1/search?q=&lang=&page=&per_page=&orderby=` | `{ ids: [...], total, page, pages, corrected }` | The ranked product ids a page of results needs. The results hook calls the service directly, not this route; the route exists for headless shops and for tests. `per_page` capped at 100. |
| `GET /csearch/v1/status` (manage_woocommerce) | the index's generation, document and term counts, the last reindex, the queue's backlog | For the Tools screen and monitoring |

**Behaviour.** Query strings are trimmed and capped at 200 characters; an empty `q` returns an empty body with 200, never an error; `lang` falls back to the site's language. Responses carry `Cache-Control: public, max-age=60` and an `ETag` of the index generation plus the normalised query, so a CDN or the browser can answer a repeat; the plugin's own object cache keeps the last 10,000 distinct queries for 60 seconds per generation (`csearch_cache_ttl` filter), cleared by generation number, never by scanning. A suggest call is one SQL statement against the index plus one against the documents table; no WooCommerce product objects are loaded.

**Rate limit.** 30 suggest requests per 10 seconds per client (IP, or the session cookie when WooCommerce has one), kept in the object cache with a sliding window, answered 429 with `Retry-After` beyond it; search 20 per 10 seconds. Settable through `csearch_rate_limit`; off under WP-CLI and in tests.

**Logging.** Every suggest and search call with a non-empty query writes one `csearch_log` row, in a deferred write after the response is sent (`shutdown`, or Action Scheduler when the request is a REST one with no shutdown guarantee), so logging never adds to the response time; clicks come in through `POST /csearch/v1/click` with the log row id and what was clicked, sent by the block with `sendBeacon` when a suggestion is chosen.

**Schema and errors.** Every route registers an `args` schema (type, sanitising, validation) and a response schema; errors are `WP_Error` with codes under `csearch_`, in the shop's language, never a stack trace. The routes are versioned in the namespace; a breaking change is `csearch/v2` with `v1` kept for a release.

## The front end

One block, `creative-search/search`, server-rendered with the Interactivity API: a form that works with no JavaScript (it submits to the shop's results page), and with it becomes the combobox the [ARIA Authoring Practices describe](https://www.w3.org/WAI/ARIA/apg/patterns/combobox/examples/combobox-autocomplete-list/): suggestions as you type, keyboard and screen reader parity, no framework, under 12 kB compressed.

**Markup (server-rendered, so the first paint needs no script).** A `form role="search"` with a visible or visually hidden label (a block setting), an `input` with `role="combobox"`, `aria-autocomplete="list"`, `aria-expanded`, `aria-controls` pointing at the panel, `aria-activedescendant` for the highlighted row, `autocomplete="off"`, `enterkeyhint="search"`, `inputmode="search"`; a submit button with a name; a clear button that appears with text; the panel as `ul role="listbox"` with `li role="option"` rows (each row's accessible name is the product's name, price and stock in words, "KAZUMI Skate Deck, Dealer, $84.99, in stock"); a visually hidden `aria-live="polite"` region that says "6 suggestions" or "No suggestions" after each answer; and a last option "See all 23 results for kazumi". Photos are `img` with the product's alt text, lazy, 2× for retina, and a fixed box so the panel never jumps.

**Keyboard**, as the pattern with the field written as you move (1.1, D-091): Down and Up move through rows and wrap through the typed text, and each move writes the highlighted row's name into the field (a product's, a category's or brand's, a page's) without fetching new suggestions; moving up past the first row restores what the shopper typed; hovering never writes. Enter opens the highlighted row, or searches the field's text once it has been edited; Escape restores the typed text and closes the panel, then clears, Home and End move the caret, Tab leaves the field and closes the panel, Alt+Down opens it. Focus stays in the input throughout; `aria-activedescendant` carries the highlight; the highlighted row scrolls into view; nothing is trapped.

**Behaviour.** Requests after 150 ms of quiet and 2 characters (`csearch_min_chars`), one in flight at a time, stale answers dropped by sequence number, the last 20 answers cached in memory for the page; the panel opens below the field (above it on a phone when the keyboard would cover it), closes on outside click, Escape or Tab, and reopens on focus with the last answer. Recent searches (the last 5, in `localStorage`, a setting, off by default for privacy) show on focus before typing. A slow answer shows a quiet "Searching…" after 400 ms, never a spinner that flashes. Errors and 429s fail silent: the field still submits. `prefers-reduced-motion` turns the panel's fade off. On a phone, a full-width panel with 48 px rows; on desktop, 44 px rows and 24 px targets at least.

**The results page.** WooCommerce's shop archive on `?s=`: unchanged template, the plugin's order, a "Relevance" entry first in the sort menu, the result count ("23 results for kazumi"), the corrected query line when a token was dropped ("Showing results for *kazumi deck*; search instead for *kazumi deck 9.5*"), and a no-results state with the three nearest categories and the owner's chosen fallback products. The Kadence search block in the header is replaced by this block (Zach's edit, two minutes); other themes place it with the block inserter, a `[creative_search]` shortcode or a widget, with a one-click option to take over the theme's search form (`get_search_form` and the `searchform.php` template) for classic themes.

**Styling.** CSS custom properties for every colour, radius and spacing, inheriting the theme's (`--wp--preset--color--*`) by default; no `!important`; dark themes work because nothing assumes white; logical properties for RTL. Block settings: label visibility, placeholder, number of suggestions, show prices, show stock, show categories and brands, recent searches, panel position.

**Testing the block:** jsdom for the state machine (typing, debounce, stale answers, keyboard moves, aria attributes); Playwright for the real thing on three engines, including a run with scripts blocked (the form still searches) and a run with a 2 s network delay; axe on every state (closed, open, no results, error).

## Admin

One menu entry, WooCommerce → Search, with four tabs, built with WordPress's own admin markup (settings API, list tables, the `wp.components` only where a list table cannot do it), every control labelled, every action a form with a nonce, every change answered with a notice that says what happened.

| Tab | Who | What it does |
| --- | --- | --- |
| Analytics | `manage_woocommerce` | The last 7, 30 or 90 days: searches a day (a chart), the top 50 queries with their result counts and click-through, the top 50 with no results (the owner's buying list), the top clicked products, suggestion versus results-page use, and an Explain button on any query. Export CSV. |
| Synonyms | `manage_woocommerce` | A table of synonyms per language: word, means, one way or both, who added it, when; add, edit, delete; import and export CSV; a test box that shows what a word expands to. Changes apply at once (synonyms are read at query time, no reindex). |
| Boosts and exclusions | `manage_woocommerce` | Rules that add a number to a category, brand or tag; the list of excluded products; the "no results" fallback products; the hidden-products-by-SKU setting. |
| Tools | `manage_woocommerce` | Index status (generation, documents, terms, last build, queue backlog), Rebuild index with progress and Cancel (the first build runs by itself on activation), a Test search box with the ranked answer and its explanation, stop words per language, retention days, what to index (long descriptions on or off, tags, variations' SKUs), the block's defaults. |

**Product editor.** A Search panel on the product edit screen (and in the variation panel for SKUs): the product's boost (−10 to 10), Exclude from search, and "Last indexed 2 minutes ago" with a Reindex now link. The same two fields appear as columns in the CSV importer and exporter under `Meta:` so a catalogue import carries them.

**Notices.** A persistent, dismissible notice when the index is empty or more than 10 % of products are missing from it (with the Rebuild link), when the queue has not run for 15 minutes (the cron is not firing), and when a reindex finished with errors (which products, why).

**WP-CLI** (`wp csearch`): `status`, `reindex [--inline]`, `index <id>...`, `search <query> [--lang] [--format]`, `explain <query>`, `synonyms list|add|delete|import|export`, `log purge [--days]`, `cache flush`. Every command has `--help`, exits non-zero on failure and prints JSON with `--format=json`.

**Uninstall** removes the six tables, every option, the product meta, the scheduled actions and the cache group; deactivation leaves everything in place.

## Analytics and privacy

The log answers the owner's questions (what people want, what they cannot find, what they click) and holds nothing about who asked: no IP address, no user id, no session id, no user agent, no page URL, and the query itself trimmed, lower-cased and capped at 200 characters.

- **Kept:** the query as typed and as normalised, the language, the result count, the source (suggestions, results page, or, from 1.7.0, another plugin that asks for a ranking from PHP and names a source: `builder`, a source it registered, or `other`; such searches are logged as the shop's are, with nothing more about who asked, and stay out of the shop's own counts and suggested searches, D-163), the clicked suggestion's type and id, and the time to the minute.
- **Never kept:** anything that identifies the shopper or the device. A query that looks like an email address, a phone number, a card number or a postal code (patterns in `Domain\Scrub`, tested) is logged as `[redacted]` so a shopper typing their own details into the wrong box leaves no trace.
- **Retention:** 90 days by default (a setting, 7 to 365), purged nightly by `csearch_purge_log` in batches of 1,000; aggregates for the Analytics charts are rolled up daily into `csearch_settings['stats']` (counts only) so the charts survive the purge.
- **Rate-limit counters** live in the object cache for 10 seconds, keyed by a hash of the IP, never written to the database.
- **Recent searches** in the block are the shopper's own, in their browser's `localStorage`, off by default and cleared with the clear button.
- **Consent:** nothing here needs a consent banner under Quebec's Law 25 or PIPEDA, because nothing is personal; the privacy policy gets one sentence (the plugin ships it for the Privacy Policy Guide: "This site keeps anonymous search terms for 90 days to improve its search"), and the WordPress privacy exporter and eraser have nothing to find, which is stated in the plugin's privacy section.
- **Admins see** aggregates and individual queries, never a shopper; the CSV export carries the same columns as the table.

## French and other languages

The plugin is built for a shop in more than one language from the first line: every document has a `lang`, every query is answered in one, and the words the shopper sees come from the shop's translations.

- **Languages to index** come from the `csearch_languages` filter: a list of codes, each with a callback that returns a product's text in that language (name, descriptions, term names). The default is the site's language read from the product itself. skatehouse-core answers with `en` and `fr`, reading its French fields; a WPML or Polylang site answers with its translated product ids. No multilingual plugin is required or assumed.
- **Query language** comes from the block (the page's `lang`), the `lang` parameter, or the site default; a French page searches the French documents and, when they find nothing, the English ones (a setting, on by default, because a brand name is the same in both).
- **Per language:** stop words (English and French lists built in, the owner adds), stemmer (Porter for English, Snowball French), synonyms (each row has a language), and the suggestion panel's words ("in stock", "See all results") through the text domain, with Canadian French (`fr_CA`) shipped in `languages/`.
- **Accents** fold for matching in every language (a shopper types `québécois` or `quebecois`, both find the deck), but the suggestion shows the name as written.
- **Numbers and prices** in a suggestion come from `wc_price()`, so the French page shows `84,99 $`.
- **Admin screens** are translatable like the rest; the Analytics tab shows each query with its language.
- **RTL** languages work in the block (logical properties, no left/right in the CSS) and the admin.

## Security, performance and compatibility

**Security.** The REST routes are the plugin's only public surface and they read. Every input is typed by the route schema and sanitised (`sanitize_text_field`, length caps, allow-lists for `lang`, `orderby` and `type`); every SQL statement is `$wpdb->prepare()`d, with identifier lists built from integers only; every admin action checks a nonce and `manage_woocommerce`; WP-CLI commands check `--allow-root` conventions and nothing else because the shell is already trusted; output is escaped at the sink (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` for price HTML); no user data is serialised; the log never stores identity; the rate limit blunts scraping and abuse; no file is written; no remote request is made, ever. A `docs/SECURITY.md` checklist (OWASP's top ten mapped to WordPress) is walked before each tag, and the two CI grep gates fail on string-built SQL and on the banned functions.

**Performance** (each a test in CI against a generated 10,000-product, 40,000-variation catalogue on a 2-vCPU runner):

| What | Budget |
| --- | --- |
| `/suggest` server time, p95, warm cache | 60 ms |
| `/suggest` server time, p95, cold | 100 ms |
| `/search` for a results page, p95 | 100 ms |
| Indexing one product (tokenise and write) | 50 ms |
| Full reindex of 10,000 products | 5 minutes, in jobs of 100 |
| Extra queries on a product save request | 0 (the job is queued only) |
| Extra queries on a results page | 1 (the ranked ids) |
| The block's script | 12 kB compressed; its CSS 4 kB; both deferred, no render blocking |
| Memory for a suggest call | 4 MB above WordPress's baseline |

How: the postings primary key `(term_id, document_id, field)` answers a term lookup from the index alone; the documents table carries everything a suggestion renders; the object cache holds answers by generation; Action Scheduler runs indexing off the request; no product objects are loaded on the search path; the lookup for typo candidates is bounded (trigram rows for the token, grouped and limited) and skipped for short tokens; `EXPLAIN` output for the four search statements is kept in `docs/QUERIES.md` and checked when the schema changes.

**Compatibility.** Tested in the wp-env matrix on the oldest and newest supported WordPress and WooCommerce on PHP 8.5, MySQL 8.0 and MariaDB 10.6; with the Kadence, Storefront and Twenty Twenty-Five themes; with HPOS on and off (the plugin never touches orders); with and without a persistent object cache (transients fall back); with WooCommerce's Product Filters blocks (the ranked set and the filters compose: filters narrow, relevance orders); with Creative Stockroom (its stock writes reach the index through WooCommerce's stock hooks within the minute); with skatehouse-core's colour cards (a product listed as itself is one document; a product listed by colour is still one document, its cards are a presentation matter); with Mature Content for WooCommerce (a flagged product's suggestion shows its blurred image and the same consent the shop uses, through a hook the plugin provides and skatehouse-core answers); with the classic `searchform.php` and the block header; multisite-safe (tables per site, uninstall per site). Known non-goals: Elasticsearch and external engines, WooCommerce versions before 10.0, WordPress 6.x, PHP 8.4 and earlier.

## Testing and quality

Five suites, each with a reason to exist, all green before a milestone closes; the floors are in the Quality bar above.

| Suite | Runs against | Covers |
| --- | --- | --- |
| Unit (PHPUnit, no WordPress) | `src/Domain`: Tokenizer, Stemmer (English and French word lists from the Snowball test vectors), Trigrams, Query, Scorer, Scrub | Every rule with a table of cases: accents, apostrophes, numbers and inch marks, SKUs, stop words, phrase bonus, each match weight, each bonus, tie-breaks, the redaction patterns; property-based tests for the tokeniser (any string tokenises without error, idempotent on its own output) |
| Integration (PHPUnit, wp-env) | Repositories, Indexer, the hooks, the REST routes, the results hook, admin actions, WP-CLI, uninstall | A real schema: `dbDelta` twice is a no-op; a product saved is indexed within the job; every hook in the Indexing section queues exactly once; stock changes flip `in_stock` without re-tokenising; a reindex builds the next generation and switches; the routes' schemas, caps, rate limit and cache headers; the results hook's `post__in` and the sort menu; nonces and capabilities refused; uninstall leaves nothing |
| Browser (Playwright, three engines) | The block on a built shop | The APG keyboard script step by step; `aria-*` after each step; the live region's words; a touch run on a phone viewport; scripts blocked; a 2 s network delay; the results page's Relevance sort and corrected-query line; axe on every state and every admin tab |
| Mutation (Infection) | `src/Domain` at 100 % MSI, the rest at 90 % | Each run's escaped mutants listed in the pull request with the reason they are acceptable, or a test added |
| Benchmarks (PHPUnit groups, CI job) | A generated catalogue of 10,000 products and 40,000 variations | Every budget in the Performance table, failing the build when missed; the numbers appended to `docs/BENCHMARK.md` per tag |

**Fixtures.** A generator (`tests/fixtures/catalogue.php`) builds catalogues of any size with realistic names (brands, series, widths, colours, SKUs, French names) so the same tests run at 300 and at 10,000 products. A 50-query golden set (`tests/fixtures/queries.yml`: query, expected top result, expected suggestions) is checked on every build; changing an expectation needs a reason in the commit.

**Accessibility, by hand.** `docs/A11Y.md` scripts a 15-minute pass with VoiceOver on Safari and NVDA on Firefox: open the shop, tab to the search, type, arrow through suggestions, hear the count, pick one, go back, clear, submit with Enter, use the results page's sort; then each admin tab by keyboard alone. Run and recorded per release by someone who did not write the code.

**Review.** Every milestone's pull request carries the seven-dimension review, the escaped mutants, the benchmark numbers and the Plugin Check output; Zach merges.

## Milestones for Claude Code

*(Drawing in the Claude doc: milestones · eight in order, a gate on each.)*

Each milestone is a pull request with its tests, its benchmark numbers where it has them, its review against the matrix and a changelog entry; it ends in a tag (`v0.1.0` to `v0.8.0`, then `v1.0.0`). The order puts the pure domain and the index before anything a shopper sees, so the block and the admin are built on numbers that already hold.

| # | Milestone | Builds | Tests that must pass | Done when |
| --- | --- | --- | --- | --- |
| 1 | Foundation | Plugin skeleton (bootstrap, PSR-4, container), `Install\Schema` with the six tables, settings store and defaults, uninstall, `.wp-env.json`, CI (lint, PHPStan 9, PHPUnit, Infection, Plugin Check, Playwright, axe, benchmarks), the catalogue generator and the golden-query fixture | Schema created and upgraded (`dbDelta` twice is a no-op), uninstall leaves nothing, CI green on every gate against an empty plugin | Plugin Check clean; a 10,000-product fixture generates in under a minute |
| 2 | Domain | `Domain\Tokenizer`, `Stemmer` (Porter, Snowball French), `Trigrams`, `Query` (parse, synonyms, widening plan), `Scorer`, `Scrub`; no WordPress imports | The case tables for every rule; Snowball's test vectors; property tests; the golden set scored in memory against a hand-built index | 100 % line, branch and mutation coverage on `src/Domain` |
| 3 | Index | Repositories for documents, terms, postings, trigrams; `Indexer`; the hook set; Action Scheduler jobs; generations and the switch; `wp csearch index`, `reindex`, `status` | Each hook queues once; a save request runs 0 extra queries; stock flips without re-tokenising; the reindex builds N+1 and switches; cancel; removal decrements `df` | 10,000 products reindexed in under 5 minutes on the CI runner; one product in under 50 ms |
| 4 | Query and API | The search repository (the widening plan to one SQL statement), the cache, the rate limiter, `/suggest`, `/search`, `/status`, the log writer, `explain` | Route schemas, caps, 429s, cache headers, the deferred log write, redaction; the golden set through the API | p95 60 ms warm and 100 ms cold on `/suggest`, 100 ms on `/search`, 4 MB |
| 5 | Results page | The results hook (`post__in`, Relevance sort, `posts_search` removed for products), the count, the corrected-query line, the no-results state and fallbacks | Integration tests on the archive query; Playwright on the results page with Kadence and Storefront; the Product Filters blocks composing with the ranked set | One extra query per results page; e2e green on the shop |
| 6 | Search block | `creative-search/search` (Interactivity API), server render, the panel, the live region, click beacon, recent searches, shortcode, widget, theme-form takeover, styles | jsdom state machine; Playwright on three engines (keyboard script, scripts blocked, slow network, phone); axe on every state | 12 kB compressed; axe clean; the APG script passes on three engines |
| 7 | Admin | The four tabs, the product panel, importer and exporter columns, notices, `wp csearch synonyms`, `log`, `cache`, CSV import and export | Nonces and capabilities refused; list tables paginate; synonyms apply at once; the Analytics numbers match the log; a CSV round trip | axe clean on every tab by keyboard alone |
| 8 | Languages and 1.0 | `csearch_languages`, French stop words and stemming end to end, `fr_CA` translations, the fallback to English, `docs/` complete (HOOKS, SETTINGS, SECURITY, A11Y, BENCHMARK, QUERIES, DECISIONS), readme.txt, the manual accessibility pass, the FiboSearch benchmark on a copy of Skatehouse | A French run of the browser tests; the matrix on all supported versions; every gate | `v1.0.0` tagged; Plugin Check clean; the benchmark written up |

**After 1.0 (not in scope, noted so the design leaves room):** posts and pages in results (1.1), a "did you mean" from the log's own corrections, per-category boosts learned from clicks, an Elasticsearch adapter behind the index-store interface for shops past 100,000 products, and the Skatehouse builder's parts search.

## Open questions

For Zach, before milestone 1:

- [ ] Name and repo: `creative-search` under `web-projects`, like the Stockroom? The namespace and prefix above follow from it.
- [ ] Licence and sale: GPL-2.0-or-later like the rest (it must be, for WordPress.org); sold as a product with support, or kept to the agency's shops for 1.0?
- [ ] Posts and pages in 1.0 or 1.1? The spec says 1.1; the index is ready for it either way.
- [ ] Recent searches in the block: off by default (privacy) or on (convenience)?
- [x] The FiboSearch benchmark: one Pro Personal licence ($59) for a day on a copy of the shop, or benchmark against the free version only? **Decided 21 October 2026: no licence. Documented behaviour from FiboSearch's public pages plus a measured run against the free version (D-005).**

For the owner, before milestone 7:

- [ ] Synonyms to seed: the words his customers use that the catalogue doesn't ("board" for deck, "wheels" sizes, brand nicknames).
- [ ] Boosts: which brands or categories should rise when a search is ambiguous (Philly's first?).
- [ ] Hidden products: should a search by exact SKU find a hidden product (dealer-only lines)?
- [ ] No-results fallbacks: which products or categories to show when nothing matches.
- [ ] Retention: 90 days of anonymous search terms, or longer for seasonal patterns?

Decided here, so Claude Code does not have to: AND across tokens with one dropped-token retry; numbers and SKUs never fuzzed; sold-out products shown below in-stock ones, never hidden; one document per product with variations folded in; the shop's own results template kept; no external service; the APG list-autocomplete pattern for the combobox; 90-day retention; six tables, no JSON columns; generations for reindexing.

**Sources opened for this spec (21 October 2026):** [FiboSearch pricing](https://fibosearch.com/pricing/) and its [WordPress.org page](https://cs.wordpress.org/plugins/ajax-search-for-woocommerce/); [YayCommerce's plugin comparison](https://yaycommerce.com/?p=53914) (updated 26 May 2026); [Elegant Themes' round-up](https://www.elegantthemes.com/blog/wordpress/best-wordpress-search-plugins) (updated 13 January 2025); [Motive's WooCommerce search guide](https://motive.co/woocommerce-search); the [ARIA Authoring Practices combobox example](https://www.w3.org/WAI/ARIA/apg/patterns/combobox/examples/combobox-autocomplete-list/); WooCommerce 11.2.1's `FilterData` and `Filterer` source as mirrored on [wp-kama](https://wp-kama.com/plugin/woocommerce/function/FilterData::get_attribute_counts).
