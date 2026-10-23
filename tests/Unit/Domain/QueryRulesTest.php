<?php
/**
 * QueryRules tests.
 *
 * @package CreativeBranding\Search\Tests
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Tests\Unit\Domain;

use CreativeBranding\Search\Domain\QueryRules;
use CreativeBranding\Search\Domain\Tokenizer;
use PHPUnit\Framework\TestCase;

/**
 * A rule answers a query whose words are its words as the index reads them, in its language or in every one
 * (D-108, D-110).
 */
final class QueryRulesTest extends TestCase {

	private const RULES = array(
		3 => array(
			'query' => 'size guide',
			'lang'  => '',
		),
		7 => array(
			'query' => 'Grip Tape',
			'lang'  => 'en_CA',
		),
		9 => array(
			'query' => 'grip tape',
			'lang'  => '',
		),
		11 => array(
			'query' => 'the',
			'lang'  => '',
		),
		12 => array(
			'query' => 'planche 8,25 po',
			'lang'  => 'fr',
		),
	);

	public function test_the_words_as_the_index_reads_them(): void {
		$rules = new QueryRules();
		self::assertSame( 3, $rules->match( self::RULES, 'size guide', 'en' ) );
		self::assertSame( 3, $rules->match( self::RULES, '  SIZE   Guide! ', 'en' ), 'case, blanks and punctuation' );
		self::assertSame( 3, $rules->match( self::RULES, 'the size guide', 'en' ), 'stop words' );
		self::assertSame( 3, $rules->match( self::RULES, 'sizé guidé', 'en' ), 'accents' );
		self::assertNull( $rules->match( self::RULES, 'guide size', 'en' ), 'the order of the words' );
		self::assertNull( $rules->match( self::RULES, 'size guides', 'en' ), 'no stemming: the owner names the query' );
		self::assertNull( $rules->match( self::RULES, 'size', 'en' ), 'all the words' );
		self::assertSame( 12, $rules->match( self::RULES, 'Planche 8.25″', 'fr_CA' ), 'a size however it is written, in the rule’s language' );
	}

	public function test_a_rule_with_a_language_answers_that_language(): void {
		$rules = new QueryRules();
		self::assertSame( 7, $rules->match( self::RULES, 'grip-tape', 'en-US' ), 'the first rule that answers' );
		self::assertSame( 9, $rules->match( self::RULES, 'grip tape', 'fr' ), 'a rule without a language answers every one' );
		self::assertNull( $rules->match( self::RULES, 'planche 8.25', 'en' ) );
	}

	public function test_a_query_or_rule_without_words_never_matches(): void {
		$rules = new QueryRules( new Tokenizer() );
		self::assertNull( $rules->match( self::RULES, 'the', 'en' ), 'only stop words' );
		self::assertNull( $rules->match( self::RULES, '!!!', 'en' ) );
		self::assertNull( $rules->match( array(), 'size guide', 'en' ) );
		self::assertSame( '', $rules->key( 'the a', 'en' ) );
		self::assertSame( 'size guide', $rules->key( 'The Size-Guide', 'en' ) );
	}

	public function test_rule_keys_are_worked_out_once_per_language(): void {
		$rules = new QueryRules();
		self::assertSame(
			array(
				3  => 'size guide',
				7  => 'grip tape',
				9  => 'grip tape',
				11 => '',
			),
			$rules->keys( self::RULES, 'en-CA' ),
			'by the rule\'s place; another language\'s rule left out; read with the query\'s stop words'
		);
		self::assertSame( array( 3, 9, 11, 12 ), array_keys( $rules->keys( self::RULES, 'fr_CA' ) ) );
		self::assertSame( 7, QueryRules::find( $rules->keys( self::RULES, 'en' ), 'grip tape' ), 'the first' );
		self::assertNull( QueryRules::find( array( 11 => '' ), '' ), 'an empty key matches nothing, not a rule of stop words' );
		self::assertNull( QueryRules::find( array( 3 => 'size guide' ), 'size' ) );
		self::assertSame( 0, QueryRules::find( array( 0 => 'size guide' ), 'size guide' ), 'the first place is a place' );
	}
}
