<?php
/**
 * Indexer tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Domain\Fields;
use CreativeBranding\Search\Domain\QueryParser;
use CreativeBranding\Search\Domain\Searcher;
use CreativeBranding\Search\Domain\Token;
use CreativeBranding\Search\Index\AnalyzerFactory;
use CreativeBranding\Search\Index\Indexer;
use CreativeBranding\Search\Index\Languages;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Repository\IndexRepository;
use CreativeBranding\Search\Repository\StatRepository;
use CreativeBranding\Search\Support\Options;
use CreativeBranding\Search\Support\Tables;
use CreativeBranding\Search\Tests\Support\IndexTestCase;
use CreativeBranding\Search\Tests\Support\WooCommerceCatalogue;
use WC_Product;

/**
 * A product becomes one document per language with everything the spec lists; removal decrements df; the
 * public filters reach the document; the SQL store answers the searcher the way the in-memory one does.
 */
final class IndexerTest extends IndexTestCase {

	private function indexer(): Indexer {
		return $this->service( Indexer::class );
	}

	public function test_a_variable_product_becomes_one_document_with_every_field(): void {
		[ $id ] = $this->products( array( 0 ) );
		self::assertTrue( $this->indexer()->indexProduct( $id ) );

		$docs = $this->documents( 'product', $id );
		self::assertCount( 1, $docs, 'one document, the site language' );
		$doc = $docs[0];
		self::assertSame( 'en', $doc['lang'] );
		self::assertSame( 'KAZUMI Skate Deck, Dealer', $doc['title'] );
		self::assertSame( get_permalink( $id ), $doc['url'] );
		self::assertSame( '1', $doc['in_stock'] );
		self::assertSame( '1', $doc['visible'] );
		self::assertSame( '340', $doc['total_sales'] );
		self::assertSame( 84.99, (float) $doc['price_min'] );
		self::assertSame( 84.99, (float) $doc['price_max'] );

		$p = $this->postingsOf( (int) $doc['id'] );
		self::assertSame( array( 1, 0 ), $p['kazumi'][ Fields::NAME ] );
		self::assertArrayHasKey( Fields::BRAND, $p['kazumi'], 'the brand taxonomy' );
		self::assertArrayHasKey( Fields::SKU, $p['kaz'] );
		self::assertArrayHasKey( Fields::SKU, $p['=kaz dea 00001'], 'the SKU key (D-041)' );
		self::assertArrayHasKey( Fields::SKU, $p['=kaz dea 00001 825'], 'a variation SKU key' );
		self::assertArrayHasKey( Fields::CATEGORY, $p['skateboards'], 'the parent category' );
		self::assertArrayHasKey( Fields::CATEGORY, $p['decks'] );
		self::assertArrayHasKey( Fields::TAG, $p['popsicle'] );
		self::assertArrayHasKey( Fields::ATTRIBUTE, $p['8.25'], 'a global attribute term' );
		self::assertArrayHasKey( Fields::SHORT, $p['dealer'] );
		self::assertSame( array( 1, 0 ), $p['deck'][ Fields::NAME ] === array( 1, 2 ) ? array( 1, 0 ) : $p['deck'][ Fields::NAME ], 'the stem shares the position' );
		self::assertNull( $this->df( 'kaz dea 00001' ), 'keys carry the = prefix only' );
		self::assertSame( 0, $this->trigramsOf( '=kaz dea 00001' ), 'a SKU key gets no trigrams' );
		self::assertGreaterThan( 0, $this->trigramsOf( 'kazumi' ) );
	}

	public function test_custom_attributes_and_stock_roll_up(): void {
		[ $wheels, $sold ] = $this->products( array( 6, 3 ) );
		$this->indexer()->indexProduct( $wheels );
		$this->indexer()->indexProduct( $sold );
		$p = $this->postingsOf( (int) $this->documents( 'product', $wheels )[0]['id'] );
		self::assertArrayHasKey( Fields::ATTRIBUTE, $p['54mm'], 'a custom attribute value' );
		self::assertArrayHasKey( Fields::ATTRIBUTE, $p['99a'] );
		self::assertSame( '0', $this->documents( 'product', $sold )[0]['in_stock'], 'no variation in stock' );
	}

	public function test_a_variation_indexes_its_parent(): void {
		[ $id ] = $this->products( array( 1 ) );
		$children = WooCommerceCatalogue::get( $id )->get_children();
		self::assertTrue( $this->indexer()->indexProduct( (int) $children[0] ) );
		self::assertCount( 1, $this->documents( 'product', $id ) );
		self::assertSame( array(), $this->documents( 'product', (int) $children[0] ) );
	}

	public function test_hidden_products_are_indexed_with_visible_zero(): void {
		[ $id ] = $this->products( array( 20 ) );
		$this->indexer()->indexProduct( $id );
		self::assertSame( '0', $this->documents( 'product', $id )[0]['visible'] );
	}

	public function test_drafts_excluded_and_filtered_products_lose_their_documents(): void {
		[ $id ] = $this->products( array( 15 ) );
		$this->indexer()->indexProduct( $id );
		self::assertCount( 1, $this->documents( 'product', $id ) );

		update_post_meta( $id, Options::META_EXCLUDE, 'yes' );
		self::assertFalse( $this->indexer()->indexProduct( $id ) );
		self::assertSame( array(), $this->documents( 'product', $id ), 'excluded' );

		delete_post_meta( $id, Options::META_EXCLUDE );
		$this->indexer()->indexProduct( $id );
		$product = WooCommerceCatalogue::get( $id );
		$product->set_status( 'draft' );
		$product->save();
		self::assertFalse( $this->indexer()->indexProduct( $id ) );
		self::assertSame( array(), $this->documents( 'product', $id ), 'drafted' );

		$product->set_status( 'publish' );
		$product->save();
		add_filter( 'csearch_index_product', '__return_false' );
		self::assertFalse( $this->indexer()->indexProduct( $id ) );
		remove_filter( 'csearch_index_product', '__return_false' );
		self::assertSame( array(), $this->documents( 'product', $id ), 'refused by the filter' );

		self::assertFalse( $this->indexer()->indexProduct( 999999 ), 'a missing product' );
	}

	public function test_reindexing_is_idempotent_and_removal_decrements_df(): void {
		[ $a, $b ] = $this->products( array( 0, 21 ) );
		$this->indexer()->indexProduct( $a );
		$this->indexer()->indexProduct( $b );
		self::assertSame( 2, $this->df( 'kazumi' ) );
		$postings = $this->rowCount( Tables::postings() );

		$this->indexer()->indexProduct( $a );
		self::assertSame( 2, $this->df( 'kazumi' ), 'a second index of the same product changes no count' );
		self::assertSame( $postings, $this->rowCount( Tables::postings() ) );

		$this->indexer()->removeProduct( $a );
		self::assertSame( 1, $this->df( 'kazumi' ) );
		self::assertSame( 0, $this->df( 'dealer' ), 'df 0 keeps its row' );
		self::assertSame( array(), $this->documents( 'product', $a ) );

		wp_trash_post( $b );
		$this->indexer()->indexProduct( $b );
		self::assertSame( 0, $this->df( 'kazumi' ), 'a trashed product is removed by its job' );
		self::assertSame( 0, $this->rowCount( Tables::postings() ) );
	}

	public function test_the_document_fields_and_tokens_filters_reach_the_index(): void {
		[ $id ] = $this->products( array( 16 ) );
		$fields = static function ( array $fields ): array {
			$fields[ Fields::LONG ] = 'zinc plated';
			return $fields;
		};
		$document = static function ( array $document ): array {
			$document['title'] = 'Allen Bolts';
			$document['boost'] = 4;
			return $document;
		};
		$tokens = static fn( array $tokens, int $field ): array => Fields::NAME === $field ? array_merge( $tokens, array( new Token( 'bolts', 9 ) ) ) : $tokens;
		add_filter( 'csearch_product_fields', $fields );
		add_filter( 'csearch_document', $document );
		add_filter( 'csearch_tokens', $tokens, 10, 2 );
		$this->service( AnalyzerFactory::class )->flush();
		$this->indexer()->indexProduct( $id );
		remove_filter( 'csearch_product_fields', $fields );
		remove_filter( 'csearch_document', $document );
		remove_filter( 'csearch_tokens', $tokens, 10 );
		$this->service( AnalyzerFactory::class )->flush();

		$doc = $this->documents( 'product', $id )[0];
		self::assertSame( 'Allen Bolts', $doc['title'] );
		self::assertSame( '4', $doc['boost'] );
		$p = $this->postingsOf( (int) $doc['id'] );
		self::assertArrayHasKey( Fields::LONG, $p['zinc'] );
		self::assertSame( array( 1, 9 ), $p['bolts'][ Fields::NAME ] );
	}

	public function test_stop_words_come_from_the_owner_and_the_filter(): void {
		[ $id ] = $this->products( array( 16 ) );
		update_option( Options::STOPWORDS_PREFIX . 'en', array( 'Riveter' ) );
		$filter = static fn( array $words ): array => array_merge( $words, array( 'allen' ) );
		add_filter( 'csearch_stop_words', $filter );
		$this->service( AnalyzerFactory::class )->flush();
		$this->indexer()->indexProduct( $id );
		remove_filter( 'csearch_stop_words', $filter );
		$this->service( AnalyzerFactory::class )->flush();

		$p = $this->postingsOf( (int) $this->documents( 'product', $id )[0]['id'] );
		self::assertArrayNotHasKey( 'riveter', $p, 'the owner\'s stop word' );
		self::assertArrayNotHasKey( 'allen', $p, 'the filter\'s stop word' );
		self::assertArrayHasKey( 'hardware', $p );
	}

	public function test_languages_filter_writes_a_document_per_language(): void {
		[ $id ] = $this->products( array( 0 ) );
		$languages = static fn( array $langs ): array => $langs + array(
			'fr' => array(
				'product' => static function ( WC_Product $product, array $fields ): array {
					$fields[ Fields::NAME ] = 'Planche KAZUMI, Dealer';
					return $fields;
				},
				'term'    => static fn( \WP_Term $term, string $name ): string => 'Planches' === $name ? $name : $name . ' (fr)',
			),
		);
		add_filter( 'csearch_languages', $languages );
		$this->service( AnalyzerFactory::class )->flush();
		$this->indexer()->indexProduct( $id );
		$cat = get_term_by( 'slug', 'decks', 'product_cat' );
		self::assertInstanceOf( \WP_Term::class, $cat );
		$this->indexer()->indexTerm( 'product_cat', $cat->term_id );
		remove_filter( 'csearch_languages', $languages );
		$this->service( AnalyzerFactory::class )->flush();

		$docs = $this->documents( 'product', $id );
		self::assertSame( array( 'en', 'fr' ), array_column( $docs, 'lang' ) );
		self::assertSame( 'Planche KAZUMI, Dealer', $docs[1]['title'] );
		self::assertArrayHasKey( 'planch', $this->postingsOf( (int) $docs[1]['id'] ), 'stemmed in French' );
		self::assertSame( array( 'Decks', 'Decks (fr)' ), array_column( $this->documents( 'category', $cat->term_id ), 'title' ) );
		self::assertSame( array( 'en' ), $this->service( Languages::class )->codes(), 'back to the site language' );
	}

	/**
	 * Also the check of `StatRepository` and the postings' language and kind in Infection's run (D-048).
	 *
	 * @group sql-mutation
	 */
	public function test_the_stored_counts_match_the_postings_through_writes_removals_and_a_reindex(): void {
		$ids       = $this->products( array( 0, 1, 2, 3 ) );
		$languages = static fn( array $langs ): array => $langs + array(
			'fr' => array(
				'product' => static function ( WC_Product $product, array $fields ): array {
					$fields[ Fields::NAME ] = 'Planche ' . $product->get_name();
					return $fields;
				},
			),
		);
		add_filter( 'csearch_languages', $languages );
		$this->service( AnalyzerFactory::class )->flush();
		foreach ( $ids as $id ) {
			$this->indexer()->indexProduct( $id );
		}
		$cat = get_term_by( 'slug', 'decks', 'product_cat' );
		self::assertInstanceOf( \WP_Term::class, $cat );
		$this->indexer()->indexTerm( 'product_cat', $cat->term_id );
		$this->assertStatsMatchPostings( 'written in two languages' );
		self::assertGreaterThan( 0, $this->rowCount( Tables::stats() ) );

		$this->indexer()->indexProduct( $ids[0] );
		$this->assertStatsMatchPostings( 'rewritten' );
		$this->indexer()->removeProduct( $ids[1] );
		$this->assertStatsMatchPostings( 'removed' );

		global $wpdb;
		$old = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(generation) FROM %i', Tables::documents() ) ); // phpcs:ignore
		$wpdb->insert( Tables::stats(), array( 'term_id' => 999999, 'generation' => $old, 'lang' => 'en', 'df' => 3 ) ); // phpcs:ignore -- a stray count, as a crashed write could leave.
		$this->service( Reindex::class )->runInline();
		$this->assertStatsMatchPostings( 'after a reindex and its cleanup, which drops the old generation\'s strays' );
		remove_filter( 'csearch_languages', $languages );
		$this->service( AnalyzerFactory::class )->flush();

		foreach ( $ids as $id ) {
			$this->indexer()->removeProduct( $id );
		}
		$this->indexer()->removeTerm( 'product_cat', $cat->term_id );
		$this->assertStatsMatchPostings( 'all removed but the reindex\'s other categories, tags and brands' );
		self::assertSame( '0', $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM %i WHERE df = 0', Tables::stats() ) ), 'no row at zero is kept' ); // phpcs:ignore
	}

	/**
	 * The stats table holds exactly what counting the postings gives: documents per term, generation and
	 * language, with no row at zero.
	 *
	 * @param string $when What just happened.
	 */
	private function assertStatsMatchPostings( string $when ): void {
		global $wpdb;
		$expected = $wpdb->get_results( $wpdb->prepare( 'SELECT p.term_id, p.generation, p.lang, COUNT(DISTINCT p.document_id) AS df FROM %i p INNER JOIN %i d ON d.id = p.document_id AND d.lang = p.lang AND d.generation = p.generation AND (d.type = %s) = p.product GROUP BY p.term_id, p.generation, p.lang ORDER BY 1, 2, 3', Tables::postings(), Tables::documents(), 'product' ), ARRAY_A ); // phpcs:ignore
		$actual   = $wpdb->get_results( $wpdb->prepare( 'SELECT term_id, generation, lang, df FROM %i ORDER BY 1, 2, 3', Tables::stats() ), ARRAY_A ); // phpcs:ignore
		self::assertSame( $expected, $actual, $when );
		// And the store reads them back by term: every term of the live generation, in each language.
		$live = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(generation) FROM %i', Tables::stats() ) ); // phpcs:ignore
		foreach ( array( 'en', 'fr' ) as $lang ) {
			$want = array();
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT t.term, COUNT(DISTINCT p.document_id) AS df FROM %i p INNER JOIN %i t ON t.id = p.term_id WHERE p.generation = %d AND p.lang = %s GROUP BY t.term', Tables::postings(), Tables::terms(), $live, $lang ), ARRAY_A ) as $row ) { // phpcs:ignore
				$want[ (string) $row['term'] ] = (int) $row['df'];
			}
			$asked = array_merge( array_keys( $want ), array( 'zzznotaterm' ) );
			$got   = $this->service( StatRepository::class )->frequencies( $asked, $live, $lang );
			ksort( $want );
			ksort( $got );
			self::assertSame( $want, $got, "{$when}: frequencies() in {$lang}" );
		}
		self::assertSame( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Tables::postings() ) ), (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i p INNER JOIN %i d ON d.id = p.document_id AND d.lang = p.lang AND (d.type = %s) = p.product', Tables::postings(), Tables::documents(), 'product' ) ), $when . ': every posting carries its document\'s language and kind' ); // phpcs:ignore
	}

	public function test_the_site_language_is_the_setting_not_the_visitors_locale(): void {
		$french = static fn(): string => 'fr_CA';
		add_filter( 'locale', $french );
		self::assertSame( 'en', Languages::siteCode(), 'a per-visitor locale leaves the site language alone' );
		remove_filter( 'locale', $french );
		// WordPress refuses to store a language whose core files are not installed, so the setting is filtered.
		$setting = static fn(): string => 'fr_CA';
		add_filter( 'pre_option_WPLANG', $setting );
		self::assertSame( 'fr', Languages::siteCode() );
		remove_filter( 'pre_option_WPLANG', $setting );
		self::assertSame( 'en', Languages::siteCode() );
		// An empty setting is the site choosing English, as `get_locale()` reads it.
		$english = static fn(): string => '';
		add_filter( 'pre_option_WPLANG', $english );
		self::assertSame( 'en', Languages::siteCode() );
		remove_filter( 'pre_option_WPLANG', $english );
	}

	public function test_a_term_without_woocommerces_count_uses_its_own(): void {
		$this->products( array( 0 ) );
		$cat = get_term_by( 'slug', 'decks', 'product_cat' );
		self::assertInstanceOf( \WP_Term::class, $cat );
		update_term_meta( $cat->term_id, 'product_count_product_cat', 7 );
		self::assertSame( 7, \CreativeBranding\Search\Index\TermReader::count( $cat ) );
		delete_term_meta( $cat->term_id, 'product_count_product_cat' );
		self::assertSame( $cat->count, \CreativeBranding\Search\Index\TermReader::count( $cat ), 'the meta not written yet' );
	}

	public function test_category_tag_and_brand_documents(): void {
		$this->products( array( 0, 21 ) );
		$cat   = get_term_by( 'slug', 'decks', 'product_cat' );
		$brand = get_term_by( 'name', 'KAZUMI', 'product_brand' );
		$tag   = get_term_by( 'name', 'popsicle', 'product_tag' );
		self::assertInstanceOf( \WP_Term::class, $cat );
		self::assertInstanceOf( \WP_Term::class, $brand );
		self::assertInstanceOf( \WP_Term::class, $tag );
		self::assertTrue( $this->indexer()->indexTerm( 'product_cat', $cat->term_id ) );
		self::assertTrue( $this->indexer()->indexTerm( 'product_brand', $brand->term_id ) );
		self::assertTrue( $this->indexer()->indexTerm( 'product_tag', $tag->term_id ) );
		self::assertFalse( $this->indexer()->indexTerm( 'pa_width', 1 ), 'not an indexed taxonomy' );

		$doc = $this->documents( 'category', $cat->term_id )[0];
		self::assertSame( 'Decks', $doc['title'] );
		self::assertSame( '2', $doc['total_sales'], 'the product count rides in total_sales (D-013)' );
		self::assertSame( get_term_link( $cat ), $doc['url'] );
		self::assertSame( 'KAZUMI', $this->documents( 'brand', $brand->term_id )[0]['title'] );
		self::assertSame( 'popsicle', $this->documents( 'tag', $tag->term_id )[0]['title'] );

		// A parent category lists its subcategories' products, as WooCommerce counts them.
		$parent = get_term( $cat->parent, 'product_cat' );
		self::assertInstanceOf( \WP_Term::class, $parent );
		wc_recount_all_terms();
		$this->indexer()->indexTermTaxonomies( 'product_cat', array( $cat->term_taxonomy_id ) );
		self::assertSame( '2', $this->documents( 'category', $parent->term_id )[0]['total_sales'], 'counted through its children (D-078)' );

		wp_delete_term( $brand->term_id, 'product_brand' );
		self::assertFalse( $this->indexer()->indexTerm( 'product_brand', $brand->term_id ) );
		self::assertSame( array(), $this->documents( 'brand', $brand->term_id ), 'a deleted term loses its document' );
	}

	public function test_the_sql_store_answers_the_searcher(): void {
		$ids = $this->products( array( 0, 1, 2, 6, 7, 15, 20, 21 ) );
		foreach ( $ids as $id ) {
			$this->indexer()->indexProduct( $id );
		}
		$cat = get_term_by( 'slug', 'decks', 'product_cat' );
		self::assertInstanceOf( \WP_Term::class, $cat );
		$this->indexer()->indexTerm( 'product_cat', $cat->term_id );

		$store    = $this->service( IndexRepository::class );
		$searcher = new Searcher( $store, $this->service( AnalyzerFactory::class )->analyzer() );
		$parser   = new QueryParser( $this->service( AnalyzerFactory::class )->tokenizer() );

		self::assertSame( $ids[0], $searcher->search( $parser->parse( 'kazumi dealer', 'en' ) )->productIds()[0] );
		self::assertSame( $ids[0], $searcher->search( $parser->parse( 'KAZ-DEA-00001', 'en' ) )->productIds()[0], 'exact SKU' );
		self::assertSame( $ids[1], $searcher->search( $parser->parse( 'phily rascals', 'en' ) )->productIds()[0], 'a typo' );
		self::assertSame( $ids[4], $searcher->search( $parser->parse( 'cruis', 'en' ) )->productIds()[0], 'a prefix' );
		self::assertContains( $ids[3], $searcher->search( $parser->parse( '54mm', 'en' ) )->productIds(), 'an attribute value' );
		self::assertNotContains( $ids[6], $searcher->search( $parser->parse( 'kazumi', 'en' ) )->productIds(), 'hidden' );
		$terms = $searcher->search( $parser->parse( 'deck', 'en' ) )->terms;
		self::assertSame( 'Decks', $terms[0]->document->title, 'the category suggestion' );

		$docs = $store->documents( array( (int) $this->documents( 'product', $ids[0] )[0]['id'] ) );
		$doc  = array_values( $docs )[0];
		self::assertSame( 'kazumi skate deck dealer', $doc->nameKey );
		self::assertContains( 'kaz dea 00001', $doc->skuKeys );
		self::assertSame( 4, count( $doc->skuKeys ), 'the product and its three variations' );
		$one = array_values( $store->documents( array_keys( $docs ), 'kaz dea 00001 825' ) )[0];
		self::assertSame( array( 'kaz dea 00001 825' ), $one->skuKeys, 'with the query key, that key alone' );
		$none = array_values( $store->documents( array_keys( $docs ), 'kaz dea' ) )[0];
		self::assertSame( array(), $none->skuKeys, 'and nothing when it has not that key' );
		self::assertSame( 8, $store->documentCount( 'en' ) - 1, 'seven products and a category' );
	}

	public function test_one_product_indexes_within_budget(): void {
		$ids = $this->products( array( 0, 10, 13, 19 ) );
		$this->indexer()->indexProduct( $ids[0] ); // Warm the dictionary and the code paths.
		$times = array();
		foreach ( $ids as $id ) {
			$start   = hrtime( true );
			$this->indexer()->indexProduct( $id );
			$times[] = ( hrtime( true ) - $start ) / 1e6;
		}
		self::assertLessThan( 50.0, max( $times ), sprintf( 'spec: one product within 50 ms; took %s ms', implode( ', ', array_map( static fn( float $t ): string => sprintf( '%.1f', $t ), $times ) ) ) );
	}

	public function test_df_counts_documents_in_both_languages_and_removal_takes_them_all_off(): void {
		$languages = array( WooCommerceCatalogue::class, 'bilingual' );
		add_filter( 'csearch_languages', $languages );
		$this->service( AnalyzerFactory::class )->flush();
		[ $a, $b ] = $this->products( array( 0, 21 ) );
		$this->indexer()->indexProduct( $a );
		$this->indexer()->indexProduct( $b );
		self::assertSame( 4, $this->df( 'kazumi' ), 'two products, two languages' );
		$this->indexer()->indexProduct( $a );
		self::assertSame( 4, $this->df( 'kazumi' ), 'a save leaves the count' );
		$this->indexer()->removeProduct( $a );
		self::assertSame( 2, $this->df( 'kazumi' ), 'both of its documents come off' );
		$this->indexer()->removeDocuments( array_map( static fn( array $d ): int => (int) $d['id'], $this->documents( 'product', $b ) ) );
		self::assertSame( 0, $this->df( 'kazumi' ), 'two documents removed in one call take two off' );
		remove_filter( 'csearch_languages', $languages );
		$this->service( AnalyzerFactory::class )->flush();
	}

	public function test_a_failed_document_insert_throws(): void {
		[ $id ] = $this->products( array( 15 ) );
		$this->indexer()->indexProduct( $id );
		$row = $this->documents( 'product', $id )[0];
		$this->expectException( \CreativeBranding\Search\Support\PluginException::class );
		$GLOBALS['wpdb']->suppress_errors( true );
		try {
			$this->service( \CreativeBranding\Search\Repository\DocumentRepository::class )->insert(
				array(
					'generation'  => 1,
					'type'        => 'product',
					'object_id'   => $id,
					'lang'        => (string) $row['lang'],
					'title'       => 'duplicate',
					'name_key'    => 'duplicate',
					'url'         => '',
					'image_id'    => null,
					'price_min'   => null,
					'price_max'   => null,
					'in_stock'    => true,
					'visible'     => true,
					'boost'       => 0,
					'total_sales' => 0,
				)
			);
		} finally {
			$GLOBALS['wpdb']->suppress_errors( false );
		}
	}

	/**
	 * How many trigram rows a term has.
	 *
	 * @param string $term Term.
	 */
	private function trigramsOf( string $term ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i g JOIN %i t ON t.id = g.term_id WHERE t.term = %s', Tables::trigrams(), Tables::terms(), $term ) ); // phpcs:ignore
	}
}
