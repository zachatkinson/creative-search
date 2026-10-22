<?php
/**
 * The indexer.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Index;

use CreativeBranding\Search\Domain\Document;
use CreativeBranding\Search\Domain\Fields;
use CreativeBranding\Search\Install\Upgrader;
use CreativeBranding\Search\Repository\DocumentRepository;
use CreativeBranding\Search\Repository\PostingRepository;
use CreativeBranding\Search\Repository\ProductIdRepository;
use CreativeBranding\Search\Repository\StatRepository;
use CreativeBranding\Search\Repository\TermRepository;
use CreativeBranding\Search\Repository\TrigramRepository;
use RuntimeException;
use WC_Product;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes documents (spec: Indexing): a product becomes one document per language, cut into tokens field by
 * field, with its stems and its SKU keys (D-041); categories, tags and brands become documents of their own;
 * a removed product loses its rows and its terms lose a count, in the dictionary and per generation and language
 * (D-087). Runs in jobs, never on the request that saved.
 */
final class Indexer {

	/**
	 * The prefix of a SKU key term (D-041): no token starts with it.
	 */
	public const SKU_KEY_PREFIX = '=';

	/**
	 * Constructor.
	 *
	 * @param ProductReader       $products  Reads products.
	 * @param TermReader          $terms     Reads categories, tags and brands.
	 * @param AnalyzerFactory     $analyzers The analyzer.
	 * @param IndexState          $state     The generations.
	 * @param DocumentRepository  $documents Documents.
	 * @param TermRepository      $dictionary Terms.
	 * @param PostingRepository   $postings  Postings.
	 * @param TrigramRepository   $trigrams  Trigrams.
	 * @param ProductIdRepository $productIds Product ids by term.
	 * @param StatRepository      $stats     Term counts per generation and language.
	 */
	public function __construct(
		private ProductReader $products,
		private TermReader $terms,
		private AnalyzerFactory $analyzers,
		private IndexState $state,
		private DocumentRepository $documents,
		private TermRepository $dictionary,
		private PostingRepository $postings,
		private TrigramRepository $trigrams,
		private ProductIdRepository $productIds,
		private StatRepository $stats
	) {
	}

	/**
	 * Index one product into every generation that is live or being built (a variation indexes its parent).
	 *
	 * @param int      $productId  Product id.
	 * @param int|null $generation One generation only (the reindex writes the one it builds).
	 * @return bool Whether the product is in the index afterwards.
	 */
	public function indexProduct( int $productId, ?int $generation = null ): bool {
		$this->ready();
		$product = wc_get_product( $productId );
		if ( $product instanceof WC_Product && $product->is_type( 'variation' ) ) {
			$productId = $product->get_parent_id();
			$product   = wc_get_product( $productId );
		}
		$generations = null === $generation ? $this->generations() : array( $generation );
		if ( ! $product instanceof WC_Product || ! $this->products->shouldIndex( $product ) ) {
			foreach ( $generations as $target ) {
				$this->remove( Document::PRODUCT, $productId, $target );
			}
			return false;
		}
		$texts = $this->products->read( $product );
		foreach ( $generations as $target ) {
			$this->remove( Document::PRODUCT, $productId, $target );
			foreach ( $texts as $text ) {
				$this->write( Document::PRODUCT, $productId, $target, $text );
			}
		}
		return true;
	}

	/**
	 * Index one category, tag or brand.
	 *
	 * @param string   $taxonomy   Taxonomy.
	 * @param int      $termId     Term id.
	 * @param int|null $generation One generation only.
	 * @return bool Whether the term is in the index afterwards.
	 */
	public function indexTerm( string $taxonomy, int $termId, ?int $generation = null ): bool {
		$this->ready();
		$type = TermReader::typeOf( $taxonomy );
		if ( null === $type ) {
			return false;
		}
		$generations = null === $generation ? $this->generations() : array( $generation );
		$term        = get_term( $termId, $taxonomy );
		foreach ( $generations as $target ) {
			$this->remove( $type, $termId, $target );
		}
		if ( ! $term instanceof WP_Term ) {
			return false;
		}
		$texts = $this->terms->read( $term );
		foreach ( $generations as $target ) {
			foreach ( $texts as $text ) {
				$this->write( $type, $termId, $target, $text );
			}
		}
		return true;
	}

	/**
	 * Rewrite the documents of terms known by term taxonomy id (what `set_object_terms` hands over), and of
	 * their parents, whose product count includes their subcategories' (D-078).
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int[]  $ttIds    Term taxonomy ids.
	 */
	public function indexTermTaxonomies( string $taxonomy, array $ttIds ): void {
		$termIds = array();
		foreach ( $ttIds as $ttId ) {
			$term = get_term_by( 'term_taxonomy_id', (int) $ttId, $taxonomy );
			if ( $term instanceof WP_Term ) {
				$termIds[] = $term->term_id;
				array_push( $termIds, ...array_map( 'intval', get_ancestors( $term->term_id, $taxonomy, 'taxonomy' ) ) );
			}
		}
		foreach ( array_unique( $termIds ) as $termId ) {
			$this->indexTerm( $taxonomy, $termId );
		}
	}

	/**
	 * The next products carrying a term (and, for a hierarchical taxonomy, any of its descendants, whose
	 * products carry its name as a parent), by id after the last one walked.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int    $termId   Term id.
	 * @param int    $after    The last product id walked (0 to start).
	 * @param int    $limit    At most.
	 * @return int[]
	 */
	public function productsOfTerm( string $taxonomy, int $termId, int $after, int $limit ): array {
		$termIds = array( $termId );
		if ( is_taxonomy_hierarchical( $taxonomy ) ) {
			$children = get_term_children( $termId, $taxonomy );
			if ( is_array( $children ) ) {
				$termIds = array_merge( $termIds, array_map( 'intval', $children ) );
			}
		}
		return $this->productIds->withTermsAfter( $termIds, $after, $limit );
	}

	/**
	 * Remove a product from every generation.
	 *
	 * @param int $productId Product id.
	 */
	public function removeProduct( int $productId ): void {
		$this->ready();
		foreach ( $this->generations() as $target ) {
			$this->remove( Document::PRODUCT, $productId, $target );
		}
	}

	/**
	 * Remove a term from every generation.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int    $termId   Term id.
	 */
	public function removeTerm( string $taxonomy, int $termId ): void {
		$this->ready();
		$type = TermReader::typeOf( $taxonomy );
		if ( null === $type ) {
			return;
		}
		foreach ( $this->generations() as $target ) {
			$this->remove( $type, $termId, $target );
		}
	}

	/**
	 * A stock-only change: flip `in_stock` on the product's rows without re-tokenising. A product not yet in
	 * the index is indexed in full instead.
	 *
	 * @param int $productId Product id.
	 */
	public function updateStock( int $productId ): void {
		$this->ready();
		$product = wc_get_product( $productId );
		if ( $product instanceof WC_Product && $product->is_type( 'variation' ) ) {
			$productId = $product->get_parent_id();
			$product   = wc_get_product( $productId );
		}
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$indexed = false;
		foreach ( $this->generations() as $target ) {
			if ( array() !== $this->documents->idsOf( Document::PRODUCT, $productId, $target ) ) {
				$indexed = true;
				$this->documents->setStock( $productId, $target, $product->is_in_stock() );
			}
		}
		if ( ! $indexed ) {
			$this->indexProduct( $productId );
		}
	}

	/**
	 * Remove every document of an object in one generation, decrementing its terms.
	 *
	 * @param string $type       Document type.
	 * @param int    $objectId   Object id.
	 * @param int    $generation Generation.
	 */
	public function remove( string $type, int $objectId, int $generation ): void {
		$this->removeDocuments( $this->documents->idsOf( $type, $objectId, $generation ) );
	}

	/**
	 * Remove some documents by id, decrementing their terms.
	 *
	 * @param int[] $ids Document ids.
	 */
	public function removeDocuments( array $ids ): void {
		$this->ready();
		if ( array() === $ids ) {
			return;
		}
		$counts = $this->postings->termCountsOf( $ids );
		$total  = array();
		foreach ( $counts as $row ) {
			$total[ $row['term_id'] ] = ( $total[ $row['term_id'] ] ?? 0 ) + $row['n'];
		}
		$this->dictionary->decrement( $total );
		$this->stats->decrement( $counts );
		$this->postings->deleteFor( $ids );
		$this->documents->delete( $ids );
	}

	/**
	 * After a reindex's cleanup: drop whatever term counts the deleted generation still holds (none, when every
	 * removal was counted), and refresh the index statistics of the three tables the rebuild rewrote, so the
	 * database plans the next search on the new rows at once instead of after its own background refresh
	 * (D-089).
	 *
	 * @param int $generation A generation with no documents left.
	 */
	public function forgetGeneration( int $generation ): void {
		$this->ready();
		$this->stats->deleteGeneration( $generation );
		$this->documents->analyze();
		$this->postings->analyze();
		$this->stats->analyze();
	}

	/**
	 * Every write waits for the schema: the upgrade runs here when it is due, and when another request holds it,
	 * nothing is written (the rebuild the upgrade owes writes it all, D-090).
	 *
	 * @throws RuntimeException While another request upgrades the schema.
	 */
	private function ready(): void {
		if ( Upgrader::needed() && ! Upgrader::maybeUpgrade() && Upgrader::needed() ) {
			throw new RuntimeException( esc_html__( 'The search index is being upgraded by another request. Try again in a few minutes.', 'creative-search' ) );
		}
	}

	/**
	 * Write one document.
	 *
	 * @param string      $type       Document type.
	 * @param int         $objectId   Object id.
	 * @param int         $generation Generation.
	 * @param ProductText $text       What to write.
	 */
	private function write( string $type, int $objectId, int $generation, ProductText $text ): void {
		$analyzer = $this->analyzers->analyzer();
		$postings = $analyzer->analyze( $text->fields, $text->lang );
		foreach ( $text->skus as $sku ) {
			$key = $this->analyzers->tokenizer()->key( $sku, $text->lang );
			if ( '' === $key ) {
				continue;
			}
			$postings[ self::SKU_KEY_PREFIX . mb_substr( $key, 0, 63, 'UTF-8' ) ][ Fields::SKU ] = array( 1, 0 );
		}
		$title      = mb_substr( $text->title, 0, 255, 'UTF-8' );
		$documentId = $this->documents->insert(
			array(
				'generation'  => $generation,
				'type'        => $type,
				'object_id'   => $objectId,
				'lang'        => $text->lang,
				'title'       => $title,
				'name_key'    => mb_substr( $this->analyzers->tokenizer()->key( $title, $text->lang ), 0, 255, 'UTF-8' ),
				'url'         => mb_substr( $text->url, 0, 1024, 'UTF-8' ),
				'image_id'    => $text->imageId,
				'price_min'   => $text->priceMin,
				'price_max'   => $text->priceMax,
				'in_stock'    => $text->inStock,
				'visible'     => $text->visible,
				'boost'       => $text->boost,
				'total_sales' => $text->totalSales,
			)
		);
		if ( array() === $postings ) {
			return;
		}
		$terms    = array_keys( $postings );
		$existing = $this->dictionary->idsOf( $terms );
		$ids      = $this->dictionary->ensure( $terms );
		$this->trigrams->write( array_diff_key( $ids, $existing ) );
		$this->postings->write( $documentId, $generation, $text->lang, Document::PRODUCT === $type, $postings, $ids );
		$this->dictionary->increment( array_values( $ids ) );
		$this->stats->increment( array_values( $ids ), $generation, $text->lang );
	}

	/**
	 * The generations a live write goes to: the live one, and the one being built.
	 *
	 * @return int[]
	 */
	private function generations(): array {
		$generations = array( $this->state->live() );
		$building    = $this->state->building();
		if ( null !== $building ) {
			$generations[] = $building;
		}
		return $generations;
	}
}
