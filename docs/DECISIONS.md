# Decisions

Every decision made outside the spec, with its reason. The spec (`docs/SPEC.md`) wins where the two disagree; a decision here that contradicts it is a bug in this file. Dates are the day the decision was taken.

## Before milestone 1: the five questions for Zach

The spec left five questions for Zach before milestone 1 (`docs/SPEC.md`, "Open questions"). Milestone 1 started before they were answered, so the four the spec already names a default for were taken at that default, and the fifth is left open. **Zach: confirm or change these; each is one line to edit.**

### D-001 · Name and repo · 2026-10-21

`creative-search` under `web-projects`, like the Stockroom. Namespace `CreativeBranding\Search`, slug `creative-search`, prefix `csearch_`, text domain `creative-search`, WP-CLI `wp csearch`, exactly as the spec's "Name" paragraph says.

**Why:** the folder already carried the name, and the spec derives everything else from it.

### D-002 · Licence and sale · 2026-10-21

GPL-2.0-or-later. Built as a product (readme.txt, Plugin Check clean, no agency-only assumptions) but **not** sold in 1.0: it ships on the agency's own shops. Selling it with support is a 1.1 question.

**Why:** GPL is required for WordPress.org and matches the rest of the agency's plugins. Nothing in the code changes between "agency only" and "sold", so the decision can wait without cost; the spec's own wording ("or kept to the agency's shops for 1.0") was read as the default.

### D-003 · Posts and pages · 2026-10-21

Not in 1.0. The `type` column on `csearch_documents` and the `csearch_document` filter leave room for them in 1.1.

**Why:** the spec says 1.1 in both the non-goals and the question.

### D-004 · Recent searches · 2026-10-21

Off by default. The block setting `recent_searches` defaults to `no`.

**Why:** the spec's "The front end" and "Analytics and privacy" sections both say "off by default for privacy"; the question only asked whether to override that.

### D-005 · FiboSearch benchmark licence · 2026-10-21 · **Decided: no purchase**

Zach declined to buy FiboSearch Pro. Milestone 8's benchmark is built from two public sources instead:

1. **Documented behaviour.** FiboSearch publishes the Pro feature list (fibosearch.com/pricing and the "Should I go Pro?" article), the fuzzy-search algorithm in full (prefix of 2 letters, up to 50/200/400 candidate expansions, Levenshtein distance 1/2/3 for soft/normal/hard), the synonyms format (comma-separated groups, one per line, phrases allowed, the index rebuilt on save, matches via a synonym not highlighted), the analytics feature set (critical phrases after three no-result searches, 30-day retention, CSV export, dashboard widget, a filter to exclude phrases and IPs), the index design (three tables: index, inverted-index wordlist, doclist; SHORTINIT request path; "products found in less than 0.2 second"; 5 to 10 % database growth, about 10 MB per 1,000 products) and their own reliability figure (1.5 % of Pro indexes fail to build, down from 5 to 8 % in 2020, per the FiboSearch 2.0 post of 27 April 2026). These become a feature-by-feature matrix in `docs/BENCHMARK.md` with the source and date for each row, and the fuzzy rules become golden-query expectations our engine must at least match.
2. **Measured behaviour against the free version.** FiboSearch free is installed on the copy of Skatehouse for the afternoon: the same 50 queries, timing, ranking and suggestions. The free engine is WooCommerce's own `WP_Query` search, so this is a floor, not a peer; the write-up says so.

What this cannot give: Pro's actual suggestion content, ranking and timing on our catalogue. FiboSearch offers no trial or public Pro demo (pricing FAQ: "We don't offer a demo version of FiboSearch Pro for trial"; refund within 30 days only). The spec's "better" claims against Pro are therefore made against its documentation, and the benchmark says which rows are documented and which are measured.

**Why:** Zach's call. The documentation is unusually specific about how Pro works, which covers most of what a licence would have shown; the one thing lost is a side-by-side on the same catalogue.

## Milestone 1: Foundation

### D-010 · Tooling versions · 2026-10-21

PHP floor 8.2 (`composer.json` platform pinned to 8.2.99 so dependencies stay installable on the floor), PHPUnit 11 (the spec's version; 13 exists but the WordPress test suite and the polyfills are known good on 11), Infection 0.32 (0.33 and later require PHP 8.3, above the spec's 8.2 floor), PHPStan 2.3 **(superseded the same day by D-023: floors 8.5 and WordPress 7.0, PHPUnit 13, Infection 0.35)** at **level 9** as the gate table says (PHPStan 2 has a level 10; the spec wrote "level 9 (max)" when 9 was the maximum; moving to 10 is a one-line change to try once the domain exists), PHPCS with WordPress-Extra, WordPress-Docs, WordPress-VIP-Go (`automattic/vipwpcs`) and PHPCompatibilityWP, `@wordpress/scripts` 37 for the JavaScript and CSS standards, `@wordpress/env` 12, Playwright 1.64 with `@axe-core/playwright`.

**Why:** the newest release of each that satisfies the spec's named version and the PHP 8.2 floor. Everything in `composer.json` and `package.json` is the current release as of 21 October 2026 (`composer outdated --direct` and `npm outdated` report nothing newer); the only deliberately older choices were Infection 0.32 (PHP floor, lifted by D-023) and PHPUnit 9.6 for integration tests (D-021, still true). PHPCompatibility is 9.3.5 because 10.0 is still alpha. The wp-env zips (WordPress 7.1.3, WooCommerce 11.2.1, Plugin Check 2.1.0) are exact pins so a run is reproducible, bumped by hand when a release lands; they were the current releases on that date.

### D-011 · wp-env pins and the compatibility matrix · 2026-10-21

`.wp-env.json` pins the newest supported pair (WordPress 7.1.3, WooCommerce 11.2.1, Plugin Check 2.1.0) on PHP 8.5. The oldest supported pair (WordPress 7.0, WooCommerce 10.0, PHP 8.5 since D-023; it was 6.8 and 8.2) is a second CI leg that writes `.wp-env.override.json` (`tools/wp-env-oldest.sh`) before `wp-env start`. wp-env's database is MariaDB (its default image); a MySQL 8.0 leg needs a Docker Compose override that wp-env does not offer in configuration, so it is **deferred to milestone 8** and listed here so it is not forgotten.

**Why:** the spec names both ends of the matrix; wp-env can pin core, plugins and PHP but not the database image.

### D-012 · The unique key on `csearch_documents` includes `generation` · 2026-10-21

The spec's table lists `UNIQUE (type, object_id, lang)` and then, under "Generations", adds a `generation` column so a reindex writes N+1 beside N. Both cannot hold: the same product exists in N and N+1 at once. The key is `UNIQUE (generation, type, object_id, lang)`, with `generation` first so the live generation's rows cluster in the index.

**Why:** generations are the mechanism that keeps the shop searching a complete index; the unique key has to allow two generations of the same document.

### D-013 · `total_sales` on the document row · 2026-10-21

`csearch_documents` carries `total_sales int unsigned` beside the columns the spec's table lists.

**Why:** the spec's tie-break rule reads "sales count (WooCommerce's `total_sales` meta, copied to the document)" and the data-model rule is that nothing on the search path loads a product, so the copy needs a column.

### D-014 · Uninstall is a class · 2026-10-21

`uninstall.php` loads the Composer autoloader and calls `Install\Uninstaller::run()`, which drops the six tables, deletes every option and transient, the two product meta keys, the Action Scheduler actions in the `csearch` group and the object-cache group, per site on multisite. If the autoloader is missing it falls back to the same list inline.

**Why:** the integration suite can then prove "uninstall leaves nothing" by calling the class; a bare `uninstall.php` cannot be tested without a WordPress uninstall simulation.

### D-015 · No deactivation hook · 2026-10-21

There is none. Deactivation leaves the tables, options and scheduled actions in place; Action Scheduler ignores actions whose hook has no callback.

**Why:** the spec says "deactivation leaves everything in place"; a hook that does nothing is noise.

### D-016 · The catalogue generator · 2026-10-21

`tests/Fixtures/Catalogue.php` (PSR-4 class; the spec's `tests/fixtures/catalogue.php` is the same path on this case-insensitive filesystem) generates products as plain arrays from a seed with its own xorshift PRNG, so product *i* is the same on every PHP version and at every catalogue size: the first 24 products are hand-written Skatehouse-shaped "hero" products (KAZUMI Skate Deck, Dealer, 8.25″ among them) that the golden queries anchor on, and the rest are generated. Writing the arrays into WooCommerce is a separate loader that arrives with milestone 3, the first milestone that needs products in a database.

**Why:** the under-a-minute budget is for generating the fixture; inserting 10,000 products through WooCommerce's CRUD takes far longer than a minute and belongs to the reindex benchmark, where it is measured separately.

### D-017 · Golden queries · 2026-10-21

`tests/Fixtures/queries.yml` holds the 50 queries with `expect.top` (a SKU, a term, or `none`), `expect.suggestions` and the synonyms the test seeds (`board` = `deck`). Milestone 1 checks the file is well-formed and every SKU and term it names exists in the catalogue at 300 and at 10,000 products; milestone 2 scores it in memory; milestone 4 runs it through the API. `symfony/yaml` reads it (dev dependency only).

**Why:** the spec names a YAML file and PHP has no YAML parser built in.

### D-018 · Settings defaults the spec does not name · 2026-10-21

`hidden_by_sku` is `no` until the owner answers the hidden-products question (safer default: a dealer-only line stays invisible). `fallback_to_default_language` is `yes` (the spec says on by default). `boost_rules`, `fallback_products` and `stats` are arrays in `csearch_settings` because the spec gives them no table.

**Why:** listed so the owner's answers in milestone 7 have a place to land.

### D-019 · Infection scope in milestone 1 · 2026-10-21

With `src/Domain` empty, `infection.json5` covers `src/Settings` and `src/Container.php` at the 90 % floor. Milestone 2 adds `infection-domain.json5` at 100 % for `src/Domain`, and `composer infection` runs both.

**Why:** Infection fails on an empty source list, and the two floors differ.

### D-020 · Three grep gates · 2026-10-21

`tools/gates.sh` fails CI on string-built SQL outside `src/Repository/` and `src/Install/`, on `eval(`, `extract(`, `unserialize(`, `query_posts(`, `create_function(`, `base64_decode(` anywhere in `src/`, and on `date(` (the spec bans `date()` without a timezone; `gmdate()` and `wp_date()` are the replacements).

**Why:** the spec asks for a grep gate beside PHPStan; a shell script is the simplest thing CI and a developer run the same way.

### D-021 · Two PHPUnit versions · 2026-10-21

The unit suite runs on the current PHPUnit through Composer (11 at first, 13 since D-023). The integration suite runs on PHPUnit 9.6 with the Yoast polyfills from a second Composer project, `tools/integration/` (its lock committed, its vendor gitignored, installed by `tools/integration.sh`; until D-023 it was a checksummed phar), because the WordPress core test suite that wp-env ships, for WordPress 7.1 as for 6.8, still calls `PHPUnit\Util\Test::parseTestMethodAnnotations()`, which PHPUnit 10 removed. Composer cannot install two versions of one package. Integration tests therefore use `set_up()`/`tear_down()` and no PHPUnit 10+ attributes.

**Why:** the first integration run under PHPUnit 11 failed every test with that undefined method; the WordPress suite's PHPUnit ceiling is core's to lift, not ours.

### D-022 · Firefox under Playwright on macOS 27 · 2026-10-21

macOS 27 puts `~/Library/Application Support/Firefox` behind a permission prompt. Playwright's Firefox build still resolves that directory for `profiles.ini` bookkeeping even when launched with its own `-profile`, so a launch from a terminal without Full Disk Access exits with "Could not find profile folder" (microsoft/playwright#42768, open; Chromium and WebKit are unaffected because Playwright hands them a complete user-data directory). `playwright.config.ts` sets `CFFIXED_USER_HOME` to a fresh temporary directory for the Firefox project on macOS only, which is the workaround confirmed in that issue; Firefox then launches and the two smoke tests pass locally. Linux CI and Windows get the default launch. Remove the branch when Playwright ships a build that owns its app-data directory.

**Why:** the browser gate names three engines; "run it in CI" was the only alternative and left the Firefox column unverified before each push.

### D-023 · Floors raised to WordPress 7.0 and PHP 8.5 · 2026-10-21

Zach raised the floors from WordPress 6.8 and PHP 8.2. The chain: Infection 0.33+ needs PHP 8.3 and PHPUnit 13 needs 8.4.1, so the 8.2 floor held the toolchain back; WordPress 6.8 is documented as supporting PHP 8.4 but not 8.5 (core handbook, "PHP Compatibility and WordPress Versions"), so 8.5 meant dropping 6.x; the agency's shops run WordPress 7, so 6.x support had no customer. WooCommerce 11.2 itself requires WordPress 7.0, so 7.0 is the natural WordPress floor; the WooCommerce floor stays 10.0 for the features the spec names. The compatibility matrix is now WordPress 7.0 + WooCommerce 10.0 and WordPress 7.1 + WooCommerce 11.2, both on PHP 8.5; the static job runs on 8.5 only until 8.6 ships.

What changed: plugin headers and readme (Requires at least 7.0, Requires PHP 8.5), `composer.json` (`php >=8.5`, platform 8.5.99, PHPUnit ^13.4, Infection ^0.35, the polyfills moved out), `phpstan.neon` (phpVersion 80500), `phpcs.xml` (testVersion 8.5-), CI, both wp-env legs, and `tools/integration/` as its own Composer project for PHPUnit 9.6 plus the polyfills, which PHPUnit 13 cannot share a tree with (the polyfills' constraint stops at ^12).

Two things the change surfaced. The integration bootstrap must register the plugin's Composer loader *behind* PHPUnit 9.6's (Composer prepends by default), or PHPUnit 9.6 loads PHPUnit 13's classes from the plugin's vendor tree and dies before the first test. And the activation test was depending on leftover state in Action Scheduler's tables, which the WordPress suite never reinstalls; see the addendum in `reviews/M1.md`.

Trade-off stated once: a host without PHP 8.5 cannot run the plugin. PHP 8.5 has been stable since 20 November 2025 and WordPress 7.0 and 7.1 support it fully; the agency controls the hosting of the shops it ships to.

**Why:** Zach's call, with the reasoning above; the floors now follow what the agency runs rather than what the market still installs.


### D-024 · Inch marks are read with the number and dropped from the token · 2026-10-21

A deck width written `8.25"` or `8.25″` is one word to the tokenizer: the regex accepts a quote mark only directly after a digit, so the mark neither splits the number nor counts as anything on its own, and the token is `8.25`. On the query side `QueryParser::prepare()` first straightens curly quotes and turns a straight quote after a digit into the typographic `″`, so `"8.25" deck"` is not read as a phrase quote and an unpaired quote that remains is replaced by a blank. Decimal points inside numbers are kept (`8.25` stays whole); any other punctuation splits.

**Why:** Skatehouse's names carry `8.25"` and `8.5"` everywhere and shoppers type them both ways, with and without the mark; `8.25"` and `8.25` must be the same term, and a quote after a digit must not open a phrase. The spec's tokenizer section lists `8.25″` among its one-token examples; this reads "one token" as "not split at the mark" and stores the number without it, which is a reading for Zach to confirm, since storing the mark would make `8.25` match `8.25″` only as a prefix. The one case that loses is a quoted phrase ending in a number (`"deck 8.25"`), which the parser reads as the unquoted words; the shopper still finds the deck.

### D-025 · Apostrophes: the joined form and the two sides, at one position · 2026-10-21

`l'orbite` indexes as `lorbite`, `l` is dropped as one character, and `orbite` is kept; all forms of one word share one position. A word with an apostrophe whose joined form is a stop word (`don't` → `dont`) is dropped whole. In a query the variants of one token are ORed within the position, so `l'orbite` finds a product indexed from `orbite` and vice versa. Curly apostrophes are straightened first.

**Why:** French elision and English contractions are the two cases the fixture has, and neither has one right answer; indexing every form costs a handful of postings per word and lets the query side stay simple. The Snowball French stemmer removes elisions itself on the joined form, so the stem of `lorbite` is `orbit`, which lands at the same position anyway.

### D-026 · Stop words are out of the index; an all-stop-word query runs and finds nothing · 2026-10-21

The tokenizer drops stop words (Snowball's English and French lists, folded, with the apostrophe contractions joined) on both sides, and positions are counted over the tokens kept (D-027). A query made only of stop words (`the`, `à la`) is not rejected: the parser keeps its tokens, marks the query `allStopWords`, removes the prefix flag, and the searcher runs it with no prefix and no typo widening, so it finds nothing and the result is an honest empty one rather than an error. The golden set's `the` is now `zero: true`.

**Why:** Dropping stop words keeps `the deck` and `deck` the same query and halves the postings for long descriptions. Rejecting an all-stop-word query would need a special case in every caller (the block, the results page, the log); running it is cheaper and gives the same outcome. Widening it would be wrong: `the` as a prefix would match `theory` and as a typo would match `tee`.

### D-027 · Positions after stop-word removal · 2026-10-21

The position stored for a token is its index among the tokens kept, so `the night shift deck` gives `night 0, shift 1, deck 2` and the phrase `"night shift deck"` is adjacent. Values of one field (a product's several tags, SKUs or categories) are separated by a gap of two positions so a phrase cannot span them.

**Why:** The spec's phrase bonus is "adjacent in one field"; counting positions before removal would make a phrase with a stop word in it (`deck of the night`) never adjacent and never match as a phrase, which is what a shopper quoting a product name would expect to work.

### D-028 · `Domain\IndexStore` interface; the Domain searcher runs against any store · 2026-10-21

The Domain owns the search algorithm end to end (`Searcher`, `Scorer`, `QueryParser`) and reads the index through one interface, `IndexStore` (document counts, document frequencies, terms by prefix, terms by shared trigrams, postings, documents). The tests implement it in memory (`tests/Support/MemoryIndex`, built from the catalogue by `CatalogueIndexer`); milestone 3's repositories implement it with SQL. It is named `IndexStore`, not `Index`, because `src/Domain/Index.php` collides with the `index.php` silence file on a case-insensitive disk.

**Why:** The spec asks for "the golden set scored in memory against a hand-built index" and 100 % mutation coverage on the Domain; both need the algorithm to run without a database. One interface is the seam; the widening plan the spec describes is the sequence of calls the searcher makes on it, which milestone 4 compiles into one statement where it can.

### D-029 · Synonyms: a one-token word, a means of any length, the stem of the synonym at 0.8 · 2026-10-21

A synonym's `word` must tokenise to exactly one token (after folding and stop-word removal); a multi-word `means` contributes each of its tokens as an alternative. A synonym match scores at 0.8 of exact; the stem of each alternative is also searched, at the same 0.8. Two-way synonyms are stored once and matched in both directions by `Synonyms::alternatives()`.

**Why:** The index has one term per posting, so a multi-word `word` would need a phrase search per synonym on every query; the owner can enter the reverse row instead. Multi-word `means` is the common shape (`deck` → `skateboard deck`) and costs nothing. Searching the synonym's stem keeps `trucks` ⇄ `axles` working when the product says `axle`.

### D-030 · Branch coverage measured by mutation; the ABSPATH guard on one line · 2026-10-21

The spec's "100 % line, branch and mutation coverage on `src/Domain`" is met as line coverage from pcov (`tools/coverage-floor.php`, floor 100 % on `src/Domain`) and mutation coverage from Infection (`infection-domain.json5`, MSI and covered MSI 100 %). pcov does not record branches; Xdebug's branch coverage would need the slower driver on every run and a different report format. A killed mutant on every condition is a stronger statement than a visited branch. Each Domain file's `defined( 'ABSPATH' ) || exit;` is written on one line so pcov does not report the `exit` branch as uncovered; it is a load guard, not WordPress code.

A mutant that cannot be told from the original on any input the method can receive (an equivalent mutant) is not a test gap. Where one appeared the code was first simplified so the mutant no longer exists (a redundant shortcut, a cast on an array key, a guard a caller already provides); the few that remain because the code is right as written are ignored by mutator and method in `infection-domain.json5`, each with its proof in the milestone review, so the floor stays 100 % and the list stays short and reviewable. Where only a string no French word reaches could tell a mutant from the original (a three-letter word opening with two vowels, `ni` before a consonant), that string is a test traced by hand against the Snowball definition, not an ignore: the stemmer runs on brand names and typos too.

**Why:** Measured, not asserted, with the cheaper tool; the mutation floor subsumes the branch floor for the kind of code the Domain is (conditions on strings and numbers, not on WordPress state). An ignore list by method is honest where lowering the floor would hide the next real gap.

### D-031 · Snowball's test vectors vendored · 2026-10-21

`tests/Fixtures/snowball/porter/` and `french/` hold the `voc.txt` and `output.txt` pairs from the snowballstem.org distribution (42,603 and 21,653 words) with its BSD-3 `COPYING`. The two stemmers are exact ports of `porter.sbl` and `french.sbl` (the current algorithms, including the French elision and `ais`/`oux` exceptions) and the tests run every vector.

**Why:** The spec names "Snowball's test vectors" as a test that must pass; a sample would prove less and the files are 1.2 MB of plain text under a licence that permits it. Both stemmers work on bytes (Porter on ASCII, French on ISO-8859-1 via `mb_convert_encoding`, returning words outside Latin-1 unchanged) because that is what the Snowball programs do and the vectors prove.

### D-032 · Four golden expectations changed · 2026-10-21

With the scorer running, four queries in `tests/Fixtures/queries.yml` were rewritten; the other 46 hold at 300 products unchanged.

- `dck` (typo) became `dek`. `dck` is a real SKU prefix in the catalogue (`KAZ-DCK-…`), so it is an exact term, not a typo, and finds those SKUs; `dek` shares `de`, `ek` with `deck` and is a typo of it.
- `KAZ-DEA-00002` (a SKU that does not exist) expects `KAZ-DEA-00001` on top with the query corrected to `kaz dea`: the AND finds nothing, the retry drops the least-matched token (D-034) and the Kazumi dealer deck is the only product the remaining tokens match.
- `the` is `zero: true` (D-026).
- `kazumi -dealer` expects `KAZ-SLO-00023` on top and both dealer products absent, instead of a specific non-dealer deck: which Kazumi product wins is a tie-break on sales, and the test is about exclusion.

At 10,000 products six expectations fail, all one shape: generated in-stock products of the same series outrank a sold-out hero by the stock penalty. That is the scorer doing what the spec says (stock −6) against a fixture where every generated product is in stock; the golden set is therefore scored at the shop's size (300) and the 10,000 run stays a shape and timing test.

**Why:** A fixture that contradicts the algorithm it tests has to change, and each change is listed here so it is reviewable; none loosens a rule.

### D-033 · Prefix terms are searched with the exact level, not after it · 2026-10-21

The widening plan's first lookup for the unfinished last token asks the store for the exact term, its variants, its stem, its synonyms *and* the terms that start with it (capped at 20), in one call. Typo candidates (trigram neighbours, capped at 10, sorted by edit distance then by trigrams shared) are asked for only when that first call found nothing.

**Why:** The spec's plan lists prefix as a level after exact, but on a catalogue of SKUs the exact term often exists and is the wrong answer: `kaz` is a term (from the SKU) and also the prefix of `kazumi`, and a shopper typing `kaz` wants the Kazumi products, not only the three with `kaz` as a whole token. Unioning the two levels costs one query shape, not two.

### D-034 · The retry runs only when the AND finds no product · 2026-10-21

The spec says the searcher retries with the least-matched token dropped when the AND returns fewer than three products. The implementation retries only when it returns none; when the retry adds products they are appended after the originals and the result is marked `corrected`. The least-matched token is the one with the fewest product documents, ties going to the later token.

**Why:** Deviation from the spec, flagged for Zach. With "fewer than three", the golden set's `orbit cru` (two Orbit Cruiser products) and `kazumi dea` (one dealer deck) would retry, drop a token and bury the right answer under every Orbit or Kazumi product; the spec's own expectations for those queries only hold with "none". A shop with two matching products has two results, and padding them with near-misses marked as a correction reads as the plugin not understanding. If Zach wants the spec's threshold, it is one constant in `Searcher::search()` and the two golden rows would move.

### D-035 · Trigram minimum is half the query token's trigrams · 2026-10-21

A typo candidate must share at least `max(1, floor(n / 2))` trigrams with the query token, where `n` is the token's trigram count (with the two-space-and-one-space padding Snowball-style: `"  deck "` gives `  d`, ` de`, `dec`, `eck`, `ck `). The spec's example, `dekc` against `deck`, shares two of five and passes; the spec did not name the threshold.

**Why:** Half is the loosest threshold at which the spec's example passes and `dek`/`deck` still match (two of four), while words that start and end differently (`tape` shares nothing with `deck`) are kept out before the edit-distance check, which does the precise work on the rest (`desk` and `dock` pass the trigram filter and are one edit away, so they are candidates; the sort by distance and shared count decides the order).

### D-036 · Generated products sell under 15 units; heroes are the bestsellers · 2026-10-21

The catalogue generator's `sales` draw for generated products is `below( 15 )` (was 400). The 24 hand-written heroes keep their sales figures, in the hundreds. The random sequence is unchanged because `below()` consumes one draw as before, so every SKU, name and price is the same as in v0.1.0.

**Why:** The golden set's "top" expectations are hero products, and the tie-break after score is sales. With generated products selling up to 400 the winner among equal scores was whichever random product drew the highest number, and the golden set could not be deterministic in intent, only in seed. Real shops look like this: a few products carry the sales.

### D-037 · Term suggestions: name field only, coverage as the second tie-break · 2026-10-21

A category, tag or brand document is scored on its name only: exact, stem or synonym 12, prefix 7, typo 4 per query token, plus a coverage figure (query tokens matched over name tokens). Terms sort by score, then coverage, then the product count (carried in the document's `total_sales` column, D-013), then title. The default is three term suggestions.

**Why:** The spec says the suggestion list carries up to three terms and that a term's weight is its product count; it does not say how a term matches. Terms have no prose, so the product scorer's field machinery is wasted on them, and coverage makes `decks` beat `complete decks` for the query `decks` while the product count breaks the remaining ties the way the spec wants.

### D-038 · The SKU field counts a term once · 2026-10-21

In the SKU field term frequency is capped at one, however many variations share a prefix. Every other field keeps its count. The stem is indexed as a second posting at the same position (computed on the unfolded, lower-cased source word, then folded), and numbers and SKU-shaped tokens are not stemmed.

**Why:** A variable product with 20 variations has `kaz` and `dea` 21 times in its SKU field, and `(1 + ln(1 + 21))` would rank a product by its variation count rather than its match. Stemming on the unfolded source keeps French accents available to the Snowball rules (`é` matters to the French stemmer) and folding the stem afterwards puts it in the same term space as everything else.

### D-039 · Scrub patterns, and what they leave alone · 2026-10-21

`Domain\Scrub::redact()` replaces, with `[redacted]`, anything shaped like an email address, a North American phone number, an international number of seven or more digits after `+`, a card number of 13 to 19 digits with optional spaces or dashes, a Canadian postal code or a ZIP+4. Plain five-digit numbers are left in place. The method is named `redact()` because PHPCS treats a method named after its class (`Scrub::scrub()`) as a PHP 4 constructor.

**Why:** The spec says the log keeps no personal data and names emails, phone numbers and card numbers; postal codes are the fourth thing a shopper pastes into a search box by mistake. Five-digit numbers are also product numbers, prices and widths, and redacting them would blind the log to the queries it exists to show.

### D-040 · Test memory limit 512 MB · 2026-10-21

`phpunit.xml.dist` sets `memory_limit` to 512M. The unit suite builds the 300-product index once per class and holds the Snowball vectors in memory; the golden set at 10,000 products is run by hand from the scratch script, not in the suite.

**Why:** The default 128M is enough for every test but the in-memory index tests; the setting is on the test runner only and says nothing about the plugin, which never builds an index in memory.

## Milestone 3: the Index

### D-041 · SKU keys are dictionary terms with an `=` prefix · 2026-10-21

Each SKU a product carries (its own and every variation's) is stored once more as a posting in the SKU field whose term is the whole folded key with an `=` in front: `KAZ-DEA-00001` gives `=kaz dea 00001` beside the tokens `kaz`, `dea`, `00001`. Key terms get no trigrams, and no prefix lookup can return one, because no token starts with `=`. The searcher's `Document::$skuKeys` (the exact-SKU bonus and the hidden-by-SKU rule) is read back from these postings with the prefix stripped. A key is cut at 63 characters to fit the 64-character term column.

**Why:** The exact-SKU rule needs every SKU of a document, and the documents table has no column for a list. A seventh table, or a column holding a list, is a schema change after milestone 1, which CLAUDE.md says to ask about. The dictionary already holds strings and the postings already join a term to a document and a field, so the keys fit the existing schema with no new statement shape.

### D-042 · Job hooks carry a `csearch_job_` prefix · 2026-10-21

The per-product and per-term Action Scheduler jobs are `csearch_job_index_product`, `csearch_job_update_stock`, `csearch_job_index_term`, `csearch_job_index_terms` and `csearch_job_term_products`. The full reindex keeps the spec's `csearch_reindex`, with `csearch_reindex_batch`, `csearch_reindex_terms` and `csearch_reindex_cleanup` for its steps.

**Why:** The spec names both the indexing job and the public "index this product at all" filter `csearch_index_product`. WordPress keeps actions and filters in one table, so the job's callback ran inside `apply_filters()` and returned null, and every product was refused (found by the first integration test). The filter is public API and keeps its name; the job is internal and moves. Spec deviation, flagged for Zach.

### D-043 · What a save request does: an id in memory, one job at shutdown · 2026-10-21

The hook callbacks only add an id to `Index\IndexQueue`; the queue attaches to `shutdown` at priority 20, after WooCommerce's own deferred product sync (priority 10), and queues the jobs there. A save request therefore runs no query on the plugin's or Action Scheduler's tables until the response has been built, and the integration test proves it with a `query` filter. Under WP-CLI the collected ids are indexed inline at the same point. A change made after the flush attaches it again.

A job is not queued while one with the same hook and arguments is **pending**. The plugin checks that itself rather than passing Action Scheduler's `$unique`, for two reasons found in review: before Action Scheduler 4.0 `$unique` ignored the arguments (one pending product job per site, and a reindex chain that refused its own next batch), and in every version it also counts a running job, so a save made while that product's job was running would be dropped.

A stock-only save is told apart from a full one on `woocommerce_before_product_object_save`, the last moment WooCommerce's pending changes can be read (it applies them before `woocommerce_update_product`). When every changed property is stock quantity, stock status, manage stock, backorders, low-stock amount or modified date, the job is `csearch_job_update_stock`. A save with no changed property (how `wc_update_product_stock()` saves before firing its stock hook, but also how a save of meta alone looks) is indexed in full unless a stock hook for the same product follows in the same request. The stock job flips `in_stock` on the rows that exist and indexes in full only when the product has no row yet. `product_visibility` term changes are not a trigger: WooCommerce rewrites them on every stock change, and a visibility change made through the product save is in its changed properties anyway. A deleted term's products are queued in jobs of 100, never one job each.

**Why:** The spec's budget is zero extra queries on the save request, and its "stock-only change updates `in_stock` directly" cannot be told from `woocommerce_update_product` alone.

### D-044 · One dictionary for every generation; df for scoring comes from the postings · 2026-10-21

`csearch_terms` has no generation or language column, so its `df` counts documents in every language and every generation present (both during a reindex). Removal subtracts, for each term, the number of removed documents that carried it, so the count is exact again after a cleanup. That count orders prefix candidates only. The score's `df` is counted by the searcher from the postings it has just read for the query's language in the live generation (D-050), so it is the spec's "documents containing it" over the same set as `N`, at no extra query.

**Why:** The first version used the dictionary's count in the score, against a per-language `N`. The review showed that this can make IDF zero or negative in a two-language shop and during every reindex, and that a batched removal decremented a shared term once instead of once per document. A per-language, per-generation dictionary would be a schema change; counting from the postings needs none.

### D-045 · The shape of `csearch_languages` · 2026-10-21

The filter receives and returns an array of language code to readers: `product` (called with the `WC_Product`, the field texts read from the product itself, and the code; returns the field texts in that language) and `term` (called with the `WP_Term`, its name and the code; returns the name). Either can be left out to read the product as it is. The default is the site language (`get_locale()` cut to its code) plus any codes in the `languages` setting, with no readers. A code that is not two to eight letters is dropped, and an empty result falls back to the site language.

**Why:** The spec says "a list of codes, each with a callback that returns a product's text in that language (name, descriptions, term names)". Field ids as keys let a reader replace any field, and the separate term reader serves category, tag and brand documents, which have no product to read from.

### D-046 · Schema upgrades on admin requests and WP-CLI, behind a lock · 2026-10-21

`Schema::maybeUpgrade()` is gone from `Plugin::boot()`. `Install\Upgrader::maybeUpgrade()` runs on `admin_init` (which also fires on `admin-ajax.php`, a logged-in or front-end AJAX request, never a page view) and before every `wp csearch` command, takes a lock row with `add_option()` (an INSERT that fails when the row exists), runs `dbDelta()` and releases it; a lock older than five minutes is taken over. Network activation walks every site the way the uninstaller does, and each site's activation queues its own first build.

**Why:** M1 review items 2 and 3: a front-end request must never run `dbDelta()`, and two concurrent requests must not both run it.

### D-047 · Index timings measured at 300 and 2,000 products here, 10,000 on CI · 2026-10-21

`tests/Integration/IndexBenchmarkTest.php` (group `benchmark`, `composer test:integration:benchmark`) loads `CSEARCH_BENCH_PRODUCTS` catalogue products (default 300) into WooCommerce with their variations and times a full inline reindex against a budget scaled linearly from the spec's (5 minutes for 10,000 is 30 ms a product). The one-product budget (50 ms) is an assertion in the regular suite. Loading 10,000 products with 40,000 variations through WooCommerce's CRUD takes longer than the reindex it measures, so the full-size run belongs on the CI runner the spec names.

**Why:** The spec's 10,000-product figure is for the CI runner; on this Mac the run is bounded by fixture loading, not by the indexer. The milestone review gives the measured rate and the extrapolation.

### D-048 · WordPress-bound code leaves the core Infection run · 2026-10-21

`src/Index`, `src/Hooks`, `src/Cli`, `src/Repository`, `src/Search` and `src/Rest` (and `src/Block` from milestone 6) join `src/Install` in `infection.json5`'s excludes. They are tested by the integration suite inside WordPress, which Infection cannot drive (it runs the unit suite on PHPUnit 13; the WordPress suite needs PHPUnit 9.6 in its own container, D-021). `Support\Cast` is plain PHP and joins both the unit coverage source and the core run.

**Why:** Same reason as D-019: a mutation tool can only judge code its test runner can execute.

**Spec deviation, flagged for Zach:** the spec asks for no escaped mutants in the SQL builders and 90 % MSI on the rest. Those builders are in `src/Repository`, outside this run, and the unit coverage floor does not measure them either; their checks are the integration tests and the grep gates. Running Infection inside the tests container against PHPUnit 9.6 may be possible and is the way to close this.


### D-049 · Small choices in the indexer · 2026-10-21

- A document's `nameKey` (the exact-name bonus) is computed from the stored title when the searcher reads it, with the same tokenizer; it is not stored.
- `Document::$recency` is the object id: WooCommerce ids grow with time, and the column is not in the schema.
- `Document::$termRefs` (the owner's boost rules) is filled in milestone 4, where the boost rules are applied; the indexer leaves it empty.
- A variable product's attribute values are the parent's attribute list, which lists every value its variations choose among; variation SKUs are read from `_sku` meta of the children, without loading each variation.
- A category, tag or brand document indexes its name (name field) and its description (short field); its product count rides in `total_sales` (D-013).
- The `csearch_tokens` filter runs once per field value, before the stems are added, through an optional closure on `Domain\Analyzer` (the Domain stays free of WordPress).
- A job that throws records `#id: message` in the index state (the last 50) and the reindex moves on; one broken product does not stop a rebuild.
- `csearch_index_switched` (an action, with the new and old generation) fires after the switch, so milestone 4's cache can drop its answers; the plugin flushes its own cache group there too.

**Why:** Each is a gap the spec leaves; recorded so the review can question them.

### D-050 · `csearch_fields` is the weights; `csearch_product_fields` is the text · 2026-10-21

The spec names `csearch_fields` "the field list and weights", and `docs/SETTINGS.md` says it runs over the `fields` setting. Milestone 3 had used the name for a product's field texts. Before either was released, the texts filter became `csearch_product_fields` and `csearch_fields` filters the weights the score uses, as the spec says. In the same change the searcher stopped asking the store for document frequencies and counts them from the postings it reads (D-044), so `IndexStore` lost `documentFrequencies()`.

**Why:** One name with two contracts is the collision D-042 was about; the spec's meaning wins.

### D-051 · Cache and rate limit live in the object cache only · 2026-10-21

Answers are kept with `wp_cache_set()` under a key of the live generation, a salt and the normalised request, for 60 seconds (`csearch_cache_ttl`). A reindex changes the generation, so old answers are unreachable without a scan; a changed setting or synonym bumps the salt (`Search\Cache::bump()`, called from milestone 7's screens). "The last 10,000 distinct queries" is left to the cache's own eviction. The rate limit is a sliding window from two fixed 10-second counters, keyed by a hash of the WooCommerce session cookie or the remote address, in the object cache only. Without a persistent object cache both live for one request: answers are not shared and the limit does not hold.

**Why:** The spec keeps rate-limit counters out of the database by name, and a transient fallback for the answer cache would write one row per distinct query. A shop that needs the rate limit has, or should have, a persistent object cache; the status screen in milestone 7 says which.

**Spec deviation, flagged for Zach:** the spec's Compatibility line says "with and without a persistent object cache (transients fall back)". Without one, answers are not shared between requests and the rate limit does not hold. The rate limit's client key trusts a WooCommerce session cookie only when its HMAC verifies (a forged cookie per request would otherwise buy a new bucket each time), and `csearch_client_ip` lets a site behind a proxy supply the shopper's address.

### D-052 · A click finds its log row by query, not by id · 2026-10-21

`POST /csearch/v1/click` takes the query, the language and what was clicked. It marks the newest unclicked log row with that normalised query and language written in the last ten minutes. Log rows are written after the response (spec: Logging), so the suggest response cannot carry the row's id.

**Why:** The spec asks for both a deferred log write and a click keyed by the log row id, which cannot both hold. Matching by query loses only when two shoppers search the same words in the same ten minutes and one of them clicks, which credits the click to the wrong one of two identical rows: the counts the owner sees are the same.

### D-053 · The index state is read fresh; cancel has its own option · 2026-10-21

`csearch_index_state` is no longer autoloaded, and `IndexState::all()` reads it from the database each time (through `Options::fresh()`, which also clears WordPress's "option does not exist" cache). The cancel flag is `csearch_reindex_cancel`, holding the generation to stop. Progress is written by re-reading the state and adding the batch's counts. A search pins the generation once per call (`IndexRepository::at()`), so it reads the state once, not on every lookup. A new reindex takes a generation above the live one, any half-built one and any being cleaned.

**Why:** Found in review: an Action Scheduler process runs many jobs and kept the state it read first, so a batch writing its progress put `cancelled` back to false, and a live product job could write into a generation already cleaned. A cancelled generation's number could also be reused while its cleanup was still queued.

### D-054 · The reindex walks product ids by keyset, through SQL on `wp_posts` · 2026-10-21

Each reindex batch carries the last product id it walked and asks `ProductIdRepository` for the next 100 published ids above it; a renamed term's products are walked the same way, descendants of a hierarchical term included. The ids come from one prepared statement on `wp_posts` (products are posts whatever the order storage); every product is still read through `wc_get_product()`.

**Why:** Found in review: with offset paging, trashing a product with a lower id during a reindex shifted every later product down one place, and one was never indexed into the new generation. WooCommerce's product query has no "id greater than" argument; the rules allow SQL in a repository, and listing ids is not reading products.

### D-055 · A suggest call is several statements, not two · 2026-10-21

The spec says a suggest call is "one SQL statement against the index plus one against the documents table". The searcher from milestone 2 asks the store level by level: a cold `/suggest` runs 15 to 18 statements, all on primary or unique keys (the index state, the synonyms, a prefix lookup, the postings of each token's terms for products and for terms, a trigram lookup only when nothing else matched, the document count, the documents and their SKU keys). At 300 products in two languages the golden set's p95 is 6.3 ms cold and 0.4 ms warm against budgets of 100 and 60.

The same holds for a results page without a persistent object cache: the warm page adds one query (the live generation, every table counted in the test), a cold one 15 to 18, and a no-results page adds the nearest-categories search (cached with the answer).

**Why:** Compiling the widening plan into one statement would duplicate the Domain's logic in SQL and lose the in-memory store the golden set is proven against (D-028). The budgets are what the statement count was for, and they hold. Flagged for Zach: if a shop shows a slow database round trip, the first step is to merge the two postings reads per level.

### D-056 · Milestones 3 and 4 ship as one commit (and 5 and 6, D-066) · 2026-10-21

The milestone 3 review's fixes (the store interface, the container's hook list, the CLI registration) touched files milestone 4 was already changing in the same tree, and the integration suite runs only against the working tree wp-env mounts. Milestone 3 alone could not be verified, so both are one commit, tagged `v0.4.0`, with a review for each in `docs/reviews/`. There is no `v0.3.0` tag.

**Why:** A tag for a state no test ran against would be a claim, not a check.

## Milestone 5: the results page

### D-057 · How the results page is changed, in classic and block themes · 2026-10-21

The plugin ranks the main query of a product search (`post_type=product`, WooCommerce's own search form and the block's) and nothing else. It hooks `pre_get_posts` at priority 20, after WooCommerce, sets `post__in` to the ranked ids (or `array( 0 )` when there are none) and removes the `posts_search` clause for that query. WooCommerce already makes "Relevance" the first entry and the default sort on a search; the plugin maps it to `post__in` order through `woocommerce_get_catalog_ordering_args`. The count and corrected lines print on `woocommerce_before_shop_loop` (priority 5) in classic templates and before the `woocommerce/product-results-count` block in block templates; the no-results state prints on `woocommerce_no_products_found` and after `woocommerce/product-collection-no-results`. A search that leaves exactly one product redirects to it, as WooCommerce does by default.

**Why:** The spec says "unchanged template" and names Kadence and Storefront (classic) while the shop may move to a block theme; hooking WooCommerce's own extension points serves both without a template override.

### D-058 · "Search instead for" turns the retry off; the nearest categories come from the term suggestions · 2026-10-21

The corrected line's link adds `csearch_exact=1`, which runs the shopper's words with `SearchOptions::$retry` off (new in the Domain, default on, tested to 100 % mutation). The no-results state's "three nearest categories" are the category documents the query's words point to (the same term suggestions the panel shows); when the words point nowhere, the three largest categories, without WooCommerce's "Uncategorized". The owner's fallback products are rendered with WooCommerce's own `[products]` shortcode class, four at most.

**Why:** The spec names both features without saying how "search instead" avoids correcting itself or what "nearest" means.

### D-059 · Storefront 4.6.2 and Kadence 1.5.2 in wp-env; a shared browser fixture · 2026-10-21

`.wp-env.json` installs both themes from WordPress.org, pinned. `tests/e2e/fixtures/shop.php`, run once by Playwright's global setup, launches the store (WooCommerce's "coming soon" mode is on in a fresh install, which hid the shop from every logged-out test), restores WooCommerce's placeholder image and its sizes (missing in the environment; WebKit requested the 768 px one and logged a 404), loads ten hero products and reindexes. The axe helper moved to `tests/e2e/support/axe.ts`.

**Why:** The milestone's tests are named against Kadence and Storefront. Themes in the test environment are not plugin dependencies; nothing ships with them.

### D-060 · The block's state machine is tested on Node's own test runner · 2026-10-21

The spec says "jsdom for the state machine". This version of `@wordpress/scripts` runs unit tests with Vitest, and neither Vitest, Jest nor jsdom is installed; CLAUDE.md says to ask before adding a dependency, and Zach was not there to ask. `npm run test:js` runs `tests/js/view.test.mjs` on `node --test`, importing the shipped `assets/src/block/view.js` through a module hook that swaps `@wordpress/interactivity` for a double with the runtime's generator-as-async behaviour, and a small DOM double for the dozen browser calls the module makes. The 13 tests cover typing, the debounce, stale answers, the cache, keyboard moves, the live region's words, failures, the beacon and recent searches; the real DOM is covered by 35 Playwright runs of the block on three engines.

**Flagged for Zach:** approving `vitest` and `jsdom` as dev dependencies would let these tests move to the spec's tool with no change to what they check.

### D-061 · The results page counts and filters what WooCommerce shows · 2026-10-21

Found in review. The count line and the no-results state use the main query's `found_posts`, after WooCommerce's filters, visibility and "hide out of stock", not the ranked list's length. The ranking runs at `pre_get_posts` priority 5 (WooCommerce's own pass reads it) and sets `post__in` again at 20 (WooCommerce resets it from `loop_shop_post_in`). WooCommerce's "exclude from search" visibility is lifted for the ranked query, because the ranking already admits a hidden product only for an exact SKU with `hidden_by_sku` on; "hide out of stock" and catalogue visibility stay. The query's `search_terms` are cleared, and WooCommerce's classic layered-nav, attribute and price filter widgets get `posts.ID IN (ranked ids)` through their SQL filters, so their counts are over the ranked set. The words are unslashed (WordPress slashes `$_GET` before `pre_get_posts`) and decoded on a pretty search URL. Empty searches and feeds are left alone; a second page or a re-sort is not logged as a new search.

**Why:** Each was a way the page could say one thing and show another, or rank `Philly\'s` instead of `Philly's`.

### D-062 · The ETag is the generation and the answer · 2026-10-21

Found in review. The spec names "an ETag of the index generation plus the normalised query", but the generation changes only on a full reindex, so a browser or CDN revalidating every 60 seconds would have kept a sold-out or renamed product's row indefinitely. The ETag is now a hash of the answer itself with the generation; the answer comes from the cache when warm, so a 304 still costs no search then, and every request (304 included) is logged.

**Why:** "A single product's change is at most one TTL stale" has to hold through revalidation too. Spec deviation in wording, not in intent; flagged for Zach.

### D-063 · Partial personal data is redacted as it is typed · 2026-10-21

Found in review: `/suggest` logs as the shopper types, so `jean.tremblay@g` reached the log. `Domain\Scrub` now also redacts any text with characters on both sides of an `@`, and a North American phone number being typed (area code and exchange each starting 2 to 9, then at least one digit, at the end of the query). Partial postal codes (`H2X 1Y`) and partial card numbers are not redacted: the first is indistinguishable from the product codes the case table protects (`a1b 2c`) and names a neighbourhood, not a person; the second from long product numbers.

**Why:** The reviewer's broader rule ("seven digits in a row") would also have redacted truck sizes (`139 144 149`), which the spec's own Scrub tests protect.

### D-064 · The block's classes are hyphenated, and an option's name is its text · 2026-10-21

The block's classes follow WordPress's stylelint pattern (`csearch-option-product`, not BEM's `csearch__option--product`). A suggestion's accessible name is its visible text with visually hidden comma separators ("KAZUMI Skate Deck, Dealer, $84.99, In stock"), not an `aria-label`, which axe flagged under WCAG 2.5.3 (label in name). The stock dot is drawn, not a `●` glyph that screen readers read.

**Why:** Both were found by the gates: stylelint and axe.

### D-065 · Suggestion term types and the click beacon follow the API · 2026-10-21

The block's term rows are typed `category`, `tag` and `brand`, as `/suggest` returns them, and the click beacon sends the query, the language, the type and the id (D-052), not a log row id.

**Why:** The block agent wrote the script against a guessed API before milestone 4 existed.

### D-066 · Milestones 5 and 6 ship as one commit · 2026-10-21

The block (milestone 6) was started in parallel and registered in the same container list as the results page's hooks; the review's fixes to milestone 5 landed in the same files. As with D-056, both ship as `v0.6.0`, with a review for each.

**Why:** A tag for a state no test ran against would be a claim, not a check.

### D-067 · The log is written at shutdown, without `fastcgi_finish_request()` · 2026-10-21

Under PHP-FPM the connection stays open until `shutdown` callbacks finish, so the one-row INSERT is on the response's tail. Calling `fastcgi_finish_request()` from a plugin would cut off any later `shutdown` output another plugin prints (Query Monitor among them).

**Why:** One prepared INSERT is well under a millisecond; flagged rather than forced.

### D-068 · Every admin change is a form to `admin-post.php`, with three conventional exceptions · 2026-10-21

Each change on WooCommerce → Search is a POST to `admin-post.php?action=csearch_*`, answered by `Admin\Actions`: capability (`manage_woocommerce`), then the nonce named after the action, then the change, a notice kept for one minute in a per-user transient, and a redirect back to the tab. Three follow WordPress's own conventions instead: a synonym's Delete row action and the product panel's "Reindex now" are links carrying a nonce (as core's list tables do), and the synonyms table's bulk Delete comes back to the screen itself (by GET, as the list tables of core's Users and Comments screens do) with the list table's own `bulk-synonyms` nonce, because the bulk select is named `action` and would collide with `admin-post.php`'s. Read-only controls (the Analytics period, the synonym test box, the test search, the table's search and filter) are GET forms without a nonce; they change nothing.

**Why:** the spec asks for "every action a form with a nonce"; these three are where WordPress itself uses a link or a self-post, and each still checks the capability and a nonce (tested).

### D-069 · Fallback products are chosen by SKU or ID, not WooCommerce's product picker · 2026-10-21

The Boosts and exclusions tab lists the chosen fallback products, each with a Remove box, and adds more from a labelled text field that takes SKUs or IDs separated by commas; an unknown one is named in the notice.

**Why:** WooCommerce's `wc-product-search` picker (selectWoo) fails axe on our screen: its search field has no label and its selection uses `aria-expanded` on a role that does not allow it. The spec's gate is "axe clean on every tab", and the fix belongs in WooCommerce, not in a plugin's override of selectWoo's markup.

### D-070 · The product's Search panel is a tab of the product data box; the CSV columns are always there · 2026-10-21

The boost and Exclude from search are a "Search" tab in the product data box, with "Last indexed … ago" and Reindex now. There is no field in the variation panel: variations are folded into their parent's document (spec: Data model), so a boost or an exclusion is the parent's. The exporter always carries `Meta: _csearch_boost` and `Meta: _csearch_exclude` (untranslated, the header WooCommerce's importer maps to meta in every language), and the keys are left out of its "export custom meta" columns so they are not repeated. An import is normalised before the save as the panel would: the boost clamped to −10..10 and 0 cleared, exclude `yes` or cleared.

**Why:** the spec's "and in the variation panel for SKUs" has nothing to set per variation in this design; flagged for Zach rather than inventing a per-variation boost.

### D-071 · The notices: when they show and how a dismissal lasts · 2026-10-21

"Missing from the index" compares the live generation's products with published products less those the owner excludes, and warns above 10 %; "empty" when it holds none. Neither shows while a rebuild runs. "The queue is not running" shows when a job of the plugin's group is still pending 15 minutes after it was due. "Errors" shows the last rebuild's recorded lines (at most ten). A dismissal is per user (`csearch_dismissed_notices` user meta, removed on uninstall) and lasts until the condition clears; when it comes back, so does the notice. Only users with `manage_woocommerce` see them.

**Why:** the spec says "persistent, dismissible" without saying for how long; until-it-clears means a dismissed problem that returns is not hidden for good.

### D-072 · The Analytics chart is drawn on the server · 2026-10-21

Searches a day are an inline SVG bar chart (searches, and those with no results) with a text description and a `<title>` per day, and the same numbers in a table under a disclosure. No chart library, no script.

**Why:** no dependency to ask for, nothing to load, and the table is the accessible form of the chart.

### D-073 · The Tools test search, `wp csearch search` and the rebuild's progress · 2026-10-21

The test search and `wp csearch search` call `SearchService::run()` directly: no log row and no cached answer, so testing does not change the Analytics. The rebuild's progress bar reads `GET /csearch/v1/status` every three seconds through `wp.apiFetch` (WordPress's own script, with its REST nonce) from a small unbuilt script in `assets/admin/`, and reloads the tab when the rebuild finishes. `/status` now reads `Admin\IndexHealth`, which the Tools tab and the notices share; its JSON is unchanged.

**Why:** the spec says the test search shows "the ranked answer and its explanation"; logging it would count the owner's tests as shoppers' searches.

### D-074 · `wp csearch log purge --days=0` deletes every row; synonym CLI rows have no author · 2026-10-21

`--days` keeps that many days; 0 deletes the whole log; without it the retention setting applies. Synonyms added by WP-CLI have no `created_by` and show as "Import or WP-CLI"; synonyms imported from the Synonyms tab are credited to the user who imported them. The purge rolls up the days it is about to delete first (`LogMaintenance::rollUp()`), as the nightly job does, so the charts keep them.

**Why:** the spec gives the flag without its range; a CLI process has no user to credit; and the spec says the aggregates survive the purge.

### D-075 · The review's fixes: streaming, bounded reads, whole sentences · 2026-10-21

The log export streams to the response 1,000 rows a read (`Analytics::exportTo()`), and CSV text is built in `php://memory`, never `php://temp`, which spills to a temporary file above 2 MB (spec: no file is written). Reads the admin makes are bounded: synonyms at 50,000 (`SynonymRepository::MAX_ROWS`), terms in the rule picker at 500 a taxonomy, excluded products at 100. Users, products and terms named on a screen are primed in one query each (`cache_users()`, `_prime_post_caches()`, `_prime_term_caches()`). Every sentence is one translatable string ("Category: Decks", "Creative Search: …", "… products (stopping)"). `IndexHealth` moved to `Index\` and the explain rows to `Search\Explanation`, so REST and the admin depend on neither each other nor the CLI. The warnings still run their five small queries on each admin screen for users with `manage_woocommerce`; caching them would hide a fixed problem for a minute, and the cost is a few milliseconds.

**Why:** the independent review of milestone 7 found these against the spec's quality bar (no N+1, no unbounded query, no concatenated sentences); docs/reviews/M7.md lists all 17 findings and what was done with each.

