<?php
/**
 * The owner's synonyms.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Admin;

use CreativeBranding\Search\Domain\Synonym;
use CreativeBranding\Search\Domain\Synonyms;
use CreativeBranding\Search\Domain\Token;
use CreativeBranding\Search\Index\AnalyzerFactory;
use CreativeBranding\Search\Index\Languages;
use CreativeBranding\Search\Repository\SynonymRepository;
use CreativeBranding\Search\Search\Cache;
use CreativeBranding\Search\Support\PluginException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds, changes, deletes, imports, exports and tests synonyms (spec: Admin → Synonyms). They are read at query
 * time, so a change applies at once: the answer cache is dropped (`Cache::bump()`), no reindex.
 */
final class SynonymService {

	public const HEADER = array( 'lang', 'word', 'means', 'both_ways' );

	/**
	 * Constructor.
	 *
	 * @param SynonymRepository $repository The table.
	 * @param Languages         $languages  The indexed languages.
	 * @param AnalyzerFactory   $analyzers  The tokenizer, for the test box.
	 */
	public function __construct( private SynonymRepository $repository, private Languages $languages, private AnalyzerFactory $analyzers ) {
	}

	/**
	 * A synonym from the owner's input, checked.
	 *
	 * @param string $lang     Language code.
	 * @param string $word     The word a shopper types.
	 * @param string $means    What it means.
	 * @param bool   $bothWays Whether it also works backwards.
	 * @throws PluginException With a message for the owner when the input is not a synonym.
	 */
	public function make( string $lang, string $word, string $means, bool $bothWays ): Synonym {
		$lang  = sanitize_key( $lang );
		$word  = mb_strtolower( trim( sanitize_text_field( $word ) ), 'UTF-8' );
		$means = mb_strtolower( trim( sanitize_text_field( $means ) ), 'UTF-8' );
		if ( ! in_array( $lang, $this->languages->codes(), true ) ) {
			throw new PluginException( esc_html__( 'Choose one of the languages the shop indexes.', 'creative-search' ) );
		}
		if ( '' === $word || '' === $means ) {
			throw new PluginException( esc_html__( 'A synonym needs a word and what it means.', 'creative-search' ) );
		}
		if ( $word === $means ) {
			throw new PluginException( esc_html__( 'A word cannot be its own synonym.', 'creative-search' ) );
		}
		if ( mb_strlen( $word, 'UTF-8' ) > 64 || mb_strlen( $means, 'UTF-8' ) > 64 ) {
			throw new PluginException( esc_html__( 'A word and its meaning are 64 characters at most.', 'creative-search' ) );
		}
		return new Synonym( $lang, $word, $means, $bothWays );
	}

	/**
	 * Add one.
	 *
	 * @param Synonym $synonym The synonym.
	 * @param int     $userId  Who added it.
	 * @return int The new id, or 0 when it already existed.
	 */
	public function add( Synonym $synonym, int $userId ): int {
		if ( $this->repository->exists( $synonym ) ) {
			return 0;
		}
		$id = $this->repository->add( $synonym, $userId );
		Cache::bump();
		return $id;
	}

	/**
	 * Change one.
	 *
	 * @param int     $id      Row id.
	 * @param Synonym $synonym New values.
	 */
	public function update( int $id, Synonym $synonym ): bool {
		$changed = $this->repository->update( $id, $synonym );
		Cache::bump();
		return $changed;
	}

	/**
	 * Delete some.
	 *
	 * @param int[] $ids Row ids.
	 * @return int Rows deleted.
	 */
	public function delete( array $ids ): int {
		$deleted = $this->repository->delete( $ids );
		Cache::bump();
		return $deleted;
	}

	/**
	 * Import CSV text with the export's columns; rows that are not synonyms are counted and skipped.
	 *
	 * @param string $csv    The text.
	 * @param int    $userId Who imports.
	 * @return array{added: int, existing: int, skipped: int}
	 */
	public function import( string $csv, int $userId ): array {
		$out = array(
			'added'    => 0,
			'existing' => 0,
			'skipped'  => 0,
		);
		foreach ( Csv::parse( $csv ) as $row ) {
			try {
				$synonym = $this->make( $row['lang'] ?? '', $row['word'] ?? '', $row['means'] ?? '', in_array( strtolower( $row['both_ways'] ?? '' ), array( '1', 'yes', 'true' ), true ) );
			} catch ( PluginException $e ) {
				++$out['skipped'];
				continue;
			}
			if ( $this->repository->exists( $synonym ) ) {
				++$out['existing'];
				continue;
			}
			$this->repository->add( $synonym, $userId );
			++$out['added'];
		}
		Cache::bump();
		return $out;
	}

	/**
	 * Every synonym as CSV.
	 */
	public function export(): string {
		$rows = array_map(
			static fn( array $row ): array => array( $row['lang'], $row['word'], $row['means'], '1' === $row['both_ways'] ? 'yes' : 'no' ),
			$this->repository->all()
		);
		return Csv::build( self::HEADER, $rows );
	}

	/**
	 * What a word or phrase also searches for in a language (the test box): each word's one-word synonyms, then the
	 * meaning of each phrase synonym whose words it holds side by side (D-109).
	 *
	 * @param string $word A word or a few.
	 * @param string $lang Language code.
	 * @return string[]
	 */
	public function expand( string $word, string $lang ): array {
		$tokenizer = $this->analyzers->tokenizer();
		$synonyms  = new Synonyms( $this->repository->forLanguages( array( $lang ) ), $tokenizer );
		$out       = array();
		$texts     = array();
		foreach ( $tokenizer->tokenize( $word, $lang, false ) as $token ) {
			$texts[ $token->position ] ??= $token->text;
			foreach ( $synonyms->alternatives( $token->text, $lang ) as $alternative ) {
				$out[] = $alternative;
			}
		}
		foreach ( $synonyms->phrases( $lang ) as $rule ) {
			if ( null !== Synonyms::find( $texts, $rule['from'] ) ) {
				$out[] = implode( ' ', array_map( static fn( Token $t ): string => $t->text, $rule['to'] ) );
			}
		}
		return array_values( array_unique( $out ) );
	}
}
