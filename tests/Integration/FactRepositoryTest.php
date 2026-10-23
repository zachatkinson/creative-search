<?php
/**
 * The facts table's writes and reads, against the rows themselves.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Integration;

use CreativeBranding\Search\Domain\Fact;
use CreativeBranding\Search\Repository\FactRepository;
use CreativeBranding\Search\Support\Tables;
use CreativeBranding\Search\Tests\Support\IndexTestCase;

/**
 * `FactRepository` (D-104) writes a document's facts in order, cut to the columns' 191 characters (not bytes),
 * with no image as NULL, across more than one insert statement, and reads and deletes them by document only. The
 * search tests read facts through the panel and the cards; this holds the repository itself for the SQL mutation
 * run (D-088).
 *
 * @group sql-mutation
 */
final class FactRepositoryTest extends IndexTestCase {

	private const DOCUMENT = 990001;
	private const OTHER    = 990002;

	public function test_facts_are_written_in_order_and_read_back_whole(): void {
		$facts = $this->service( FactRepository::class );
		$long  = str_repeat( 'é', 200 );
		$facts->write(
			self::DOCUMENT,
			array(
				5 => self::fact( 11, 'Design', 'Fire', 'MUM-FUR-FIRE', 7, "fire\tmum" ),
				9 => self::fact( 12, $long, 'z' . $long, 'y' . $long, null, "ice\t\tcold\t" ),
			)
		);
		$facts->write( self::OTHER, array( self::fact( 0, 'Width', '8.25', '8.25″', null, '825' ) ) );

		$read = $facts->of( array( self::DOCUMENT, self::OTHER, 990003 ) );
		self::assertSame( array( self::DOCUMENT, self::OTHER ), array_keys( $read ) );
		self::assertCount( 2, $read[ self::DOCUMENT ] );
		$first = $read[ self::DOCUMENT ][0];
		self::assertSame( array( 11, Fact::class ), array( $first->variationId, $first::class ) );
		self::assertSame( array( 'Design', 'Design', 'Fire', 'MUM-FUR-FIRE', 7, array( 'fire', 'mum' ) ), array( $first->attribute, $first->name, $first->value, $first->label, $first->imageId, $first->terms ) );
		$second = $read[ self::DOCUMENT ][1];
		// Cut to 191 characters, from the first: a byte cut would split "é" and leave half of it.
		self::assertSame( str_repeat( 'é', 191 ), $second->attribute );
		self::assertSame( str_repeat( 'é', 191 ), $second->name );
		self::assertSame( 'z' . str_repeat( 'é', 190 ), $second->value );
		self::assertSame( 'y' . str_repeat( 'é', 190 ), $second->label );
		self::assertNull( $second->imageId, 'no image is NULL, read back as null' );
		self::assertSame( array( 'ice', 'cold' ), $second->terms, 'empty pieces dropped, the list renumbered' );
		self::assertSame( array(), $facts->of( array() ) );

		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT position, image_id FROM %i WHERE document_id = %d ORDER BY position', Tables::facts(), self::DOCUMENT ), ARRAY_A ); // phpcs:ignore
		self::assertSame( array( '0', '1' ), array_column( $rows, 'position' ), 'positions from 0, whatever the keys' );
		self::assertSame( array( '7', null ), array_column( $rows, 'image_id' ) );

		$facts->deleteFor( array() );
		self::assertCount( 2, $facts->of( array( self::DOCUMENT, self::OTHER ) ), 'an empty delete deletes nothing' );
		$facts->deleteFor( array( self::DOCUMENT ) );
		self::assertSame( array( self::OTHER ), array_keys( $facts->of( array( self::DOCUMENT, self::OTHER ) ) ), 'only the document named' );
	}

	public function test_more_facts_than_one_statement_holds(): void {
		$facts = $this->service( FactRepository::class );
		$many  = array();
		for ( $i = 0; $i < FactRepository::CHUNK + 3; $i++ ) {
			$many[] = self::fact( $i, 'Size', (string) $i, 'S' . $i, $i % 2 ? $i : null, 'size' . $i );
		}
		$facts->write( self::DOCUMENT, $many );
		$read = $facts->of( array( self::DOCUMENT ) )[ self::DOCUMENT ];
		self::assertCount( FactRepository::CHUNK + 3, $read );
		self::assertSame( array_column( $many, 'value' ), array_map( static fn( Fact $f ): string => $f->value, $read ), 'every fact, in order' );
		self::assertSame( 'S' . ( FactRepository::CHUNK + 2 ), $read[ FactRepository::CHUNK + 2 ]->label );
		self::assertSame( FactRepository::CHUNK + 1, $read[ FactRepository::CHUNK + 1 ]->imageId );
	}

	/**
	 * One fact row as the indexer hands it over.
	 *
	 * @param int      $variation Variation id, 0 for the product.
	 * @param string   $attribute Attribute name.
	 * @param string   $value     Value.
	 * @param string   $label     Label.
	 * @param int|null $image     Image id.
	 * @param string   $terms     Terms, tab-separated.
	 * @return array{variation: int, field: int, attribute: string, name: string, value: string, label: string, image_id: int|null, terms: string}
	 */
	private static function fact( int $variation, string $attribute, string $value, string $label, ?int $image, string $terms ): array {
		return array(
			'variation' => $variation,
			'field'     => 3,
			'attribute' => $attribute,
			'name'      => $attribute,
			'value'     => $value,
			'label'     => $label,
			'image_id'  => $image,
			'terms'     => $terms,
		);
	}
}
