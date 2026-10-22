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
- **What it does:** the document about to be written for one product in one language: `title` (what the suggestion shows), `fields`, `skus`, `url`, `image_id`, `price_min`, `price_max`, `in_stock`, `visible`, `boost` (-10 to 10), `total_sales`, `lang`. Every value is coerced back to its type afterwards.
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
- **What it does:** the languages to index, as code to readers. `product` receives the `WC_Product`, the field texts read from the product and the code, and returns the field texts in that language; `term` receives the `WP_Term`, its name and the code, and returns the name. Leave a reader out to read the product as it is. The default is the site language plus the `languages` setting (D-045).
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

Internal, listed so an owner reading **Tools → Scheduled Actions** knows them; not for plugins to call. `csearch_job_index_product` (one product), `csearch_job_index_products` (up to 100, after a term is deleted), `csearch_job_update_stock` (a stock-only change), `csearch_job_index_term` and `csearch_job_index_terms` (category, tag and brand documents), `csearch_job_term_products` (a renamed term's products, 100 a job), `csearch_reindex`, `csearch_reindex_batch`, `csearch_reindex_terms` and `csearch_reindex_cleanup` (a full reindex). D-042 explains the `csearch_job_` prefix.

### WP-CLI

`wp csearch index <id>... [--format=json]`, `wp csearch reindex [--inline] [--cancel] [--format=json]`, `wp csearch status [--format=table|json|yaml]`. Each exits non-zero on failure.

## Milestone 4: the query and the API

### `csearch_fields` (filter)

- **Since:** 0.4.0
- **Signature:** `apply_filters( 'csearch_fields', array $weights ): array`
- **What it does:** the field weights the score uses, by field name (`name`, `sku`, `short`, `long`, `category`, `tag`, `brand`, `attribute`), starting from the `fields` setting. Each is clamped to 0 to 100; 0 takes a field out of the score but not out of the index (D-050).
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
| `GET /suggest?q=&lang=&limit=` | public, rate-limited, `Cache-Control: public, max-age=60`, ETag | `{ products, terms, total, query, corrected, lang }` |
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
