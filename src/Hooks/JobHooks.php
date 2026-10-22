<?php
/**
 * The Action Scheduler jobs.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Hooks;

use CreativeBranding\Search\Hookable;
use CreativeBranding\Search\Index\Indexer;
use CreativeBranding\Search\Index\IndexQueue;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Install\Upgrader;
use CreativeBranding\Search\Support\Cast;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The job hooks call the indexer and the reindex; the first build and the schema upgrade run on admin
 * requests, never on a shopper's.
 */
final class JobHooks implements Hookable {

	/**
	 * Constructor.
	 *
	 * @param Indexer $indexer The indexer.
	 * @param Reindex $reindex The reindex.
	 */
	public function __construct( private Indexer $indexer, private Reindex $reindex ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( IndexQueue::INDEX_PRODUCT, array( $this, 'indexProduct' ), 10, 1 );
		add_action( IndexQueue::UPDATE_STOCK, array( $this, 'updateStock' ), 10, 1 );
		add_action( IndexQueue::INDEX_TERM, array( $this, 'indexTerm' ), 10, 2 );
		add_action( IndexQueue::INDEX_TERMS, array( $this, 'indexTerms' ), 10, 2 );
		add_action( IndexQueue::TERM_PRODUCTS, array( $this, 'termProducts' ), 10, 3 );
		add_action( IndexQueue::INDEX_PRODUCTS, array( $this, 'indexProducts' ), 10, 1 );
		foreach ( array( Reindex::START, Reindex::BATCH_ACTION, Reindex::TERMS_ACTION, Reindex::CLEANUP ) as $hook ) {
			add_action(
				$hook,
				function ( ...$args ) use ( $hook ): void {
					$this->reindex->dispatch( $hook, Cast::ints( $args ) );
				},
				10,
				2
			);
		}
		add_action( 'admin_init', array( $this, 'adminInit' ) );
	}

	/**
	 * Index one product.
	 *
	 * @param int|mixed $productId Product id.
	 */
	public function indexProduct( $productId ): void {
		$this->indexer->indexProduct( Cast::int( $productId ) );
	}

	/**
	 * Flip one product's stock flag.
	 *
	 * @param int|mixed $productId Product id.
	 */
	public function updateStock( $productId ): void {
		$this->indexer->updateStock( Cast::int( $productId ) );
	}

	/**
	 * Rewrite one term's document.
	 *
	 * @param string|mixed $taxonomy Taxonomy.
	 * @param int|mixed    $termId   Term id.
	 */
	public function indexTerm( $taxonomy, $termId ): void {
		$this->indexer->indexTerm( Cast::str( $taxonomy ), Cast::int( $termId ) );
	}

	/**
	 * Rewrite the documents of terms known by term taxonomy id.
	 *
	 * @param string|mixed $taxonomy Taxonomy.
	 * @param array|mixed  $ttIds    Term taxonomy ids.
	 */
	public function indexTerms( $taxonomy, $ttIds ): void {
		$this->indexer->indexTermTaxonomies( Cast::str( $taxonomy ), Cast::ints( $ttIds ) );
	}

	/**
	 * Index a page of a renamed term's products, then queue the next page.
	 *
	 * @param string|mixed $taxonomy Taxonomy.
	 * @param int|mixed    $termId   Term id.
	 * @param int|mixed    $after    The last product id done.
	 */
	public function termProducts( $taxonomy, $termId, $after = 0 ): void {
		$ids = $this->indexer->productsOfTerm( Cast::str( $taxonomy ), Cast::int( $termId ), Cast::int( $after ), IndexQueue::BATCH );
		$this->indexProducts( $ids );
		if ( count( $ids ) === IndexQueue::BATCH ) {
			IndexQueue::schedule( IndexQueue::TERM_PRODUCTS, array( Cast::str( $taxonomy ), Cast::int( $termId ), max( $ids ) ) );
		}
	}

	/**
	 * Index up to 100 products in one job.
	 *
	 * @param array|mixed $productIds Product ids.
	 */
	public function indexProducts( $productIds ): void {
		foreach ( Cast::ints( $productIds ) as $productId ) {
			$this->indexer->indexProduct( $productId );
		}
	}

	/**
	 * On an admin request: the schema upgrade behind a lock, and the first build when activation could not queue it.
	 */
	public function adminInit(): void {
		Upgrader::maybeUpgrade();
		$this->reindex->queueFirstBuild();
	}
}
