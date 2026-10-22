<?php
/**
 * Index hook tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Index\IndexQueue;
use CreativeBranding\Search\Support\Tables;
use CreativeBranding\Search\Tests\Support\IndexTestCase;
use CreativeBranding\Search\Tests\Support\WooCommerceCatalogue;

/**
 * Each hook queues once; the save request runs no query of ours; a stock-only change flips the flag without
 * re-tokenising; the jobs run through Action Scheduler and index.
 */
final class HooksTest extends IndexTestCase {

	private function queue(): IndexQueue {
		return $this->service( IndexQueue::class );
	}

	/**
	 * The ids a trigger queued for a full index, and for a stock flip.
	 *
	 * @param callable $trigger The change.
	 * @return array{products: int[], stock: int[]}
	 */
	private function queued( callable $trigger ): array {
		$this->queue()->clear();
		$trigger();
		$pending = $this->queue()->pending();
		$this->queue()->clear();
		return array(
			'products' => $pending['products'],
			'stock'    => $pending['stock'],
		);
	}

	public function test_each_product_hook_queues_the_product_once(): void {
		[ $simple, $variable ] = $this->products( array( 15, 0 ) );
		$variation             = (int) WooCommerceCatalogue::get( $variable )->get_children()[0];

		$cases = array(
			'save'                => array( $simple, static function () use ( $simple ): void {
				$p = WooCommerceCatalogue::get( $simple );
				$p->set_name( 'Mainline Griptape, Perforated Black' );
				$p->save();
			} ),
			'new product'         => array( null, static function (): void {
				$p = new \WC_Product_Simple();
				$p->set_name( 'New thing' );
				$p->save();
			} ),
			'variation save'      => array( $variable, static function () use ( $variation ): void {
				$v = WooCommerceCatalogue::get( $variation );
				$v->set_sku( 'KAZ-DEA-00001-NEW' );
				$v->save();
			} ),
			'variation trash'     => array( $variable, static fn() => wp_trash_post( $variation ) ),
			'trash'               => array( $simple, static fn() => wp_trash_post( $simple ) ),
			'untrash'             => array( $simple, static fn() => wp_untrash_post( $simple ) ),
			'category set'        => array( $simple, static fn() => wp_set_object_terms( $simple, array( 'Decks' ), 'product_cat', true ) ),
			'tag set'             => array( $simple, static fn() => wp_set_object_terms( $simple, array( 'grip' ), 'product_tag', true ) ),
			'attribute term set'  => array( $simple, static fn() => wp_set_object_terms( $simple, array( '8.25' ), 'pa_width', true ) ),
			'boost meta'          => array( $simple, static fn() => update_post_meta( $simple, '_csearch_boost', 3 ) ),
			'brand set'           => array( $simple, static fn() => wp_set_object_terms( $simple, array( 'Orbit' ), 'product_brand' ) ),
			'new variation'       => array( $variable, static function () use ( $variable ): void {
				$v = new \WC_Product_Variation();
				$v->set_parent_id( $variable );
				$v->set_sku( 'KAZ-DEA-00001-90' );
				$v->save();
			} ),
			'variation delete'    => array( $variable, static fn() => WooCommerceCatalogue::get( $variation )->delete( true ) ),
			'import'              => array( $simple, static fn() => do_action( 'woocommerce_product_import_inserted_product_object', WooCommerceCatalogue::get( $simple ), array() ) ),
			'status via wp core'  => array( $simple, static fn() => wp_update_post( array( 'ID' => $simple, 'post_status' => 'draft' ) ) ),
			'delete'              => array( $simple, static fn() => wp_delete_post( $simple, true ) ),
		);
		foreach ( $cases as $name => [ $expected, $trigger ] ) {
			$queued = $this->queued( $trigger );
			if ( null === $expected ) {
				self::assertCount( 1, $queued['products'], $name );
				continue;
			}
			self::assertSame( array( $expected ), $queued['products'], $name . ' queues the product once' );
		}
	}

	public function test_unrelated_posts_and_taxonomies_queue_nothing(): void {
		$post = self::factory()->post->create();
		self::assertSame( array( 'products' => array(), 'stock' => array() ), $this->queued( static fn() => wp_update_post( array( 'ID' => $post, 'post_title' => 'x' ) ) ) );
		self::assertSame( array( 'products' => array(), 'stock' => array() ), $this->queued( static fn() => wp_set_object_terms( $post, array( 'news' ), 'category' ) ) );
		self::assertSame( array( 'products' => array(), 'stock' => array() ), $this->queued( static fn() => update_post_meta( $post, 'colour', 'red' ) ), 'other meta' );
	}

	public function test_stock_changes_queue_a_stock_flip(): void {
		[ $simple, $variable ] = $this->products( array( 15, 0 ) );
		$variation             = (int) WooCommerceCatalogue::get( $variable )->get_children()[0];

		self::assertSame( array( 'products' => array(), 'stock' => array( $simple ) ), $this->queued( static fn() => wc_update_product_stock_status( $simple, 'outofstock' ) ), 'stock status through the CRUD save' );
		self::assertSame( array( 'products' => array(), 'stock' => array( $variable ) ), $this->queued( static fn() => wc_update_product_stock( WooCommerceCatalogue::get( $variation ), 0 ) ), 'a variation\'s quantity reaches its parent' );
		self::assertSame(
			array( 'products' => array( $simple ), 'stock' => array() ),
			$this->queued(
				static function () use ( $simple ): void {
					$p = WooCommerceCatalogue::get( $simple );
					$p->set_stock_status( 'instock' );
					$p->set_name( 'Renamed' );
					$p->save();
				}
			),
			'stock and a name: a full index'
		);
	}

	public function test_a_save_runs_no_query_of_ours_and_queues_one_job_at_shutdown(): void {
		[ $id ] = $this->products( array( 15 ) );
		$ours   = array();
		$spy    = static function ( string $sql ) use ( &$ours ): string {
			if ( str_contains( $sql, 'csearch' ) || str_contains( $sql, 'actionscheduler' ) ) {
				$ours[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $spy );
		$product = WooCommerceCatalogue::get( $id );
		$product->set_name( 'Mainline Griptape, Clear' );
		$product->save();
		remove_filter( 'query', $spy );
		self::assertSame( array(), $ours, 'the save request touched no plugin or queue table' );
		self::assertSame( array(), $this->pendingJobs() );

		$this->queue()->flush();
		$jobs = array_values( $this->pendingJobs() );
		self::assertSame( array( array( 'hook' => IndexQueue::INDEX_PRODUCT, 'args' => array( $id ) ) ), $jobs, 'one job, at shutdown' );
		$this->queue()->product( $id );
		$this->queue()->flush();
		self::assertCount( 1, $this->pendingJobs(), 'unique per product while pending' );
		$this->queue()->product( $id );
		self::assertSame( IndexQueue::PRIORITY, has_action( 'shutdown', array( $this->queue(), 'flush' ) ), 'flush is attached to shutdown, after WooCommerce\'s deferred sync' );
	}

	public function test_jobs_index_through_action_scheduler_and_stock_flips_in_place(): void {
		[ $id ] = $this->products( array( 15 ) );
		$this->queue()->product( $id );
		self::assertSame( 1, $this->runQueue() );
		$doc = $this->documents( 'product', $id )[0];
		self::assertSame( '1', $doc['in_stock'] );
		$postings = $this->rowCount( Tables::postings() );

		wc_update_product_stock_status( $id, 'outofstock' );
		$pending = array_column( $this->pendingJobs(), 'hook' );
		self::assertSame( array(), $pending, 'still nothing before shutdown' );
		self::assertSame( 1, $this->runQueue() );
		$after = $this->documents( 'product', $id )[0];
		self::assertSame( '0', $after['in_stock'] );
		self::assertSame( $doc['id'], $after['id'], 'the same row: not re-tokenised' );
		self::assertSame( $doc['indexed_at'], $after['indexed_at'] );
		self::assertSame( $postings, $this->rowCount( Tables::postings() ) );
	}

	public function test_a_renamed_category_reindexes_its_document_and_products(): void {
		[ $a, $b ] = $this->products( array( 0, 1 ) );
		$cat       = get_term_by( 'slug', 'decks', 'product_cat' );
		self::assertInstanceOf( \WP_Term::class, $cat );
		$this->queue()->products( array( $a, $b ) );
		$this->runQueue();

		wp_update_term( $cat->term_id, 'product_cat', array( 'name' => 'Boards' ) );
		$this->runQueue();
		self::assertSame( 'Boards', $this->documents( 'category', $cat->term_id )[0]['title'] );
		foreach ( array( $a, $b ) as $id ) {
			self::assertArrayHasKey( 'boards', $this->postingsOf( (int) $this->documents( 'product', $id )[0]['id'] ), 'the product carries the new name' );
		}

		wp_delete_term( $cat->term_id, 'product_cat' );
		$this->runQueue();
		self::assertSame( array(), $this->documents( 'category', $cat->term_id ) );
		self::assertArrayNotHasKey( 'boards', $this->postingsOf( (int) $this->documents( 'product', $a )[0]['id'] ) );
	}

	public function test_the_inline_path_does_what_the_jobs_do(): void {
		self::assertFalse( IndexQueue::isCli(), 'the suite is not WP-CLI' );
		[ $a, $b ] = $this->products( array( 0, 15 ) );
		$cat       = get_term_by( 'slug', 'decks', 'product_cat' );
		self::assertInstanceOf( \WP_Term::class, $cat );
		$this->queue()->runInline(
			array(
				'products'        => array( $a ),
				'bulk'            => array( $b ),
				'stock'           => array(),
				'terms'           => array( 'product_cat' => array( $cat->term_id ) ),
				'term_taxonomies' => array(),
				'term_products'   => array( 'product_cat' => array( $cat->term_id ) ),
			)
		);
		self::assertCount( 1, $this->documents( 'product', $a ) );
		self::assertCount( 1, $this->documents( 'product', $b ) );
		self::assertCount( 1, $this->documents( 'category', $cat->term_id ) );
		self::assertSame( array(), $this->pendingJobs(), 'nothing queued' );
	}

	public function test_a_quantity_change_that_keeps_the_flag_does_not_retokenise(): void {
		[ $id ] = $this->products( array( 15 ) );
		$p      = WooCommerceCatalogue::get( $id );
		$p->set_manage_stock( true );
		$p->set_stock_quantity( 10 );
		$p->save();
		$this->queue()->product( $id );
		$this->runQueue();
		$doc     = $this->documents( 'product', $id )[0];
		$queries = array();
		$spy     = static function ( string $sql ) use ( &$queries ): string {
			if ( str_starts_with( ltrim( $sql ), 'INSERT' ) && ( str_contains( $sql, Tables::documents() ) || str_contains( $sql, Tables::postings() ) ) ) {
				$queries[] = $sql;
			}
			return $sql;
		};
		wc_update_product_stock( WooCommerceCatalogue::get( $id ), 9 );
		add_filter( 'query', $spy );
		self::assertSame( 1, $this->runQueue() );
		remove_filter( 'query', $spy );
		self::assertSame( array(), $queries, 'no document or posting written' );
		self::assertSame( $doc['id'], $this->documents( 'product', $id )[0]['id'] );
	}

	public function test_renaming_an_attribute_value_or_a_parent_category_reaches_the_products(): void {
		[ $deck ] = $this->products( array( 0 ) );
		$this->queue()->product( $deck );
		$this->runQueue();
		$width = get_term_by( 'name', '8.25', 'pa_width' );
		self::assertInstanceOf( \WP_Term::class, $width );
		wp_update_term( $width->term_id, 'pa_width', array( 'name' => '8.26' ) );
		$this->runQueue();
		self::assertArrayHasKey( '8.26', $this->postingsOf( (int) $this->documents( 'product', $deck )[0]['id'] ) );

		$parent = get_term_by( 'slug', 'skateboards', 'product_cat' );
		self::assertInstanceOf( \WP_Term::class, $parent );
		wp_update_term( $parent->term_id, 'product_cat', array( 'name' => 'Boards and Parts' ) );
		$this->runQueue();
		self::assertArrayHasKey( 'parts', $this->postingsOf( (int) $this->documents( 'product', $deck )[0]['id'] ), 'a product in a child category carries the parent\'s new name' );
	}

	public function test_deleting_a_term_queues_its_products_in_batches(): void {
		$ids = $this->products( array( 0, 1, 2 ) );
		$tag = get_term_by( 'name', 'popsicle', 'product_tag' );
		self::assertInstanceOf( \WP_Term::class, $tag );
		$this->queue()->clear();
		wp_delete_term( $tag->term_id, 'product_tag' );
		$pending = $this->queue()->pending();
		self::assertSame( array(), $pending['products'] );
		sort( $pending['bulk'] );
		self::assertSame( $ids, $pending['bulk'] );
		$this->queue()->flush();
		$hooks = array_count_values( array_column( $this->pendingJobs(), 'hook' ) );
		self::assertSame( 1, $hooks[ IndexQueue::INDEX_PRODUCTS ], 'one job for the three products' );
	}

	public function test_a_save_during_its_own_running_job_is_queued_again(): void {
		[ $id ] = $this->products( array( 15 ) );
		IndexQueue::schedule( IndexQueue::INDEX_PRODUCT, array( $id ) );
		$actionId = (int) array_key_first( $this->pendingJobs() );
		\ActionScheduler::store()->log_execution( $actionId ); // Now running.
		IndexQueue::schedule( IndexQueue::INDEX_PRODUCT, array( $id ) );
		self::assertCount( 1, $this->pendingJobs(), 'a second job waits behind the running one' );
		IndexQueue::schedule( IndexQueue::INDEX_PRODUCT, array( $id ) );
		self::assertCount( 1, $this->pendingJobs(), 'but only one' );
		IndexQueue::schedule( IndexQueue::INDEX_PRODUCT, array( $id + 1 ) );
		self::assertCount( 2, $this->pendingJobs(), 'another product has its own job' );
	}
}
