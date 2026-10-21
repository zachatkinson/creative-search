# Hooks

Every public action and filter the plugin offers, added as it appears. Names carry the `csearch_` prefix; a hook listed in the spec and not here has not been built yet.

## Milestone 1

None. The foundation attaches no hooks of its own: the container's hookable list is empty and the first hooks (`csearch_document`, `csearch_index_product`, `csearch_fields`, `csearch_tokens`, `csearch_stop_words`, `csearch_languages`) arrive with the indexer in milestone 3.

## Format for later entries

### `csearch_example` (filter)

- **Since:** 0.3.0
- **Signature:** `apply_filters( 'csearch_example', mixed $value, WC_Product $product ): mixed`
- **What it does:** one sentence.
- **Where it runs:** the class and method, and on which request (a job, a REST call, a save).
- **Example:**

```php
add_filter( 'csearch_example', static fn( $value, $product ) => $value, 10, 2 );
```
