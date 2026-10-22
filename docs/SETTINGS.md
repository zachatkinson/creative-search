# Settings

Everything the owner can set, with its default and its range. All of it lives in one autoloaded option, `csearch_settings`, read through `Settings\Settings` and sanitised by `Settings::sanitize()` whichever screen or command writes it. Unknown keys are dropped; a missing key takes its default; every value is coerced to its type and range (so a corrupt option never breaks a request).

Options beside it: `csearch_db_version` (the schema version `Install\Upgrader` compares on admin requests), `csearch_upgrade_lock` (a timestamp held while an upgrade runs, D-046), `csearch_index_state` (the running reindex and the live generation, below), `csearch_reindex_cancel` (the generation the owner asked to stop, D-053), `csearch_stopwords_{lang}` (the owner's stop-word additions, a list of lower-case words, set on the Tools tab). Product meta: `_csearch_boost` (−10 to 10, absent for 0) and `_csearch_exclude` (`yes` or absent), set in the product editor's Search tab and carried by the product CSV (D-070). User meta: `csearch_dismissed_notices` (the warnings a user dismissed, until each clears; D-071). Transients: `csearch_notice_{user id}` (an action's answer, for one minute).

**Where each is set.** WooCommerce → Search: the Tools tab sets what to index, the stop words, `retention_days` and the suggestion panel's defaults; the Boosts and exclusions tab sets `boost_rules`, `fallback_products` and `hidden_by_sku`. `fields` and `languages` have no screen: the spec gives them none, and the `csearch_fields` and `csearch_languages` filters are how a developer changes them. Every screen writes through `Settings::update()`, which merges, sanitises the whole and saves.

## What to index

| Key | Default | Range | Meaning |
| --- | --- | --- | --- |
| `index_long_description` | `yes` | yes/no | Index the long description (2,000 characters at most). |
| `index_tags` | `yes` | yes/no | Index product tags, and make tag documents. |
| `index_variation_skus` | `yes` | yes/no | Index every variation's SKU on the parent document. |
| `fields` | name 10, sku 10, brand 6, category 5, attribute 5, tag 3, short 2, long 1 | each 0 to 100 | Field weights in the score; the `csearch_fields` filter runs over the setting (D-050). A weight of 0 removes a field from the score but not from the index. |

## Languages

| Key | Default | Range | Meaning |
| --- | --- | --- | --- |
| `languages` | `[]` (the site's language) | codes, 2 to 8 characters | Languages to index; the `csearch_languages` filter adds how to read a product in each. |
| `fallback_to_default_language` | `yes` | yes/no | When a language's documents find nothing, search the site language's. |

## Matching

| Key | Default | Range | Meaning |
| --- | --- | --- | --- |
| `hidden_by_sku` | `no` | yes/no | A hidden product (catalogue visibility hidden) appears for an exact SKU match. Off until the owner answers the hidden-products question (docs/DECISIONS.md D-018). |
| `boost_rules` | `[]` | `{taxonomy, term_id, boost -10..10}` | A number added to every product in a category, brand or tag. |
| `fallback_products` | `[]` | product ids | Shown on the results page when nothing matches. |

## The suggestion panel's defaults

A block instance can override each of these; they are the defaults for new blocks, the shortcode and the widget.

| Key | Default | Range | Meaning |
| --- | --- | --- | --- |
| `suggest_limit` | 6 | 1 to 10 | Products in the panel. |
| `suggest_terms` | 3 | 0 to 3 | Categories, tags and brands after the products. |
| `min_chars` | 2 | 1 to 5 | Characters before the first request. |
| `show_prices` | `yes` | yes/no | Price in each row. |
| `show_stock` | `yes` | yes/no | Stock in each row. |
| `show_terms` | `yes` | yes/no | Show the category, tag and brand rows at all. |
| `label_visible` | `yes` | yes/no | A visible label, or a visually hidden one. |
| `placeholder` | `''` | text | The input's placeholder; empty uses the shop's translated default. |
| `panel_position` | `auto` | auto, below, above | `auto` opens below, and above on a phone when the keyboard would cover it. |
| `recent_searches` | `no` | yes/no | The shopper's last 5 searches, in their browser only. Off by default for privacy (D-004). |
| `theme_form_takeover` | `no` | yes/no | Replace a classic theme's `get_search_form()` with the block's form. |

## Analytics

| Key | Default | Range | Meaning |
| --- | --- | --- | --- |
| `retention_days` | 90 | 7 to 365 | Days a search-log row is kept before the nightly purge. |
| `stats` | `[]` | `{ 'Y-m-d': { name: count } }` | Daily roll-ups the Analytics charts read, so they survive the purge. Written by the plugin, not the owner. |

## The index state (`csearch_index_state`)

Written by the plugin, not the owner. Not autoloaded: jobs read it fresh from the database each time and a search reads it once (D-053). Shown by `wp csearch status`, `GET /csearch/v1/status` and the Tools tab.

| Key | Default | Meaning |
| --- | --- | --- |
| `live` | 1 | The generation the shop searches. |
| `building` | `null` | The generation a reindex is writing, or null. |
| `offset`, `total`, `done` | 0 | Products walked, products to walk, products written, for the progress bar. |
| `last_id` | 0 | The highest product id walked (the walk is by id, D-054). |
| `started_at`, `finished_at` | `null` | UTC times (`Y-m-d H:i:s`) of the last reindex. |
| `cancelled` | `false` | Derived when read: whether `csearch_reindex_cancel` names the generation being built. |
| `errors` | `[]` | The last 50 `#id: message` lines from jobs that threw. |
| `cleaning` | `null` | The old or abandoned generation being deleted. |
| `first_queued` | `false` | Activation (or the first admin request after it) queued the first build. |
