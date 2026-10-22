# What Skatehouse needs from Creative Search, 22 October 2026

For Claude Code. This comes from two places: the Baymard audit of the Skatehouse demo on 22 October, which tested the store as shoppers see it, and a read of this repo at 1.0.0 on `release-1.1`. `docs/research/BAYMARD-2026-10.md` already covers most of the generic gaps. This file adds:

- what only the real shop shows;
- the details that audit left open;
- which items Skatehouse needs before its header search switches to Creative Search.

Plan it into 1.1 and 1.2 with Zach, record each choice in DECISIONS.md, and add the queries at the end to the golden set.

## The shop today

### Header search

The header has two Kadence Blocks Search blocks (`wp-block-kadence-search`, modal style), one in the desktop header row and one in the phone row. An icon opens a modal containing:

- `input.kb-search-input`, with `name="s"`, `aria-label="Search"` and the placeholder "Search decks, brands, sizes";
- a hidden `post_type=product`.

The field doesn't take focus when the modal opens. `FormTakeover` replaces only `get_search_form()`, so 1.0 can't take over these blocks.

### Results today

Today's search is WordPress's own and covers products only.

| Query | Result today |
| --- | --- |
| philly's | 67 products |
| phillys | Nothing |
| 8.25in | Nothing |
| 8.25 deck | 10 products, though 88 decks come in 8.25 |
| wheels | 47 products, fingerboard wheels first |
| returns | Nothing |

Every miss ends at "No products were found matching your selection.", shown beside an empty filter panel.

### Categories

| Category | Products | Notes |
| --- | --- | --- |
| Decks | 122 | |
| Wheels | 28 | |
| Grip tape | 6 | |
| Hardware | 1 | |
| Tools | 1 | |
| Media | 3 | |
| Accessories | | One subcategory: Stickers |
| Apparel | | Subcategories: Hats and toques, Hoodies and sweaters, Tees and long sleeves |
| Fingerboards | | Subcategories: Fingerboard decks, Fingerboard completes, Fingerboard obstacles, Fingerboard trucks and wheels |
| Trucks, Bearings, Completes | 0 | Empty until the owner stocks them |

### Attributes and filters

- `pa_width`: terms run from `7.75″` to `9.00″` and are written with two decimals (`8.00″`, `8.50″`). Slugs are `8-00`, `8-25` and so on.
- `pa_diameter`: `52mm` to `58mm`.
- `pa_width-mm`: fingerboard widths, `29mm` to `38mm`.
- `pa_size`: apparel sizes.
- `pa_series`.
- Grip products carry their sizes in the name, as `33″ x 9″`, `1.2mm` and `110*38mm`.
- Category pages filter with `?filter_width=8-25` and `?filter_diameter=54mm`. Applied filters show as chips with Remove ("Width: 8.25").

### Pages

| Page | URL | Notes |
| --- | --- | --- |
| Shipping and returns | `/shipping-and-returns/` | |
| Size guides | `/guides/` | Child pages: Deck width guide, Truck size guide, Wheel guide, Fingerboard size guide |
| Contact | | |
| Track an order | `/track-order/` | |
| Dealers | | |
| Drops and pre-books | | |
| About | | |
| Brands | | |
| Build a complete | | |
| Privacy policy | | |

The shop also has WooCommerce's Shop, Cart, Checkout and My account pages, and the home page.

### Caching

- **Object cache:** Redis Object Cache is installed and connected on the demo. Its comment closes every page (checked 22 October). This answers D-051's open question for this host.
- **Page cache:** there isn't one yet. Responses carry no cache headers, and a never-seen URL answers as fast as a repeat, in about 0.9 s. A page cache is on the go-live list.

### Other plugins that touch search

- **Mature Content for WooCommerce 1.1.0** (the agency's): blurs flagged artwork on cards.
- **Creative Stockroom:** its stock write-backs are already in the spec.
- **skatehouse-core:** the shop's own code. It will answer `csearch_languages` if the owner switches French on.
- **Later:** Creative Builder (Phase 3) and dealer accounts (Phase 4).

## Before the header switches to Creative Search

These items block the swap. Without them, the swap changes the header or does something worse than today's search does.

### 1. Search behind an icon, in a dialog

Baymard: an icon is fine for a catalogue this size, but the field must take the cursor when it opens. This is also item 8 of COMPETITORS-2026-10.md.

Add a block setting: either a field (as now) or a button. The button is an icon with the accessible name "Search". It opens a modal `<dialog>` that holds the same form and combobox.

- On open, the field has focus and the phone keyboard comes up.
- Escape and the dialog's close button close it.
- Back closes it without leaving the page (open it with a history entry).
- Focus returns to the button when it closes.
- The page behind the dialog is inert.
- On a phone, the dialog is full screen, with the panel under the field.
- Without JavaScript the search still works and nothing shifts. One way to do that is to show the form in place under `@media (scripting: none)`.

Zach then swaps the two Kadence blocks for this block, keeping the placeholder and a visually hidden label.

The alternative is to take over `kadence/search` through `render_block`. I don't recommend it, because it depends on Kadence Blocks' markup and its modal script.

Done when Playwright, on three engines and two viewports:

- opens the dialog both by click and by keyboard, and types without a tap on the field;
- closes it with Back and with Escape, and focus returns to the button;
- gets a clean axe run with the dialog open.

### 2. The query stays in the field

BAYMARD-2026-10.md marks this Misses, because `SearchForm.php` renders `value=""`. Today's Kadence field keeps the query on the results page, so switching without this fix is a step back.

On a product search page, in both modes:

- render `get_search_query()` as the field's value and as the context's `query`;
- show the clear button;
- keep the panel closed.

### 3. Mature artwork in the panel

Mature Content blurs flagged artwork on cards with a `mcw-mature` class and CSS it prints in the head. That covers the results page, whose cards come through WooCommerce's loop. It doesn't cover the panel: its rows are built in the browser from `/suggest`, so a flagged product's photo would show sharp.

Creative Search's side:

- A filter on each product suggestion. It receives the product id, its image id and the row, and can add CSS classes. The classes are sanitised, included in the response schema and rendered on the row.
- A public way to drop cached suggestions. Mature Content fires `mcw_flags_changed` when flags change.

Mature Content 1.2 does the rest, outside this repo: its card selectors, the blur on the 48 px thumbnail, and no button inside an option.

### 4. Safe under a page cache

Today the block's server render holds nothing per user and nothing time-limited: there's no nonce, and `/suggest` and `/click` need none. Keep it that way through the dialog, pages and anything new, so a page cached for days still searches.

Close D-051's open question with the Redis finding above.

## In 1.1 if they fit

All of these improve on today's search. None of them is a regression if it waits.

### 5. Arrow keys copy the suggestion into the field

This is Baymard's CP guideline in BAYMARD-2026-10.md: 58 % of sites don't do it.

- Down and Up write the highlighted row's text into the field: a product's name, or a category's or brand's name.
- Moving up past the first row restores what the shopper typed.
- Escape restores it and closes the panel.
- Hovering never writes.
- Writing the field this way doesn't fetch new suggestions.
- Enter on a highlighted row opens it, as now. Enter after editing the copied text searches the edited text.

`aria-activedescendant` and the live region stay as they are. Update the keyboard paragraph in SPEC.md and the APG keyboard script.

### 6. Units and number forms

This is Abbreviations in BAYMARD-2026-10.md; 54 % of sites have issues with it. It extends D-024, and confirms the reading Zach was asked about there.

Today:

- `8.25in` is one SKU-shaped token.
- `54 mm` is two tokens, and neither matches `54mm`.
- `8.5` and `8.50` are different terms, and numbers are never fuzzed. So "8.5 deck" misses every deck whose term is `8.50″`, unless 8.5 is the last word typed.

Apply these rules on both the index side and the query side:

- **Inches:** `8.25in`, `8.25 in`, `8.25 inch`, `8.25 inches`, `8.25"`, `8.25''` and `8.25″` all read as `8.25`. `in` counts as a unit only right after a number. When French is on, `8,25` and `8,25 po` also read as 8.25.
- **Trailing zeros:** `8.50` reads as `8.5`, and `8.00` and `9.00` as `8` and `9`. `8.125` and `8.375` stay as they are, and `8.25` never meets `8.5`.
- **Millimetres:**
  - `54mm`, `54 mm`, and a bare `54` beside another word (`54 wheels`) all find the 54 mm wheels.
  - `1.2 mm` finds Koyo's 1.2mm griptape.
  - `32 mm` finds the 32 mm fingerboard decks.
- **Sizes written as pairs:** `x`, `×` and `*` between two numbers split them.
  - `9x33`, `9 x 33`, `9×33`, `33x9` and `33″ x 9″` find the three `33″ x 9″` grip sheets.
  - `110x38` finds SHFB's `110*38mm` grip.
- **Fuzzing and stemming:** numbers and units stay out of both, as now.

Done when:

- the Tokenizer and QueryParser case tables carry every form above;
- `src/Domain` stays at 100 % mutation;
- the golden queries below pass.

### 7. Pages

The 1.1 plan already adds posts and pages, off by default. Skatehouse will turn pages on. Posts stay off until the owner decides about the 21 old blog posts.

What to index:

- Published pages, except:
  - WooCommerce's shop, cart, checkout and account pages;
  - the front page;
  - private and password-protected pages.
- Pages get an "Exclude from search" box, like the product's `_csearch_exclude`.
- Index each page's title (weighted like a product name), its parent page's title, and its text.

Where pages show:

- **In the panel:** their own labelled group after the products, at most two rows. Each row is the title with its parent ("Wheel guide · Size guides").
- **On the results page:** a short list above the products, at most three. When no product matches and a page does, the page leads, and the shopper never gets the bare no-results state.

A page never outranks a product whose name the query matches exactly.

These must be found by title and text, with no synonyms needed:

| Query | Page |
| --- | --- |
| returns, shipping | Shipping and returns |
| size guide | Size guides, ahead of its child pages |
| wheel guide | Wheel guide |
| deck width | Deck width guide, alongside the decks |
| contact | Contact |
| track order | Track an order |
| dealer | Dealers |
| pre-book | Drops and pre-books |

Other wordings follow once the owner's synonyms are in. The owner questions doc proposes refund → returns, tracking → track and wholesale → dealers.

### 8. The no-results state

This is NR in BAYMARD-2026-10.md: nearly half of sites give no way to recover. 1.0 shows the three nearest categories and the owner's fallback products (D-058). Add above them:

- matching pages (item 7);
- a help line with a link to a page the owner picks (for Skatehouse, Contact);
- a second link to a page the owner picks (for Skatehouse, Size guides).

Make the help line and the links settings, with translated generic defaults. Skatehouse's wording goes in its settings, not in the plugin.

The empty filter panel beside today's message belongs to the theme. skatehouse-core hides it on a search with no results.

## Before Skatehouse goes live

### 9. A size in the query becomes a removable filter

39 % of sites have trouble with feature queries (Baymard, 2026). BAYMARD-2026-10.md rates this Meets because the values are indexed. The match is there, but the shopper can neither see it nor undo it.

How it should work:

- A setting lists the attribute taxonomies the shop filters by. For Skatehouse: `pa_width`, `pa_diameter`, `pa_width-mm`, `pa_size`.
- When a query word equals a term of one of those attributes (after item 6's rules), the results apply WooCommerce's own filter (`filter_width=8-25`) instead of requiring the word.
- The theme then shows its usual chip, and removing the chip widens the results.

Examples:

- "8.25 deck" opens decks filtered to Width 8.25: 88 on the demo's filter today, against 10 from today's search.
- "54mm wheels" opens wheels filtered to 54mm.

The wording of the count line is yours.

### 10. A query that names a category opens that category

46 % of sites don't send a product-type query to its category (Baymard). This is AD in BAYMARD-2026-10.md, rated Partly.

The rule: take the query's words that are left after items 6 and 9. If they match one category's whole name (stems and the owner's synonyms count), go to that category's page:

- carry any filters from item 9;
- put a line at the top: "Showing Wheels. Search all products for “wheels”."

Examples:

- "wheels" opens Wheels (28 products), instead of 47 mixed results with fingerboard wheels first.
- "deck" and "decks" open Decks (122), not Fingerboard decks.
- "8.25 deck" opens Decks with Width 8.25 applied.

Don't redirect when:

- the category has no visible products (Trucks, Bearings and Completes today);
- the query also matches a product's name exactly;
- two categories match equally. Then lead the results with both categories as links.

A setting turns this off, and a redirected query is logged like any other search.

### 11. Tests on a copy of Skatehouse

Zach exports the demo into a gitignored `tests/fixtures/skatehouse/`:

- products, with WooCommerce's CSV exporter and all columns;
- pages, with Tools → Export.

No customers or orders go in.

Run the queries below as a `skatehouse` test group, and do D-081's FiboSearch measured run on the same copy.

## Later

- **Phase 3, Creative Builder** (specced, not started):
  - It adds a `cbuild_kit` product type whose price comes from its parts. Index it like any other product.
  - Give other plugins a documented action that queues products for reindexing, so the builder can refresh a kit when a part's price changes.
- **Phase 4, dealers:**
  - Signed-in dealers see their own prices. Suggestions are built from the index and cached publicly, so a dealer mustn't see the retail price and nobody else may see a dealer's. Either leave prices out for signed-in dealers or vary the answer by role.
  - Whether a hidden dealer-only product turns up for an exact SKU is the owner's call (`hidden_by_sku`, D-018).
- **The rest of BAYMARD-2026-10.md and COMPETITORS-2026-10.md**, as you and Zach plan it. Of those, Skatehouse would feel the variation's own photo most: Mumber-Fur's grip strips come in 22 designs, so "mumber fur fire" should show the Fire design.

## Handled outside this repo

So you know these are covered:

- Swapping the header blocks (item 1) and turning pages on (item 7).
- skatehouse-core:
  - hides the filter panel on a search with no results;
  - puts sold-out products last on category pages, with a "Sold out" label, and leaves the Relevance order on searches to Creative Search;
  - answers `csearch_languages` if the owner switches French on.
- Mature Content 1.2's side of item 3.
- The owner's search answers (synonyms, boosts, fallback products, retention) are questions 35 to 38 in the owner's questions doc. D-018's defaults stand until then.
- A page cache before go-live.

## The golden queries

Run these on the Skatehouse copy (item 11). Counts are from the demo on 22 October.

| Query | Expect | Item |
| --- | --- | --- |
| `philly's`, `phillys` | The same Philly's products for both (today's search: 67 and none) | 1.0 |
| `8.25in`, `8.25 in`, `8.25 inch`, `8.25"`, `8.25″` | The decks that come in 8.25″ | 6 |
| `8.5 deck`, `8.50 deck` | The decks that come in 8.50″ | 6 |
| `54 mm wheels`, `54mm wheels` | The 54 mm wheels | 6 |
| `9x33 grip`, `33 x 9 grip` | The three 33″ x 9″ grip sheets | 6 |
| `32 mm fingerboard deck` | Fingerboard decks in 32 mm | 6, 9, 10 |
| `8.25 deck` | Decks, filtered to Width 8.25 (88) | 9, 10 |
| `wheels` | The Wheels category (28) | 10 |
| `decks` | Decks (122), not Fingerboard decks | 10 |
| `returns` | Shipping and returns | 7 |
| `size guide` | Size guides first | 7 |
| `contact`, `track order` | Contact; Track an order | 7 |
| `mumber fur` | Mumber-Fur's products (the hyphen splits) | 1.0 |
| `kazumi deck` | The KAZUMI decks | 1.0 |
| `xyzzy` | No results: help line, size guides link, nearest categories, fallback products | 8 |
