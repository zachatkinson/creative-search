<?php
/**
 * The Porter stemmer.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain\Stemmer;

defined( 'ABSPATH' ) || exit;

/**
 * Martin Porter's 1980 algorithm as Snowball defines it (algorithms/porter.sbl), written out in PHP with no
 * extension, checked against Snowball's test vectors (tests/Fixtures/snowball/porter). The word is treated as
 * bytes: every rule reads and writes ASCII letters only, so a multibyte character is never split, it only
 * counts as a consonant.
 */
final class PorterStemmer implements Stemmer {

	private const VOWELS = 'aeiouy';

	/**
	 * The word being stemmed.
	 *
	 * @var string
	 */
	private string $w = '';

	/**
	 * The start of region R1: after the first non-vowel that follows a vowel.
	 *
	 * @var int
	 */
	private int $p1 = 0;

	/**
	 * The start of region R2: the same again, from R1.
	 *
	 * @var int
	 */
	private int $p2 = 0;

	/**
	 * The stem of a lower-cased word.
	 *
	 * @param string $word A lower-cased word.
	 */
	public function stem( string $word ): string {
		$this->w = $word;
		$this->markY();
		$this->markRegions();
		$this->step1a();
		$this->step1b();
		$this->step1c();
		$this->step2();
		$this->step3();
		$this->step4();
		$this->step5a();
		$this->step5b();
		return str_replace( 'Y', 'y', $this->w );
	}

	/**
	 * An initial y, or a y after a vowel, is marked Y so it counts as a consonant.
	 */
	private function markY(): void {
		$n = strlen( $this->w );
		for ( $i = 0; $i < $n; $i++ ) {
			if ( 'y' === $this->w[ $i ] && ( 0 === $i || $this->isVowel( $i - 1 ) ) ) {
				$this->w[ $i ] = 'Y';
			}
		}
	}

	/**
	 * Find p1 and p2 ("go past a vowel, then past a non-vowel", twice).
	 */
	private function markRegions(): void {
		$this->p1 = $this->pastVowelThenNonVowel( 0 );
		$this->p2 = $this->pastVowelThenNonVowel( $this->p1 );
	}

	/**
	 * From a position, the position after the first non-vowel that follows a vowel; the end of the word when
	 * there is none, so the region is empty.
	 *
	 * @param int $from Start position.
	 */
	private function pastVowelThenNonVowel( int $from ): int {
		$n = strlen( $this->w );
		$i = $from;
		while ( $i < $n && ! $this->isVowel( $i ) ) {
			++$i;
		}
		while ( $i < $n && $this->isVowel( $i ) ) {
			++$i;
		}
		++$i; // Past the non-vowel.
		return min( $i, $n );
	}

	/**
	 * Whether the byte at a position is one of a, e, i, o, u, y (Y is a consonant).
	 *
	 * @param int $i Position.
	 */
	private function isVowel( int $i ): bool {
		return str_contains( self::VOWELS, $this->w[ $i ] );
	}

	/**
	 * Whether the suffix starts in R1.
	 *
	 * @param string $suffix The suffix.
	 */
	private function r1( string $suffix ): bool {
		return strlen( $this->w ) - strlen( $suffix ) >= $this->p1;
	}

	/**
	 * Whether the suffix starts in R2.
	 *
	 * @param string $suffix The suffix.
	 */
	private function r2( string $suffix ): bool {
		return strlen( $this->w ) - strlen( $suffix ) >= $this->p2;
	}

	/**
	 * Whether the word up to a position ends in consonant-vowel-consonant where the last is not w, x or Y.
	 *
	 * @param int $end Position just after the last character considered.
	 */
	private function endsShortSyllable( int $end ): bool {
		return $end >= 3
			&& ! $this->isVowel( $end - 1 ) && ! str_contains( 'wxY', $this->w[ $end - 1 ] )
			&& $this->isVowel( $end - 2 )
			&& ! $this->isVowel( $end - 3 );
	}

	/**
	 * The longest of the suffixes the word ends with, or null.
	 *
	 * @param string[] $suffixes Suffixes, any order.
	 */
	private function longestSuffix( array $suffixes ): ?string {
		$best = null;
		foreach ( $suffixes as $suffix ) {
			if ( str_ends_with( $this->w, $suffix ) && ( null === $best || strlen( $suffix ) > strlen( $best ) ) ) {
				$best = $suffix;
			}
		}
		return $best;
	}

	/**
	 * Replace the suffix the word ends with.
	 *
	 * @param string $suffix      The suffix (the word ends with it).
	 * @param string $replacement What replaces it.
	 */
	private function replaceSuffix( string $suffix, string $replacement ): void {
		$this->w = substr( $this->w, 0, strlen( $this->w ) - strlen( $suffix ) ) . $replacement;
	}

	/**
	 * Step 1a: plurals.
	 */
	private function step1a(): void {
		$suffix = $this->longestSuffix( array( 'sses', 'ies', 'ss', 's' ) );
		switch ( $suffix ) {
			case 'sses':
				$this->replaceSuffix( 'sses', 'ss' );
				break;
			case 'ies':
				$this->replaceSuffix( 'ies', 'i' );
				break;
			case 's':
				$this->replaceSuffix( 's', '' );
				break;
		}
	}

	/**
	 * Step 1b: -eed, -ed, -ing.
	 */
	private function step1b(): void {
		$suffix = $this->longestSuffix( array( 'eed', 'ed', 'ing' ) );
		if ( null === $suffix ) {
			return;
		}
		if ( 'eed' === $suffix ) {
			if ( $this->r1( 'eed' ) ) {
				$this->replaceSuffix( 'eed', 'ee' );
			}
			return;
		}
		$stemEnd = strlen( $this->w ) - strlen( $suffix );
		if ( ! $this->hasVowelBefore( $stemEnd ) ) {
			return;
		}
		$this->replaceSuffix( $suffix, '' );
		$tail = $this->longestSuffix( array( 'at', 'bl', 'iz', 'bb', 'dd', 'ff', 'gg', 'mm', 'nn', 'pp', 'rr', 'tt' ) );
		if ( null === $tail ) {
			if ( strlen( $this->w ) === $this->p1 && $this->endsShortSyllable( strlen( $this->w ) ) ) {
				$this->w .= 'e';
			}
			return;
		}
		if ( in_array( $tail, array( 'at', 'bl', 'iz' ), true ) ) {
			$this->w .= 'e';
			return;
		}
		$this->w = substr( $this->w, 0, -1 );
	}

	/**
	 * Whether a vowel occurs before a position.
	 *
	 * @param int $end Position.
	 */
	private function hasVowelBefore( int $end ): bool {
		for ( $i = 0; $i < $end; $i++ ) {
			if ( $this->isVowel( $i ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Step 1c: a final y or Y after a vowel becomes i.
	 */
	private function step1c(): void {
		$n = strlen( $this->w );
		if ( $n > 0 && ( 'y' === $this->w[ $n - 1 ] || 'Y' === $this->w[ $n - 1 ] ) && $this->hasVowelBefore( $n - 1 ) ) {
			$this->w[ $n - 1 ] = 'i';
		}
	}

	/**
	 * A step of the form "longest suffix among the keys, in a region, replaced by the value".
	 *
	 * @param array<string, string> $map    Suffix to replacement.
	 * @param bool                  $inR2   True to require R2, false R1.
	 */
	private function replaceInRegion( array $map, bool $inR2 ): void {
		$suffix = $this->longestSuffix( array_keys( $map ) );
		if ( null === $suffix ) {
			return;
		}
		if ( $inR2 ? $this->r2( $suffix ) : $this->r1( $suffix ) ) {
			$this->replaceSuffix( $suffix, $map[ $suffix ] );
		}
	}

	/**
	 * Step 2.
	 */
	private function step2(): void {
		$this->replaceInRegion(
			array(
				'tional'  => 'tion',
				'enci'    => 'ence',
				'anci'    => 'ance',
				'abli'    => 'able',
				'entli'   => 'ent',
				'eli'     => 'e',
				'izer'    => 'ize',
				'ization' => 'ize',
				'ational' => 'ate',
				'ation'   => 'ate',
				'ator'    => 'ate',
				'alli'    => 'al',
				'alism'   => 'al',
				'aliti'   => 'al',
				'fulness' => 'ful',
				'ousli'   => 'ous',
				'ousness' => 'ous',
				'iveness' => 'ive',
				'iviti'   => 'ive',
				'biliti'  => 'ble',
			),
			false
		);
	}

	/**
	 * Step 3.
	 */
	private function step3(): void {
		$this->replaceInRegion(
			array(
				'alize' => 'al',
				'icate' => 'ic',
				'iciti' => 'ic',
				'ical'  => 'ic',
				'ative' => '',
				'ful'   => '',
				'ness'  => '',
			),
			false
		);
	}

	/**
	 * Step 4.
	 */
	private function step4(): void {
		$suffix = $this->longestSuffix(
			array( 'al', 'ance', 'ence', 'er', 'ic', 'able', 'ible', 'ant', 'ement', 'ment', 'ent', 'ion', 'ou', 'ism', 'ate', 'iti', 'ous', 'ive', 'ize' )
		);
		if ( null === $suffix || ! $this->r2( $suffix ) ) {
			return;
		}
		if ( 'ion' === $suffix && ! str_contains( 'st', $this->w[ strlen( $this->w ) - 4 ] ) ) {
			// R2 holds a vowel-consonant pair before the suffix, so the word is long enough to look back.
			return;
		}
		$this->replaceSuffix( $suffix, '' );
	}

	/**
	 * Step 5a: a final e goes in R2, or in R1 when the rest does not end in a short syllable.
	 */
	private function step5a(): void {
		if ( ! str_ends_with( $this->w, 'e' ) ) {
			return;
		}
		$end = strlen( $this->w ) - 1;
		if ( $end >= $this->p2 || ( $end >= $this->p1 && ! $this->endsShortSyllable( $end ) ) ) {
			$this->w = substr( $this->w, 0, $end );
		}
	}

	/**
	 * Step 5b: a double l in R2 loses one.
	 */
	private function step5b(): void {
		if ( str_ends_with( $this->w, 'll' ) && strlen( $this->w ) - 1 >= $this->p2 ) {
			$this->w = substr( $this->w, 0, -1 );
		}
	}
}
