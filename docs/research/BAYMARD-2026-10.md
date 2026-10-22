# Creative Search against Baymard's search research, 22 October 2026

Public Baymard articles and summaries. Per-guideline pages behind the paywall (#359, #363, #368, #934, #944) were seen as titles only. All paths are under baymard.com/research-articles/ unless noted:

- **QT:** ecommerce-search-query-types
- **QT14:** cdn.baymard.com/blog/ecommerce-search-query-types-449fdef76dc5caaaac8b59f22933731a.pdf
- **AC:** autocomplete-design
- **CP:** copy-search-suggestion-to-search-field
- **MS:** offer-autocomplete-suggestions-for-misspellings
- **SC:** search-scope
- **SF:** search-field-design
- **SB:** baymard.com/blog/search-bar-ui
- **MB:** mobile-search-submit-button
- **PQ:** persist-search-queries
- **AD:** autodirect-searches-matching-category-scopes
- **NR:** no-results-page
- **NP:** support-non-product-search
- **FS:** faceted-sorting
- **SN:** search-snippets
- **CV:** color-and-variation-searches
- **MN:** mobile-ecommerce-search-and-navigation
- **SW:** "search within the current category" (title only)

## Verdicts

| Guideline (Baymard figure, source) | 1.0 | Evidence |
| --- | --- | --- |
| Field prominence and size (SF) | Theme's job | The block fills its container |
| Placeholder (SB) | Meets | "Search products…", editable |
| Submit button on mobile (21 % don't, MB) | Meets | `.csearch-submit` |
| Search key on the phone keyboard (17 % don't, MB) | Meets | `enterkeyhint="search"`, `inputmode="search"` |
| Query kept in the field after searching (37 % don't, PQ) | **Misses** | The form renders `value=""` |
| Query shown on the results page (PQ) | Meets | `ResultsView::summary()` |
| Clear button big enough to tap (PQ) | Meets | 44 and 48 px |
| At most 10 suggestions on desktop, 4 to 8 on mobile (AC) | Partly | 10 rows by default on every screen, up to 14 |
| Keyword query suggestions (SB; #359 title only) | **Misses** | Products and terms only |
| Category-scope suggestions (62 % fail, SC; 72 % on mobile, MN) | Partly | Term rows link to the term, not "query within category" |
| Highlight the untyped part (AC) | **Misses** | No highlighting |
| No scrollbar in the panel (26 % have one, AC) | **Misses** | `overflow-y: auto`, accepted in D-079 |
| Keyboard, active row, pointer (AC) | Meets | `view.js`, `search-block.spec.ts` |
| Copy the active suggestion into the field and wrap back to it (58 % don't, CP) | **Misses** | The input is never written while arrowing |
| Dim the page behind the panel (AC) | Partly | A shadow only |
| Hit areas (AC) | Meets | 44 and 48 px rows |
| Misspellings in autocomplete (69 % don't, MS) | Meets | Trigram widening on `/suggest` |
| Images in suggestions (AC) | Meets | 48 px thumbnails |
| Recent searches (no public ruling) | Partly | Off by default (D-004) |
| Exact and name searches (12 %, QT) | Meets | Name and SKU fields, the exact-name bonus |
| Product-type searches (20 %, QT) | Meets | Categories, stemming, synonyms |
| Feature searches (39 %, QT) | Meets | Attribute values indexed |
| Thematic and use-case searches (43 %, QT) | Partly | Synonyms only |
| Symptom searches (37 %, QT) | Partly | Synonyms only |
| Relational searches (50 %, QT14) | Partly | Brands only |
| Compatibility searches (44 %, QT) | Partly | Attributes and synonyms |
| Abbreviations and symbols (54 %, QT) | Partly | `8.25in` stays one token; synonyms are single words (D-029) |
| Spelling mistakes (18 % fail phonetic, QT14) | Meets | Distance 1 or 2; compound words are not split |
| Natural language (QT14) | Partly | Stop words are dropped; AND matching with one dropped-word retry (D-034) |
| Non-product searches (66 % have issues, QT; 15 % unsupported, NP) | **Misses** | Coming in 1.1 |
| Result count (PQ) | Meets | `ResultsView::summary()` |
| "Did you mean" (AC, PQ) | Partly | Only when a word was dropped (D-058); the panel ignores `corrected` |
| Auto-direct to a matching category (46 % don't, AD) | Partly | Categories in the panel and on the no-results page only |
| Search within the current category (94 % of mobile sites don't, SW) | **Misses** | The form sends `s` and `post_type` only |
| Filters on results (SC, CV) | Meets | WooCommerce's filters over the ranked set (D-061) |
| Sorting must not bring weak matches up (10 % get it right, FS) | **Misses** | Other sorts keep all matches (D-057) |
| No-results page with ways forward (50 % offer few, NR) | Partly | Categories and fallback products; no help link, no alternative queries |
| Variation thumbnail matching the search (54 % don't, CV) | **Misses** | The parent's image |
| Snippets showing why a result matched (96 % don't, SN) | **Misses** | The template is unchanged |

## Where Baymard and DECISIONS.md disagree

- **D-079:** the panel scrolls; Baymard says it should not.
- **D-003 and Zach's 1.1 answer:** non-product search is the most widespread query-type failure. Zach keeps posts and pages off by default, so the owner chooses.
- **D-057:** other sorts keep weak matches.
- **D-029 and D-024:** single-word synonyms; a number with its unit stays one token.
- **The spec's keyboard model:** the input is not written while arrowing. The APG list-autocomplete example and CP write it.
