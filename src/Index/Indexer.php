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
use CreativeBranding\Search\Domain\Kind;
use CreativeBranding\Search\Install\Upgrader;
use CreativeBranding\Search\Repository\ContentIdRepository;
use CreativeBranding\Search\Repository\DocumentRepository;
use CreativeBranding\Search\Repository\FactRepository;
use CreativeBranding\Search\Repository\PostingRepository;
use CreativeBranding\Search\Repository\ProductIdRepository;
use CreativeBranding\Search\Repository\StatRepository;
use CreativeBranding\Search\Repository\TermRepository;
use CreativeBranding\Search\Repository\TrigramRepository;
use RuntimeException;
use WC_Product;
use WP_Post;
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
	 * The longest list of a fact's terms, the column's width.
	 */
	public const FACT_TERMS = 255;

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
	 * @param ContentReader       $content   Reads posts and pages.
	 * @param ContentIdRepository $contentIds Post and page ids.
	 * @param FactRepository      $facts     The products' SKUs and attribute values (D-104).
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
		private StatRepository $stats,
		private ContentReader $content,
		private ContentIdRepository $contentIds,
		private FactRepository $facts
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
	 * Index one post or page into every generation that is live or being built; one switched off, unpublished
	 * or refused leaves the index (D-093).
	 *
	 * @param int      $postId     Post id.
	 * @param int|null $generation One generation only.
	 * @return bool Whether the post is in the index afterwards.
	 */
	public function indexContent( int $postId, ?int $generation = null ): bool {
		$this->ready();
		$post        = get_post( $postId );
		$type        = $post instanceof WP_Post ? ContentReader::typeOf( $post->post_type ) : null;
		$generations = null === $generation ? $this->generations() : array( $generation );
		if ( null === $type ) {
			foreach ( $generations as $target ) {
				$this->remove( Document::POST, $postId, $target );
				$this->remove( Document::PAGE, $postId, $target );
			}
			return false;
		}
		foreach ( $generations as $target ) {
			$this->remove( $type, $postId, $target );
		}
		if ( ! $post instanceof WP_Post || ! $this->content->shouldIndex( $post ) ) {
			return false;
		}
		$texts = $this->content->read( $post );
		foreach ( $generations as $target ) {
			foreach ( $texts as $text ) {
				$this->write( $type, $postId, $target, $text );
			}
		}
		return true;
	}

	/**
	 * The next published posts and pages of the types switched on, by id after the last one walked.
	 *
	 * @param int      $after     The last id walked (0 to start).
	 * @param int      $limit     At most.
	 * @param string[] $postTypes The post types; every type switched on when null.
	 * @return int[]
	 */
	public function contentAfter( int $after, int $limit, ?array $postTypes = null ): array {
		return $this->contentIds->publishedAfter( $postTypes ?? ContentReader::enabled(), $after, $limit );
	}

	/**
	 * One batch of a post type switched on or off: the next published posts indexed, or up to so many of its
	 * documents removed.
	 *
	 * @param string $postType Post type.
	 * @param int    $after    The last id walked (0 to start; ignored when removing).
	 * @param int    $limit    At most.
	 * @return int|null Where the next batch starts, or null when the type is done.
	 */
	public function syncContentType( string $postType, int $after, int $limit ): ?int {
		$this->ready();
		$type = ContentReader::typeOf( $postType );
		if ( null === $type ) {
			return null;
		}
		if ( in_array( $postType, ContentReader::enabled(), true ) ) {
			$ids = $this->contentAfter( $after, $limit, array( $postType ) );
			foreach ( $ids as $postId ) {
				$this->indexContent( $postId );
			}
			return array() !== $ids && count( $ids ) === $limit ? max( $ids ) : null;
		}
		$ids = $this->documents->idsOfType( $type, $limit );
		$this->removeDocuments( $ids );
		return count( $ids ) === $limit ? 0 : null;
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
				$this->documents->setStock( $productId, $target, $product->is_in_stock(), ProductReader::cart( $product ) );
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
		$this->facts->deleteFor( $ids );
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
				'cart'        => $text->cart,
			)
		);
		if ( array() !== $text->facts ) {
			$this->facts->write( $documentId, $this->factTerms( $text ) );
		}
		if ( array() === $postings ) {
			return;
		}
		$terms    = array_keys( $postings );
		$existing = $this->dictionary->idsOf( $terms );
		$ids      = $this->dictionary->ensure( $terms );
		$kind     = Kind::ofType( $type );
		$this->trigrams->write( array_diff_key( $ids, $existing ) );
		$this->postings->write( $documentId, $generation, $text->lang, $kind, $postings, $ids );
		if ( Kind::CONTENT === $kind ) {
			return; // The posts and pages' counts are read from their postings, so the catalogue's stay as they are (D-093).
		}
		$this->dictionary->increment( array_values( $ids ) );
		$this->stats->increment( array_values( $ids ), $generation, $text->lang );
	}

	/**
	 * A product's facts with the terms each one's label is cut into, as the document's own postings are (its
	 * exact tokens and stems), a SKU's key term with them, so a query's terms can be compared with each (D-104).
	 *
	 * @param ProductText $text The product in one language.
	 * @return array<int, array{variation: int, field: int, attribute: string, name: string, value: string, label: string, image_id: int|null, terms: string}>
	 */
	private function factTerms( ProductText $text ): array {
		$analyzer = $this->analyzers->analyzer();
		$out      = array();
		foreach ( $text->facts as $fact ) {
			$terms = array_map( 'strval', array_keys( $analyzer->analyze( array( $fact['field'] => $fact['label'] ), $text->lang ) ) );
			if ( Fields::SKU === $fact['field'] ) {
				$key = $this->analyzers->tokenizer()->key( $fact['label'], $text->lang );
				if ( '' !== $key ) {
					$terms[] = self::SKU_KEY_PREFIX . mb_substr( $key, 0, 63, 'UTF-8' );
				}
			}
			$joined = '';
			foreach ( array_unique( $terms ) as $term ) {
				if ( strlen( $joined ) + strlen( $term ) + 1 > self::FACT_TERMS ) {
					break;
				}
				$joined .= ( '' === $joined ? '' : ' ' ) . $term;
			}
			$out[] = $fact + array( 'terms' => $joined );
		}
		return $out;
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
