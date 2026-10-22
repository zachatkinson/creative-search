<?php
/**
 * Stop words per language.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Words too common to carry meaning, dropped from queries and kept out of the index (spec: Vocabulary). English
 * and French are built in (Snowball's lists, folded the way tokens are); the owner's additions are merged per
 * language through {@see StopWords::with()}; the `csearch_stop_words` filter runs over the result in milestone 3.
 */
final class StopWords {

	/**
	 * Snowball's English list, contractions already folded (the tokenizer folds `don't` to `dont`).
	 *
	 * @var string[]
	 */
	private const ENGLISH = array(
		'i',
		'me',
		'my',
		'myself',
		'we',
		'our',
		'ours',
		'ourselves',
		'you',
		'your',
		'yours',
		'yourself',
		'yourselves',
		'he',
		'him',
		'his',
		'himself',
		'she',
		'her',
		'hers',
		'herself',
		'it',
		'its',
		'itself',
		'they',
		'them',
		'their',
		'theirs',
		'themselves',
		'what',
		'which',
		'who',
		'whom',
		'this',
		'that',
		'these',
		'those',
		'am',
		'is',
		'are',
		'was',
		'were',
		'be',
		'been',
		'being',
		'have',
		'has',
		'had',
		'having',
		'do',
		'does',
		'did',
		'doing',
		'would',
		'should',
		'could',
		'ought',
		'im',
		'youre',
		'hes',
		'shes',
		'were',
		'theyre',
		'ive',
		'youve',
		'weve',
		'theyve',
		'id',
		'youd',
		'hed',
		'shed',
		'wed',
		'theyd',
		'ill',
		'youll',
		'hell',
		'shell',
		'well',
		'theyll',
		'isnt',
		'arent',
		'wasnt',
		'werent',
		'hasnt',
		'havent',
		'hadnt',
		'doesnt',
		'dont',
		'didnt',
		'wont',
		'wouldnt',
		'shant',
		'shouldnt',
		'cant',
		'cannot',
		'couldnt',
		'mustnt',
		'lets',
		'thats',
		'whos',
		'whats',
		'heres',
		'theres',
		'whens',
		'wheres',
		'whys',
		'hows',
		'a',
		'an',
		'the',
		'and',
		'but',
		'if',
		'or',
		'because',
		'as',
		'until',
		'while',
		'of',
		'at',
		'by',
		'for',
		'with',
		'about',
		'against',
		'between',
		'into',
		'through',
		'during',
		'before',
		'after',
		'above',
		'below',
		'to',
		'from',
		'up',
		'down',
		'in',
		'out',
		'on',
		'off',
		'over',
		'under',
		'again',
		'further',
		'then',
		'once',
		'here',
		'there',
		'when',
		'where',
		'why',
		'how',
		'all',
		'any',
		'both',
		'each',
		'few',
		'more',
		'most',
		'other',
		'some',
		'such',
		'no',
		'nor',
		'not',
		'only',
		'own',
		'same',
		'so',
		'than',
		'too',
		'very',
	);

	/**
	 * Snowball's French list, folded (`à` is `a`, `même` is `meme`). One-letter elisions (`c`, `d`, `l`…) are dropped
	 * by the one-character rule before this list is consulted.
	 *
	 * @var string[]
	 */
	private const FRENCH = array(
		'au',
		'aux',
		'avec',
		'ce',
		'ces',
		'dans',
		'de',
		'des',
		'du',
		'elle',
		'en',
		'et',
		'eux',
		'il',
		'je',
		'la',
		'le',
		'leur',
		'lui',
		'ma',
		'mais',
		'me',
		'meme',
		'mes',
		'moi',
		'mon',
		'ne',
		'nos',
		'notre',
		'nous',
		'on',
		'ou',
		'par',
		'pas',
		'pour',
		'qu',
		'que',
		'qui',
		'sa',
		'se',
		'ses',
		'sur',
		'ta',
		'te',
		'tes',
		'toi',
		'ton',
		'tu',
		'un',
		'une',
		'vos',
		'votre',
		'vous',
		'c',
		'd',
		'j',
		'l',
		'a',
		'm',
		'n',
		's',
		't',
		'y',
		'etee',
		'etees',
		'etant',
		'suis',
		'es',
		'etes',
		'sont',
		'serai',
		'seras',
		'sera',
		'serons',
		'serez',
		'seront',
		'serais',
		'serait',
		'serions',
		'seriez',
		'seraient',
		'etais',
		'etait',
		'etions',
		'etiez',
		'etaient',
		'fus',
		'fut',
		'fumes',
		'futes',
		'furent',
		'sois',
		'soit',
		'soyons',
		'soyez',
		'soient',
		'fusse',
		'fusses',
		'fussions',
		'fussiez',
		'fussent',
		'ayant',
		'eu',
		'eue',
		'eues',
		'eus',
		'ai',
		'avons',
		'avez',
		'ont',
		'aurai',
		'aurons',
		'aurez',
		'auront',
		'aurais',
		'aurait',
		'aurions',
		'auriez',
		'auraient',
		'avais',
		'avait',
		'aviez',
		'avaient',
		'eut',
		'eumes',
		'eutes',
		'eurent',
		'aie',
		'aies',
		'ait',
		'ayons',
		'ayez',
		'aient',
		'eusse',
		'eusses',
		'eussions',
		'eussiez',
		'eussent',
		'ceci',
		'cela',
		'cet',
		'cette',
		'ici',
		'ils',
		'les',
		'leurs',
		'quel',
		'quels',
		'quelle',
		'quelles',
		'sans',
		'soi',
	);

	/**
	 * The built-in lists by language.
	 *
	 * @var array<string, string[]>
	 */
	private const BUILT_IN = array(
		'en' => self::ENGLISH,
		'fr' => self::FRENCH,
	);

	/**
	 * Lists by language code, each a set (word => true).
	 *
	 * @var array<string, array<string, int>>
	 */
	private array $lists = array();

	/**
	 * Constructor.
	 *
	 * @param array<string, string[]>|null $lists Lists by language; null for the built-in English and French.
	 */
	public function __construct( ?array $lists = null ) {
		foreach ( $lists ?? self::BUILT_IN as $lang => $words ) {
			$this->lists[ self::code( $lang ) ] = self::set( $words );
		}
	}

	/**
	 * A copy with the owner's additions for one language merged in.
	 *
	 * @param string   $lang  Language code.
	 * @param string[] $words Extra stop words, folded here.
	 */
	public function with( string $lang, array $words ): self {
		$copy                 = clone $this;
		$code                 = self::code( $lang );
		$copy->lists[ $code ] = ( $copy->lists[ $code ] ?? array() ) + self::set( $words );
		return $copy;
	}

	/**
	 * Whether a folded token is a stop word in a language. An unknown language has no stop words.
	 *
	 * @param string $token Folded token.
	 * @param string $lang  Language code.
	 */
	public function isStopWord( string $token, string $lang ): bool {
		return isset( $this->lists[ self::code( $lang ) ][ $token ] );
	}

	/**
	 * The list for a language, sorted.
	 *
	 * @param string $lang Language code.
	 * @return string[]
	 */
	public function listFor( string $lang ): array {
		$words = array_keys( $this->lists[ self::code( $lang ) ] ?? array() );
		sort( $words );
		return $words;
	}

	/**
	 * The languages with a list.
	 *
	 * @return string[]
	 */
	public function languages(): array {
		return array_keys( $this->lists );
	}

	/**
	 * `fr_CA` and `fr-ca` are `fr`.
	 *
	 * @param string $lang Language code.
	 */
	public static function code( string $lang ): string {
		$lower = strtolower( trim( $lang ) );
		$short = preg_replace( '/[_-].*$/', '', $lower ) ?? $lower;
		return '' === $short ? $lower : $short;
	}

	/**
	 * Words to a folded set; apostrophes join (`don't` is `dont`), the way the tokenizer joins them.
	 *
	 * @param string[] $words Words.
	 * @return array<string, int>
	 */
	private static function set( array $words ): array {
		$folded = array();
		foreach ( $words as $word ) {
			$folded[] = str_replace( array( '’', "'" ), '', Folding::fold( $word ) );
		}
		return array_flip( array_filter( $folded, static fn( string $w ): bool => '' !== $w ) );
	}
}
