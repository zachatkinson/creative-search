<?php
/**
 * Indexes the generated catalogue in memory.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Support;

use CreativeBranding\Search\Domain\Analyzer;
use CreativeBranding\Search\Domain\Document;
use CreativeBranding\Search\Domain\Fields;
use CreativeBranding\Search\Tests\Fixtures\Catalogue;

/**
 * Builds a {@see MemoryIndex} from {@see Catalogue} products the way milestone 3's indexer will from
 * WooCommerce: one document per product and language with its name, SKUs, descriptions, category names (with
 * parents), tags, brand and attribute values (variations folded in); one document per category, tag and brand
 * per language, with its name.
 */
final class CatalogueIndexer {

	public const LANGS = array( 'en', 'fr' );

	/**
	 * Constructor.
	 *
	 * @param Analyzer $analyzer The analyzer.
	 */
	public function __construct( private Analyzer $analyzer = new Analyzer() ) {
	}

	/**
	 * Index a catalogue.
	 *
	 * @param array<int, array<string, mixed>> $products Products from {@see Catalogue::generate()}.
	 */
	public function index( array $products ): MemoryIndex {
		$index = new MemoryIndex();
		$id     = 0;
		$tags   = array();
		$brands = array();
		$counts = array();
		foreach ( $products as $product ) {
			if ( 'publish' !== $product['status'] ) {
				continue;
			}
			foreach ( $product['tags'] as $tag ) {
				$tags[ $tag ] = ( $tags[ $tag ] ?? 0 ) + 1;
			}
			$brands[ $product['brand'] ] = ( $brands[ $product['brand'] ] ?? 0 ) + 1;
			foreach ( $product['categories'] as $slug ) {
				$counts['category'][ $slug ] = ( $counts['category'][ $slug ] ?? 0 ) + 1;
			}
			foreach ( self::LANGS as $lang ) {
				$suffix = 'en' === $lang ? '' : '_fr';
				$skus   = array_merge( array( $product['sku'] ), array_column( $product['variations'] ?? array(), 'sku' ) );
				$attrs  = array();
				foreach ( $product['attributes'] as $values ) {
					foreach ( (array) $values as $value ) {
						$attrs[ $value ] = true;
					}
				}
				foreach ( $product['variations'] ?? array() as $variation ) {
					foreach ( $variation['attributes'] as $value ) {
						$attrs[ $value ] = true;
					}
				}
				$fields = array(
					Fields::NAME      => $product[ 'name' . $suffix ],
					Fields::SKU       => $skus,
					Fields::SHORT     => $product[ 'short' . $suffix ],
					Fields::LONG      => $product[ 'long' . $suffix ],
					Fields::CATEGORY  => $product[ 'category_names' . $suffix ],
					Fields::TAG       => $product['tags'],
					Fields::BRAND     => $product['brand'],
					Fields::ATTRIBUTE => array_map( 'strval', array_keys( $attrs ) ),
				);
				$tokenizer = $this->analyzer->tokenizer();
				$refs      = array_map( static fn( string $slug ): string => 'category:' . $slug, $product['categories'] );
				$refs[]    = 'brand:' . $product['brand'];
				foreach ( $product['tags'] as $tag ) {
					$refs[] = 'tag:' . $tag;
				}
				$index->add(
					new Document(
						++$id,
						Document::PRODUCT,
						$product['id'],
						$lang,
						$product[ 'name' . $suffix ],
						$tokenizer->key( $product[ 'name' . $suffix ], $lang ),
						array_map( static fn( string $sku ): string => $tokenizer->key( $sku, $lang ), $skus ),
						(bool) $product['in_stock'],
						(bool) $product['visible'],
						0,
						(int) $product['total_sales'],
						(int) $product['id'],
						$refs
					),
					$this->analyzer->analyze( $fields, $lang )
				);
			}
		}
		$term = 0;
		foreach ( Catalogue::CATEGORIES as $slug => $names ) {
			++$term;
			foreach ( self::LANGS as $lang ) {
				$name = 'en' === $lang ? $names[0] : $names[1];
				$index->add( $this->termDocument( ++$id, Document::CATEGORY, $term, $lang, $name, $counts['category'][ $slug ] ?? 0 ), $this->analyzer->analyze( array( Fields::NAME => $name ), $lang ) );
			}
		}
		foreach ( $tags as $tag => $count ) {
			++$term;
			foreach ( self::LANGS as $lang ) {
				$index->add( $this->termDocument( ++$id, Document::TAG, $term, $lang, (string) $tag, $count ), $this->analyzer->analyze( array( Fields::NAME => (string) $tag ), $lang ) );
			}
		}
		foreach ( $brands as $brand => $count ) {
			++$term;
			foreach ( self::LANGS as $lang ) {
				$index->add( $this->termDocument( ++$id, Document::BRAND, $term, $lang, (string) $brand, $count ), $this->analyzer->analyze( array( Fields::NAME => (string) $brand ), $lang ) );
			}
		}
		return $index;
	}

	/**
	 * A category, tag or brand document; its product count rides in `totalSales` as the tie-break.
	 *
	 * @param int    $id       Document id.
	 * @param string $type     Document type.
	 * @param int    $objectId Term id.
	 * @param string $lang     Language code.
	 * @param string $name     Name.
	 * @param int    $count    How many products carry it.
	 */
	private function termDocument( int $id, string $type, int $objectId, string $lang, string $name, int $count ): Document {
		return new Document( $id, $type, $objectId, $lang, $name, $this->analyzer->tokenizer()->key( $name, $lang ), array(), true, true, 0, $count );
	}
}
