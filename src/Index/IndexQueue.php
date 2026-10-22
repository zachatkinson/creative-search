<?php
/**
 * The indexing queue.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Index;

use CreativeBranding\Search\Hooks\IndexHooks;
use CreativeBranding\Search\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects what a request changed and hands it to Action Scheduler once, at shutdown (spec: Indexing → When).
 * The hook callbacks only add an id to an array, so the request that saved a product runs no query of ours;
 * the jobs are queued after WooCommerce's own deferred product sync (shutdown priority 10). Under WP-CLI the
 * work runs inline at shutdown instead. A job is not queued twice while one with the same arguments waits.
 * Job hooks carry a `csearch_job_` prefix so none shares a name with a public filter (D-042: the spec's
 * `csearch_index_product` is the filter).
 */
final class IndexQueue {

	public const INDEX_PRODUCT  = 'csearch_job_index_product';
	public const INDEX_PRODUCTS = 'csearch_job_index_products';
	public const UPDATE_STOCK   = 'csearch_job_update_stock';
	public const INDEX_TERM     = 'csearch_job_index_term';
	public const INDEX_TERMS    = 'csearch_job_index_terms';
	public const TERM_PRODUCTS  = 'csearch_job_term_products';
	public const BATCH          = 100;
	public const PRIORITY       = 20;

	/**
	 * The product properties a save can change and still be "stock only" (spec: a stock-only change updates
	 * `in_stock` without re-tokenising).
	 */
	public const STOCK_PROPS = array( 'stock_quantity', 'stock_status', 'manage_stock', 'backorders', 'low_stock_amount', 'date_modified' );

	/**
	 * Product ids to index in full, one job each.
	 *
	 * @var array<int, true>
	 */
	private array $products = array();

	/**
	 * Product ids to index in full, in jobs of 100.
	 *
	 * @var array<int, true>
	 */
	private array $bulk = array();

	/**
	 * Product ids whose stock flag changed.
	 *
	 * @var array<int, true>
	 */
	private array $stock = array();

	/**
	 * Terms whose document to rewrite: taxonomy to term ids.
	 *
	 * @var array<string, array<int, true>>
	 */
	private array $terms = array();

	/**
	 * Term taxonomy ids whose document to rewrite: taxonomy to ids (resolved to terms in the job).
	 *
	 * @var array<string, array<int, true>>
	 */
	private array $termTaxonomies = array();

	/**
	 * Terms whose products to re-queue: taxonomy to term ids.
	 *
	 * @var array<string, array<int, true>>
	 */
	private array $termProducts = array();

	/**
	 * Products being saved: id to true (only stock changed), false (more changed) or null (no property changed,
	 * which is how `wc_update_product_stock()` saves before its stock hook; meta changes look the same).
	 *
	 * @var array<int, bool|null>
	 */
	private array $stockOnly = array();

	/**
	 * Products queued in full only because a save changed no property: a stock hook in the same request turns
	 * them into a stock flip.
	 *
	 * @var array<int, true>
	 */
	private array $unclear = array();

	/**
	 * Whether the shutdown callback is attached.
	 *
	 * @var bool
	 */
	private bool $attached = false;

	/**
	 * Constructor.
	 *
	 * @param Indexer $indexer The indexer, for the inline path.
	 */
	public function __construct( private Indexer $indexer ) {
	}

	/**
	 * A product (or its parent, for a variation) changed: index it in full.
	 *
	 * @param int $productId Product id.
	 */
	public function product( int $productId ): void {
		if ( $productId <= 0 ) {
			return;
		}
		$this->products[ $productId ] = true;
		$this->attach();
	}

	/**
	 * Many products changed at once (a term was deleted from them): queued in jobs of 100 ids, never one job
	 * each on the request that changed them.
	 *
	 * @param int[] $productIds Product ids.
	 */
	public function products( array $productIds ): void {
		foreach ( $productIds as $productId ) {
			if ( $productId > 0 ) {
				$this->bulk[ $productId ] = true;
			}
		}
		if ( array() !== $this->bulk ) {
			$this->attach();
		}
	}

	/**
	 * A product is about to be saved: remember whether its changes are stock only. WooCommerce applies the
	 * changes before its update hook fires, so this is the last moment they can be read.
	 *
	 * @param int      $productId Product or variation id (0 for a new one, which is never stock only).
	 * @param string[] $changed   The names of the changed properties.
	 */
	public function saving( int $productId, array $changed ): void {
		if ( $productId <= 0 ) {
			return;
		}
		$this->stockOnly[ $productId ] = array() === $changed ? null : array() === array_diff( $changed, self::STOCK_PROPS );
	}

	/**
	 * A product or variation was saved: queue a stock flip when only stock changed, else a full index of the
	 * product (the parent, for a variation).
	 *
	 * @param int $productId Product or variation id.
	 * @param int $parentId  The parent's id for a variation, else 0.
	 */
	public function saved( int $productId, int $parentId = 0 ): void {
		$target = $parentId > 0 ? $parentId : $productId;
		$only   = array_key_exists( $productId, $this->stockOnly ) ? $this->stockOnly[ $productId ] : false;
		unset( $this->stockOnly[ $productId ] );
		if ( true === $only ) {
			$this->stock( $target );
			return;
		}
		if ( null === $only && ! isset( $this->products[ $target ] ) ) {
			$this->unclear[ $target ] = true;
		} elseif ( false === $only ) {
			unset( $this->unclear[ $target ] );
		}
		$this->product( $target );
	}

	/**
	 * Only a product's stock changed.
	 *
	 * @param int $productId Product id.
	 */
	public function stock( int $productId ): void {
		if ( $productId <= 0 ) {
			return;
		}
		if ( isset( $this->unclear[ $productId ] ) ) {
			unset( $this->unclear[ $productId ], $this->products[ $productId ] );
		}
		$this->stock[ $productId ] = true;
		$this->attach();
	}

	/**
	 * A category, tag or brand changed: rewrite its document.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int    $termId   Term id.
	 */
	public function term( string $taxonomy, int $termId ): void {
		if ( null === TermReader::typeOf( $taxonomy ) || $termId <= 0 ) {
			return;
		}
		$this->terms[ $taxonomy ][ $termId ] = true;
		$this->attach();
	}

	/**
	 * Terms changed, known by term taxonomy id (what `set_object_terms` hands over).
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int[]  $ttIds    Term taxonomy ids.
	 */
	public function termTaxonomies( string $taxonomy, array $ttIds ): void {
		if ( null === TermReader::typeOf( $taxonomy ) ) {
			return;
		}
		foreach ( $ttIds as $ttId ) {
			if ( $ttId > 0 ) {
				$this->termTaxonomies[ $taxonomy ][ $ttId ] = true;
			}
		}
		$this->attach();
	}

	/**
	 * A term whose name products carry was renamed (a category, tag, brand or global attribute value): its
	 * products are re-queued in batches by a job.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int    $termId   Term id.
	 */
	public function termProducts( string $taxonomy, int $termId ): void {
		if ( ! IndexHooks::isProductTaxonomy( $taxonomy ) || $termId <= 0 ) {
			return;
		}
		$this->termProducts[ $taxonomy ][ $termId ] = true;
		$this->attach();
	}

	/**
	 * What is waiting, for the tests and the inline path.
	 *
	 * @return array{products: int[], bulk: int[], stock: int[], terms: array<string, int[]>, term_taxonomies: array<string, int[]>, term_products: array<string, int[]>}
	 */
	public function pending(): array {
		return array(
			'products'        => array_keys( $this->products ),
			'bulk'            => array_keys( array_diff_key( $this->bulk, $this->products ) ),
			'stock'           => array_keys( array_diff_key( $this->stock, $this->products, $this->bulk ) ),
			'terms'           => array_map( 'array_keys', $this->terms ),
			'term_taxonomies' => array_map( 'array_keys', $this->termTaxonomies ),
			'term_products'   => array_map( 'array_keys', $this->termProducts ),
		);
	}

	/**
	 * Forget everything collected without queuing it (a test resets the request between cases).
	 */
	public function clear(): void {
		$this->products       = array();
		$this->bulk           = array();
		$this->stock          = array();
		$this->terms          = array();
		$this->termTaxonomies = array();
		$this->termProducts   = array();
		$this->stockOnly      = array();
		$this->unclear        = array();
		$this->attached       = false;
	}

	/**
	 * Hand everything collected to Action Scheduler (or run it, under WP-CLI) and forget it. Attached to
	 * `shutdown`; the tests call it directly. A change made after this ran attaches it again.
	 */
	public function flush(): void {
		$pending = $this->pending();
		$this->clear();
		remove_action( 'shutdown', array( $this, 'flush' ), self::PRIORITY );
		if ( self::isCli() ) {
			$this->runInline( $pending );
			return;
		}
		foreach ( $pending['products'] as $productId ) {
			self::schedule( self::INDEX_PRODUCT, array( $productId ) );
		}
		foreach ( array_chunk( $pending['bulk'], self::BATCH ) as $chunk ) {
			self::schedule( self::INDEX_PRODUCTS, array( $chunk ) );
		}
		foreach ( $pending['stock'] as $productId ) {
			self::schedule( self::UPDATE_STOCK, array( $productId ) );
		}
		foreach ( $pending['terms'] as $taxonomy => $termIds ) {
			foreach ( $termIds as $termId ) {
				self::schedule( self::INDEX_TERM, array( $taxonomy, $termId ) );
			}
		}
		foreach ( $pending['term_taxonomies'] as $taxonomy => $ttIds ) {
			foreach ( array_chunk( $ttIds, self::BATCH ) as $chunk ) {
				self::schedule( self::INDEX_TERMS, array( $taxonomy, $chunk ) );
			}
		}
		foreach ( $pending['term_products'] as $taxonomy => $termIds ) {
			foreach ( $termIds as $termId ) {
				self::schedule( self::TERM_PRODUCTS, array( $taxonomy, $termId, 0 ) );
			}
		}
	}

	/**
	 * Queue one job unless one with the same arguments is waiting. Not Action Scheduler's own `$unique`: before
	 * 4.0 it ignored the arguments, and it also counts a running job, so a save during that product's own job
	 * would be dropped (D-043).
	 *
	 * @param string            $hook The action.
	 * @param array<int, mixed> $args Its arguments.
	 */
	public static function schedule( string $hook, array $args = array() ): void {
		if ( ! self::available() || self::isPending( $hook, $args ) ) {
			return;
		}
		as_enqueue_async_action( $hook, $args, Options::QUEUE_GROUP );
	}

	/**
	 * Whether a job with these arguments is waiting (not running).
	 *
	 * @param string            $hook The action.
	 * @param array<int, mixed> $args Its arguments.
	 */
	public static function isPending( string $hook, array $args = array() ): bool {
		if ( ! self::available() ) {
			return false;
		}
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => Options::QUEUE_GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);
		return is_array( $ids ) && array() !== $ids;
	}

	/**
	 * Whether a job of a hook, any arguments, is waiting or running.
	 *
	 * @param string $hook The action.
	 */
	public static function isScheduled( string $hook ): bool {
		return self::available() && as_has_scheduled_action( $hook, null, Options::QUEUE_GROUP );
	}

	/**
	 * Whether Action Scheduler can take a job right now.
	 */
	public static function available(): bool {
		return function_exists( 'as_enqueue_async_action' ) && class_exists( 'ActionScheduler' ) && \ActionScheduler::is_initialized();
	}

	/**
	 * Whether this is a WP-CLI process.
	 */
	public static function isCli(): bool {
		return defined( 'WP_CLI' ) && WP_CLI;
	}

	/**
	 * The jobs a WP-CLI request would have queued, run now.
	 *
	 * @param array{products: int[], bulk: int[], stock: int[], terms: array<string, int[]>, term_taxonomies: array<string, int[]>, term_products: array<string, int[]>} $pending What was collected.
	 */
	public function runInline( array $pending ): void {
		foreach ( array_merge( $pending['products'], $pending['bulk'] ) as $productId ) {
			$this->indexer->indexProduct( $productId );
		}
		foreach ( $pending['stock'] as $productId ) {
			$this->indexer->updateStock( $productId );
		}
		foreach ( $pending['terms'] as $taxonomy => $termIds ) {
			foreach ( $termIds as $termId ) {
				$this->indexer->indexTerm( $taxonomy, $termId );
			}
		}
		foreach ( $pending['term_taxonomies'] as $taxonomy => $ttIds ) {
			$this->indexer->indexTermTaxonomies( $taxonomy, $ttIds );
		}
		foreach ( $pending['term_products'] as $taxonomy => $termIds ) {
			foreach ( $termIds as $termId ) {
				$after = 0;
				do {
					$ids = $this->indexer->productsOfTerm( $taxonomy, $termId, $after, self::BATCH );
					foreach ( $ids as $productId ) {
						$this->indexer->indexProduct( $productId );
					}
					$after = array() === $ids ? $after : max( $ids );
					$full  = count( $ids ) === self::BATCH;
				} while ( $full );
			}
		}
	}

	/**
	 * Attach the shutdown callback once, after WooCommerce's deferred product sync.
	 */
	private function attach(): void {
		if ( $this->attached ) {
			return;
		}
		$this->attached = true;
		add_action( 'shutdown', array( $this, 'flush' ), self::PRIORITY );
	}
}
