# Benchmarks

The spec's budgets are tests: a build fails when one is missed. The numbers from each tag are appended here. The FiboSearch comparison is added in milestone 8 in two parts (D-005): a documented-behaviour matrix built from FiboSearch's public feature pages, documentation and changelog (fuzzy rules, synonyms, analytics, index design, reliability figures, each row with its source and date), and a measured run of the same 50 queries, their timing, ranking and suggestions against FiboSearch **free** on a copy of Skatehouse. No Pro licence is bought and there is no public Pro demo, so Pro's own suggestions and timings on our catalogue are not measured; every row says whether it is documented or measured.

## Budgets (spec: Performance)

| What | Budget | Suite |
| --- | --- | --- |
| `/suggest` server time, p95, warm cache | 60 ms | integration, milestone 4 |
| `/suggest` server time, p95, cold | 100 ms | integration, milestone 4 |
| `/search` for a results page, p95 | 100 ms | integration, milestone 4 |
| Indexing one product (tokenise and write) | 50 ms | integration, milestone 3 |
| Full reindex of 10,000 products | 5 minutes, in jobs of 100 | integration, milestone 3 |
| Extra queries on a product save request | 0 | integration, milestone 3 |
| Extra queries on a results page | 1 | integration, milestone 5 |
| The block's script | 12 kB compressed; CSS 4 kB | build, milestone 6 |
| Memory for a suggest call | 4 MB above baseline | integration, milestone 4 |
| Generating the 10,000-product fixture | 60 s | unit, `composer test:benchmark`, milestone 1 |

## v0.1.0

Machine: Apple Silicon Mac, PHP 8.5.4 CLI (CI: ubuntu-latest, PHP 8.4).

| What | Result |
| --- | --- |
| Generating 10,000 products (48,482 variations) as arrays | 0.4 s |

## v0.4.0 and v0.6.0

Machine: Apple Silicon Mac, wp-env (PHP 8.5, WordPress 7.1.3, WooCommerce 11.2.1, MariaDB in Docker). Times are measured around the work itself (`rest_do_request()`, the reindex loop), without WordPress's bootstrap. The 10,000-product runs are the CI job `index-budgets`; they were not run on this Mac because loading 10,000 products with their variations through WooCommerce takes longer than the reindex (D-047).

| What | Result | Budget |
| --- | --- | --- |
| Full reindex, 300 products in two languages | 1.24 s, 4.1 ms a product | 9 s (scaled) |
| Full reindex, 2,000 products | 9.06 s, 4.5 ms a product; 113,591 postings, 13,732 terms | 60 s (scaled) |
| Full reindex, 10,000 products | not measured here; about 45 s extrapolated from the flat rate | 5 minutes |
| Indexing one product (a 22-variation shoe, a 12-variation tee, a deck, a complete) | under 50 ms each (asserted) | 50 ms |
| Extra queries on a product save request | 0 (asserted with a query spy) | 0 |
| `/suggest` p95, cold (cache flushed before each of the 50 golden queries, 300 products, two languages) | 6.3 ms | 100 ms |
| `/suggest` p95, warm | 0.4 ms | 60 ms |
| `/search` p95 | 5.6 ms | 100 ms |
| Memory, p95 of a cold suggest | 0.55 MB | 4 MB |
| Extra queries on a results page, warm | 1 (asserted, every table counted) | 1 |
| Extra queries on a results page, cold | 15 to 18 (D-055) | 1 |
| The block's script | 2.6 kB compressed (6.0 kB minified) | 12 kB |
| The block's CSS | 1.1 kB compressed | 4 kB |

## v1.0.0

Same machine and environment as v0.6.0. Milestone 8 changed no ranking; the 300-product numbers below were taken after the two read fixes in D-085, and the golden set still passes 50 of 50 through the API.

| What | Result | Budget |
| --- | --- | --- |
| Full reindex, 300 products in two languages | 1.35 s, 4.5 ms a product; 351 documents, 16,951 postings, 2,426 terms | 9 s (scaled) |
| `/suggest` p95, cold (50 golden queries, 300 products, two languages) | 4.6 ms | 100 ms |
| `/suggest` p95, warm | 0.3 ms | 60 ms |
| `/search` p95 | 4.0 ms | 100 ms |
| Memory, p95 of a cold suggest | 0.44 MB | 4 MB |
| Generating the 10,000-product fixture (48,482 variations) as arrays | 0.40 s | 60 s |
| The block's script | 2.6 kB compressed | 12 kB |
| The block's CSS | 1.1 kB compressed | 4 kB |
| Indexing one product (a 22-variation shoe, a 12-variation tee, a deck, a complete) | under 50 ms each (asserted, green) | 50 ms |
| Extra queries on a product save request | 0 (asserted with a query spy, green) | 0 |
| Extra queries on a results page, warm | 1 (asserted, green) | 1 |
| Every search statement has a key (`QueriesExplainTest`) | yes (docs/QUERIES.md) | — |

### At 10,000 products (D-085, D-086, D-087)

The spec's size: 10,000 generated products with their variations, English and French, loaded through WooCommerce's CRUD (loading takes between 44 minutes and 3 hours on this Mac, depending on what else runs; the test-database run is `CSEARCH_BENCH_PRODUCTS=10000 composer test:integration -- --group benchmark`). The first two columns were measured in the test database on 2026-10-22; the last on the development site's committed 10,000-product catalogue the same day, with the same 50 golden queries through `rest_do_request()`, three passes (`tmp/api.php`, not shipped), after products were ranked in the database (D-086) and the term counts stored and the postings keyed by language and kind (D-087).

| What | First run | After D-085 | After D-086 and D-087 | Budget |
| --- | --- | --- | --- | --- |
| Full reindex, 10,000 products | 99.9 s, 10.0 ms a product | not re-run | 224.8 s for `wp csearch reindex --inline`, which also deleted the previous 1.1 million postings | 300 s |
| `/suggest` p95, cold (50 golden queries) | 213.6 ms | 81.4 ms | **44.3 ms** | 100 ms |
| `/suggest` p95, warm | 0.5 ms | 0.4 ms | 0.4 ms | 60 ms |
| `/search` p95 | 209.2 ms | 76.9 ms | **43.2 ms** | 100 ms |
| Memory, p95 of a cold suggest | 14.45 MB | 8.41 MB: missed | **1.69 MB** | 4 MB |

The first run shared the machine with a second 10,000-product load, so its times are high; its memory is not affected by that. The golden set's expectations are checked at 300 products only (D-032). The search's own work (`SearchService::run()`, `tmp/profile.php`) has a p95 of 51.1 ms and 0.38 MB over the golden set; its slowest query is the nine-word one, at 134.5 ms, nearly all of it the ranking statement over the products that carry "deck" and "skate".

The index at this size, both languages: 20,104 documents, 1,144,797 postings, 64,171 terms and 127,157 term counts. On disk (data and keys): postings 292.6 MB, term counts 10.5 MB, documents 8.1 MB, terms 8.0 MB, trigrams 3.5 MB. The spec estimated "a few hundred megabytes".

On MySQL 8.0 (the `csearch-mysql80` leg, 300 products), where CI had measured 227 ms for `/suggest` cold before D-086: 39.3 ms cold, 0.5 ms warm, `/search` 35.6 ms, 0.32 MB.

## v1.1.0

Same machine. One test (`CatalogueBenchmarkTest`) now loads the catalogue once for both measurements and logs each phase's time: PR #1's 10,000-product CI job spent over an hour loading the catalogue twice, once per test. The API is now measured with pages and posts switched on (a handful per hundred products, worded like a skate shop's), so each cold `/suggest` makes the content pass too (D-093).

| What (300 products) | Result | Budget |
| --- | --- | --- |
| Loading the catalogue through WooCommerce | 22.7 s | none (setup) |
| Full reindex, English | 1.43 s, 4.8 ms a product | 9 s (scaled) |
| `/suggest` p95, cold, two languages, pages and posts on | 6.5 ms (1.0: 4.4 ms, pages off) | 100 ms |
| `/suggest` p95, warm | 0.4 ms | 60 ms |
| `/search` p95 | 6.0 ms | 100 ms |
| Peak memory p95 | 0.30 MB | 4 MB |

## v1.0.0: the FiboSearch comparison (D-005, D-081)

Two parts, as D-005 decided: what FiboSearch documents, and a measured run against FiboSearch **free**. No Pro licence was bought and there is no public Pro demo, so nothing here measures Pro.

**Not yet done: the run on a copy of Skatehouse.** The spec asks for the measured run on a copy of the real shop. That copy needs Zach. The run below is an interim one, on the generated 300-product catalogue (`tests/Fixtures/Catalogue.php`, the heroes plus generated products, English and French), in wp-env on the same Mac, on 2026-10-21. It should be repeated on the Skatehouse copy with the shop's own top queries before the comparison is quoted to anyone.

**The harness for the Skatehouse run (1.2, D-103).** Put the export in `tests/fixtures/skatehouse/` (the products CSV from WooCommerce's exporter, all columns; the pages from Tools → Export), then load it into the development site, where FiboSearch can be installed beside the plugin:

```sh
npx wp-env run cli -- wp eval 'use CreativeBranding\Search\Tests\Support\SkatehouseCopy; print_r( SkatehouseCopy::loadProducts( SkatehouseCopy::productsFile() ) ); print_r( SkatehouseCopy::loadPages( SkatehouseCopy::pagesFile() ) );'
npx wp-env run cli -- wp csearch reindex --inline
```

`composer test:skatehouse` runs the handoff's golden queries on the same files in the test site. The FiboSearch run then repeats the method below with the shop's own top queries.

### Measured: the 50 golden queries against FiboSearch free 1.34.3

FiboSearch free 1.34.3 was installed from wordpress.org beside Creative Search and set to the most it can do for free: search in SKU, short description and description, show categories and tags. Each of the 50 queries in `tests/Fixtures/queries.yml` went to both engines over HTTP (`/wp-json/csearch/v1/suggest` and `/?wc-ajax=dgwt_wcas_ajax_search`), four times each with 0.4 s between requests. Each answer was graded against the query's expectations: the right product first, the expected products and terms present, the excluded ones absent, nothing for a query that must find nothing. Creative Search's `hidden_by_sku` was turned on for the one query that tests it. FiboSearch was removed afterwards. The script and the raw answers are not shipped; the method is here so it can be repeated.

**A bias to keep in mind.** The golden set was written to describe Creative Search's rules (typos, stems, synonyms, minus words, French). FiboSearch free does none of those by design, so the pass rate measures how far the free version is from what this plugin promises. It does not show how good FiboSearch is at what it does. FiboSearch free is a floor, not a peer (D-005).

| What | Creative Search | FiboSearch free |
| --- | --- | --- |
| Golden queries passed | 50 of 50 | 14 of 50 |
| Whole request, first ask, median / p95 | 103 ms / 107 ms | 90 ms / 105 ms |
| Whole request, asked again, median / p95 | 100 ms / 106 ms | 90 ms / 97 ms |

**Times.** These are whole HTTP requests on wp-env (PHP 8.5, WordPress 7.1.3, WooCommerce 11.2.1, no persistent object cache), and they are dominated by WordPress's own start-up. On the same machine a REST request that does nothing but refuse (`/csearch/v1/status` without a login) takes 75 to 90 ms, and WooCommerce's own `wc-ajax` fragment request 63 to 82 ms. Creative Search's own work on a suggestion is the 6 ms cold / 0.4 ms warm measured above (v0.4.0 and v0.6.0) without the start-up. The REST bootstrap costs Creative Search about 10 ms a keystroke against FiboSearch free's `wc-ajax` endpoint. FiboSearch Pro documents a SHORTINIT path, which skips most of the start-up, so it is very likely faster per keystroke than both. A lighter request path is a follow-up for after 1.0, not done here.

| Query kind | Queries | Creative Search | FiboSearch free |
| --- | --- | --- | --- |
| exact | 2 | 2 | 1 |
| phrase | 4 | 4 | 3 |
| apostrophe | 1 | 1 | 0 |
| stock | 3 | 3 | 2 |
| prefix | 5 | 5 | 2 |
| typo | 6 | 6 | 1 |
| number | 2 | 2 | 0 |
| attribute | 4 | 4 | 2 |
| sku | 2 | 2 | 1 |
| variation-sku | 1 | 1 | 0 |
| hidden | 1 | 1 | 0 |
| category | 1 | 1 | 0 |
| stem | 1 | 1 | 0 |
| synonym | 2 | 2 | 0 |
| stopword | 2 | 2 | 0 |
| brand | 1 | 1 | 0 |
| accent | 1 | 1 | 0 |
| tag | 1 | 1 | 0 |
| minus | 2 | 2 | 0 |
| drop-token | 1 | 1 | 0 |
| zero | 1 | 1 | 1 |
| cap | 1 | 1 | 0 |
| french | 4 | 4 | 0 |
| fallback | 1 | 1 | 1 |

What FiboSearch free got wrong, query by query (its first three product suggestions shown as SKUs):

| Query | What failed | FiboSearch free's first products |
| --- | --- | --- |
| `kazumi skate deck dealer` | term KAZUMI, term Decks | KAZ-DEA-00001 |
| `philly's rascals` | term Philly's | PHL-RAS-00002 |
| `phillys horror party` | top, missing PHL-HPR-00003 | — |
| `kazumi deck` | term KAZUMI, term Decks | KAZ-DEA-00001, KAZ-GTO-00022, KAZ-SDM-00237 |
| `kaz` | top, term KAZUMI | KAZ-WEL-00140, KAZ-RCP-00145, KAZ-SLO-00023 |
| `phil` | top, missing PHL-HPR-00003, term Philly's | PHL-HED-00183, PHL-RCY-00100, PHL-DSS-00139 |
| `hoo` | top, missing SKH-SLO-00012 | KEL-AML-00090, KEL-LQT-00170, KEL-CBP-00160 |
| `kazumy` | top, missing KAZ-DEA-00001, term KAZUMI | — |
| `dekc` | top, missing KAZ-DEA-00001, term Decks | — |
| `horor party` | top, missing PHL-HPR-00003 | — |
| `bearrings` | top, missing KEL-SEV-00010, missing KEL-SWI-00009, term Bearings | — |
| `griptape perforrated` | top, missing MNL-PER-00016 | — |
| `8.25` | missing KAZ-DEA-00001, missing PHL-RAS-00002, missing PHL-HPR-00003 | RSC-ESR-00272, SKH-POC-00253, CND-NLO-00241 |
| `rascals 8.125` | top | RSC-RBY-00152, RSC-GTA-00092, RSC-SBA-00005 |
| `54mm` | top, missing ORB-CLA-00007 | KAZ-WEL-00140, KEL-AML-00211, FER-WGN-00167 |
| `trucks 149` | top | KEL-LPT-00283, KEL-GVL-00226, FER-HFL-00006 |
| `kaz-dea-00001-825` | top, missing KAZ-DEA-00001 | — |
| `KAZ-DEA-00002` | top, missing KAZ-DEA-00001 | — |
| `KAZ-DBA-00021` | top, missing KAZ-DBA-00021 | — |
| `decks` | top, missing KAZ-DEA-00001 | — |
| `bearing` | top | KEL-SWI-00009, KEL-SEV-00010, ORB-GLA-00197 |
| `kazumi board` | top, missing KAZ-DEA-00001, missing KAZ-GTO-00022 | — |
| `skatehouse tee` | top, missing SKH-SLO-00011 | — |
| `the deck` | top, missing KAZ-DEA-00001, term Decks | KAZ-NTR-00076, FER-NRR-00029, SKH-NKR-00156 |
| `the` | expected nothing | RSC-NSR-00131, SKH-NHR-00260, PHL-NCR-00120 |
| `quebecois` | term Québécois | QBC-FLE-00013, QBC-ISO-00298, QBC-FLD-00199 |
| `québécois` | term Québécois | QBC-FLE-00013, QBC-ISO-00298, QBC-FLD-00199 |
| `montreal` | top, missing QBC-FLE-00013 | CND-HTA-00128, CND-SVL-00200, HLF-YDL-00250 |
| `kazumi -dealer` | top, missing KAZ-SLO-00023 | — |
| `orbit wheels -cruiser` | top, missing ORB-CLA-00007, missing ORB-CLA-00024 | — |
| `kazumi dealer snowboard` | top, missing KAZ-DEA-00001 | — |
| `dealer skate deck kazumi maple popsicle canada ships across quebec extra words beyond eight` | top, missing KAZ-DEA-00001 | — |
| `planche kazumi` | top, missing KAZ-DEA-00001, term KAZUMI, term Planches | — |
| `roues 54mm` | top, missing ORB-CLA-00007, term Roues | — |
| `roulement` | top, missing KEL-SEV-00010, missing KEL-SWI-00009, term Roulements | — |
| `chandail capuchon` | top, missing SKH-SLO-00012, term Chandails à capuchon | — |

### Documented: FiboSearch free and Pro against Creative Search 1.0

Every FiboSearch cell comes from a public page opened on 2026-10-21. Nothing in the FiboSearch columns was measured. The Creative Search column was checked against the code at v0.7.0; milestone 8 adds no search feature. One correction to the spec follows from it: the competitor table in docs/SPEC.md says FiboSearch free has no analytics, and FiboSearch's own blog says analytics are "free for both FiboSearch Free and Pro". The spec is Zach's, so the correction is flagged for Zach, not made here.

Short source keys used in the last column (all opened 2026-10-21):

- **[pricing]** https://fibosearch.com/pricing/
- **[wporg]** https://wordpress.org/plugins/ajax-search-for-woocommerce/ (readme and the free changelog, stable 1.34.3, dated October 8, 2026)
- **[gopro]** https://fibosearch.com/should-i-go-pro-fibosearch-free-vs-pro/
- **[fuzzy]** https://fibosearch.com/documentation/features/fuzzy-search/ (modified 2023-04-11)
- **[syn]** https://fibosearch.com/documentation/features/synonyms/ (modified 2025-01-15)
- **[an-doc]** https://fibosearch.com/documentation/features/fibosearch-analytics/ (modified 2025-06-17)
- **[an-blog]** https://fibosearch.com/fibosearch-presents-analytics/
- **[fs2]** https://fibosearch.com/fibosearch-2-0-is-on-the-way/ (published 2026-04-27, modified 2026-07-15)
- **[idx-fail]** https://fibosearch.com/documentation/troubleshooting/the-search-index-could-not-be-built/
- **[ml]** https://fibosearch.com/documentation/plugins-integrations/multilingual-plugins/
- **[cl-pro]** https://fibosearch.com/changelog/
- **[details]** https://fibosearch.com/feature/details-panel/
- **[extra-text]** https://fibosearch.com/documentation/tips-tricks/how-to-display-extra-text-in-search-suggestions/
- **[privacy]** https://fibosearch.com/privacy-policy/
- **[tr]** https://translate.wordpress.org/api/projects/wp-plugins/ajax-search-for-woocommerce/stable
- **[svn]** https://plugins.svn.wordpress.org/ajax-search-for-woocommerce/trunk/fs/config.php

| Feature | FiboSearch free | FiboSearch Pro | Creative Search 1.0 | Source (FiboSearch) |
| --- | --- | --- | --- | --- |
| Live suggestions: photo, price | Yes. Image, price, SKU and description can be shown in suggestions. | Same as free ("look and feel exactly the same on the front-end"). | Yes. Photo (`woocommerce_thumbnail` with srcset, fixed box), price through `wc_price()`, each with a setting. | [wporg], [pricing], [gopro] |
| Live suggestions: stock | Not in the suggestion row by default. Stock status is shown in the hover Details Panel, along with a larger image, SKU, price, short description and an add-to-cart button with a quantity field. The panel exists in free; it is listed under the free features. | Same Details Panel. To show stock in the row itself, the docs give custom code: one filter to store stock in the index and one to print it. | Yes, in the row by default (`show_stock`). The option's accessible name includes it ("…, $84.99, in stock"). There is no details panel and no add-to-cart from the panel. | [details], [extra-text], [wporg] |
| Categories, tags, brands as suggestions | Categories and tags as suggestions. | Adds brands, posts and pages to autocomplete. Category and brand thumbnails. | Categories, tags and brands as their own documents, at most 3 after the products, with counts ("Decks (122)"). No posts or pages. | [pricing], [wporg] |
| Search fields: title, description, short description, SKU | Yes, all four. | Yes. | Yes: name, SKU, short description and long description (long is capped at 2,000 characters and can be turned off with `index_long_description`). | [pricing], [wporg], [gopro] |
| Search fields: variation SKU | No. | Yes. An exact variation SKU match shows the variable product, or the variation itself. | Yes. Every variation's SKU is folded into the parent document (`index_variation_skus`, on by default). The variation is not a suggestion of its own. | [pricing], [wporg], [details] |
| Search fields: categories, tags | No. Categories and tags can match as suggestions, but a product is not found by its category or tag name. | Yes ("Search by product categories", "Search by product tags"). | Yes. Category names with their parents, and tags, are product fields with weights 5 and 3. | [pricing], [gopro] |
| Search fields: brands | No. | Yes. Supported: WooCommerce Brands, Perfect Brands for WooCommerce, Brands for WooCommerce, YITH WooCommerce Brands. | Yes, WooCommerce's `product_brand` taxonomy only (weight 6). Other brand plugins are not supported; a developer can add their text through `csearch_product_fields`. | [wporg], [pricing] |
| Search fields: attributes | No. | Yes. | Yes. Every attribute value of the product and its variations, global and custom (weight 5). | [pricing], [wporg] |
| Search fields: custom fields | No. | Yes, with dedicated ACF support. | No setting and no ACF integration. A developer can add meta to any field with the `csearch_product_fields` filter (HOOKS.md). | [pricing], [wporg], [gopro] |
| Posts, pages, custom post types | No. | Posts and pages in autocomplete. | No. Non-product searches are left to WordPress. | [pricing], [wporg] |
| Fuzzy / typo tolerance | No. | Yes. The token is cut to `prefixLength` letters, up to `maxExpansions` indexed words starting with that prefix are taken, and those within Levenshtein `distance` (insert, delete, substitute) are used in place of the typed word. Four modes: disabled; soft (prefix 2, expansions 50, distance 1); normal, the default (2, 200, 2); hard (2, 400, 3). The docs do not say whether a fuzzy match ranks below an exact one, or whether numbers and SKUs are excluded. Because candidates must share the first 2 letters, a typo in the first two letters cannot be corrected. | Yes. Only tokens of 4 or more characters are widened. Candidates are terms sharing at least half the token's trigrams (no fixed prefix, so a typo in the first letters can still be found). They are filtered by Damerau–Levenshtein distance (a transposition counts as one edit): 1 for 4–7 characters, 2 for 8 or more; at most 10 terms. Numbers and SKU-shaped tokens are never fuzzed. A typo match scores 0.5 (distance 1) or 0.3 (distance 2) against 1.0 for exact. Fuzzy is only tried when the exact, stem and prefix levels find nothing. There are no owner-selectable modes. | [fuzzy] |
| Synonyms: format and scope | No. | One textarea under Search config. Synonyms within a group are separated by commas, one group per line, and phrases are allowed. Saving rebuilds the index automatically. A match found through a synonym is not highlighted. The docs mention no per-language lists and no one-way synonyms. | A table with one row per synonym: word, means, one-way or both-ways, language, who added it, when. Managed in a list table, through CSV import/export, or with `wp csearch synonyms`. Applied at query time, so no reindex. Each synonym adds an alternative and never replaces the typed word; it scores 0.8. A test box shows what a word expands to. | [syn], [pricing] |
| Stemming, plurals, accents | Not documented as a feature. The free changelog (1.32.0, November 17, 2025) has "Support for diacritic and accent characters in search and scoring calculation". | Same. Both free and Pro have Stopwords and Greeklish doc pages. [fs2] lists "plural and singular forms, and transliteration" as work planned for 2.0, which suggests they are not handled consistently today. | Porter (English) and Snowball (French) stemmers, with the original token always stored as well. Accent folding uses a fixed table. Stop words per language are built in for English and French, and the owner can add more. | [wporg], [fs2], [ml] (doc menu) |
| Analytics: what is recorded | Yes, the same module as Pro. One row per search goes into `{prefix}dgwt_wcas_stats`. Modules: "Critical searches without result", where a phrase becomes critical after at least three searches with no results; search totals (autocomplete, results page, with results); top 100 phrases from autocomplete; top 100 from the results page. The `should_record` filter receives phrase, hits, lang and autocomplete. A dashboard widget lists critical searches. | Same. The blog says "FiboSearch Analytics is free for both FiboSearch Free and Pro". | One row per suggest or search call: query as typed and normalised, language, result count, source (suggestions or results page), the clicked suggestion's type and id, and the time to the minute. The screen shows the last 7, 30 or 90 days: searches per day (chart plus table), the top 50 queries with result counts and click-through, the top 50 with no results, the most-clicked suggestions, suggestion versus results-page use, and an Explain link for any query. There is no dashboard widget. | [an-doc], [an-blog], [wporg] (screenshot 9, 1.31.0 entry) |
| Analytics: retention | 30 days, purged automatically. A `DGWT_WCAS_ANALYTICS_EXPIRATION_IN_DAYS` constant in wp-config.php can shorten it ("e.g. from 30 to 14 days"). `DGWT_WCAS_ANALYTICS_ONLY_CRITICAL` keeps only no-result phrases. There is a "reset your stats" link. | Same. | 90 days by default, settable from 7 to 365 on the Tools tab. Purged nightly in batches. Daily counts are rolled up first, so the charts survive the purge. Also `wp csearch log purge [--days]`. | [an-doc], [an-blog] |
| Analytics: export | "Export CSV" link under the tables. | Same. | CSV export of the log's own columns, streamed. | [an-doc] |
| Analytics: privacy and IP handling | The docs do not say whether IP addresses or user ids are stored. The `$data` passed to `should_record` contains phrase, hits, lang and autocomplete, with no IP. To exclude IPs or CIDR ranges, the owner adds a code snippet that reads client headers, including `HTTP_X_FORWARDED_FOR` and `REMOTE_ADDR`. Since 1.31.0 (July 16, 2025) there is a filter to skip recording by IP, phrase or language. No redaction of personal data typed into the box is documented. Separately, the free plugin can send events to Google Analytics. | Same. | Stores no IP, user id, session id, user agent or page URL. Queries that look like an email address, phone number, card number or postal code are stored as `[redacted]` (`Domain\Scrub`). Rate-limit counters use a salted SHA-256 hash of the IP (or of the WooCommerce session) and live only in the object cache. Recent searches stay in the shopper's browser and are off by default. There is no Google Analytics integration. | [an-doc], [wporg] |
| Index design: tables | No index of its own. The free engine is described as "WooCommerce Search Engine with all its pros and cons", searching `wp_posts` and `wp_postmeta` linearly. Since 1.32.0 (November 17, 2025) indexer logs are kept in their own table. | Inverted index in `wp_dgwt_wcas_index`, `wp_dgwt_wcas_invindex_wordlist` and `wp_dgwt_wcas_invindex_doclist` (this matches D-005). The indexer also has separate readable, searchable, taxonomy and variation stages, judging by the cron hook names in [idx-fail]; whether each has its own table is not stated. | Six InnoDB tables: documents, terms, postings (with field, tf and first position), trigrams, synonyms, log. Generations: a full reindex writes beside the live index and switches only when complete. | [gopro], [wporg], [idx-fail] |
| Index design: request path (SHORTINIT) | Not applicable. | Search runs in WordPress's SHORTINIT mode, so most of WordPress, plugins and theme are not loaded. | No SHORTINIT. Requests go through a normal REST route (`GET /csearch/v1/suggest`) with a full WordPress bootstrap. The plugin's work on that request is one index query plus one documents query, with an object cache keyed by index generation and HTTP caching (`Cache-Control: max-age=60`, ETag). | [gopro] |
| Index design: claimed speed | Not claimed. FiboSearch says response time grows with the number of products. | "Ultra-fast search engine (average 0.2 seconds per request)" [pricing]. "Products found in less than 0.2 second even for big stores" [gopro]. "Up to 10× faster" [wporg], [pricing]. "Often in just a few milliseconds" for very large catalogues [fs2]. None of these come with a published method or hardware. | Measured on an Apple Silicon Mac in wp-env with 300 products in two languages, timing the plugin's own work without WordPress's bootstrap: `/suggest` p95 6.3 ms cold and 0.4 ms warm, `/search` p95 5.6 ms (BENCHMARK.md). CI budgets on 10,000 products: 60 ms warm and 100 ms cold. These figures are not comparable with FiboSearch's, which are whole-request times on unknown hardware. | [pricing], [gopro], [wporg], [fs2] |
| Index design: database growth | Not applicable. | "Approx. 5-10% bigger", "every 1000 products equals to approx. 10MB" (this matches D-005). | Not measured in MB. Measured row counts: 2,000 products gave 113,591 postings and 13,732 terms. The spec estimates about 2 million postings and "a few hundred megabytes" for 10,000 products, which would be larger than FiboSearch's figure. Needs a real measurement. | [gopro] |
| Indexer reliability (published figures) | Not applicable. | [fs2]: "In 2020 … roughly 5% to 8% of indexes failed to build correctly … we reduced this number to around 1.5%." This matches D-005. [idx-fail] gives a different figure: "About 2% of FiboSearch Pro users experience issues with index building". Causes listed: the server cannot make loopback HTTP requests (the indexer uses `wp_remote_post()` to itself), WP-Cron problems, batches that are too large, and MySQL `max_connections`. There is a `DGWT_WCAS_INDEXER_MODE` = `sync` fallback. | No field figures (no installs yet). Indexing runs in Action Scheduler jobs with no loopback HTTP request. The old generation serves until the new one is complete, a reindex can be cancelled, the last 50 errors are kept, and admin notices fire for an empty index, more than 10 % of products missing, or a queue idle for 15 minutes. | [fs2], [idx-fail] |
| Incremental updates | Not applicable. | Index "updated incrementally" [fs2]. The changelog mentions updating the index on product and variation changes. | Each product change is queued as one Action Scheduler job, run within the minute. A stock-only change updates the flag without re-tokenising. Nothing is done on the save request. | [fs2], [cl-pro] |
| Multilingual: WPML, Polylang | Both compatible with free and Pro. The free readme also lists qTranslate-XT. | Same. | No built-in integration. Languages come from the `csearch_languages` filter: a site's code supplies each language's product text. Each document and query has a language, falling back to the site language when nothing matches (a setting). | [ml], [wporg] |
| Multilingual: TranslatePress, qTranslate-XT | No ("only works with FiboSearch Pro") for TranslatePress. The free readme lists qTranslate-XT, but [ml] says qTranslate-XT is "only FiboSearch Pro". The two pages contradict each other. | Yes. | No built-in integration (through the filter only). | [ml], [wporg] |
| French | The free plugin's French (fr_FR) translation on translate.wordpress.org is 65 % complete for the stable branch; fr_CA and fr_BE are at 0 %. The plugin's trunk has no bundled languages folder. No French stemming is documented. | Not documented separately. | French is a first-class language: Snowball French stemmer, French stop words, accent folding, and fr_CA translation files shipped in `languages/` (.po, .mo and JS JSON). | [tr], [svn] (trunk listing) |
| Accessibility: claims | No WCAG conformance claim, and no combobox or ARIA pattern documented. A site search for accessibility or WCAG on fibosearch.com returned nothing. | Same. | Built as an APG list-autocomplete combobox: `role="combobox"`, `aria-expanded`, `aria-controls`, `aria-activedescendant`, a `listbox` of named options, a polite live region with the count, full keyboard support, focus kept in the input, 44/48 px rows, reduced motion honoured. axe (WCAG 2.0–2.2 A/AA) runs in CI on three engines and two viewports, 85 runs for the front end and 40 for admin. The manual VoiceOver/NVDA pass has **not** been run yet; it belongs to milestone 8. | [wporg], [cl-pro] |
| Accessibility: fixes in changelog | Free changelog versions: 1.29.0 (October 29, 2024) "WCAG improvements: possibility to select and open a search icon using the keyboard"; 1.30.0 (January 27, 2025) "WCAG: 'Links do not have a discernible name'"; 1.31.0 (July 16, 2025) "ARIA label on the search icon"; 1.34.0 (August 3, 2026) "Focus trap in the search history when navigating with the Tab key". | Pro changelog (dates only): the same four entries on the same dates, plus "W3C - Accessibility errors" (release of January 26, 2020) and "Remove interaction on the TAB key" (in an earlier release; the version could not be pinned from the page). | Not applicable. | [wporg], [cl-pro] |
| Results page integration | "After typing enter, users get the same results as in FiboSearch bar" on the WooCommerce results page. Integrations with Bricks, JetSmartFilters and others are listed. Doc pages cover custom sorting on the results page. | Same, using the Pro index. | WooCommerce's own archive: the ranked ids are written into `WP_Query` (`post__in`, ordered by them), so template, pagination, filters and sort still work. Adds a "Relevance" sort, a corrected-query line, and a no-results state with the nearest categories and the owner's fallback products. | [wporg], [ml] (doc menu) |
| Ranking control | "Better ordering – a smart algorithm"; developer filters for score. [fs2] says relevance tuning is currently "hidden behind hooks and custom code". | Same, plus exclude-products rules. | A documented formula: field weights (a setting), stem/synonym/prefix/typo multipliers, phrase and exactness bonuses, a penalty for sold-out items, per-product boosts (−10 to 10) and boost rules for categories, brands and tags, set in admin. Explain in admin and `wp csearch explain`. | [wporg], [fs2] |
| Embedding | Block, navigation block, shortcode, widget, menu item, PHP, one-click replacement for over 50 themes. | Same, plus free help embedding the bar. | Block, `[creative_search]` shortcode, widget, and a one-click takeover of a classic theme's `get_search_form()`. No menu item and no per-theme integrations. | [wporg] |
| Price and licence | Free (GPL, wordpress.org). | Personal: $59 the first year, renewals $50.15 a year, 1 site, up to 10,000 products. Entrepreneur: $99, 3 sites, 50,000 products. Agency: $249, 25 sites, 150,000 products. 30-day refund, no trial or demo ("We don't offer a demo version of FiboSearch Pro for trial"). One licence covers production, staging, dev and localhost. Multisite needs one licence per subsite. If the licence is not renewed, "Your existing project will remain intact. The only difference is support and updates." | No licence and no per-site fee; the agency installs it on every shop it builds. Built to pass Plugin Check with zero findings. | [pricing] |
| Outbound requests, phoning home, licence checks | Bundles the Freemius SDK in wordpress.org-compliant mode (`is_org_compliant` true, `is_premium` false). According to the privacy policy, if the admin opts in on activation, Freemius receives name, email, site URL, WordPress, PHP and plugin versions, plugin status, site language, and optionally the list of plugins and themes. That data is used for support, usage analysis and the Mailchimp list. Optional Google Analytics events from the shop's front end. The indexer makes loopback HTTP requests to the site itself. | Licence sales and activation are handled by Freemius ("manages your licence"; activation and deactivation through My Account in wp-admin). The Pro changelog updates the Freemius SDK in most releases. How often the licence is checked is not documented on the pages opened. Loopback HTTP requests for indexing, as in free. | None. "No remote request is made, ever" (SPEC.md), enforced by the outbound-request grep gate in `tools/gates.sh`. Grep of src/ finds no `wp_remote_*` or curl calls. No telemetry, no licence check, no loopback requests (Action Scheduler instead). | [privacy], [svn], [pricing], [cl-pro], [idx-fail], [wporg] |
| Extras FiboSearch has that Creative Search lacks | Search history for the shopper (whether it is on by default is not stated), voice search (the changelog mentions speech recognition), mobile overlay mode, darkened background, Google Analytics events. | Plus: exclusion rules, custom CSS, priority support. | Recent searches (off by default), admin product exclusion and boosts, CSV import and export of synonyms, WP-CLI (`wp csearch …`), a public REST API with a schema. | [wporg], [pricing], [cl-pro] |

#### D-005 claims checked

| D-005 claim | Result |
| --- | --- |
| Fuzzy: prefix 2, expansions 50/200/400, distance 1/2/3 for soft/normal/hard | Confirmed on [fuzzy]. |
| Synonyms: comma-separated groups, one per line, phrases allowed, index rebuilt on save, synonym matches not highlighted | Confirmed on [syn]. |
| Analytics: critical after 3 no-result searches, 30-day retention, CSV export, dashboard widget, filter to exclude phrases and IPs | Confirmed on [an-doc]. IP exclusion is a code snippet built on the `should_record` filter, not a setting. Not mentioned in D-005: analytics is available in the free version too. |
| Three tables, SHORTINIT, "less than 0.2 second", 5–10 % growth, ~10 MB per 1,000 products | Confirmed on [gopro]. |
| 1.5 % of Pro indexes fail, down from 5–8 % in 2020, FiboSearch 2.0 post of 27 April 2026 | Confirmed on [fs2] (published 2026-04-27). Note that [idx-fail] says "About 2%". |
| Pricing FAQ: no demo, 30-day refund | Confirmed on [pricing]. |
| "Should I go Pro?" article as the Pro feature list | Confirmed ([gopro]). It is a fibosearch.com blog post, not a docs page. |

#### Where FiboSearch Pro is ahead

- **Breadth of what it searches.** Pro searches custom fields with ACF support, posts and pages, and four brand plugins. Creative Search covers custom fields only through a developer filter, has no posts or pages, and supports only WooCommerce's own brand taxonomy.
- **Multilingual plugins out of the box.** WPML, Polylang, TranslatePress and qTranslate-XT work without code. Creative Search needs a developer to answer `csearch_languages` for each site.
- **Adjustable typo tolerance.** The owner chooses soft, normal or hard, up to distance 3. Creative Search has one fixed rule that stops at distance 2, and only for tokens of 4 or more characters.
- **A lighter request path.** SHORTINIT skips loading plugins and the theme. Creative Search goes through a full WordPress REST bootstrap. Its object and HTTP caching offset part of that, but a cold request still pays the full bootstrap.
- **Shopper-facing extras.** The Details Panel has add to cart and quantity, there is a variation-level suggestion, voice search, a mobile overlay, shopper search history, Google Analytics events, and one-click integrations with over 50 themes.
- **Analytics conveniences.** There is a WordPress dashboard widget for critical searches. Nothing in Creative Search matches it.
- **Track record.** Seven years in production, a public reliability figure, a troubleshooting module, and a published database-growth estimate. Creative Search has no field data, and its index size per 1,000 products has not been measured; the spec's own estimate is larger than FiboSearch's.

#### Where Creative Search is ahead

- **Accessibility built to a pattern and tested.** The APG combobox is implemented from the start, with axe in CI on three browser engines at two widths, and options whose accessible names include price and stock. FiboSearch documents no ARIA pattern or WCAG claim. Its changelog shows keyboard, ARIA-label, link-name and focus-trap fixes arriving one at a time from 2024 to August 2026. Caveat: Creative Search's manual screen-reader pass has not been run yet.
- **Privacy by design in analytics.** No IP or identity is stored. Personal-looking queries are redacted. The rate limiter keeps only a salted hash, in cache only. Nothing leaves the server. FiboSearch's docs do not say what identity data its stats table holds, IP exclusion needs a code snippet, and the plugin bundles Freemius, which takes opt-in data to Mailchimp.
- **Analytics that answer more questions.** Creative Search records click-through, the most-clicked suggestions and suggestion-versus-results-page use, gives an Explain view for any query, and keeps 90 days by default (configurable 7–365 in the UI). Daily roll-ups outlive the purge. FiboSearch keeps 30 days, configurable only by a wp-config constant, and documents no click data.
- **Typo handling that is safer and finds more.** Trigram candidates mean a typo in the first two letters can still be found, which FiboSearch's 2-letter prefix rule cannot do. Transpositions count as one edit. Numbers and SKUs are never fuzzed, so `8.25` does not find `8.5`. Typo matches rank below exact, stem and prefix matches by a documented weight.
- **Synonyms with more control.** Synonyms can be one-way or both-ways, are kept per language, have an audit trail (who, when), support CSV and WP-CLI, and apply without a reindex. FiboSearch has one textarea of equal-weight groups, and every save rebuilds the index.
- **Explainable ranking set from admin.** Field weights, per-product and per-term boosts, a sold-out penalty and fallback products are all set in admin, with an Explain view. FiboSearch's own 2.0 post says its relevance control is "hidden behind hooks and custom code".
- **French as a first-class language.** Stemming, stop words, accent folding, and a complete fr_CA translation. FiboSearch's French translation is 65 % complete and no stemming is documented.
- **Indexing without the documented failure modes.** Action Scheduler is used instead of loopback HTTP and WP-Cron, which are the two main causes FiboSearch lists for failed index builds. A generation switch means the shop never searches a half-built index.
- **No licence, no phoning home.** There is no per-site fee, no Freemius and no outbound request at all. FiboSearch Pro Personal costs $59 and then $50.15 a year per site, capped at 10,000 products.
- **Open interfaces.** A versioned REST API with schemas (`suggest`, `search`, `status`), WP-CLI for every admin action, and documented hooks.


## v1.4.0

From 1.4.0 the cold `/suggest` and the `/search` timings keep the fastest of three per query (D-129); the budgets are unchanged. On this Mac, 300 products: cold `/suggest` p95 12.2 ms, warm 0.6 ms, `/search` 8.7 ms, memory 0.51 MB, with the log seeded and 200 redirects and 200 pins loaded (measured before D-129, single timings). 1.4.0 changed no server code on the search path.

## v1.7.0

`csearch_ranked_ids` kept to 500 products (D-162): every twentieth product of the 10,000-product catalogue, in English and French with the synonyms, pages and posts, the search log seeded and 200 redirects and 200 pins, as for the other budgets. The golden set's one-word and three-word queries, each cold (the cache flushed), the fastest of three timings kept (D-129), measured around `apply_filters()`. On this Mac, 18 November 2026:

| What (10,000 products) | Result | Budget |
| --- | --- | --- |
| `csearch_ranked_ids`, 500-id scope, one-word query, p95 (21 queries) | 35.7 ms | 100 ms (the `/search` budget) |
| `csearch_ranked_ids`, 500-id scope, three-word query, p95 (5 queries) | 40.6 ms | 100 ms |
| Full reindex | 110.4 s, 11.0 ms a product | 300 s |
| `/suggest` p95, cold / warm | 55.5 ms / 0.7 ms | 100 ms / 60 ms |
| `/search` p95 | 31.8 ms | 100 ms |
| Memory, p95 of a cold suggest | 1.70 MB | 4 MB |

At 300 products the same scoped queries take 4.3 ms and 4.1 ms.
