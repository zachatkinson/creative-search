# Hooks

Every public action and filter the plugin offers, added as it appears. Names carry the `csearch_` prefix; a hook listed in the spec and not here has not been built yet.

## Milestone 1

None. The foundation attached no hooks of its own.

## Milestones 3 and 4: the index (0.4.0)

Filters run in the indexing jobs (Action Scheduler, or inline under WP-CLI), never on the request that saved a product. Every example is complete code for a theme's `functions.php` or a small plugin.

### `csearch_index_product` (filter)

- **Since:** 0.4.0
- **Signature:** `apply_filters( 'csearch_index_product', bool $allowed, WC_Product $product ): bool`
- **What it does:** decides whether a product is in the index at all; false removes its documents. `$allowed` is already false for an unpublished product or one with `_csearch_exclude` set to `yes`.
- **Where it runs:** `Index\ProductReader::shouldIndex()`, in the indexing job and each reindex batch.
- **Example:**

```php
add_filter(
	'csearch_index_product',
	static fn( bool $allowed, WC_Product $product ): bool => $allowed && 'gift-card' !== $product->get_type(),
	10,
	2
);
```

### `csearch_product_fields` (filter)

- **Since:** 0.4.0
- **Signature:** `apply_filters( 'csearch_product_fields', array $fields, WC_Product $product ): array`
- **What it does:** the field texts of a product before they are cut into tokens. Keys are the field ids of `Domain\Fields` (name 1, SKU 2, short 3, long 4, category 5, tag 6, brand 7, attribute 8); each value is a string or a list of strings (a list keeps a phrase from spanning two values). The field weights are the `fields` setting and the `csearch_fields` filter (milestone 4); this filter is the text (D-050).
- **Where it runs:** `Index\ProductReader::fields()`, once per product per job, before the languages are read.
- **Example:**

```php
add_filter(
	'csearch_product_fields',
	static function ( array $fields, WC_Product $product ): array {
		$fields[ \CreativeBranding\Search\Domain\Fields::ATTRIBUTE ][] = (string) $product->get_meta( '_deck_shape' );
		return $fields;
	},
	10,
	2
);
```

### `csearch_document` (filter)

- **Since:** 0.4.0
- **Signature:** `apply_filters( 'csearch_document', array $document, WC_Product $product, string $lang ): array`
- **What it does:** the document about to be written for one product in one language: `title` (what the suggestion shows), `fields`, `skus`, `url`, `image_id`, `price_min`, `price_max`, `in_stock`, `visible`, `boost` (-10 to 10), `total_sales`, `lang`, and since 1.3.0 `cart` (a simple product that can go to the cart from the panel, D-106) and `facts` (its SKUs and attribute values, each `variation_id`, `field`, `attribute`, `name`, `value`, `label`, `image_id`, D-104; as the shop wrote them, the same on every language's document, so a language plugin translates `name` and `label` here). Every value is coerced back to its type afterwards.
- **Where it runs:** `Index\ProductReader::read()`, once per product and language per job.
- **Example:**

```php
add_filter(
	'csearch_document',
	static function ( array $document, WC_Product $product, string $lang ): array {
		if ( $product->get_meta( '_mature' ) ) {
			$document['visible'] = false; // Found by SKU only.
		}
		return $document;
	},
	10,
	3
);
```

### `csearch_tokens` (filter)

- **Since:** 0.4.0
- **Signature:** `apply_filters( 'csearch_tokens', Domain\Token[] $tokens, int $field, string $lang ): Domain\Token[]`
- **What it does:** the tokens cut from one value of one field (folded text and position), before stems are added. Return fewer to drop words, more to add them; anything that is not a `Token` is dropped.
- **Where it runs:** `Index\AnalyzerFactory::analyzer()`, through `Domain\Analyzer`, in the indexing job.
- **Example:**

```php
add_filter(
	'csearch_tokens',
	static fn( array $tokens, int $field ): array => array_values( array_filter( $tokens, static fn( $t ): bool => 'sale' !== $t->text ) ),
	10,
	2
);
```

### `csearch_stop_words` (filter)

- **Since:** 0.4.0
- **Signature:** `apply_filters( 'csearch_stop_words', string[] $words, string $lang ): string[]`
- **What it does:** the stop words of one language: the built-in list (Snowball's English and French) with the owner's additions from `csearch_stopwords_{lang}` already merged. Words are folded the way tokens are.
- **Where it runs:** `Index\AnalyzerFactory::stopWords()`, once per request that indexes or (from milestone 4) searches.
- **Example:**

```php
add_filter(
	'csearch_stop_words',
	static fn( array $words, string $lang ): array => 'en' === $lang ? array_merge( $words, array( 'skateboard' ) ) : $words,
	10,
	2
);
```

### `csearch_languages` (filter)

- **Since:** 0.4.0
- **Signature:** `apply_filters( 'csearch_languages', array $languages ): array`
- **What it does:** the languages to index, as code to readers. `product` receives the `WC_Product`, the field texts read from the product and the code, and returns the field texts in that language; `term` receives the `WP_Term`, its name and the code, and returns the name. Leave a reader out to read the product as it is. The default is the site language (the one set under Settings → General, not the visitor's locale; D-076) plus the `languages` setting (D-045).
- **Where it runs:** `Index\Languages::all()`, once per request.
- **Example:**

```php
add_filter(
	'csearch_languages',
	static fn( array $languages ): array => $languages + array(
		'fr' => array(
			'product' => static function ( WC_Product $product, array $fields ): array {
				$fields[ \CreativeBranding\Search\Domain\Fields::NAME ] = (string) $product->get_meta( '_name_fr' ) ?: $fields[1];
				return $fields;
			},
			'term'    => static fn( WP_Term $term, string $name ): string => (string) get_term_meta( $term->term_id, 'name_fr', true ) ?: $name,
		),
	)
);
```

### `csearch_index_switched` (action)

- **Since:** 0.4.0
- **Signature:** `do_action( 'csearch_index_switched', int $generation, int $previous )`
- **What it does:** fires when a full reindex goes live, before the old generation is deleted. The plugin flushes its own cache group just before.
- **Where it runs:** `Index\Reindex::terms()`, in the last reindex job.
- **Example:**

```php
add_action( 'csearch_index_switched', static fn( int $generation ) => error_log( "Search index generation {$generation} is live." ) );
```

### Jobs (Action Scheduler, group `csearch`)

Internal, listed so an owner reading **Tools → Scheduled Actions** knows them; not for plugins to call. `csearch_job_index_product` (one product), `csearch_job_index_products` (up to 100, after a term is deleted), `csearch_job_update_stock` (a stock-only change), `csearch_job_index_term` and `csearch_job_index_terms` (category, tag and brand documents), `csearch_job_term_products` (a renamed term's products, 100 a job), `csearch_reindex`, `csearch_reindex_batch`, `csearch_reindex_terms` and `csearch_reindex_cleanup` (a full reindex). `csearch_job_upgrade` (the schema upgrade, queued by any request while it is due, D-090). D-042 explains the `csearch_job_` prefix.

### WP-CLI

`wp csearch index <id>... [--format=json]`, `wp csearch reindex [--inline] [--cancel] [--format=json]`, `wp csearch status [--format=table|json|yaml]`. Each exits non-zero on failure.

## Milestone 4: the query and the API

### `csearch_fields` (filter)

- **Since:** 0.4.0
- **Signature:** `apply_filters( 'csearch_fields', array $weights ): array`
- **What it does:** the field weights the score uses, by field name (`name`, `sku`, `short`, `long`, `category`, `tag`, `brand`, `attribute`, and since 1.3.0 `custom`, the owner's custom fields, D-119), starting from the `fields` setting. Each is clamped to 0 to 100; 0 takes a field out of the score but not out of the index (D-050).
- **Where it runs:** `Search\SearchService::options()`, on every search that is not answered from the cache.
- **Example:**

```php
add_filter( 'csearch_fields', static fn( array $weights ): array => array( 'long' => 0 ) + $weights );
```

### `csearch_cache_ttl` (filter)

- **Since:** 0.4.0
- **Signature:** `apply_filters( 'csearch_cache_ttl', int $seconds ): int`
- **What it does:** how long an answer stays in the object cache; default 60, 0 turns the cache off (D-051).
- **Where it runs:** `Search\Cache::set()`, after a search that was not cached.
- **Example:**

```php
add_filter( 'csearch_cache_ttl', static fn(): int => 300 );
```

### `csearch_rate_limit` (filter)

- **Since:** 0.4.0
- **Signature:** `apply_filters( 'csearch_rate_limit', int $limit, string $route ): int`
- **What it does:** requests per 10 seconds per client for `suggest` (30), `search` (20) and `click` (30); 0 turns the limit off. Over it, the route answers 429 with `Retry-After`. Counters live in the object cache only (D-051).
- **Where it runs:** `Search\RateLimiter::hit()`, at the start of each public route.
- **Example:**

```php
add_filter( 'csearch_rate_limit', static fn( int $limit, string $route ): int => 'suggest' === $route ? 60 : $limit, 10, 2 );
```

### REST routes (`csearch/v1`)

| Route | Access | Returns |
| --- | --- | --- |
| `GET /suggest?q=&lang=&limit=&cat=&here=` | public, rate-limited, `Cache-Control: public, max-age=60`, ETag | `{ queries, products, scopes, terms, content, total, query, corrected, lang }`. Since 1.3.0: `queries`, the suggested searches (D-112); `scopes`, `{ id, name, url }` to search within a category (D-113); `cat`, a product category's slug, keeps the products to it and its subcategories (D-117); since 1.5.0, `here`, the category page the form is on, offered first among `scopes` when it holds any match, the rest of the answer unscoped (D-130); each product carries `matched` (the "why this matched" line or an empty string, D-105), `price` (its lowest price as a number, or null, D-116) and `cart` (whether the panel can add it to the cart, D-115), and its `url` and `image` are the named variation's (D-104); `corrected` also names a typo's correction (D-114) |
| `GET /search?q=&lang=&page=&per_page=&orderby=relevance` | public, rate-limited, cacheable | `{ ids, total, page, pages, corrected, query, lang }` |
| `GET /status` | `manage_woocommerce` | `{ generation, building, products, documents, terms, last_reindex, progress, backlog, errors }` |
| `POST /click` (`q`, `lang`, `type`, `id`) | public, rate-limited | `202 { recorded }` (D-052) |

Each route's argument and response schemas are served on an `OPTIONS` request.

### Jobs and WP-CLI

`csearch_purge_log` (Action Scheduler, daily at 03:00 UTC): yesterday's counts rolled into `csearch_settings['stats']`, then log rows past the retention period deleted 1,000 at a time. `wp csearch explain "<query>" [--lang=] [--limit=] [--format=]` prints each candidate with its tokens, fields, weights and bonuses.

## Milestones 5 and 6: the results page (0.6.0)

### `csearch_rank_query` (filter)

- **Since:** 0.6.0
- **Signature:** `apply_filters( 'csearch_rank_query', bool $ours, WP_Query $query ): bool`
- **What it does:** whether the plugin ranks a search. True for the main query of a product search (`?s=…&post_type=product`) on the front end; return false to hand a search back to WordPress, or true to rank another main-query search.
- **Where it runs:** `Results\ProductSearch::isProductSearch()`, on `pre_get_posts` of the main query.
- **Example:**

```php
add_filter( 'csearch_rank_query', static fn( bool $ours, WP_Query $q ): bool => $ours || ( $q->is_main_query() && $q->is_search() && ! is_admin() ), 10, 2 );
```

The page also reads `csearch_exact=1` (the "search instead for" link: the shopper's words with no token dropped) and `lang` (the language to search; the site's by default).

## Milestone 6: the search block

### `csearch_min_chars` (filter)

- **Since:** 0.6.0
- **Signature:** `apply_filters( 'csearch_min_chars', int $chars ): int`
- **What it does:** characters typed before the block asks for suggestions; default the `min_chars` setting (2), clamped to 1 to 5.
- **Where it runs:** `Block\FormOptions::fromSettingKeys()`, when a form renders.
- **Example:**

```php
add_filter( 'csearch_min_chars', static fn(): int => 3 );
```

### `csearch_client_ip` (filter)

- **Since:** 0.6.0
- **Signature:** `apply_filters( 'csearch_client_ip', string $ip, array $server ): string`
- **What it does:** the shopper's address for the rate limit; default `REMOTE_ADDR`. Behind a proxy or CDN, return the address your trusted proxy forwards (a forwarded header alone can be forged).
- **Where it runs:** `Search\RateLimiter::client()`, on each public route.
- **Example:**

```php
add_filter( 'csearch_client_ip', static fn( string $ip, array $server ): string => (string) ( $server['HTTP_CF_CONNECTING_IP'] ?? $ip ), 10, 2 );
```

### The block, the shortcode, the widget

`creative-search/search` (block, Interactivity API store `creative-search/search`), `[creative_search]` (shortcode, same options as the settings keys), the "Creative Search" widget, and, with the `theme_form_takeover` setting, `get_search_form`. All four render through `Block\SearchForm`.

## Milestone 7: the admin (0.7.0)

No new filter or action for other plugins. What the admin attaches, listed so nothing is a surprise:

### Admin actions (`admin-post.php`)

Internal endpoints, not for plugins to call; each checks `manage_woocommerce` and a nonce named after the action (D-068): `csearch_synonym_save`, `csearch_synonym_delete`, `csearch_synonym_import`, `csearch_synonym_export`, `csearch_analytics_export`, `csearch_boosts_save`, `csearch_tools_save`, `csearch_reindex`, `csearch_reindex_cancel`, `csearch_product_reindex`, `csearch_dismiss_notice`.

### WooCommerce hooks the plugin uses

`woocommerce_product_data_tabs` and `woocommerce_product_data_panels` (the product's Search tab), `woocommerce_admin_process_product_object` (its save), `woocommerce_product_export_product_default_columns`, `woocommerce_product_export_product_column_csearch_boost`, `woocommerce_product_export_product_column_csearch_exclude` and `woocommerce_product_export_skip_meta_keys` (the CSV columns), `woocommerce_product_import_pre_insert_product_object` (an import's values normalised; D-070).

### WP-CLI

`wp csearch search "<query>" [--lang=] [--limit=] [--format=]` (not logged), `wp csearch synonyms list [--lang=] [--search=] [--format=]`, `wp csearch synonyms add <word> <means> [--lang=] [--one-way] [--format=json]`, `wp csearch synonyms delete <id>...`, `wp csearch synonyms import <file|->`, `wp csearch synonyms export`, `wp csearch log purge [--days=] [--format=json]` (D-074), `wp csearch cache flush`. Each has `--help` and exits non-zero on failure.

## Milestone 8: languages and 1.0 (1.0.0)

No new hook. `csearch_languages` now starts from the language set under Settings → General rather than the request's locale (D-076), and the plugin's translations load through WordPress's just-in-time loading from its `Domain Path`, so the plugin hooks nothing for them.

## 1.1: the Skatehouse handoff and the Baymard fixes (1.1.0)

### `csearch_index_content` (filter)

- **Since:** 1.1.0
- **Signature:** `apply_filters( 'csearch_index_content', bool $allowed, WP_Post $post ): bool`
- **What it does:** whether a post or page is indexed (D-093). False by default for a type switched off (`index_posts`, `index_pages`), an unpublished post, one with a password, one the editor's "Exclude from search" box ticks (`_csearch_exclude`), the front page and WooCommerce's shop, cart, checkout and account pages.
- **Where it runs:** `Index\ContentReader::shouldIndex()`, in the index job for that post and in each reindex batch.
- **Example:**

```php
add_filter( 'csearch_index_content', static fn( bool $allowed, WP_Post $post ): bool => $allowed && ! has_category( 'internal', $post ), 10, 2 );
```

### `csearch_content_document` (filter)

- **Since:** 1.1.0
- **Signature:** `apply_filters( 'csearch_content_document', array $document, WP_Post $post, string $lang ): array`
- **What it does:** the document about to be written for a post or page in one language: `title`, `fields` (`Domain\Fields` ids to text: the title as NAME, the excerpt as SHORT, the content as LONG, the parent page's title as CATEGORY), `url`, `image_id`, `lang`, and the product keys, which a post leaves empty.
- **Where it runs:** `Index\ContentReader::read()`, in the index job for that post and in each reindex batch, after the language's `content` reader (`csearch_languages`).
- **Example:**

```php
add_filter(
	'csearch_content_document',
	static function ( array $document, WP_Post $post ): array {
		$document['fields'][ \CreativeBranding\Search\Domain\Fields::TAG ] = implode( ' ', wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ) );
		return $document;
	},
	10,
	2
);
```

`csearch_languages` takes a third reader for them, `content`: it receives the `WP_Post`, the field texts read from it and the language code, and returns the field texts in that language.

### `csearch_content_limit` (filter)

- **Since:** 1.1.0
- **Signature:** `apply_filters( 'csearch_content_limit', int $limit, string $context ): int`
- **What it does:** how many posts and pages show beside the products: 3 on the results page (`page`), which it can raise to 10, and 2 in the panel (`suggest`), which it can only lower, since the panel's row budget holds at most 2 (D-092); 0 shows none.
- **Where it runs:** `Search\SearchService::content()`, on a `/suggest` request and on the results page.
- **Example:**

```php
add_filter( 'csearch_content_limit', static fn( int $limit, string $context ): int => 'page' === $context ? 5 : $limit, 10, 2 );
```

### `csearch_suggestion_classes` (filter)

- **Since:** 1.1.0
- **Signature:** `apply_filters( 'csearch_suggestion_classes', array $classes, int $productId, ?int $imageId, array $row ): array`
- **What it does:** CSS classes for a product's suggestion row (Skatehouse handoff item 3: Mature Content blurs flagged artwork with one). Each is passed through `sanitize_html_class()`, repeats and non-strings are dropped, and at most 8 are kept. Since 1.3.0 `$imageId` is the image the row shows, a named variation's own photo when it has one (D-104). The answer is cached: a plugin whose answer changes calls `do_action( 'csearch_flush_cache' )`.
- **Where it runs:** `Search\Suggestions`, on a `/suggest` request that is not answered from the cache.
- **Example:**

```php
add_filter( 'csearch_suggestion_classes', static fn( array $classes, int $productId ): array => my_is_flagged( $productId ) ? array_merge( $classes, array( 'mcw-mature' ) ) : $classes, 10, 2 );
```

### `csearch_flush_cache` (action)

- **Since:** 1.1.0
- **Signature:** `do_action( 'csearch_flush_cache' )`
- **What it does:** drops every cached answer (the cache group's version is raised, so nothing is deleted one by one). The plugin listens to `mcw_flags_changed` (Mature Content) the same way.
- **Where it runs:** `Hooks\RestHooks`, whenever another plugin fires it.
- **Example:**

```php
add_action( 'my_flags_saved', static fn() => do_action( 'csearch_flush_cache' ) );
```

### `csearch_no_results_links` (filter)

- **Since:** 1.1.0
- **Signature:** `apply_filters( 'csearch_no_results_links', array $links, string $query ): array`
- **What it does:** the links the no-results page offers (D-096), each `url`, `text` and `kind` (`help`, `guide`, or a plugin's own word, used in the class name): the owner's help page and second page by default (`help_page`, `guide_page`).
- **Where it runs:** `Results\ResultsView::noResults()`, on a results page that found no product.
- **Example:**

```php
add_filter( 'csearch_no_results_links', static fn( array $links ): array => array_merge( $links, array( array( 'url' => 'tel:+18005550199', 'text' => __( 'Call us', 'my-theme' ), 'kind' => 'phone' ) ) ) );
```

### `csearch_alternative_queries` (filter)

- **Since:** 1.1.0
- **Signature:** `apply_filters( 'csearch_alternative_queries', array $found, string $query ): array`
- **What it does:** the single words of a query that found nothing which each find something, word to result count, offered as "Try one word:" links (D-096). Return an empty array to show none.
- **Where it runs:** `Results\ResultsView::noResults()`, on a results page that found no product for a query of two words or more.
- **Example:**

```php
add_filter( 'csearch_alternative_queries', static fn( array $found ): array => array_slice( $found, 0, 2, true ) );
```

### `csearch_dim_page` (filter)

- **Since:** 1.1.0
- **Signature:** `apply_filters( 'csearch_dim_page', bool $dim ): bool`
- **What it does:** whether the page dims behind the open suggestions (D-097); receives the block's own "Dim the page behind the suggestions" choice (`dimPage`, true by default, D-127). Return false for a theme whose header makes the backdrop sit in the wrong place. Button mode (D-099) has the dialog's own backdrop and ignores it.
- **Where it runs:** `Block\SearchForm::render()`, once per form on the page.
- **Example:**

```php
add_filter( 'csearch_dim_page', '__return_false' );
```

### Jobs

`csearch_job_index_content` (one post or page), `csearch_job_content_type` (a type switched on or off on the Tools tab: its posts indexed, or its documents deleted, 100 a job) and `csearch_reindex_content` (a full reindex's posts and pages, between the product batches and the terms).

### The block, the shortcode

The block's `mode` attribute and the shortcode's `mode="button"` show the form as an icon that opens it in a modal dialog (D-099). The block's markup holds nothing per visitor and no nonce, so a page cache may keep it for any length of time (Skatehouse handoff item 4).

### Admin

A "Search" box in the post and page editors ("Exclude from search", nonce `csearch_content_exclude`, saved on `save_post` for a user who can edit the post).

## 1.2: sizes and categories in the query (1.2.0)

### `csearch_query_filters` (filter)

- **Since:** 1.2.0
- **Signature:** `apply_filters( 'csearch_query_filters', array $filters, string $query ): array`
- **What it does:** the shop filters a results-page query names (D-101), attribute taxonomy to term slugs (`array( 'pa_width' => array( '8-25' ) )`), read from the attributes ticked under Tools → Results page. Return an empty array to search those words instead; add an entry to filter by a value the plugin did not read. Only attribute taxonomies that exist are kept. A word whose filter is removed is searched again.
- **Where it runs:** `Search\QueryRoutes::route()`, from `Results\QueryRouter::route()` on `pre_get_posts` (priority 4), for the main product search on its first page.
- **Example:**

```php
// Never treat a bare 8 as a size.
add_filter( 'csearch_query_filters', static fn( array $filters ): array => array_map( static fn( array $slugs ): array => array_values( array_diff( $slugs, array( '8-0' ) ) ), $filters ) );
```

### `csearch_category_redirect` (filter)

- **Since:** 1.2.0
- **Signature:** `apply_filters( 'csearch_category_redirect', ?WP_Term $term, string $query ): ?WP_Term`
- **What it does:** the category a results-page query opens (D-102): the one category with products whose name the query reads as, when no product is named exactly as the query reads and `category_redirect` is on; null otherwise. Return null to show the results; return another `product_cat` term to open it instead.
- **Where it runs:** `Results\QueryRouter::route()`, on `pre_get_posts` (priority 4). The shopper is sent there on `template_redirect` (priority 5, a 302), before WooCommerce's own single-result redirect.
- **Example:**

```php
// Keep "sale" on the results page even though a Sale category exists.
add_filter( 'csearch_category_redirect', static fn( ?WP_Term $term, string $query ): ?WP_Term => 'sale' === strtolower( $query ) ? null : $term, 10, 2 );
```

### Query arguments

- `csearch_from` and `csearch_sig`: set on the page a query was sent to (a category, or the search with its filters); the query as typed and the site's hash of it (`wp_hash`, 12 characters). Without a matching signature `csearch_from` is ignored. That page shows "Showing Wheels. Search all products for “wheels”." (`.csearch-results-routed`), keeps the typed query in the search field, does not log the query again, does not route again, and is not replaced by WooCommerce's single-result redirect.
- `csearch_all=1`: the ranked results page for the words as typed, never sent to a category or a filter (the link in that line).
- A search that already carries a `filter_*`, `query_type_*`, price or rating filter, an `orderby`, `csearch_exact` or a page past the first is never routed.

## 1.3: owner rules, the panel and the results page (1.3.0)

### `csearch_query_redirect` (filter)

- **Since:** 1.3.0
- **Signature:** `apply_filters( 'csearch_query_redirect', ?string $url, string $words, string $lang ): ?string`
- **What it does:** where a results-page search goes instead of the results (D-108): the owner's redirect for the query's words in its language, or null. Return an address to send it there, null to show the results. The address must be on this site: it goes through `wp_validate_redirect()` after the filter, and one that fails is ignored.
- **Where it runs:** `Search\OwnerRules::redirect()`, called by `Results\QueryRouter::route()` on `pre_get_posts` (priority 4), before the filters and categories of D-101 and D-102.
- **Example:**

```php
// Send "returns" to the returns page in every language.
add_filter( 'csearch_query_redirect', static fn( ?string $url, string $words ): ?string => 'returns' === strtolower( trim( $words ) ) ? home_url( '/returns/' ) : $url, 10, 2 );
```

### `csearch_pinned_products` (filter)

- **Since:** 1.3.0
- **Signature:** `apply_filters( 'csearch_pinned_products', int[] $ids, string $words, string $lang ): int[]`
- **What it does:** the products shown first for a search, in order (D-110): the owner's pins for the query's words in its language, or none. At most five are used; a product that is not published, is hidden from search or has no document in the language is still left out.
- **Where it runs:** `Search\OwnerRules::pins()`, called by `SearchService::rankedIds()` (results page and `/search`), `Suggestions` (the panel) and `Results\QueryRouter` (a pinned search is not routed).
- **Example:**

```php
// Pin a seasonal product to "gift".
add_filter( 'csearch_pinned_products', static fn( array $ids, string $words ): array => 'gift' === $words ? array_merge( array( 4321 ), $ids ) : $ids, 10, 2 );
```

### Query arguments

- `product_cat=<slug>` beside `s` and `post_type=product`: the search kept to a category and its subcategories (D-117), ranked within it (not ranked over the shop and then cut). The results page reads "3 results for “kazumi” in Decks" and links to "Search all products for “kazumi”." (`.csearch-results-scope`, with `csearch_all=1`). Such a search is never sent to another category; a size in it becomes the filter in the same category (D-101). Since 1.5 the form on a category page, and on a search kept to one, searches the whole shop; its panel offers the category first as a scope row, "kazumi in Decks", which leads here (D-130). 1.3's "Only in Decks" box (`.csearch-scope`) is gone.
- `orderby=` anything but `relevance` (price, popularity, rating, date): only the strong matches are listed, every word as typed, its spellings, stem or the owner's synonyms, never a word it begins or a typo's neighbour; when nothing is strong, every match is (D-118).

### Custom fields

The `custom_fields` setting (Tools → What to index) names product meta keys searched as the `custom` field (id 9, weight 4; `csearch_fields` can change it). An ACF field is read through `get_field_object()` when ACF is active: choices add their labels, post fields their titles, taxonomy fields their term names; image, file, gallery, user, map, colour, password, URL, e-mail, oEmbed and true/false fields are skipped. A change to one of those keys on a product (`added_post_meta`, `updated_post_meta`, `deleted_post_meta`) queues the product's reindex (D-119). `csearch_product_fields` sees the texts under `Domain\Fields::CUSTOM`.

### `csearch_suggested_searches` (filter)

- **Since:** 1.3.0
- **Signature:** `apply_filters( 'csearch_suggested_searches', array $picked, string $query, string $lang ): array`
- **What it does:** the past searches the panel suggests for what is typed (D-112), up to three strings, most searched first. Return fewer to keep a word off the panel; anything not a non-empty string is dropped, and at most three are kept.
- **Where it runs:** `Search\QuerySuggestions::for()`, on a `csearch/v1/suggest` request when `suggest_queries` is on and three characters or more are typed.
- **Example:**

```php
add_filter( 'csearch_suggested_searches', static fn( array $picked ): array => array_values( array_filter( $picked, static fn( string $q ): bool => ! str_contains( $q, 'clearance' ) ) ) );
```

### Events in the page (JavaScript)

With `analytics_events` on (D-116), the panel dispatches bubbling `CustomEvent`s on its `<form>`; listen on the form or on `document`:

| Event | `detail` | `dataLayer` push |
|---|---|---|
| `csearch:search` | `{ query }` | `{ event: 'search', search_term }` |
| `csearch:select` | `{ query, type, id, name, url, position }` (`type`: `product`, `scope`, `product_cat`, `post`, `page`…) | `select_item` for a product (`ecommerce.items`, list `csearch_suggestions`), otherwise `select_content` (`content_type`, `content_id`) |
| `csearch:no-results` | `{ query }` | `{ event: 'csearch_no_results', search_term }`, once per query |
| `csearch:add-to-cart` | `{ id, name, quantity }` | `add_to_cart` (`ecommerce.items`, `currency` and `value` when known) |

The `dataLayer` entries are pushed only when `window.dataLayer` already exists, each ecommerce one after `{ ecommerce: null }`.

```js
document.addEventListener( 'csearch:select', ( event ) => console.log( event.detail.name ) );
```

### WooCommerce events the panel fires

After adding to the cart (D-115): jQuery's `added_to_cart` on `document.body` when jQuery is on the page, otherwise `wc-blocks_added_to_cart` on `document.body`, so the mini-cart refreshes.

## Format for later entries

### `csearch_example` (filter)

- **Since:** 0.4.0
- **Signature:** `apply_filters( 'csearch_example', mixed $value, WC_Product $product ): mixed`
- **What it does:** one sentence.
- **Where it runs:** the class and method, and on which request (a job, a REST call, a save).
- **Example:**

```php
add_filter( 'csearch_example', static fn( $value, $product ) => $value, 10, 2 );
```

## 1.4: CSS custom properties (1.4.0)

The block's colour hooks (D-128). Set them anywhere a CSS custom property inherits from: `:root`, a header, the block. The plugin never declares them, so nothing it ships overrides a value a site sets, and each falls back to the look the block has without it.

| Property | Colours | Falls back to |
| --- | --- | --- |
| `--csearch-field-bg` | the field's background | the block's background (`--wp--preset--color--base`, else white) |
| `--csearch-field-fg` | the field's text and icons | the block's text colour |
| `--csearch-panel-bg` | the suggestions' background; the chosen row's tint is mixed from it | the block's background |
| `--csearch-panel-fg` | the suggestions' text; the secondary text is mixed from it | the block's text colour (`--wp--preset--color--contrast`, else #1e1e1e) |
| `--csearch-submit-bg` | the search button's background | transparent |
| `--csearch-submit-fg` | the search button's icon | the field's text colour |
| `--csearch-focus-ring` | the field's focus outline, and the clear and search buttons' | the current text colour |

```css
.site-header {
	--csearch-field-bg: #111;
	--csearch-field-fg: #fff;
	--csearch-panel-bg: #111;
	--csearch-panel-fg: #fff;
	--csearch-focus-ring: #f5c400;
}
```

Choose pairs with at least 4.5:1 contrast; the focus ring needs 3:1 against what surrounds the field.
