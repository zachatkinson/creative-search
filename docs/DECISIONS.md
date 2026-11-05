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

**Settled 2026-10-22 (Skatehouse handoff, item 6):** the reading is confirmed: a number is stored without its inch mark, so `8.25` and `8.25″` are one term. 1.1 extends the rule (D-098): `8.25in`, `8.25 in`, `8.25 inch`, `8.25 inches`, `8.25"`, `8.25''` and `8.25″` all read as `8.25` (`in` is a unit only right after a number); trailing zeros go (`8.50` is `8.5`, `8.00` is `8`); with French on, `8,25` and `8,25 po` read as `8.25`. Numbers and units stay out of fuzzing and stemming.

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

**Superseded by D-109 (1.3):** either side may now be several words, matched as a phrase; a one-word row works as described here.

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

`tests/Integration/IndexBenchmarkTest.php` (since 1.1 part of `CatalogueBenchmarkTest.php`, which loads the catalogue once for this and the API budgets; group `benchmark`, `composer test:integration:benchmark`) loads `CSEARCH_BENCH_PRODUCTS` catalogue products (default 300) into WooCommerce with their variations and times a full inline reindex against a budget scaled linearly from the spec's (5 minutes for 10,000 is 30 ms a product). The one-product budget (50 ms) is an assertion in the regular suite. Loading 10,000 products with 40,000 variations through WooCommerce's CRUD takes longer than the reindex it measures, so the full-size run belongs on the CI runner the spec names.

**Why:** The spec's 10,000-product figure is for the CI runner; on this Mac the run is bounded by fixture loading, not by the indexer. The milestone review gives the measured rate and the extrapolation.

### D-048 · WordPress-bound code leaves the core Infection run · 2026-10-21

`src/Index`, `src/Hooks`, `src/Cli`, `src/Repository`, `src/Search` and `src/Rest` (and `src/Block` from milestone 6) join `src/Install` in `infection.json5`'s excludes. They are tested by the integration suite inside WordPress, which Infection cannot drive (it runs the unit suite on PHPUnit 13; the WordPress suite needs PHPUnit 9.6 in its own container, D-021). `Support\Cast` is plain PHP and joins both the unit coverage source and the core run.

**Why:** Same reason as D-019: a mutation tool can only judge code its test runner can execute.

**Spec deviation, flagged for Zach:** the spec asks for no escaped mutants in the SQL builders and 90 % MSI on the rest. Those builders are in `src/Repository`, outside this run, and the unit coverage floor does not measure them either; their checks are the integration tests and the grep gates. Running Infection inside the tests container against PHPUnit 9.6 may be possible and is the way to close this. (Done on 2026-10-22 for the SQL builders: D-088.)


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

**Checked 2026-10-23 on Redis:** with Redis 7.4 and Redis Object Cache (Predis) on wp-env, a second `/suggest` was served from Redis (141 ms, then 46 ms, keyspace hits up); a reindex moved the answers to the new generation's keys (`g27-…` to `g28-…`); a synonym added through WP-CLI applied on the next request; the 31st request in ten seconds was answered 429 with `Retry-After`; and the shopper's browser tests (search block, panel, results, the handoff checks, the sticky header) passed with Redis on, 78 of 78. Redis and the plugin were removed after; nothing was added to the project.

**Settled 2026-10-22 (Skatehouse handoff):** the open question is closed. Redis Object Cache is installed and connected on the Skatehouse demo (its comment closes every page, checked 22 October), so the answer is the persistent object cache, as Zach said; the spec's transient fallback does not come back. The demo has no page cache yet; one is on Skatehouse's go-live list, and the block's server render stays free of nonces and anything per user or time-limited so a page cached for days still searches (handoff item 4).

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

**Superseded by D-086 (2026-10-22):** products are now ranked by one statement; the other reads stay as described here.

The spec says a suggest call is "one SQL statement against the index plus one against the documents table". The searcher from milestone 2 asks the store level by level: a cold `/suggest` runs 15 to 18 statements, all on primary or unique keys (the index state, the synonyms, a prefix lookup, the postings of each token's terms for products and for terms, a trigram lookup only when nothing else matched, the document count, the documents and their SKU keys). At 300 products in two languages the golden set's p95 is 6.3 ms cold and 0.4 ms warm against budgets of 100 and 60.

The same holds for a results page without a persistent object cache: the warm page adds one query (the live generation, every table counted in the test), a cold one 15 to 18, and a no-results page adds the nearest-categories search (cached with the answer).

**Why:** Compiling the widening plan into one statement would duplicate the Domain's logic in SQL and lose the in-memory store the golden set is proven against (D-028). The budgets are what the statement count was for, and they hold. Flagged for Zach: if a shop shows a slow database round trip, the first step is to merge the two postings reads per level.

### D-056 · Milestones 3 and 4 ship as one commit (and 5 and 6, D-066) · 2026-10-21

The milestone 3 review's fixes (the store interface, the container's hook list, the CLI registration) touched files milestone 4 was already changing in the same tree, and the integration suite runs only against the working tree wp-env mounts. Milestone 3 alone could not be verified, so both are one commit, tagged `v0.4.0`, with a review for each in `docs/reviews/`. There is no `v0.3.0` tag.

**Why:** A tag for a state no test ran against would be a claim, not a check.

## Milestone 5: the results page

### D-057 · How the results page is changed, in classic and block themes · 2026-10-21

The plugin ranks the main query of a product search (`post_type=product`, WooCommerce's own search form and the block's) and nothing else. It hooks `pre_get_posts` at priority 20, after WooCommerce, sets `post__in` to the ranked ids (or `array( 0 )` when there are none) and removes the `posts_search` clause for that query. WooCommerce already makes "Relevance" the first entry and the default sort on a search; the plugin maps it to `post__in` order through `woocommerce_get_catalog_ordering_args`. The count and corrected lines print on `woocommerce_before_shop_loop` (priority 5) in classic templates and before the `woocommerce/product-results-count` block in block templates; the no-results state prints on `woocommerce_no_products_found` (WooCommerce's own notice taken off it on such a search, D-122) and after `woocommerce/product-collection-no-results`. A search that leaves exactly one product redirects to it, as WooCommerce does by default.

**Why:** The spec says "unchanged template" and names Kadence and Storefront (classic) while the shop may move to a block theme; hooking WooCommerce's own extension points serves both without a template override.

**Superseded in part by D-118 (1.3, Zach's choice to follow Baymard):** a sort other than Relevance no longer keeps every match; it keeps the strong ones.

### D-058 · "Search instead for" turns the retry off; the nearest categories come from the term suggestions · 2026-10-21

The corrected line's link adds `csearch_exact=1`, which runs the shopper's words with `SearchOptions::$retry` off (new in the Domain, default on, tested to 100 % mutation). The no-results state's "three nearest categories" are the category documents the query's words point to (the same term suggestions the panel shows); when the words point nowhere, the three largest categories, without WooCommerce's "Uncategorized". The owner's fallback products are rendered with WooCommerce's own `[products]` shortcode class, four at most.

**Why:** The spec names both features without saying how "search instead" avoids correcting itself or what "nearest" means.

**Changed for 1.1 (Skatehouse handoff, item 8; D-096):** the no-results state no longer starts with the nearest categories. Above them, in order: the posts and pages that match, a help line linking to a page the owner picks, and a second link to another page the owner picks (both settings, with translated generic defaults; Skatehouse's wording goes in its settings). The nearest categories and the fallback products follow as before. A search that finds no product but finds a page leads with the page and never shows the bare no-results state.

### D-059 · Storefront 4.6.2 and Kadence 1.5.2 in wp-env; a shared browser fixture · 2026-10-21

`.wp-env.json` installs both themes from WordPress.org, pinned. `tests/e2e/fixtures/shop.php`, run once by Playwright's global setup, launches the store (WooCommerce's "coming soon" mode is on in a fresh install, which hid the shop from every logged-out test), restores WooCommerce's placeholder image and its sizes (missing in the environment; WebKit requested the 768 px one and logged a 404), loads ten hero products and reindexes. The axe helper moved to `tests/e2e/support/axe.ts`.

**Why:** The milestone's tests are named against Kadence and Storefront. Themes in the test environment are not plugin dependencies; nothing ships with them.

### D-060 · The block's state machine is tested on Node's own test runner · 2026-10-21

The spec says "jsdom for the state machine". This version of `@wordpress/scripts` runs unit tests with Vitest, and neither Vitest, Jest nor jsdom is installed; CLAUDE.md says to ask before adding a dependency, and Zach was not there to ask. `npm run test:js` runs `tests/js/view.test.mjs` on `node --test`, importing the shipped `assets/src/block/view.js` through a module hook that swaps `@wordpress/interactivity` for a double with the runtime's generator-as-async behaviour, and a small DOM double for the dozen browser calls the module makes. The 13 tests cover typing, the debounce, stale answers, the cache, keyboard moves, the live region's words, failures, the beacon and recent searches; the real DOM is covered by 35 Playwright runs of the block on three engines.

**Flagged for Zach:** approving `vitest` and `jsdom` as dev dependencies would let these tests move to the spec's tool with no change to what they check.

**Update, 2026-10-22:** Zach approved Vitest. `npm run test:js` now runs the same 13 tests on Vitest 5.0.3 (`vitest.config.mjs` aliases `@wordpress/interactivity` to the double, which replaces Node's module hook; fake timers through `vi`). jsdom was not needed: the module makes a dozen browser calls, and the existing DOM double covers them.

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


### D-076 · The site language is the setting; translations load from the plugin's `Domain Path` · 2026-10-21

`Languages::siteCode()`, which the query falls back to and which the index always holds, reads the language set under Settings → General (`WPLANG`, the network's on multisite, `en_US` when empty), not `get_locale()`. The plugin does not call `load_plugin_textdomain()`. WordPress has registered an active plugin's `Domain Path` folder for just-in-time loading since 6.8, and the floor is 7.0, so `languages/creative-search-fr_CA.mo` loads without a call. A WordPress.org language pack would still win where it has a string.

**Why:** the French browser run found the fallback to English failing. A visitor whose locale a language switcher or multilingual plugin sets to `fr_CA` made `get_locale()` say `fr`, so French fell back to French. An early draft of this milestone added the call; Plugin Check warned about it, and it is redundant on the floor, so it was taken out before the tag.

### D-077 · "See all" has a singular and a plural · 2026-10-21

The suggestion panel's last row was one string, "See all %1$s results for %2$s", which said "See all 1 results". It is now `_n()` evaluated for 1 and for 2 on the server and passed to the script as two forms; the script picks the first when the total is 1. That is right for English and French. A language with more plural forms (Polish, Russian, Arabic) would need the count's own form chosen by its plural rule, through script translations in the view module; the panel's other count strings already avoid this by being built for every count from 0 to the maximum on the server.

**Why:** the rule is `_n()` for plurals; the French run showed the row as "Voir les 1 résultats".

### D-078 · A category's count is WooCommerce's, subcategories included · 2026-10-21

A category, tag or brand document's product count is WooCommerce's `product_count_{taxonomy}` term meta (visible products, the subcategories' included; WooCommerce keeps it for `product_brand` too), falling back to the term's own `count` until WooCommerce has written the meta. When a product's terms change, the terms' parents are re-indexed too, since their count includes it; when its catalogue visibility or its out-of-stock flag changes (WooCommerce's `product_visibility` terms), its categories, tags and brands are re-indexed, since WooCommerce's count follows visibility.

**Why:** a parent category such as "Skateboards" or "Apparel" showed "(0)" because the raw term count leaves out products that only sit in subcategories. The spec's "Decks (122)" means the number the shop shows.

### D-079 · axe's `scrollable-region-focusable` is exempt on the open panel · 2026-10-21

**Superseded by D-092 (1.1):** the panel no longer scrolls, and the exemption is gone.

When nine rows (six products, two terms and "See all") are taller than the panel's `min(70vh, 36rem)`, the panel scrolls. axe then reports `scrollable-region-focusable` (serious), because nothing inside the panel takes focus. In the APG list-autocomplete pattern, focus stays in the input and the options are reached with `aria-activedescendant`, and the script scrolls the active option into view on every arrow key. A keyboard user therefore reaches every row, which is the need the rule protects. The French browser test proves it: Up from the field brings the last row into view. That one rule is turned off for that one check, through the shared axe helper's `exempt` list. It applies in every other state and region. The same run found and fixed a bug: Up with no row chosen went to the second-to-last row, not the last.

**Why:** making the panel focusable to satisfy the rule would add a tab stop that the pattern says must not exist. Hiding rows to avoid scrolling would drop suggestions.

### D-080 · The French browser run uses a must-use fixture and a cookie · 2026-10-21

`tests/e2e/fixtures/french.php`, mapped into wp-env as a must-use plugin, answers `csearch_languages` with the catalogue's French text the way skatehouse-core will. For a visitor with the `csearch_lang=fr` cookie, it sets the locale to `fr_CA` and WooCommerce's price format to `84,99 $`. It does nothing unless the shop fixture set the `csearch_e2e_french` option, so the integration tests never see it. No multilingual plugin is installed.

**Why:** the spec asks for a French run of the browser tests, and Skatehouse makes its French site with its own code, not WPML or Polylang. A cookie lets the English and French specs share one site and one index.

### D-081 · The FiboSearch comparison's measured half was run on the generated catalogue · 2026-10-21

The spec asks for the measured run on a copy of Skatehouse. That copy needs Zach, so the run in docs/BENCHMARK.md used the generated 300-product catalogue and says so in its heading. FiboSearch free 1.34.3 was installed in wp-env for the run and then removed, with its options. The golden set's pass rate is reported with its bias: the set describes this plugin's rules.

**Why:** the comparison was due in this milestone. An interim run on known data is better than none, provided it is labelled as interim and repeated on the real shop.

### D-082 · docs/QUERIES.md is a test · 2026-10-21

`QueriesExplainTest` (group `benchmark`) catches every statement a suggestion and a results page run on the 300-product bilingual catalogue and `EXPLAIN`s it. It fails when a read of a plugin table has no key it could use; it accepts the optimiser reading a small table whole when a key exists. The plans in docs/QUERIES.md are copied from its report.

**Why:** the spec says the EXPLAIN output is "kept current whenever the schema changes". A test is the only way that stays true.

### D-083 · The matrix's other legs, and the i18n check · 2026-10-21

The compatibility matrix gains four integration legs beside the two version legs (D-011): MySQL 8.0 and MariaDB 10.6, each a throwaway container on wp-env's Docker network that the test suite reaches through `WORDPRESS_DB_HOST` (`tools/matrix-db.sh`); HPOS off, through a `pre_option` filter in the test bootstrap; and a persistent object cache, by telling WordPress one is present (`wp_using_ext_object_cache( true )`), so transients and the plugin's caches take the object-cache path. `tools/integration.sh` passes `CSEARCH_DB_HOST`, `CSEARCH_HPOS`, `CSEARCH_OBJECT_CACHE` and `CSEARCH_BENCH_PRODUCTS` into the container, so a leg runs the same way on a laptop and in CI. Plugin Check and the browser tests stay on the two version legs, whose site runs on wp-env's own database. The object-cache leg uses WordPress's in-memory cache, not Redis or Memcached: it proves the code paths, not a particular server, and adds no dependency.

`composer i18n:check` (`tools/i18n-check.php`, run on the newest leg in CI) fails when the committed POT is missing a string the code has or keeps one it lost, and when `fr_CA` has an untranslated or fuzzy entry, a missing plural form or placeholders that differ from the source's.

**Why:** the spec's matrix names MySQL 8.0, MariaDB 10.6, HPOS on and off, and with and without a persistent object cache; D-011 left the MySQL leg to this milestone; and the spec's i18n evidence is "the i18n check in CI". Without a check, the translation is complete today and nothing keeps it so.

### D-084 · The POT is made from the built scripts · 2026-10-21

`composer i18n:pot` excludes `assets/src`, so the JavaScript strings are read from `build/block/editor.js` and the unbuilt `assets/admin/tools.js`, the files WordPress loads. WordPress names a script's translation file after the MD5 of the script's path relative to the plugin. Strings read from the source files would produce JSON files that WordPress never looks for. Run `npm run build` before `composer i18n:pot`.

**Why:** reading the source files would name the JSON files after paths WordPress never loads, and the editor would stay in English.

### D-085 · The 10,000-product run: two time fixes, and the memory budget missed · 2026-10-22

The first full-size run (`CSEARCH_BENCH_PRODUCTS=10000`, 10,000 products and their variations, two languages) met the reindex budget (99.9 s against 300 s) but missed every request budget: `/suggest` p95 cold 214 ms, `/search` 209 ms, memory 14.5 MB. A query matching a common word reads thousands of documents: "deck" reaches 2,740 products. Two changes followed, neither touching the schema:

- `IndexStore::documents()` takes the query's key. The repository then reads that one SKU key by the terms table's unique index, not every SKU key of every matched document. The SKU keys are only ever compared with the query's key (the exact-SKU bonus and the hidden-by-SKU rule), so the answer is the same. That one read was the slowest statement on common words.
- `Repository::rows()` releases wpdb's own copy of the last result once it has been converted, so thousands of postings are not held twice through the next statement.

Measured again through the API: `/suggest` p95 cold 81.4 ms (budget 100), warm 0.4 ms (60), `/search` 76.9 ms (100). **Memory p95 is 8.41 MB against a budget of 4 MB: missed.** The postings of one common term, held as the Domain's arrays, take 3.3 MB on their own; wpdb's buffered result and its conversion to arrays roughly double that at the peak. The spec's design for this is one SQL statement that intersects and scores in the database (spec, "Query and API"). The milestone 4 searcher reads level by level and scores in PHP instead (D-028, D-055), which holds at 300 products and not at 10,000 for memory.

**Why:** the time fixes are exact and cheap. Meeting 4 MB needs either the spec's in-database scoring, which duplicates the Domain in SQL, or a compact postings structure in the Domain and a streamed read past wpdb. Either is a design change for Zach to choose, not something to slip into the 1.0 milestone overnight. The benchmark still asserts 4 MB, so the 10,000-product CI leg stays red until that choice is made.

### D-086 · Products are ranked by one statement in the database · 2026-10-22

Zach chose the spec's design after D-085: the widening plan runs as one SQL statement. The searcher still decides what to look for in PHP, from small lookups: each token's exact term, spellings, stem, synonyms, prefix terms and typo candidates, and each term's df over the query's language (`IndexStore::frequencies()`, D-050). It hands the store a `Domain\RankPlan`: per token its terms with their match weight and idf, the field weights, the minus words' terms, the quoted phrases, the query's key, hidden-by-SKU, the owner's rules and the limit. `Repository\RankRepository` runs it as one statement with common table expressions, which MySQL 8.0 and MariaDB 10.6 both have:

- per posting, w · m · (1 + ln(1 + tf)) · idf, the best field per token, AND across tokens by `HAVING COUNT(*)`;
- the phrase bonus and the quoted phrases from each token's first position per field;
- the exact-SKU bonus and hidden-by-SKU from the `=`-prefixed SKU-key term (D-041);
- the exact-name bonus from `csearch_documents.name_key`, a column Zach approved (schema 2, the key written at index time);
- the stock penalty, the owner's boost and rules (through `term_relationships`), the minus words by `NOT EXISTS`;
- the order (score to six decimals, in stock, sales, newest, id) and the limit.

Only the page of results reaches PHP. The term ids are read first, so the statement joins integers and never compares a literal's collation with a column's. Floats go to SQL in PHP's round-trip form.

`Domain\Ranker` keeps the same ranking in PHP: the in-memory index of the unit suite, and `explain` (`wp csearch explain`, the Tools tab's test search), which needs every part of every score. `RankParityTest` holds the statement to it: the golden set and 240 generated queries (words, prefixes, typos, minus words, quoted phrases, SKUs, both languages), each in three settings rounds (defaults; rules with hidden-by-SKU off; custom weights with it on), about 870 comparisons of ids, order and totals, on every matrix leg. Changing the adjacent-phrase bonus in the SQL by one point failed it at once. `Hit::compare()` compares totals to six decimals, because the two engines sum the same parts in a different order.

The schema moves to version 2. The upgrader now also runs before the indexer writes, because a job or a save can come before any admin request after an update. When it upgrades an index built under schema 1, it queues a rebuild, so every document gets its name key. Until the rebuild finishes, an older document's name key is worked out when it is read, and the statement does not give the exact-name bonus to that document.

**Why:** at 10,000 products, ranking in PHP held every matching product's postings in memory: 8.4 MB at p95 against the spec's 4 MB (D-085). The spec asked for one statement, and D-055 had deviated from it.

### D-087 · Term counts are stored; postings carry their language and kind · 2026-10-22

Zach asked whether the in-database search follows industry practice and approved the two fixes that answered him (a schema change, still schema 2, since 1.0 has not been tagged):

- **`csearch_term_stats` (term, generation, language, df)**, a seventh table. An inverted index keeps a term's document frequency beside its postings (Lucene stores `docFreq` per term); D-044 and D-050 had the searcher count it at query time instead, with `COUNT(DISTINCT)` over every posting of the term joined to its document. The indexer now adds one to each of a document's terms when it writes it and takes them off when it removes it (`Repository\StatRepository`), and drops a row that reaches zero. The search reads the count by its primary key. The dictionary's own `df` still orders prefix candidates and typo candidates.
- **`csearch_postings.lang` and `.product`**, written with the posting, and a primary key that leads with them: (term, generation, language, kind, document, field). Every postings read of the search (the ranking statement, the category, tag and brand lookups, the retry's product count, the SKU key, the minus words) names a term, a generation, a language and a kind, so it is a range scan of the clustered key; none joins `csearch_documents` to filter any more.

An index built before this is migrated in place (`Install\Migration`, run inside `Schema::install()` so activation and the upgrader both take it): the two columns added and filled from the documents, the primary key replaced (`dbDelta()` never replaces one), then the counts made from the postings. Until the migration is recorded, searches read the index as schema 1 and writes wait (D-090); the rebuild D-086 queues follows. `IndexerTest` holds the counts to a recount from the postings after writes in two languages, a rewrite, a removal and a full reindex with its cleanup; `SchemaTest` migrates a schema-1 table and checks a second `dbDelta()` changes nothing.

**Why:** both are the standard layout for an inverted index, and both take work off the shop's database on every search (no per-search count over the postings of common words, no lookup of each posting's document). The spec names six tables; this adds one for the reason above.

### D-088 · Infection reaches the SQL builders, inside the tests container · 2026-10-22

Zach's answer on D-048 was to try. It works: `composer infection:sql` runs Infection 0.35 inside wp-env's tests container under `phpdbg` (the container has no pcov or Xdebug) against PHPUnit 9.6 and the WordPress suite (`tools/integration/infection.json5` and its `phpunit.xml`), over `src/Repository/RankRepository.php`, `PostingRepository.php`, `StatRepository.php` and their base `Repository.php`. The tests it runs are the ones tagged `@group sql-mutation`: the parity test on 40 products with 60 generated queries and every hidden product's SKU in three settings rounds, the stored-count test, and `PostingRepositoryTest`, which holds the postings reads to a recount (the parity test cannot see them, because both of its sides read through them). One thread, because the tests share one database; about 40 minutes. `SchemaOneReads` (D-090) is left out: it is 0.7.0's postings reads moved into their own class and only runs before an upgrade, and `UpgradeWindowTest` holds it to the schema 2 answers; its test changes the schema, which the mutation run's shared database cannot undo between mutants.

Three things it needed: it runs from `tools/integration/` (Infection resolves the source folders against its config file and the coverage filter against the PHPUnit config's folder, so the two must be one folder); Infection's own process takes the unit bootstrap, because the plugin's files `exit` without `ABSPATH` and that ended Infection silently with code 0; and no exclude may be a suffix of other files (Finder's `notPath()` matches anywhere in the path, so excluding `Repository.php` had excluded every repository).

The result: 324 mutants, 260 killed, 64 escaped, none uncovered (80 % MSI). The first run escaped 74 and showed two real gaps, both closed: `StatRepository::frequencies()` and the postings reads had no direct test (a broken read fed both sides of the parity test alike). The 64 left are equivalent, each read one by one:

- casts PHP makes anyway: a numeric string used as an array key becomes an integer, and `%d` casts what it is given (`(int) $row['document_id']`, `(string) $term` and the like);
- chunk sizes (500, 1000) moved by one, which only change how many statements a batch takes;
- `min( $tf, 65535 )` moved by one (no field repeats a term 65,535 times);
- early returns that skip a statement whose answer is empty anyway (no ids, no term for a token, no rule);
- `$wpdb->flush()` removed (memory only) and `run()`'s return value (no caller reads it);
- the hidden-by-SKU flag's `0` becoming `-1` (both are not 1; its `1` becoming `0` was killed);
- the rule references' parsing (`array_pad`, `explode`'s limit, the `isset … &&` guard) on references that are always well formed, because the settings sanitiser writes them.

**Spec deviation, flagged for Zach:** the spec asks for no escaped mutants in the SQL builders. None of the 64 changes what the shop sees, but they are not zero. Making them zero would mean an ignore list in the config (the Domain run has three such entries, D-030) or code written for the tool. Neither was done without asking.

**Closed 2026-10-23 (Zach: finish it):** the run over 1.3's code found 105 escaped, 41 of them in `FactRepository` (1.3's facts table), which no test in the group held. Two real gaps were closed with tests: `FactRepositoryTest` (writes in order across two statements, 191-character cuts on characters not bytes, no image as NULL, empty term pieces dropped, reads and deletes by document only), and `RankParityTest` now quotes neighbouring words, asserts that a phrase finds products, and adds queries with two phrases (phrases are numbered from 1, so with one phrase a mutant reading the wrong flag still read the right one). `PostingRepositoryTest` holds `productCount()` of no words to 0. The rest were read again one by one and are the equivalents listed above, plus `FactRepository`'s: a column cut one longer than its `VARCHAR(191)`, which the database cuts anyway; `array_fill()` start indexes, which `implode()` ignores; and phrase-flag casts on integers. They are ignored in `tools/integration/infection.json5`, per mutator and by line (`ignoreSourceCodeByRegex`), with four small methods' early returns ignored by method; any new mutant on another line still runs. Result: 388 mutants, 292 killed, 96 ignored, 0 escaped (100 % MSI). The cost: a line is ignored for every mutant of that mutator on it, so about 24 mutants that were killed share a line with an equivalent one and are no longer run (the run before the ignores killed 316).

### D-089 · A reindex refreshes the index statistics of the tables it rewrote · 2026-10-22

When a reindex's cleanup finishes, it runs `ANALYZE TABLE` on `csearch_documents`, `csearch_postings` and `csearch_term_stats` (each repository's own `analyze()`, called from `Indexer::forgetGeneration()`). The query plans in `QueriesExplainTest` showed why: on statistics taken before a bulk rewrite, MariaDB chose to read `csearch_postings` whole for the category lookups and the ranking statement, though the primary key served them; with fresh statistics every postings read is a range of the primary key (docs/QUERIES.md). InnoDB refreshes statistics by itself once a tenth of a table has changed, but in the background and later; refreshing them when the bulk write ends is the usual practice. It runs in the cleanup job, once per reindex, never on a shopper's request.

`ANALYZE TABLE` commits implicitly, which would end the integration suite's per-test transaction, so `IntegrationTestCase` records the tables a statement names and runs a no-op instead; `ReindexTest` asserts the three are refreshed once, after the cleanup. `QueriesExplainTest` waits for InnoDB's own refresh (`mysql.innodb_table_stats`) before it explains. The API benchmark runs the recorded statements on a second connection (`analyzeAside()`), which leaves the test's transaction open: on statistics taken from the emptied tables the planner chose differently and the 300-product `/suggest` p95 went from about 5 ms to 25 ms.

On MySQL before 8.0.24, `ANALYZE TABLE` also evicts the table's definition, so the next statement on it waits for the queries already running on it; the cleanup is a background job and the three tables' queries are short, so the wait is milliseconds. MariaDB and later MySQL do not do this.

**Why:** a search plan should not depend on when the database last sampled a table that a reindex has just replaced.

### D-090 · The window between an update and its schema upgrade · 2026-10-22

The review of D-086 and D-087 found what happens between deploying 1.0.0 over 0.7.0 and the upgrade running. Searches would have run the schema 2 statement against schema 1 tables and found nothing. A migration cut off halfway would never be retried. A write in between would count terms twice or lose them. What was done:

- **Reads:** while `Upgrader::needed()`, `IndexRepository` reads postings, term counts and product counts through `Repository\SchemaOneReads` (the schema 1 joins to the documents) and ranks in PHP (`Ranker`), as 0.7.0 did. `UpgradeWindowTest` holds the answers to six queries identical, in the same order with the same totals, before the upgrade, while it waits and after it.
- **Writes:** the indexer refuses to write while an upgrade is due and cannot run, with a translated message ("The search index is being upgraded by another request…"). The job is marked failed and Action Scheduler does not retry it: the write is dropped, and the rebuild the upgrade owes writes that product with every other. A full reindex returns the same job, or throws for `wp csearch reindex`. Nothing is half-written.
- **Who upgrades:** `admin_init`, every `wp csearch` command and activation, as before, now all through the lock (activation used to call `Schema::install()` directly), and a job, `csearch_job_upgrade`, which any request queues once when an upgrade is due, so a shop whose owner does not visit the admin is upgraded on the next Action Scheduler run. A shopper's search never runs it.
- **Resumable:** `Migration` checks before each step whether it is done: columns added only if missing, rows filled in batches of 5,000 documents with the lock's heartbeat after each, so a long backfill is not taken over as stale; the key swapped only if it differs; the counts remade from the postings. The version is recorded only when `Migration::complete()` sees schema 2 tables, so an upgrade cut off anywhere runs again on the next request. `SchemaTest` cuts it off after the columns and before the key swap, and runs it twice. A request that loaded the version before another finished the upgrade reads it again from the database before and after it takes the lock, so it never runs the migration a second time (`SchemaTest`, the stale version).
- **The rebuild:** owed as an option (`csearch_rebuild_owed`) when an upgrade from schema 1 completes. The option is 0 until a reindex starts, then holds that reindex's generation. The rebuild is queued once nothing is running, and again after any reindex's cleanup while it is owed. Only that generation going live settles it (generation numbers are reused after an abandon, so they cannot be compared), so neither a reindex that was already running (it started before the name keys existed) nor an owed rebuild that is cancelled or abandoned loses it.
- **The counts:** written after the postings they count, in the same write, so a failure between the two leaves the counts behind the postings and the next reindex corrects them.

**Why:** a shop must search through an update as it did before it, and an upgrade that stops halfway must finish on its own, without the owner doing anything.

### Zach's answers on the flagged decisions · 2026-10-22

- **1.1, the postings' `product` column (2026-10-22):** approved: posts and pages are marked 2 (0 terms, 1 products), with their term counts kept apart from the products', so turning them on changes no product's rank. No DDL and no migration (D-093).
- **The competitor scan and the Baymard audit (2026-10-22):** Zach wants every gap both found, the ones needing a schema change included (the variation's own photo when a search names it). Planned as 1.1 (posts and pages, plus the small autocomplete and results-page fixes) and 1.2 (the rest), each reviewed and tagged on its own. Posts and pages stay off by default (Claude's recommendation; Zach did not change it). "Why this matched" is the slim version Zach chose: one small line under a result's title, shown only when the match came from something the card does not show (SKU, an attribute value, the description), e.g. "Matches SKU KZ-825"; last in 1.2.
- **D-001 to D-004:** confirmed (Zach was told that anything not mentioned stands).
- **D-034:** kept: the retry runs only when the AND finds nothing.
- **D-048:** try running Infection inside the tests container against the repositories as part of the in-database scoring work (D-086); if that cannot be made to work, the integration tests stay the check for that code.
- **D-051:** the shops' host (the same company as the demo site) is believed to run LiteSpeed. The answer is a persistent object cache, not a transient fallback: the plugin keeps using `wp_cache_*()`, and the host's object cache provides persistence. Which one depends on what the host offers. Checked 2026-10-22: the demo (zachatkinson.com/demos/skate) is on Foxpress Cloud (warren.foxpress.cloud; cPanel with CloudLinux), answers with `server: Apache` and no LiteSpeed headers, and has no object-cache drop-in or cache plugin installed; Foxpress's plan pages do not mention Redis or Memcached. Open: ask Foxpress whether Redis is available. If it is, Redis Object Cache (Till Krüss, the most installed standalone one) is the choice; if not, the spec's transient fallback comes back on the table.
- **D-060:** `vitest` approved as a dev dependency, and `jsdom` too if Vitest alone is not enough.
- **D-062:** confirmed.
- **D-070:** confirmed: the boost and exclusion stay on the parent product.
- **The exact-name bonus under in-database scoring:** a `name_key` column on `csearch_documents` is approved (the schema change CLAUDE.md asks about after milestone 1). See D-086.
- **Stored term counts and the postings' language and kind:** approved ("we should do it - we need to stick to standards and best practices"). See D-087.

### Zach on 1.1 · 2026-10-22

Posts and pages in the results (the spec's 1.1) come after the 1.0 tag, the same day. Zach: posts and pages are both off by default, each with a toggle in the admin. Where they show (the suggestion panel, the results page) is settled when 1.1 is built.

### Zach on the Skatehouse handoff · 2026-10-22

docs/research/SKATEHOUSE-HANDOFF-2026-10.md is folded into the plans: items 1 to 4 (the search dialog, the query kept in the field, classes on a suggestion for Mature Content, safe under a page cache) and 5 to 8 (arrow keys write the field, units and number forms, pages, the no-results state) in 1.1; items 9 to 11 (a size becomes a removable filter, a category query opens the category, tests on a copy of Skatehouse) in 1.2, on their own so the shop's go-live waits on nothing else; the rest of docs/research (the variation's own photo, "Matches SKU", the remaining Baymard and competitor items) in 1.3. Zach left the split to Claude ("whatever is best for you"). Zach allowed SPEC.md's keyboard paragraph to be updated for D-091 (handoff item 5); it was. No schema change is planned for any of it: the pages use the approved kind 2, the attribute terms of item 9 live in an option, the exclusion box in post meta.

### D-091 · The arrow keys write the chosen suggestion into the field · 2026-10-22

Down and Up move the choice as before (APG list autocomplete, focus kept in the field, `aria-activedescendant` on it) and now also write the chosen row's name into the field; above the first row or past the last, the field shows what the shopper typed again, and "See all" shows the typed text too. Escape puts the typed text back and closes. Writing the field never fetches: the panel keeps answering the typed text until the shopper types again, and Enter after editing the copied text searches the edit. Hovering highlights a row and never writes the field. SPEC.md's keyboard paragraph was updated with Zach's leave.

**Why:** Baymard's autocomplete guideline and the Skatehouse handoff (item 5): a shopper who arrows to "KAZUMI Skate Deck" sees in the field what Enter will act on, and can refine it. The APG pattern allows either; Skatehouse's test plan asks for this one.

### D-092 · The panel never scrolls: a row budget, then fit to the screen · 2026-10-22

Supersedes D-079. The panel has no height limit and no scrollbar. Its rows are budgeted: on a desktop, at most 10 suggestions, the owner's product limit first, the terms (3 at most) giving way, and up to 2 posts or pages taken from the products' share, never added to the 10; on a phone (600 px or less, or a coarse pointer), 4 products and 2 terms. After each render `fit()` drops the last suggestion, one per frame, until the panel is inside the visible viewport; "See all" always stays, and the live region's count follows. It measures only once the rendered rows match the context (the Interactivity API renders after the context changes), waits up to 10 frames for that, and re-measures when the panel's size changes (a `ResizeObserver`: a web font arriving made the first measure 1.5 px short in the browser test). A dropped row that was chosen sends the choice back to the field, and a kept one keeps its choice under its new id, so `aria-activedescendant` never names a row that is gone (axe's `aria-valid-attr-value` caught it in the French run).

**Why:** Baymard: 4 to 8 suggestions on a phone, at most 10 on a desktop, and no scrollbar in the panel. D-079's exemption is gone: the French browser test runs axe on the open panel with every rule, and the 1.1 tests check that the panel fits at 1280×720 and 390×664.

### D-093 · Posts and pages: their own kind, their own counts, their own group · 2026-10-22

Reshaped by the Skatehouse handoff (item 7). Settings `index_posts` and `index_pages`, both off by default (Zach). Their postings are kind 2 (`Domain\Kind`: 0 terms, 1 products, 2 posts and pages), a value in the existing column, approved by Zach with no DDL. Their term counts are counted from their own postings and never added to the stored product counts, and N is counted over them alone, so turning them on changes no product's rank. A content search is a second pass over kind 2 with no boosts, no hidden-by-SKU rule and no exactness key; its prefix candidates come from the content postings. A typo is corrected only toward words the catalogue's dictionary holds (the trigram table is the catalogue's; `MemoryIndex` now asks for df > 0, as the SQL does), so a word only a page uses is matched exactly or as a prefix.

What is indexed: a published post or page of a type switched on, without a password, not excluded with the editor's "Exclude from search" box (`_csearch_exclude`), and not the front page or WooCommerce's shop, cart, checkout or account pages. The title is the name, the excerpt the short description, the content the long one (2,000 characters), and a page's parent's title the category, only while the parent is published with no password (a private parent's title is never indexed or shown). Saving a page queues its children, and a page that becomes or stops being the front page or a WooCommerce page is read again. Filters `csearch_index_content` and `csearch_content_document`; `csearch_languages` takes a `content` reader. Switching a type on queues its posts (100 a job); switching it off deletes its documents in batches.

Where they show: in the panel, after the products and terms, 2 at most, the first under a visible group heading ("Pages", "Posts" or "Pages and posts", by what is on). The heading is `aria-hidden`, because a listbox holds options only; each row's accessible name says "Page" or "Post" instead. On the results page, 3 at most (`csearch_content_limit` can raise it to 10, and only lower the panel's 2), above the products, or after them when a product's name matches the query exactly. When no product matches and a page does, the page leads ("No products match “size guide”, but these do:"). WooCommerce's jump to a single result is kept off while a page matches. Clicks on them are logged as `post` and `page`.

**Why:** the spec's 1.1, and Skatehouse's size guides and shipping page, which shoppers search for in the product search.

### D-094 · The query stays in the field; the panel says when a word was left out · 2026-10-22

On a search page the block's field holds the query it answers (`get_search_query( false )`, escaped once by the markup), with the clear button shown and the panel closed, in both modes. When `/suggest` answers with a word left out (D-034's retry), the panel's status line and the live region say "Showing results for …".

**Why:** Baymard: keep the query in the field on the results page so the shopper can refine it, and say when the search changed what was typed. The handoff's item 2.

### D-095 · A suggestion marks what it adds, not what was typed · 2026-10-22

Each word of a suggestion keeps the part the shopper typed plain and marks the rest in bold (`<mark>` styled by weight, not colour, so it holds in forced colours); a word matched by a typo or a synonym is marked whole; with nothing typed, nothing is marked. Comparison is on folded text (accents and case), so "vetements" marks "Vêtements" correctly. The parts are built as text nodes and `mark` elements, never markup.

**Why:** Baymard's autocomplete guideline: highlight the suggested completion, not the typed text.

### D-096 · No results: pages, two links, single words, categories, fallbacks · 2026-10-22

Changes D-058 (handoff item 8). The no-results state reads, in order: the matching posts and pages (leading, as D-093 says), the owner's help link and second link, the single words of the query that each find something ("Try one word:", up to 3, `csearch_alternative_queries`), the nearest categories, the fallback products. The links are settings: `help_page` and `guide_page` (page pickers, a published page or none) and `help_text` and `guide_text` (120 characters, plain text); empty wording reads "Can’t find what you need? Contact us" and "Need help choosing? Read our guides", translated. Filter `csearch_no_results_links`. These replace the uncommitted `help_url`: a page the owner picks cannot be a broken or unsafe link, and it moves with the site.

**Why:** Baymard: a no-results page should never be a dead end. Skatehouse's wording goes in its settings, not in the plugin.

### D-097 · The page dims behind the open panel · 2026-10-22

While the panel is open, a fixed backdrop (25 % black) covers the page below the form; a pointer on it closes the panel. It is `aria-hidden`, hidden in forced colours, and fades only without reduced motion. Filter `csearch_dim_page` turns it off for a theme whose header stacking puts it in the wrong place. Button mode has the dialog's own `::backdrop` instead.

**Why:** Baymard: give the open panel visual depth so it reads as on top of the page.

### D-098 · Sizes are read one way however they are written · 2026-10-22

Extends D-024 (handoff item 6). `Tokenizer::units()` runs on the index side and the query side before words are cut: `8.25in`, `8.25 in`, `8.25 inch`, `8.25 inches`, `8.25"`, `8.25''` and `8.25″` all become `8.25″`, whose mark the tokenizer drops; trailing zeros go (`8.50` is `8.5`); `54 mm` is `54mm`, indexed with `54` beside it at one position (D-025's rule), so a bare `54` finds it; `x`, `×` and `*` between two numbers split them (`9x33`, `33″ x 9″`, `110*38mm`); with French, `8,25` is `8.25` and `po`, `pouce`, `pouces` are inches. A number a letter runs into (`kz825x2`, a SKU) is left alone, and a query ending in an inch mark is not a prefix. Numbers stay out of fuzzing and stemming. Because the index changes, `Tokenizer::VERSION` is 2 and `csearch_analysis_version` records what the index was built under: an update that finds an older value owes one full rebuild through D-090's path (no DDL); a fresh install records the current value, and a shop never built needs none.

**Why:** Skatehouse's names and shoppers write sizes every one of these ways.

### D-099 · Button mode: the same form in a modal dialog · 2026-10-22

The block's `mode` attribute (`field` or `button`) and the shortcode's `mode="button"` render an icon button (accessible name "Search", `aria-haspopup="dialog"`) and the same form inside a `<dialog>`. The button opens it with `showModal()`, so the page behind is inert natively, and focuses the field within the click, so a phone raises its keyboard without a second tap. Escape closes the panel first and the dialog second; the close button and a click on the backdrop close it; opening pushes a history entry so Back closes it instead of leaving the page, and a suggestion or search chosen from it replaces that entry. Focus returns to the button. Full screen at 600 px or less, with the panel under the field. With no script (`@media (scripting: none)`) the form shows in place and searches.

**Why:** handoff item 1: Skatehouse's header has room for an icon only.

### D-100 · The exact-name bonus counts the name being typed · 2026-10-22

While the last word is still being typed (a prefix), the exactness bonus also counts each completion of it the search used: "size guide" is the exact name of "Size guides" as the shopper types it. `RankPlan::nameKeys()` holds the query's key and those completions; `Scorer` and the ranking statement (`d.name_key IN (…)`) compare against all of them. A finished word (followed by a space) is not completed. The golden set is unchanged.

**Why:** found writing the pages' tests: "size guide" ranked a product named "Size guides" below one that only mentioned guides, because the exact name was only exact once the word was finished.

### D-101 · A size in the query becomes the shop's own filter · 2026-10-22

Handoff item 9. A new setting, `filter_attributes` (Tools → Results page, none ticked by default), names the attribute taxonomies a query may filter by. `Index\AttributeTerms` keeps, in the autoloaded option `csearch_attribute_terms` (so a search page reads nothing for it), each of their terms by the folded word its name reads as through the same tokenizer a query goes through (`8.25″`, `8.25 in` and `8.25` all read `8.25`; `8.0` reads `8`); names of more than one word are left out. It is deleted when a term of those attributes is created, edited or deleted and when the choice changes by any path (`update_option_csearch_settings`), and rebuilt by the next search, so the request that saves a term does no work for it.

On the main product search's first page, `Results\QueryRouter` (on `pre_get_posts`, priority 4) parses the query and `Domain\FeatureFilters` takes out each word that names exactly one value: not a minus word, not a word in a quoted phrase, not a word two attributes share (`8` a width and a shoe size stays a word). The hook `csearch_query_filters` can change the result. With a filter and no category (D-102), the shopper is sent (302, on `template_redirect` priority 5) to `?s=<the other words>&post_type=product&filter_width=8-25` (several values joined by commas with `query_type_width=or`), or to the shop page with the filter when nothing else was typed, plus `csearch_from` with the query as typed and `csearch_sig`, the site's hash of it (a `csearch_from` without a matching signature is ignored, so a link made elsewhere cannot put words on the shop's pages). The routed request fetches nothing: no ranking, no search clause, `post__in` of 0. That page shows "Showing products with Width 8.25. Search all products for “deck 8.25”." and WooCommerce's single-result redirect is off there, so the shopper always sees the filter and the way out. Searches that already filter, sort, ask for the exact words or every product (`csearch_all`), or are past the first page, are never routed. The query is logged once, as typed, with the count the page landed on lists (the other words' products with the filter, or the shop's products with it), so a size nobody stocks shows up as a search that found nothing.

**Why:** handoff item 9: a shopper typing "deck 8.25" wants decks in 8.25, and WooCommerce's own filter is visible and removable where a required word is not. The filter is WooCommerce's, so its widgets, blocks and counts agree with what is shown. One word per value keeps the match exact; a shared word is ambiguous, and searching it is the safe reading.

### D-102 · A query that names a category opens it · 2026-10-22

Handoff item 10. With `category_redirect` on (the default), a product search whose remaining words (after D-101) name a category whole opens that category. `Domain\CategoryMatcher` compares by stem: every word of the query matches a different word of the name and every word of the name is matched, a word's synonyms counting as the word (so "decks" names Decks but not Fingerboard decks, and "deck" names Decks). Names are read in the query's language through the `csearch_languages` term reader, stop words left out, and kept in the autoloaded option `csearch_category_stems`, deleted when a category is created, edited or deleted and when a rebuild goes live. Only categories that list products count (checked for the matched ones only), never the default "Uncategorized". The redirect happens when exactly one category matches and no product is named exactly as the query reads (D-063's test, so a product called "Bearings" still wins); the target is the category's link with D-101's filters and `csearch_from`. Two or more matches: no redirect, even with a size in the query, and the results page for every word typed leads with "Categories: A and B", each a link; a name two of them share carries its parent ("Wheels (Skateboards)"), so no two links read the same (WCAG 2.4.4). With `category_redirect` off, no category is matched and no such line is shown. A query with a minus word or a quoted phrase is never sent to a category. The category page says "Showing Wheels. Search all products for “wheels”.", the link carrying `csearch_all=1`; classic themes print it on `woocommerce_before_shop_loop` (or above "No products were found"), block themes before the product results count or the no-results block. The query is logged once with the category's product count (with D-101's filters applied when there are any). The search field on that page keeps the query as typed. Hook: `csearch_category_redirect`.

**Why:** handoff item 10, and Baymard: a query that is a category name is best answered by the category page, with its own filters and copy; the line keeps the search one click away. Two categories of one name are not the shopper's choice to guess, so both are offered.

### D-103 · Tests on a copy of Skatehouse, from the shop's own exports · 2026-10-22

Handoff item 11. The copy is two files Zach exports into the gitignored `tests/fixtures/skatehouse/`: the products CSV from WooCommerce's exporter (all columns) and the pages from Tools → Export. `tests/Support/SkatehouseCopy` loads them: products through WooCommerce's own `WC_Product_CSV_Importer` with the admin importer's own column mapping (`auto_map_columns`, read by reflection) and as an administrator, so terms are made as the admin's import would make them; pages through a DOMDocument reader of the WXR (title, slug, content, status, order, password, parent), so the WordPress Importer plugin is not a dependency. No customers or orders are read. `SkatehouseCopyTest`, in the default run, round-trips WooCommerce's exporter and `export_wp()` through the loader on the generated catalogue, so the loader is tested before the export exists. `SkatehouseTest` (group `skatehouse`, left out of the default run, `composer test:skatehouse`) loads the copy, ticks every attribute for D-101, sets the contact and size-guides pages as the no-results links, rebuilds, and checks the handoff's golden table; it skips with a message while the files are absent. D-081's FiboSearch run on the copy uses the same loader in the development site (docs/BENCHMARK.md).

**Why:** the golden queries are about Skatehouse's real names, sizes and pages; only the shop's own data tests them. WooCommerce's importer is what the shop itself would use, so a row that fails here would fail there too.

### D-104 · A query that names a variation shows it · 2026-10-22

Each product document now keeps its SKUs and attribute values beside it, in a new table `facts`, one row per value in the shop's order: the product's own attribute values (taxonomy term names, custom options as written), its SKU, then each visible variation's values and SKU (variation SKUs only when `index_variation_skus` is on), at most 300 a product. A row holds the variation id (0 for the product), the field (SKU or attribute), the query argument that chooses it (`attribute_` and the sanitized attribute name), the attribute's name, the value, the label shown, the variation's own image and the value's index terms (the analyzer's terms, plus the `=` SKU key for a SKU, at most 255 characters). The rows are written and deleted with the document, so a rebuild leaves one generation. `Domain\MatchExplainer` names a variation when the query is one of its SKUs in full, or else by the query tokens that reach the attribute values of some variations but not all: the variation reaching most of them wins, the first in the shop's order on a tie. The panel's row and the results page's card (`woocommerce_product_get_image_id`, `post_type_link`, for the main product search only) then show the variation's own photo when it has one and link to the product with its arguments, so WooCommerce opens it with that design chosen. A value every variation shares names nothing, and an "any" value adds no argument.

**Why:** handoff research (docs/research): a shopper who types "mumber fur fire" wants to see the Fire grip and land on it chosen, not the product's first photo and a blank form. One row a value, rather than a JSON column on the document, lets the reader fetch only the shown documents' rows by primary key prefix and keeps the values' terms comparable with the query's without decoding; the table is small (a few rows a product) and never on the ranking path.

**Languages (review minor 5):** the facts are read once, from the product as the shop wrote it, and the same labels go on every language's document; they do not pass through the `csearch_languages` readers. So a French page reads "Correspond à Design : Fire" with the shop's own value, and a French word names a variation only when the shop's value is that word. A language plugin translates them through `csearch_document`'s `facts` (HOOKS.md). **Why:** WooCommerce keeps one set of attribute values per product; the readers of D-076 translate the fields a shopper searches, and a variation's label is shown as the shop wrote it on the product page too.

### D-105 · "Why this matched", for the shown products only · 2026-10-22

A suggestion row and a results-page card say what matched when the name the shopper sees does not: nothing when every query token is in the name; otherwise the query's whole SKU key, then for each token outside the name in order a SKU it reaches ("Matches SKU MUM-FUR-ICE", the named variation's first), an attribute value ("Matches Design: Fire", or "Matches 54mm" when the attribute has no name), then the short or long description ("Matches the description"). A token found only in a category, tag or brand says nothing: the card already places the product, and those are not something the shopper asked of it. The search reports the terms each token reached and the query's key (the reduced query's when a word was dropped, so the line explains what was actually searched); `Search\MatchDetails` reads, for the shown documents only, which of those terms each carries field by field (one join of terms to postings) and their facts, and caches the answer by the document ids, which belong to one generation and are never reused. On the results page `Results\MatchDecorations` works it out lazily, once for the page's products, on the first card that asks; the line prints on `woocommerce_after_shop_loop_item_title` (priority 4) and after the block theme's product title. In the panel it is part of the option's name.

**Why:** Baymard: a result whose reason is not visible reads as a wrong result, notably for SKU and attribute matches. Computing it only for what is shown keeps the search's budgets untouched; the cache keeps a warm results page at its one extra query.

### D-106 · Documents say whether the panel can add to cart · 2026-10-22

Documents gain `cart`: 1 for a simple product that is purchasable and in stock, 0 otherwise (a variable product needs a choice). It is written with the document and kept current by the stock update (`DocumentRepository::setStock`), so the panel's add to cart (fork C of 1.3) reads it from the document without loading the product.

**Why:** the panel answers from the index alone; whether a product can go to the cart in one click is the one more thing it needs to know.

### D-107 · Schema 3, read only once rebuilt · 2026-10-22

`Schema::DB_VERSION` becomes '3' (pre-approved under "Zach's answers on the flagged decisions"). A site on schema 2 gets the `facts` table and the `cart` column through `dbDelta` and owes a rebuild (`Upgrader::rebuildAfter` now owes one for any stored version from 1 up to the current one). The schema-one fallbacks of D-090 now ask `Upgrader::below('2')`, so a site on 2 waiting for its rebuild keeps reading its live index as before; the facts and the per-field terms are read only from 3, so until the rebuild goes live no variation is named and no reason is shown, rather than half of one. `Migration::complete()` also requires the new table and column.

**Why:** an upgrade must never break the search it upgrades; the new features can wait for the rebuild, the search cannot.

### D-108 · The owner's redirect for a search · 2026-10-22

Spec 1.3. On the Redirects and pins tab (D-111) or with `wp csearch redirect add "size guide" /size-guides/`, the owner sends a search to a page instead of the results. A rule answers a query whose words are its words: both are read as a query is (`Domain\QueryRules`, the tokenizer's folded key, stop words out), so capitals, accents, punctuation and "the" do not matter and the order of the words does. A rule names a language or every language; one for the query's language and one for every language: the first stored wins. The target must be on this site: a path such as `/size-guide/` is made a full address with `home_url()`, and the address is checked with `wp_validate_redirect()` when saved and again when used, after the filter `csearch_query_redirect`, so neither the setting nor the filter can send a shopper off the site. `Results\QueryRouter` checks it first, before D-101's filters and D-102's categories, on the same terms as those: the main product search's first page, not `csearch_all`, not a page past the first, not a search that already filters or sorts. The query is logged once, as typed, with the count of products its words rank, so the owner still sees what the search would have found; the routed request fetches nothing, and the shopper goes on with the 302 on `template_redirect` (priority 5). The panel and the REST answers are unchanged: the panel's own submit lands on the results page, which redirects. Adding a rule for words that already have one replaces it. At most 200 rules (`Settings::QUERY_RULES_MAX`); a query 200 characters, an address 2,048.

**Why:** spec 1.3's query redirects. A search for "returns" or "size guide" wants the page, not products that mention it. Same-site only because a stored address is a way to send a shopper anywhere; `wp_validate_redirect` is WordPress's own test and honours `allowed_redirect_hosts`. Logging with the ranked count keeps D-102's rule that a routed query is logged once.

### D-109 · Synonyms of more than one word, matched as a phrase · 2026-10-22

Supersedes D-029's one-word rule. A synonym row with more than one word on either side ("ss" means "stainless steel", "skate shoes" means "sneakers") is a phrase rule (`Domain\Synonyms::phrases()`): when the query's kept words hold its words side by side, in order, the parser (`QueryParser::alternatives()`) makes another form of the query with those words replaced by what they mean. The replacing words keep the place's phrase number, carry their own one-word synonyms, are never a prefix, and score as SYNONYM (`QueryToken::$synonym`, which `Searcher::widen()` turns from EXACT, variant and stem into the SYNONYM kind), so a form ranks a match below the words the shopper typed. `Searcher::run()` ranks the query and each form and merges them: each product once, at its best score, then cut to the limit; categories and tags the same way. At most three forms (`Query::MAX_ALTERNATIVES`), each at most eight words; the spelling retry runs on the query as typed only. A one-word for one-word row works as before (D-029's alternatives). `SynonymService::expand()` and the Synonyms tab's "Test a search" show phrase meanings too.

Behaviour change: a `means` of several words is now that phrase as a whole. Before, "deck" meaning "skateboard deck" added "skateboard" and "deck" each as alternatives; now a search for "deck" also searches the form "skateboard deck". A product that says only "skateboard" no longer matches "deck" through that row.

Caveat: a form's words score as SYNONYM, but a form with more words can match more fields than the typed word, so a product found only through the meaning can outrank one found through the typed word. The golden set and the rank-parity tests are unchanged.

**Why:** spec 1.3, and D-029's own reasoning: a multi-word `word` needed a phrase search per synonym on every query. Matching phrases on the query's kept words in the parser costs nothing per document, and running a form as an ordinary query reuses the whole ranking (and the SQL plan's parity) instead of a new phrase path. Treating a multi-word meaning as a phrase is what owners mean by "ss = stainless steel": steel alone is not stainless.

### D-110 · Products pinned to a search · 2026-10-22

Spec 1.3. On the Redirects and pins tab or with `wp csearch pin add kazumi MNL-PER-00016,123`, the owner pins up to five products (`Settings::PINS_MAX`), by SKU or ID, in order, to a search's words (matched as D-108's are). They come first, in that order, in the panel, on the results page and in `/search`, before the ranked products and each once; the rest follow in their rank. Visibility still rules: a pin that is not published, is hidden from search or excluded, or has no document in the language, is left out (`IndexRepository::productDocuments()`, the same documents a search would find). A pinned search is never sent to a category or filter (D-101, D-102), since the owner chose what it shows. The pins are part of the cache key (`SearchService::settingsKey()`), and saving a rule bumps the cache, so a change shows at once. Filter: `csearch_pinned_products`.

**Why:** spec 1.3's pinned products: an owner who knows the best answer for "kazumi" wants it first, whatever the scores say. Five keeps the panel's own results in view (it shows six by default).

### D-111 · Redirects and pins: one tab, stored in the settings · 2026-10-22

The rules of D-108 and D-110 live in the settings option (`query_redirects`, `pinned_products`), not in a table: they are few (200 each at most), read whole on every search with the other settings already loaded, and fork A's schema change (DB_VERSION 3) is the only one this milestone. A new tab, "Redirects and pins", after "Boosts and exclusions", holds both lists, each rule with a remove box, and an add row for each; one Save button saves both, through `Admin\Actions` (`csearch_rules_save`) and `Search\OwnerRules`, which checks and stores a rule and bumps the cache. Products are entered as SKUs or IDs separated by commas, as the fallback products are, and an unknown one is named in a warning. WP-CLI: `wp csearch redirect list|add|remove` and `wp csearch pin list|add|remove`. The panel script does not change: a redirect happens where the panel's submit already lands.

**Why:** the boosts tab is already long; the two rules are both "for this exact search, do this", so they share the matching explanation once. Text entry by SKU matches the fallback products and works by keyboard and screen reader without a picker script.

### D-112 · Suggested searches from the search log · 2026-10-22

The panel offers up to three searches other shoppers made that start with what is typed ("kazumi" → "kazumi deck", "kazumi dealer"), above the products, two on a phone (Baymard: keyword query suggestions). `Search\QuerySuggestions` reads them from the search log of the retention window (`LogRepository::completions()`: a `LIKE` prefix on `normalised`, escaped, in the panel's language, grouped and counted), keeping only a query that was searched at least three times (`MINIMUM`), found something (`results > 0`), and was either submitted from a results page or led to a click in the panel (`source = 'page' OR clicked_type IS NOT NULL`), so half-typed words the panel searched as they were typed never come back as suggestions. Nothing is suggested before three characters (`MIN_CHARS`). `Domain\Completions` then leaves out the typed words themselves, duplicates by key, anything holding the log's redaction mark, and any query with a word the index does not hold in some form (as written, a spelling, its stem): one dictionary lookup for all twelve candidates read (`SCAN`). The owner's filter `csearch_suggested_searches` has the last word. Setting `suggest_queries`, on by default. No summary table: the log is read on its key, and the answer is cached with the rest of the panel's.

**Why:** Baymard's autocomplete research: query suggestions are what most shoppers use the panel for, and the shop's own searches are the best source of them. The thresholds keep one shopper (or a bot) from putting words in everyone's panel: three searches, a result, a page or a click, and every word already in the catalogue's own vocabulary, so nothing can be suggested that the shop did not write. A summary table would be a schema change for a read that is already one indexed range.

### D-113 · Category scopes ("kazumi in Decks") · 2026-10-22

Under the products the panel offers up to two categories holding most of the first ten ranked products, each a link to the same search within the category: `?s=<query>&post_type=product&product_cat=<slug>`, WooCommerce's own parameter, so its widgets and counts agree. `Domain\Scopes::pick()` counts each category once a product over the first ten (`TOP`), keeps those holding at least two (`MINIMUM`), drops a parent whose child holds as many (the child says it better), drops a category holding every product found (it narrows nothing), drops categories the panel already shows as a term, and orders by count, then id. `Search\CategoryScopes` reads the ten products' categories in one query (`DocumentRepository::categoriesOf()`) and their names in one `get_terms()`. Shown only with products. Setting `suggest_scopes`, on by default.

**Why:** Baymard: scope suggestions help shoppers narrow a broad search before the results page, and a category the results already concentrate in is the narrowing they most likely want. Ten products and two categories keep the read small and the panel short.

### D-114 · "Showing results for" a typo's correction · 2026-10-22

When a search finds products only through a typo match (the one- and two-edit candidates of D-033), `SearchResult::$corrected` now carries the query as it was read, each typo replaced by the term it matched ("kazuni dealer" → "kazumi dealer"), and the new `SearchResult::$dropped` says whether a word was also left out. The results page, the Tools tab's test search and `wp csearch search` say "Showing results for “kazumi dealer”." Only when a word was dropped do they add "Search instead for" the words as typed; a typo alone has no such link, because the exact words find the same products. The panel's corrected line reads the same. The term shown is the folded index term (lower case, no accents).

**Why:** Baymard: a search that silently corrects a typo should say so, so the shopper can trust the results. A link back to the misspelled words would lead to the same page and only confuse.

### D-115 · Add to cart from the panel · 2026-10-22

With `suggest_cart` on (off by default) a product row the index marks as addable in one step (the document's `cart` flag) shows an "Add" affordance. Following the ARIA Authoring Practices for a combobox listbox and axe's `nested-interactive` rule, an option holds no focusable child: the affordance is a pointer-only `<span aria-hidden="true">` that stops the click from reaching the row, and the keyboard and screen-reader path is Shift+Enter on the active product row, announced through the row's `aria-describedby` ("Shift+Enter adds it to the cart."). The result is announced in the panel's live region ("Dealer deck added to your cart." or "Could not add …"). The request is WooCommerce's own `wc-ajax=add_to_cart` endpoint (same origin, form-encoded, `product_id` and `quantity`), which works under a page cache; afterwards the cart fragments are refreshed through jQuery's `added_to_cart` when the theme has it, otherwise the blocks' `wc-blocks_added_to_cart` event, and the shopper is sent to the cart when the shop's "Redirect to the cart page after successful addition" is set. Known limit: a touch screen-reader user has no Shift+Enter, so they add from the product page as before.

**Why:** Baymard notes that shoppers who know what they want expect to add it from the autocomplete. Off by default, because a store selling variable products, or one that wants every shopper to see the product page, should choose it.

### D-116 · Analytics events from the panel · 2026-10-22

With `analytics_events` on (the default) the panel dispatches `csearch:search`, `csearch:select`, `csearch:no-results` and `csearch:add-to-cart` as bubbling `CustomEvent`s on its form, and, when the page has a Google Tag Manager `dataLayer`, pushes the matching GA4 events: `search` (`search_term`), `select_item` for a product (with `item_list_id` `csearch_suggestions` and the item), `select_content` for anything else (`content_type`, `content_id`), `add_to_cart` (currency and value when the price is known) and `csearch_no_results` (`search_term`, once per query). Every ecommerce push is preceded by `{ ecommerce: null }`, as Google's examples do. The plugin never creates `dataLayer` and makes no request of its own; a site using `gtag.js` alone forwards the DOM events itself or uses GTM.

The search term in every event is redacted first (1.3 review, blocker 1): the form carries the search log's own patterns (`Scrub::sources()`), and a search that looks like an e-mail address, a phone number, a card number or a postal code is sent as `[redacted]`, as the log stores it. `no-results` fires once, when a search the panel found nothing for is submitted, not for every prefix the panel looked up while it was typed.

**Why:** owners want to know what the panel does for them without a second plugin. Pushing to a layer that already exists adds no tracking the owner did not install; the DOM events let any other tool listen. What the plugin will not keep in its own log it does not hand to a tag manager either.

### D-117 · Search within the current category: a ticked box, a `product_cat` argument, a scoped ranking · 2026-10-22

*The ticked box was replaced in 1.5 by a scope row in the panel (D-130); the `product_cat` argument, the scoped ranking, the results page and `/suggest`'s `cat` stand.*

1.3, Baymard's "search within the current category" (94 % of mobile sites miss it). The scope is WooCommerce's own query variable, `product_cat=<slug>` beside `s` and `post_type=product`, so the URL is one WordPress and WooCommerce already understand (the archive's tax query, the filters, the sort) and a link from anywhere, a suggestion row among them, can carry it as is. The form shows the choice only where there is one to make: on a category page, or on the results of a search already kept to a category (`Results\CategoryScope::current()`), as a single checkbox above the field, "Only in Decks", ticked. A checkbox, not a two-option select: it is one control with one name, reads the same to a screen reader ("Only in Decks, checkbox, checked"), needs no "All products" value (unticked sends nothing, so the URL stays clean and the search covers the shop), works without the script, and is a 44 px target on a phone. Above the field, so the suggestion panel still opens right under the input.

The ranking is scoped, not cut: `SearchOptions::$scope` and `RankPlan::$scope` carry the category's term-taxonomy id and its subcategories' (`get_term_children()`, as WooCommerce's `product_cat` includes children), and `RankRepository` adds `EXISTS (… term_relationships …)` to the one statement, through the relationships' primary key; the PHP ranking (explain, the upgrade window) keeps the same documents through one lookup. So a broad word in a small category is ranked within the category and never lost past the 500-id cap of a shop-wide ranking. The panel takes the same scope as `cat` on `/suggest` (the script sends it while the box is ticked, each scope cached apart), so the suggestions match the results. The results page reads "N results for “x” in Decks" (`_n()`, the category's name) and adds "Search all products for “x”." with `csearch_all=1` and without `product_cat`, keeping the page's filters and sort. `Results\QueryRouter` never opens another category for a scoped search (the shopper chose this one); a size in it becomes the filter on the search for the other words in the same category, or on the category page itself when the size was all of it (D-101). An unknown slug keeps nothing out.

**Why:** Baymard: shoppers on a category page expect the search to stay there, and must be able to leave it. WooCommerce's own argument keeps every other part of the page working unchanged; ranking within the scope keeps the answer right for any catalogue size.

### D-118 · A sort other than Relevance lists the strong matches only · 2026-10-22

1.3, Baymard's faceted-sorting finding (only 10 % of sites keep weak matches from rising when the shopper sorts by price), chosen by Zach over D-057's behaviour. A strong match is a product that matches every word of the query as typed, through the word itself, its spellings, its stem or the owner's synonyms (`SearchOptions::$strict`): `Domain\Searcher::widen()` adds no prefix completions of the word being typed and no typo candidates, and the least-matched word is never dropped. "Strong" is defined by how each word matched, not by a score threshold, because a score depends on the field weights, the catalogue's term counts and the owner's boosts, where a word-by-word rule means the same thing in every shop and to the shopper ("every word I typed"). The results page runs the strict search when `orderby` is set to anything but `relevance` (`ProductSearch::sorted()`); if it finds nothing, the full set is used, so a sort never empties a page that Relevance fills. Relevance is unchanged. The count, the filters and the corrected line follow the set actually listed. Cached apart (`rankedIds`' key carries the flag).

**Why:** Zach's choice to follow Baymard: on a price sort, a near-miss (a completion such as "skate" → "Skateboards", a typo's neighbour) would otherwise sit at the top just for being cheap.

### D-119 · Custom fields are field 9, "custom", weight 4 · 2026-10-22

1.3, the competitor scan's first gap (custom fields and ACF; six of the plugins have it). The setting `custom_fields` lists product meta keys (letters, digits, `_ . : -`, 64 characters, 20 at most, never WordPress's own `_wp_…` and `_edit_…`). They are indexed as a new field, `Domain\Fields::CUSTOM` (9, `custom`), not folded into the attributes: the postings' `field` column is a `tinyint` and every reader of field ids iterates `Fields::NAMES`, so a ninth field needs no schema change, and its own weight (4, between an attribute's 5 and a tag's 3) lets the owner or `csearch_fields` turn it up or down without touching the attributes. Values go through `Domain\MetaText` (pure): strings and numbers kept, lists flattened three levels deep (a serialised array, an ACF checkbox), `true`/`false`/`null`/objects dropped, then tags stripped and the product's custom text capped at 2,000 characters in all, as the long description is. The Tools tab offers, as checkboxes, the 30 meta keys products use most (`Repository\MetaKeyRepository`, one grouped query on the admin screen only, keys starting with `_` left out as WooCommerce's and ACF's internals) plus an "Other fields" box for any other name. A changed list says to rebuild the index, as the other index settings do; a change to a chosen key on one product (`added/updated/deleted_post_meta`) queues that product.

**Why:** shops keep searchable facts (material, compatibility, a model number) in custom fields; a field of its own keeps their weight the owner's call and leaves the attribute weight as the spec set it.

**Why this matched (review minor 6):** a match found only in a custom field says nothing (`MatchExplainer` has no reason for field 9). **Why:** a meta key is a developer's name (`material`, `_spec_core`), not words a shopper should read, and ACF's field label would need a WordPress read per shown product; the card is no worse than before 1.3.

### D-120 · ACF read through its own field object, with a hand-written stub · 2026-10-22

When ACF is active, each chosen key is read through `get_field_object( $key, $productId, false, false )` for its type: choices (select, checkbox, radio, button group) add their labels to their stored values, post fields (post object, relationship, page link) are searched by the posts' titles, taxonomy fields by the terms' names, and fields that hold no words a shopper types (image, file, gallery, user, Google map, colour, password, URL, e-mail, oEmbed, true/false) are skipped. Without ACF the raw meta is read. PHPStan knows the one function from `tools/acf-stubs.php`, written by hand like the WP-CLI stubs, rather than adding the ACF stubs package as a dependency (CLAUDE.md: ask before a dependency).

**Why:** ACF stores ids and choice keys, not the words shoppers see; reading the field's own settings indexes the words. One function does not justify a dependency.

### D-121 · Merging 1.3: pins checked for visibility, WooCommerce 11.2's search rule, the test bootstrap · 2026-10-22

Found while the four parts of 1.3 were put together and run in wp-env:

- **WooCommerce 11.2's search rule.** On a search the page does not treat as a product archive, WooCommerce 11.2 adds "hidden from search" to the query itself, outside `woocommerce_product_query_tax_query`, the filter `hidden_by_sku` lifted it through. `ProductSearch::enforce()` (after WooCommerce's `pre_get_posts`) lifts it the same way for a ranked search, so a hidden product's exact SKU reaches the results page again.
- **Pins and hidden products.** The ranked search lifting that rule means WooCommerce no longer drops a pinned product hidden from search, so `rankedIds()` keeps a pin only when its document is visible, as the panel already did. A panel kept to a category shows no pins and no category scopes (one may lie outside it).
- **A variation's SKU named whole.** A fact's terms are stored tab-separated, so a SKU's key term (`=mum fur ice`, spaces in it) survives; and an attribute value the shopper can read ("Design: Fire") says why before a part of a SKU holding the same word.
- **Custom fields offered.** `total_sales`, WooCommerce's counter on every product, is not offered as a field to search.
- **The integration bootstrap** declares `WC_Unit_Tests_Bootstrap`, WooCommerce's own switch that stops it caching its visibility term ids for the process: the core suite deletes every term after each test class, so a later class read ids that no longer existed.

**Why:** each was a fault the parts' own suites could not see, because they ran without WordPress; they are covered by the integration suite now.

### D-122 · One result message on an empty search · 2026-10-22

From the Skatehouse demo check on 22 October: a product search with no results showed WooCommerce's "No products were found matching your selection." (`wc_no_products_found` on `woocommerce_no_products_found`) and then the plugin's no-results state ("0 results for …" and the nearest categories). That is two status messages saying the same thing, and a screen reader announced both.

On a product search the plugin answers that found nothing (`ResultsView::answersEmpty()`: a ranked main query with words and no products shown), `ResultsHooks::oneNoResultsMessage()` removes `wc_no_products_found`, and nothing else, on `wp`: after the main query has run and before the template, so before the action fires. The action still runs with every other callback on it; a listing that is not such a search (the shop, a category, a filter with no match, a search of posts) keeps WooCommerce's notice. This replaces 1.1's narrower removal (handoff item 7), which took the notice out only when a post or page led the no-results state.

Block themes are not changed: their notice is the `woocommerce/product-collection-no-results` block, not `wc_no_products_found`, and is still left out only when a page leads.

**Why:** one message per state. The plugin's state says the same thing and offers somewhere to go, so it is the one kept.

### D-123 · "Sorted by relevance" on search results · 2026-10-22

From the same check: WooCommerce's result count adds visually hidden "Sorted by …" text from the shop's default sort. `woocommerce_result_count()` does not look at whether the page is a search, and its `woocommerce_catalog_orderedby` messages have no entry for relevance, so a screen reader heard "Sorted by latest" while the sort menu showed Relevance and the products were in the plugin's order.

On a search the plugin answers in Relevance order (no `orderby` in the URL, or `orderby=relevance`; `ResultsView::inRelevanceOrder()`):

- `woocommerce_catalog_orderedby` gains `relevance` → "Sorted by relevance" (translatable, with context);
- `woocommerce_default_catalog_orderby` answers `relevance`, so the result count reads it when the URL has no `orderby`.

Checked against WooCommerce 11.2's code, and in `ResultsTest` by comparing with the filters removed: the sort menu is unchanged (on a search WooCommerce already drops "Default sorting", the only option the default decides, and selects Relevance), and so are WooCommerce's ordering arguments (for a search with words they already use relevance; the default is read only for a search without any). A search sorted another way (`orderby=price`) keeps WooCommerce's own line ("Sorted by price: low to high"), and category and shop pages are not touched.

**Why:** the hidden text has to name the order the products are actually in.

### D-124 · The dialog's history step keeps the Interactivity API's key · 2026-10-22

From the Skatehouse header check on 22 October (the search dialog in Button mode, D-099): Forward after Back reloaded the page. Opening the dialog pushed a history step whose state was only `{ csearch: '<block id>' }`. WordPress's Interactivity API stamps the page's own step with `{ wpInteractivityId }` and, on `popstate`, reloads the page when the state it lands on is not null and lacks the current session's id. Back landed on the page's own step and was fine; Forward landed on the dialog's step and reloaded.

The step is now built on the current state, `{ ...history.state, csearch: id }`, so it carries the runtime's key. That `pushState` is the only place the plugin writes history state (its other navigations are `location.replace`). A page loaded or reloaded on a dialog's step has no dialog open, so `view.js` removes the stale `csearch` key when it loads, keeping the rest of the state (the runtime's key among it, stamped before the plugin's module runs).

**Why:** the plugin's step is an addition to the page's step, not a replacement for it; any key another script keeps there must survive.

### D-125 · The field's input resists theme input styles · 2026-10-22

From the Skatehouse header (Field mode in Kadence's sticky header): themes style `input[type="text"]` and `input:focus` more specifically than `.csearch-input`. Kadence adds a background, a 1px border, a 3px radius, padding and a focus box-shadow, and sites add their own `input:focus` outline, so the input drew a second box inside `.csearch-field` and focus showed stacked rings.

In Field mode the input's rules are scoped as `.wp-block-creative-search-search:not([data-csearch-mode="button"]) .csearch-field .csearch-input` (and its `:hover`, `:focus` and `:focus-visible`), specificity 0,4,0 to 0,5,0. That beats the usual theme selectors (`input[type="text"]` 0,1,1; `.site-header input[type="search"]:focus` 0,3,1) without `!important`. They reset background, border, radius, box-shadow, outline, padding, size, max-width and appearance. The one focus indicator is `.csearch-field:focus-within`. On a touch screen the input's font size is at least 16px (`max(16px, 1em)`), so iOS does not zoom the page on focus. Button mode's dialog is left as it was.

**Why:** the block is often dropped into a header the theme styles; it has to look like itself there without the site writing overrides.

### D-126 · A panel in a fixed or sticky header scrolls inside itself · 2026-10-22

D-092 keeps the panel at its natural height and drops its last rows until it fits the screen, because the page can scroll. In a fixed or sticky header (Skatehouse's Kadence header, which shrinks, hides on scroll down and comes back on scroll up) the page scrolling cannot bring a row into view, and on a phone the keyboard covers the lower part of the screen.

When the block has a fixed or sticky ancestor, or on a touch screen (`pointer: coarse`), the panel is capped to the room between the field and the bottom of `visualViewport`, or between the top and the field when it opens above. It scrolls inside itself with `overscroll-behavior: contain`, and keeps at least two rows (96px). The cap follows `visualViewport` resize and scroll (the keyboard opening and closing) and the page's scroll, at most once a frame. D-092's rule then applies within the cap: the last rows are dropped until the rest fit it, so with room for two suggestions and "See all" there is still no inner scrollbar. Only when even those do not fit (the keyboard open over a short screen) does the panel scroll. Arrowing to a row scrolls the panel, not the page (`scrollIntoView` is not used there), and once rows have been dropped the chosen one is brought back into view. With `panelPosition: auto` the panel opens above only when the room below is short (240px) and there is more room above than below. The arithmetic is in `assets/src/block/placement.js`, unit-tested on its own; the view only measures and applies.

A desktop page that scrolls keeps D-092: natural height, no inner scrollbar.

The first build of this scrolled the panel instead of dropping rows. The full browser run caught it on the phone projects: D-092's "no scrollbar" tests failed, and axe in Safari flagged the scrolling panel (`scrollable-region-focusable`; the rows are reached from the field through `aria-activedescendant`, which that rule cannot see). Dropping rows to the cap fixed both. The border is counted in the cap (`box-sizing: border-box`).

**Why:** Baymard: every suggestion must be reachable. Where the page cannot scroll it into view, the panel has to.

### D-127 · Dimming the page: a block setting, and inside a transformed header · 2026-10-22

D-097's backdrop already renders in Field mode, on by default, and a click on it closes the suggestions; the `csearch_dim_page` filter was the only way to turn it off. The block now has a "Dim the page behind the suggestions" toggle (`dimPage`, on by default; `dim_page="no"` on the shortcode). Like `mode` it is set per form, with no site-wide setting, and the filter still has the last word.

The backdrop is `position: fixed; inset: 0`. A header with a transform, as Kadence's sticky header has (`position: fixed` with `translateY` for hide on scroll), becomes the containing block of its fixed descendants, so the backdrop covered only the header. When the panel opens, and as the page scrolls, the view measures where the backdrop landed and, if it is not the viewport, moves it back over the viewport. It stays inside the header's stacking context: the header's own `z-index` keeps it above the page, and the form stays above the backdrop (`.is-dimmed > .csearch-form`).

**Why:** Baymard recommends dimming the page behind desktop suggestions; it has to work in the sticky headers shops actually use, and an owner whose header still misbehaves can switch it off per block.

### D-128 · Colour hooks for the field, the panel, the submit button and the focus ring · 2026-10-22

Seven documented custom properties: `--csearch-field-bg`, `--csearch-field-fg`, `--csearch-panel-bg`, `--csearch-panel-fg`, `--csearch-submit-bg`, `--csearch-submit-fg` and `--csearch-focus-ring`. They are read where they are used, each falling back to today's value (`var(--csearch-field-bg, var(--csearch-bg))` and so on), and never declared by the plugin. A site can therefore set them on `:root`, a header or the block without a specificity contest. The panel's row tint and secondary text are mixed from the panel's colours, so a dark panel keeps readable rows. They apply in both modes; with nothing set, both look as before.

**Why:** sites were targeting internal classes to colour the search bar, and those classes are not a contract.

### D-129 · The cold budgets keep the fastest of three timings · 2026-10-23

The catalogue benchmark times each golden query's cold `/suggest` and its `/search` three times, emptying the object cache before each, and keeps the fastest. The p95 is then taken over the 50 queries as before, and the budgets are unchanged (100 ms cold, 100 ms `/search`). The warm timing and the memory figure are still single measurements.

**Why:** the merge of 1.2 failed CI on identical code. The 10,000-product job measured a cold p95 of 78.6 ms on the pull request, then 123.3 ms on `main` and 132.6 ms on the tag; `/search` went from 52.8 ms to 93.7 ms. A shared runner's noise only ever adds time, so the fastest of three is the code's cost, while a slow query still shows in the p95 across queries. The extra two timings add about 10 seconds at 10,000 products.

### D-130 · On a category page the panel offers the category first; the form searches the shop · 2026-10-23

1.5, Zach's choice over D-117's checkbox. The "Only in Decks" box, ticked by default, kept the panel's suggestions and the search to the category until the shopper noticed and unticked it: a shopper on Decks who types "t-shirt" saw nothing, which reads as "the shop has none". The form on a category page now searches the whole shop, like every other form, and still carries the category (`scope` in the block's context). The script sends it to `/suggest` as `here=decks`; `Search\CategoryScopes` then puts "kazumi in Decks" first among the scope rows (D-113) whenever Decks or a subcategory holds any of the ranked products, through the same scoped lookup as the picked scopes (`RankRepository::scopedObjects()`). The picked scopes follow, at most `Scopes::MOST` in all, never the same category twice; their minimum (D-113) applies to them only, so the category the shopper is in is offered even when it holds one match. A category with no match is not offered. Off with `suggest_scopes`, like the other scope rows. Each `here` is cached apart.

`cat` on `/suggest` stays (the panel no longer sends it), as do the `product_cat` argument, the scoped ranking and the results page with its "Search all products" link: the scope row leads there. Enter, and "See all", search the shop.

**Why:** Baymard's finding is that shoppers expect to be able to search within the category, not that they expect to be kept in it. A row they choose gives them that without hiding the rest of the shop from the ones who did not notice a ticked box, and it costs no space above the field.

### D-131 · Security advisories in the build tools: patched through npm `overrides` · 2026-10-23

Dependabot flagged `qs`, `serialize-javascript`, `postcss-selector-parser`, `js-yaml` 5, `smol-toml` and `katex`, all pulled in by `@wordpress/scripts` 37 and `@wordpress/env` 12 (the latest of both), which still ask for the vulnerable ranges, so Dependabot's own update failed ("Security update not possible"). None of them ships: the zip holds the built assets only, and these run on a developer's machine and in CI (the build, the linters, wp-env's local server).

`package.json` now forces the first patched versions through `overrides`: `qs` ^6.16.1, `smol-toml` ^1.9.0, `js-yaml@^5` ^5.4.1 (the 3 and 4 lines are not affected), `katex` ^0.18.2, `serialize-javascript` ^7.0.5 and `postcss-selector-parser` ^7.1.7. Three of these cross a major line (`serialize-javascript` 6 → 7 under copy-webpack-plugin, `postcss-selector-parser` 6 → 7 under cssnano, `katex` 0.16 → 0.18 under markdownlint), so they were accepted only on evidence: `npm run build` gives byte-identical files before and after, `npm run check` and the JS tests pass, wp-env starts and runs commands, and markdownlint reports exactly what it did before. `npm audit` finds nothing.

An override is removed once `@wordpress/scripts` and `@wordpress/env` ask for a patched range themselves; one that breaks a tool is dropped and its alert dismissed in GitHub as build-only, with the reason here.

**Why:** WordPress practice is to keep the WordPress packages current, which clears most advisories, and to override the rest only where the tools still work, rather than leave the alerts and Dependabot's failing runs open.

### D-132 · The ranking statement starts from the rarest word and reads the postings once · 2026-10-23

The 10,000-product budget job failed twice on CI by a few milliseconds (cold `/suggest` p95 103.8 ms on the v1.4.0 tag, 100.6 ms on D-088's pull request), with identical code passing on the pull request before. Locally, at 10,000 products with the golden queries, the cold p95 was 52.5 ms for `/suggest` and 54 ms for `/search`. The `RankRepository::rank()` statement was 739 ms of the 50 queries' SQL, with a worst case of 136.7 ms: "dealer skate deck kazumi maple popsicle canada ships…", where "dealer" has 12 postings and "canada" 20,000.

Two changes, both inside the one statement, with no schema change:

1. **The rarest word leads.** A ranked document holds every word, so the documents of the word with the fewest postings (the sum of its matches' document frequencies, which the plan already carries) are the candidates, `c`. `f` then reads `c STRAIGHT_JOIN q STRAIGHT_JOIN postings`, each candidate looked up by the postings' primary key once per term (`term_id, generation, lang, product, document_id`), instead of reading every term's postings and grouping them. `STRAIGHT_JOIN` because MariaDB otherwise joins `q` to the postings first and filters by `c` afterwards, which saves little (134 ms → 25 ms on the worst query, against 1.2 ms in this order). It applies only with two words or more and when the lookups (the lead's frequency times the terms) are fewer than the postings the old plan reads, so a query whose words are all common keeps the range scans.
2. **`f` is read once.** MariaDB works a CTE out again for each reference, and `f` had two: the scores (`s`) and the phrase tables (`x`, `ph`). `x` now carries each word's best score per field beside its first position, and `s` is worked out from `x` in one pass: the base score, the bests string, the phrase bonus and the quoted phrases, `HAVING` every word. The main query reads `s.bonus` and `s.qN` and no longer joins `ph`.

The answer is the same: RankParityTest (SQL against the PHP ranking) and the whole integration suite pass unchanged, and the worst query's rows were compared byte for byte before and after. At 10,000 products, cold p95: `/suggest` 52.5 → 27.3 ms, `/search` 54 → 23.4 ms, the worst query 148.5 → 27.7 ms.

What is left on a cold request is mostly the rank statement for a single common word ("decks", 16 ms) and the document count for N (3.3 ms: `generation_lang` lacks `type`, and the `object` key it uses is index-only but walks every entry). A key on `(generation, lang, type)` would make that count near-instant; it is a schema change, so it waits for Zach.

**Why:** the budget must hold on a shared runner, which is up to 2.5 times slower than this machine; at under 30 ms locally it has room, where 55 ms did not.

### D-133 · HTML entities are decoded once, in the tokenizer, before anything else · 2026-10-27

Product names went into the index as `get_name()` returns them. A name an import or a feed stored as `Philly&#8217;s Hoodie` was cut by the word pattern into `philly`, `8217` and a dropped one-letter `s`, so "phillys" found it only by a typo and "8217" was a searchable word. WordPress itself stores a term name with its `&` as `&amp;` (`_wp_specialchars` on `pre_term_name`), so `Rock &amp; Roll` gave `rock`, `amp`, `roll`, and the panel, which builds names as text nodes, showed the entity. Descriptions and content titles were already decoded in their readers; names, attribute values, term names, variation values, custom fields and the query were not.

1. **One function, at the tokenizer's door.** `Domain\Tokenizer::decode()` runs `html_entity_decode( ENT_QUOTES | ENT_HTML5, 'UTF-8' )` and is the first step of `Tokenizer::words()`, which every `tokenize()` and `key()` goes through: the indexer's fields and facts, the name and SKU keys, the attribute terms the router matches, synonyms, query rules and the query. The query parser also decodes the query before it cuts it into chunks, so `&quot;horror party&quot;` is the phrase it reads as and a decoded `&nbsp;` is a blank. `&#8217;` then becomes `’`, which the apostrophe rule already folds. It is plain PHP, so `src/Domain` stays free of WordPress.
2. **Until nothing changes.** It decodes again while the text changes, so `&amp;#8217;` (text escaped twice, common in imports) reads as `’`, and decoding text that has already been decoded changes nothing. That is what makes the second pass on the query side harmless, and keeps both sides equal however often a text passes through. Each pass that changes the text removes an `&` or shortens an `&amp;`, so the loop ends. A bare `&` (`AT&T`) is text and stays.
3. **What is shown is text.** The indexer decodes a document's title and its facts' labels and attribute names as it writes them, whichever reader or `csearch_document` filter they came from, and `CategoryScopes` decodes the term names its rows carry. The panel builds them as text nodes; the results page's lines go through `esc_html()` once. A fact's `value` stays as stored, since it is the query argument that chooses the variation.
4. **One rebuild.** `Tokenizer::VERSION` goes from 2 to 3, so an index built by 1.5.0 owes one full rebuild through `Reindex::checkAnalysis()` (D-098), and the titles are rewritten with it.

Ranking is otherwise unchanged: a name stored as `Philly&#8217;s Hoodie` now scores exactly as `Philly’s Hoodie` does (GoldenQueriesTest), and the 50 golden queries and the ranking parity test pass as before.

**Why:** an entity is how the text was stored, not what the shop wrote. Decoding it where every text becomes tokens means no reader can forget it, and the query and the index are always cut the same way.

### D-134 · The block's buttons resist a theme's button states, at one class's weight · 2026-10-27

From the Skatehouse header: once something is typed, hovering the clear button (the X) showed a dark square. Kadence's `global.min.css` colours every `button:hover`, `button:focus` and `button:active` (`color: var(--global-palette-btn-hover); background: var(--global-palette-btn-bg-hover)`, with a shadow), specificity 0,1,1, which beats `.csearch-clear`'s `background: transparent` at 0,1,0. The site's Additional CSS then set `.wp-block-kadence-header .csearch-clear:hover { color: var(--global-palette3) }`, the same `#121212` as the theme's hover background, so the X disappeared into it. Button mode's open and close buttons had the same exposure. D-125 had hardened the input only.

Each button's states are now `.csearch-clear:is(:hover, :focus, :active)` (and the same for `.csearch-submit`, `.csearch-open`, `.csearch-close`), 0,2,0, which set the button's own colour (`inherit`, the submit's `--csearch-submit-fg`), its own background (none, the submit's `--csearch-submit-bg`), no border and no shadow. Outline is not touched, so `:focus-visible` keeps the one focus ring.

The weight is deliberate, and lower than D-125's 0,4,0. A site styles the block in its header as `.site-header .csearch-submit` (0,2,0; Skatehouse's submit has its own background that way). At 0,2,0 the plugin's state rules beat the theme's `button:hover` but only tie with such a rule, and the site's CSS loads after the block's (Additional CSS prints last), so the site keeps its look on hover; a heavier selector would have turned Skatehouse's submit transparent whenever it was hovered. A theme selector heavier than 0,2,0 on buttons would still reach them; none is known. The panel's add to cart is a `<span>`, which theme button rules do not reach.

**Why:** the block has to look like itself in a theme that styles buttons, without the site writing overrides, and without undoing the overrides a site does write.


### D-135 · The header's Description stays under 140 characters, and readme.txt repeats it · 2026-10-28

The Plugin Handbook's header requirements ask for a Description of fewer than 140 characters, because the Plugins screen shows it. 1.5.2's was 174, and readme.txt's short description, a different sentence, was 143. Both are now the same 137-character sentence, and the agency's other plugins took the same fix on 27 October.

`tests/Unit/PluginHeaderTest.php` reads the Description line of `creative-search.php` as WordPress does and fails at 140 characters or more (counted in UTF-8 characters, not bytes). It also fails when readme.txt's short description is not the same sentence, so the two cannot drift apart again and the readme's 150-character limit is covered by the header's.

The Canadian French entry was edited in the .po by hand, not through `composer i18n:po`, so that no other entry, comment or header line in it could change; the compiled .mo differs from 1.5.2's in that one entry only.

**Why:** a long Description is cut or wraps badly on the Plugins screen, and a unit test catches it on every run, before Plugin Check or a review would.

### D-136 · One cap on the whole panel, filled in a fixed order · 2026-11-03

From the 3 November UX review (the brief for 1.6.0): for "hood" the panel showed 11 rows on a desktop and 10 on a phone, of which about four fit above the keyboard, because each group (suggested searches, products, categories and scopes, pages) had its own limit and D-092's budget of 10 was their sum. Baymard's autocomplete research: at most 10 suggestions on a desktop, 4 to 8 on a phone.

`panel_max` (default 8, 4 to 12) and `panel_max_phone` (default 5, 3 to 8) cap the whole panel; "See all" is never counted and always shows. A block (`panelMax`, `panelMaxPhone`) or shortcode (`panel_max`, `panel_max_phone`) overrides both, as it does the other panel options; the widget keeps the owner's. A phone is what the panel already treated as one (`(max-width: 600px), (pointer: coarse)`), so the cap is applied in the browser, in `budget()`, and the answer from `/suggest` is unchanged and still cached once for both widths.

Each group keeps its own limit first (the owner's products; 3 suggested searches, 2 on a phone; 3 terms, 2 on a phone, sharing their room with up to 2 scopes; 2 posts or pages). Then the rows are taken in this order until the cap: one suggested search; 3 products (on a phone this is the brief's "at least 3 when there are 3"); one category or scope row (a scope first, D-113); the other products; then, choices the brief left open, the other suggested searches and the other categories and scopes, in that order; and posts or pages last. One exception: when the cap cannot hold the suggested search and three products (`panel_max_phone` at 3), the three products win, as the brief promises "at least 3 when there are 3"; the search returns at a cap of 4. The rows still show in their groups' order (searches, products, scopes, terms, pages), so taking a category before the fourth product changes which rows show, not where.

This replaces D-092's row budget (10 on a desktop with terms giving way first, 4 products on a phone): a phone's products are now bounded by its cap rather than a fixed 4, so an owner who raises `panel_max_phone` gets more products, not more categories. D-092's `fit()` is unchanged and still drops the last rows when the capped panel does not fit the screen.

**Why:** the panel's length should be one number the owner sets, not a sum nobody chose; and when room is short the shopper should see the one suggested search, the first products and the one category before anything else.

*1.6.2: the page asked for (a title holding every word searched) gets a row after the first three products, and products found only by their description go after it (D-143).*

### D-137 · The panel suggests a page by its title; a phone's panel leaves pages out · 2026-11-03

For "hood" the panel offered Wheel guide and Privacy policy, pages with the word somewhere in their content, not in their titles. A suggestion row shows only the title, so the shopper cannot see why it is there.

In the panel (`SearchService::content()` with `suggest`), the content search now ranks its first 10, as before, and keeps those whose title the query matches, in rank order, up to the panel's 2: every token of the query must have reached at least one term the document carries in its name field (`Domain\TitleMatch`, from the postings through `IndexRepository::fieldsOf()`, which now takes the kind, D-105). The same widening applies as everywhere else, so the last word still counts as a prefix ("wheel gui" offers Wheel guide), and a stem or a corrected typo counts. A parent page's title, indexed as a child's category, does not count, and neither does a search that had to drop a word: the shopper's words have to be in the title the row shows. The results page keeps matching titles and content as before, and the cache key now carries the context, so the two never share an answer.

`suggest_pages_phone` (default no) leaves posts and pages out of the panel on a phone, where the cap (D-136) leaves few rows and they go to products. A phone is what the panel already treats as one, so this is applied in the browser too; `/suggest` returns the same answer for both.

**Why:** a suggestion has to look like what was typed; a page matched by its body belongs on the results page, where it has a line of context. On a phone the four or five rows above the keyboard are worth more as products.

*1.6.2: the first of those pages, the one asked for, shows on a phone too whatever `suggest_pages_phone` says, and before the products that only mention the words in their description; the other pages keep this rule (D-143).*

### D-138 · In stock first is the store's choice, not a fixed rule · 2026-11-03

Zach, 3 November: for "hood" the first two products were two sold-out hoodies ahead of two in stock, because "Hoody" matched better than the −6 the scorer takes for sold out (spec: Matching → B_stock). The plugin is reused across shops, so this is a setting, `in_stock_first`, off by default, which keeps today's ranking exactly.

On, products that can be bought come before sold-out ones, and each part keeps its score order: the score is not changed, only the order's first key. "Can be bought" is the document's `in_stock` flag, which the indexer takes from WooCommerce's `is_in_stock()` and so already includes products on backorder. It is applied where the ranking is made, so it holds before the limit and nothing ranked below the first page can be missed: the one SQL statement leads its `ORDER BY` with `d.in_stock DESC` (D-086), and the PHP order (`explain`, the schema-1 window, the merge of a query's phrase-synonym forms, D-109) sorts with `Hit::stockFirst()` instead of `Hit::compare()`. It reaches the Domain as `SearchOptions::$inStockFirst` and `RankPlan::$inStockFirst`, and the cache key carries it. Pinned products (D-110) are put first after the ranking, as before, so a pinned sold-out product keeps its place. The panel, the results page and `/search` all rank through `SearchService`, so they agree.

The switch sits on the Boosts and exclusions tab, beside the other rules that change the order ("Sold-out products: Show products that can be bought first"). The brief names no tab; this one already holds the ranking rules.

**Why:** Skatehouse wants it on, to match Creative Shop's "Sold-out products last" on the shop's own lists, but another shop may want a better match first even when it is sold out; the score's −6 stays the default.

*1.6.2: in stock first applies among products that match alike: products found by more than their description come first, then those found only there, each group in stock first (D-142).*

### D-139 · Analytics count shoppers: staff and test runs stay out, and the numbers can be cleared · 2026-11-03

On the demo the top queries were the end-to-end suite's ("kazumi" 446 times, "xyzzy" 319, "zzqxv" 16), so click-through read 0.3% and the no-results list was mostly the agency's own. Before launch the numbers have to be the shoppers'.

1. **Staff.** `log_staff_searches` (default no): a search or a suggestion click from a signed-in user who can `edit_products` (administrators and shop managers) is not logged, so it is in no count, chart or suggested search. The panel's REST requests carry no nonce (the form stays free of nonces so a cached page still searches, Skatehouse handoff item 4, D-051), so WordPress resets them to a signed-out user; `SearchLog::ignored()` then reads the logged-in cookie itself with `wp_validate_auth_cookie( '', 'logged_in' )`. This is safe without a nonce because it can only leave a row out, never grant anything; a forged or expired cookie does not validate and is logged as a shopper. A customer's account is a shopper's.
2. **Test runs.** The filter `csearch_log_search( true, $query, $lang, $results, $source )` can leave any search out, and a request carrying `X-Creative-Test: 1` (exactly "1") is not logged. The header needs no secret: it hides only the sender's own searches. Clicks are left out on the same terms (staff and header), so a test run or a manager clicking can never mark a shopper's search of the same words (D-052); the filter is about searches and is not asked for clicks. The browser's click beacon cannot carry a header, but a suite's click follows its own search, which was not logged, so it marks nothing unless a shopper searched the same words in the last ten minutes.
3. **Clear analytics.** On the Analytics tab, "Clear analytics…" is a link to a confirmation on the same screen, not a script dialog. It says what goes: the number of logged searches (with their clicks), the days of daily counts, and the suggested searches built from them, and that synonyms, redirects, pins and the index stay. Only the confirmation's button, a POST with its own nonce (`csearch_analytics_clear`, `manage_woocommerce`), deletes: `LogRepository::clear()` (`DELETE FROM`, which needs no `DROP` privilege as `TRUNCATE` would) and `stats` emptied, then the cached answers are dropped so no suggested search outlives its rows. `wp csearch analytics clear` does the same after WP-CLI's confirmation, or at once with `--yes`.
4. **The Plugins screen.** A Settings link, first, to WooCommerce → Search's Tools tab, where the settings are; only for users who can open it.

**Why:** a shop's analytics are for deciding what to stock and which synonyms to add, and a launch should start from the shoppers' first search, not from the agency's tests.

### D-140 · Updates come from the agency's releases: one outbound request, to its own manifest · 2026-11-03

The spec says "No external service, no API key, no outbound requests", and gate 5 held it. But the plugin had no way to update itself: it is not on wordpress.org, so a fix meant uploading a zip to each shop by hand, while the agency's other plugins update from `zachatkinson/creative-releases` through wp-admin. Zach chose to bring Creative Search into the same channel (3 November 2026, "1."). SPEC.md is left as written; this decision is the exception to it.

1. **The request.** `Updates\HttpManifestSource` reads `https://raw.githubusercontent.com/zachatkinson/creative-releases/main/creative-search/info.json` with `wp_safe_remote_get()`, a 10-second timeout and the user agent `Creative Search/<version>`, which leaves out the site address WordPress would otherwise send. It does this at most every 12 hours, or every hour after a failure, cached in the site transient `csearch_update_manifest`. It is only read when WordPress checks for plugin updates: the `update_plugins_raw.githubusercontent.com` filter named by the `Update URI` header, the details popup (`plugins_api`) and Dashboard → Updates → Check again. Nothing about the shop, its products or its shoppers is sent. The search path makes no request.
2. **What is trusted.** `Updates\Manifest` accepts only a semantic version, an https package on `github.com` ending in `.zip`, and a 64-character SHA-256. Anything else in the manifest is dropped. `upgrader_pre_download` downloads the package and checks it against that hash; a mismatch is deleted and refused with `csearch_package_mismatch`, so a changed release asset is not installed even from the right host. The changelog in the details popup is escaped.
3. **Turning it off.** The `CSEARCH_DISABLE_UPDATES` constant or the `csearch_updates_enabled` filter (false) attaches none of the hooks, so the plugin makes no request at all.
4. **The gate.** Gate 5 still fails on any outbound call in src/, except `wp_safe_remote_get(` in `src/Updates/HttpManifestSource.php` and `return download_url(` in `src/Updates/Updater.php`. A new call anywhere else, or another kind of call in those files, fails it.
5. **The pipeline.** A `v*.*.*` tag runs `release.yml`, which runs all of `ci.yml` on the tagged commit (`workflow_call`; `ci.yml` no longer runs on tags itself, so a tag runs the matrix once), then checks the tag against the header's version, builds the zip with `tools/build-zip.sh`, writes the manifest with `tools/release-manifest.php` (versions from readme.txt, changelog lines from CHANGELOG.md), publishes the GitHub release `creative-search-<version>` in creative-releases and points `creative-search/info.json` at it, with the `RELEASES_TOKEN` secret (Contents read and write on that repository only). The code and pipeline are ported from Creative Shipment Tracking, so all the agency's plugins update the same way.
6. **Uninstall** deletes the site transient too, once for the network.
7. **Plugin Check** reports the `Update URI` header as `plugin_updater_detected`, a wordpress.org hosting rule; the plugin is not hosted there, and the header is also what stops wordpress.org offering a same-slug plugin over it. `npm run plugin-check` is now `tools/plugin-check.sh`, as in the sibling plugins: it allows that one code and fails on any other error or warning (until now Plugin Check's findings were read by eye, since it exits 0 with errors).

**Why:** without updates, every fix waits on someone uploading a zip to each shop; with a pinned host, a pinned hash and an off switch, the one request costs a shop less than an unpatched plugin.

*1.6.2: a package offered for this plugin that the cached manifest doesn't name is checked against the manifest read again, and refused when it still doesn't match (D-144).*

### D-141 · The browser suite marks every request to the shop as a test run · 2026-11-03

The demo's top searches were this suite's own ("kazumi", "xyzzy"), from runs pointed at it with `WP_BASE_URL`. Zach approved sending `X-Creative-Test: 1` (D-139) from the suite. Every spec now imports `test` from `tests/e2e/support/test.ts`, which adds the header to each request for the base URL's origin through a context route, on every run, local or not. It isn't Playwright's `extraHTTPHeaders`, because that option sends the header to every origin. Then Twenty Twenty-Five's Google Fonts requests needed a CORS preflight that fonts.gstatic.com refused, the page fell back to another font, the field moved, and the panel's height budget (D-092) dropped the "kazumi in Decks" row (scope.spec.ts failed only with it). panel.spec.ts builds the log it needs through WP-CLI, so nothing depends on the browser's searches being logged.

**Why:** a run against a real shop should leave its analytics as they were. The header costs nothing on a local run.

### D-142 · In stock first, among products that match alike · 2026-11-03

With `in_stock_first` on (D-138), every product that could be bought came before every sold-out one, whatever it matched on. For "hood" on the demo the panel then showed two hoodies and four products that only mention the word in their description, and the four sold-out hoodies named for the search were gone. The 1.6.0 brief had asked for "each part keeps its score order"; the 1.6.2 brief puts how a product matched ahead of its stock.

1. **The two groups.** A product some searched word reaches only in its short or long description (`Fields::DESCRIPTIONS`) is in the second group; every other product, found by each word in its name, SKU, attributes, category, tag, brand or custom fields, is in the first. A word counts as reaching a field when the product carries a term the word reached there, whatever the field's weight. The brief names name, SKU, attributes, brand and category; tags and custom fields go with them, because the brief's line is "only in their description". The panel's "Matches the description" line (`MatchExplainer`, one word at a time) falls on these products in practice; it is a label, worked out after ranking, and the ranking does not read it.
2. **The order.** With the setting on: the first group, then the second; in each, in stock (or on backorder) before sold out; in each part, the score order. Pins are put first after ranking, as before. Off, nothing changes: the score decides.
3. **One rule, both rankers.** `Score::$descriptionOnly` is set by the PHP `Scorer` (a word whose postings in the product are all in the description fields) and by the SQL statement (`s.reach`, the product over the words of "some field outside the description holds this word"), and `Hit::stockFirst()` and the statement's `ORDER BY` both lead with it. `RankParityTest` has an in-stock-first round and compares the flag, so the panel, the results page and `/search` cannot disagree. The merge of phrase-synonym forms keeps each product where it ranks best under the same order, so a product named in one form is not held back by its description-only form.
4. **The help text** says products come first "among products that match as well".

**Why:** a shopper who types "hood" wants hoodies; one that is sold out still answers the search better than a deck whose description mentions a hood, and the shop can still show it as sold out.

### D-143 · The page the shopper asked for gets a row · 2026-11-03

The panel filled with pages last (D-136), so once products reached the cap no page had a row, and a phone showed none (`suggest_pages_phone` off, D-137). On the demo "size guide" suggested six wheels whose descriptions mention a size guide and no Size guides page; "returns" on a phone suggested nothing.

1. **Which page.** The panel's posts and pages already have every searched word in their title, matched as the panel matches (stems and prefixes, so "guide" finds "guides", D-137). The first, the best match, is the page asked for. Only that one moves; the others keep their place at the end and their phone rule.
2. **Where.** In the fill (`budget()`): one suggested search, the first three products named for the search, the page, then the rest of the three products from those found only by their description, then the category row and the rest as before. In the panel's order: suggested searches, products named for the search, the page, products found only by their description, categories, the other pages. When no product matches by name, the page comes right after the suggested search. At a cap of 3 with three products named for the search, the promised products win (D-136).
3. **A phone** shows the page asked for whatever `suggest_pages_phone` says.
4. **The split.** Each product row in `/suggest` carries `described` (D-142's flag; a pinned product is never `described`, so it keeps its place). The panel splits the products only when there is a page asked for; otherwise they stay in the order they came, as before. The searches within a category still count every product, named or not.
5. **Small caps.** At a cap of 3 the three products named for the search win and the page gets no row (D-136's promise). At a cap of 4 with a page, the page takes the suggested search's row.
6. **When the panel doesn't fit the screen** (D-092), the rows go in this order: the categories and other pages, the products found only by their description, the suggested search, the page asked for, then the products named for the search.
7. **Phrase-synonym forms.** With `in_stock_first` off, a product keeps the score of its best form (D-109); if that form found it only by its description while another named it, the row says `described` and goes after the page. Rare, panel only, and left as is: both forms are the same query to the shopper.

**Why:** a shopper who types the name of a page wants that page; it is one row, and a product that only mentions the words is a weaker answer than the page they name.

### D-144 · Only a package that matches the manifest is installed · 2026-11-03

`Updater::verify()` checked a package only when it was the cached manifest's, and returned `false` otherwise, so WordPress installed it unchecked. That happened when a newer release was published after WordPress's last check, or when the manifest had been unreadable and was cached that way for an hour. Low risk (the address had come from a trusted manifest), but it skipped the one check that catches a replaced release asset.

1. **Whose package.** One of this plugin's releases (an address starting `https://github.com/zachatkinson/creative-releases/releases/download/creative-search-`), or the one WordPress's update data (`update_plugins`) offers for this plugin's basename, is checked. Every other package is left to core or to its own plugin. The brief said "any address under https://github.com/zachatkinson/creative-releases/", but that repository holds every agency plugin's releases: with that prefix Search would refuse Shipment Tracking's and Stockroom's updates (its filter runs first) and read its own manifest again for each. The spec review caught it; a test holds a Creative Restock package as not ours. The sibling briefs need the same narrowing to their own slug.
2. **The check.** When the cached manifest doesn't name it, the manifest is read again past the cache (`ManifestSource::fresh()`, once; the answer replaces the cached one). It is installed only when the manifest names that package and the download's SHA-256 matches. Otherwise `csearch_package_unknown`, a `WP_Error` that asks the user to check for updates again; a mismatched hash is still `csearch_package_mismatch`.
3. **Tests.** The brief asks for unit tests. The Updater needs WordPress (`WP_Error`, site transients, file deletion), so its cases are in the integration `UpdaterTest` beside the 1.6.1 ones, as in the sibling plugins: a package the manifest no longer names (read again, then installed or refused), an unreadable manifest, the package core offers for this plugin, a match, a mismatched hash. `Manifest` stays in the unit suite.

The sibling plugins use the same `Updater`; the brief carries this fix to each of their next briefs.

**Why:** a check that can be skipped by timing is not a check; refusing costs the owner one "Check again".


### D-145 · "See all" counts what the page it opens lists · 2026-11-04

1.6.3 brief, item 1. "8.25 deck" showed "See all 122 results" and opened Decks filtered to Width 8.25″, which lists 88: a size becomes the shop's filter only on the way to the page (D-101, D-102), and the panel counted the search for every word, the size as a word (Skatehouse's decks name the widths they come in, in the description).

1. **One reading of where a query lands.** `Search\Destination` holds what `Results\QueryRouter::route()` worked out (the owner's redirect, pins, the filters, the category, a search kept to a category, and the count the page lists); the router asks it and still logs, sets the target and leaves the page. The `csearch_query_filters` and `csearch_category_redirect` filters now also run for the panel.
2. **The panel's total.** When the query names a value of an attribute the shop filters by, `total` in `/suggest` is the landing page's count (the filtered category, the other words' products in the filter, or the shop in the filter; the same `WP_Query` the log already used). Any other query keeps the number of products the panel's search found, as in 1.6.2, including a category without a size ("wheels" still counts its search, not the category's products) and the owner's redirects. `Search\Destination` uses `Results\QueryRouter`'s two static argument helpers and `Results\CategoryScope`, as `CategoryScopes` already did; the two namespaces lean on each other there, left as is.
3. **What the page lists.** The count is the landing's own listing: when WooCommerce hides sold-out products (`woocommerce_hide_out_of_stock_items`), they are left out of it too. A size nobody stocks counts 0, as the results page logs it (D-101): the panel then shows its products but no "See all", since Enter would land on an empty listing, and the panel's search is logged as finding nothing.
4. **Cost.** A query that names no filter costs its parse (the options it reads are autoloaded); one that does costs the landing's search and one counting query, once per cached panel answer. `category_redirect` and `filter_attributes` join the panel's cache key.
   The benchmark (`CatalogueBenchmarkTest`) now filters by width, so the golden size queries take this path inside the `/suggest` budget.
5. **`Suggestions::answer()`** takes the category it is kept to (the API's `cat`) as a term instead of its term-taxonomy ids, so the landing for a search kept to a category is the one the results page would use.

**Why:** a number that changes between the panel and the page reads as a mistake; the panel should promise what the page delivers.

### D-146 · A misspelling finds what its word finds; loose matches in descriptions come last · 2026-11-04

1.6.3 brief, item 2. "hody" was read as "hoody" (D-114) but found only the two products named "Hoody", plus decks whose descriptions say "body", one letter off; "hoody" typed finds every hoodie and sweater. And "shipping" suggested the Shipping and returns page, then decks that only mention shipping.

1. **The corrected word brings its family.** When a word matches nothing and is read as its closest typo candidate, that term, its stem and the owner's synonyms of it (with their stems) are searched too, at the typo's weight (`TYPO1`/`TYPO2`), in every field (but a short word's descriptions, point 3). The owner's phrase synonyms come too: the query as corrected is parsed again and its other forms ranked and merged (D-109), so "fingerbord", read as "fingerboard", finds "tech deck" as "fingerboard" does: what the word finds when typed, scored a little lower, so the order can differ from "hoody"'s while the set and "See all" are the same. The closest candidate stays first, so "Showing results for" reads as before. `Searcher` takes the owner's `Synonyms` (the same object the parser uses).
2. **The other candidates are loose** (`TermMatch::$loose`). A product some word reaches only through a loose candidate in its short or long description ranks after every other product (`Score::$looseOnly`; first in `Hit::compare()` and `Hit::stockFirst()`, `s.firm` first in the statement's ORDER BY), whatever its score; with `in_stock_first` on, in stock first still holds within each group. In a name, SKU, category, tag, brand or attribute a loose candidate counts as before.
3. **Four letters or fewer** (`Searcher::SHORT_WORD`, counted in letters): a typo's description postings don't count at all (`TermMatch::$descriptions`), the corrected word's family included, as the brief asks: "hody" never finds a deck through "body" in its description, and "shup", read as "ship", doesn't bring the decks whose descriptions say "Ships across Québec". "hody" still finds every hoodie and sweater, by name and category. Five letters or more, the corrected family counts in descriptions like a typed word.
4. **The statement.** Only a plan with a loose term or a short word's typo carries `lo` and `nd` on `q`, filters `f` and adds `firm`; every other plan's SQL is unchanged. The filter is per query row, so per word: a term another word or a minus word reads keeps its description postings there. `Ranker` drops the same postings per word and `Scorer` works out the same flag, held together by RankParityTest, which now also runs typos of description words (a letter dropped from five-letter words, alone, beside the word and with the word excluded; a transposition in longer ones), appended so the other generated queries stay the same.
5. **A page asked for keeps the description-only products out of the panel.** When the panel has a page whose title holds every searched word (D-143), products some word reaches only in their description (D-142's `described`) are left out of the panel's products: "shipping" shows the page and no decks. `total` and the results page still count and list them. The panel's script keeps its D-143 split, which now only ever sees products named for the search under a page.

**Why:** a typo should land where the word would have; a one-letter neighbour found in a description is the least likely thing the shopper meant, and on a short word it is noise. A page named for the search is the answer the shopper asked for.

### D-147 · The scope row follows the strong matches · 2026-11-04

1.6.3 brief, item 3. With beanie and toque synonyms both ways, "beanie" finds the 4 products in Hats and toques (through the category's name) and 5 decks whose descriptions mention a beanie. The scope row went to Decks, which holds more of the matches, 5 to 4; on a phone the cap of 5 kept "beanie in Decks" and cut the category row "Hats and toques (4)". A shopper who taps it lands on five decks after asking for a hat.

1. **Chosen from the strong matches.** `CategoryScopes::for()` picks from the ranked products found by more than their description (D-142's flag; a pinned product counts as found), when there are any: description-only matches don't earn a scope row while stronger matches exist. Whether searching within a category would show exactly what the search shows is still judged against every product found. For "beanie" the hats choose Hats and toques, which is already a category row, so there is no scope row.
2. **All description matches** choose as in 1.6.2, and the row carries `described: true` in `/suggest` (added to the schema).
3. **The cap.** In the panel's fill (`budget()`), when the scopes carry `described`, the category row is taken before the scope row; otherwise the order is 1.6.2's (scope, then category). The rows render in their usual order.
   On a phone, where categories and scopes share two rows, a described scope also leaves the category row its room. The panel's flag is the first scope's, so the category the shopper is in (point 4) keeps its place.
4. **The scope of the category the shopper is in** (D-130) still comes first when it holds any ranked product: the shopper chose it.
5. **When the panel doesn't fit the screen** (`fit()`, D-092), rows still go from the end, the category row (after the scope) first. That only happens on a short viewport, below the cap; left as is.

**Why:** a scope row says "this is where what you asked for is"; products that only mention the word are not what was asked for, and the category row they would push out is.

