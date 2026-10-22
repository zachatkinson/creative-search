<?php
/**
 * Snowball's French stemmer.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain\Stemmer;

defined( 'ABSPATH' ) || exit;

/**
 * Snowball's French stemmer (algorithms/french.sbl, with the 2023 additions: elisions, -oux, the -ais
 * exceptions) written out in PHP with no extension and checked against Snowball's test vectors
 * (tests/Fixtures/snowball/french).
 *
 * The algorithm is defined over single characters, so the word is converted to ISO-8859-1 for the run and back
 * to UTF-8 at the end; a word with a character outside Latin-1 is not French and is returned unchanged. The
 * backward-mode routines keep a cursor measured from the left and a left limit, as Snowball's runtime does.
 */
final class FrenchStemmer implements Stemmer {

	/**
	 * The vowels, in ISO-8859-1: a e i o u y â à ë é ê è ï î ô û ù.
	 */
	private const VOWELS = "aeiouy\xE2\xE0\xEB\xE9\xEA\xE8\xEF\xEE\xF4\xFB\xF9";

	private const E_ACUTE   = "\xE9";
	private const E_GRAVE   = "\xE8";
	private const E_DIAER   = "\xEB";
	private const I_DIAER   = "\xEF";
	private const C_CEDILLA = "\xE7";

	/**
	 * The -ir verb suffixes (step 2a).
	 */
	private const I_VERB_SUFFIXES = array(
		"\xEEmes",
		"\xEEt",
		"\xEEtes",
		'i',
		'ie',
		'ies',
		'ir',
		'ira',
		'irai',
		'iraIent',
		'irais',
		'irait',
		'iras',
		'irent',
		'irez',
		'iriez',
		'irions',
		'irons',
		'iront',
		'is',
		'issaIent',
		'issais',
		'issait',
		'issant',
		'issante',
		'issantes',
		'issants',
		'isse',
		'issent',
		'isses',
		'issez',
		'issiez',
		'issions',
		'issons',
		'it',
	);

	/**
	 * The other verb suffixes (step 2b).
	 */
	private const VERB_SUFFIXES = array(
		'ions',
		self::E_ACUTE,
		self::E_ACUTE . 'e',
		self::E_ACUTE . 'es',
		self::E_ACUTE . 's',
		self::E_GRAVE . 'rent',
		'er',
		'era',
		'erai',
		'eraIent',
		'erais',
		'erait',
		'eras',
		'erez',
		'eriez',
		'erions',
		'erons',
		'eront',
		'ez',
		'iez',
		"\xE2mes",
		"\xE2t",
		"\xE2tes",
		'a',
		'ai',
		'aIent',
		'ait',
		'ant',
		'ante',
		'antes',
		'ants',
		'as',
		'asse',
		'assent',
		'asses',
		'assiez',
		'assions',
		'ais',
		'aise',
		'aises',
		'eais',
	);

	/**
	 * The word in ISO-8859-1.
	 *
	 * @var string
	 */
	private string $w = '';

	/**
	 * The backward cursor: the index just after the character under consideration.
	 *
	 * @var int
	 */
	private int $c = 0;

	/**
	 * The left limit for backward matching: 0, or pV inside {@see within()}.
	 *
	 * @var int
	 */
	private int $lb = 0;

	/**
	 * Slice start: the suffix matched last lies in [bra, ket).
	 *
	 * @var int
	 */
	private int $bra = 0;

	/**
	 * Slice end.
	 *
	 * @var int
	 */
	private int $ket = 0;

	/**
	 * The start of region RV.
	 *
	 * @var int
	 */
	private int $pV = 0;

	/**
	 * The start of region R1.
	 *
	 * @var int
	 */
	private int $p1 = 0;

	/**
	 * The start of region R2.
	 *
	 * @var int
	 */
	private int $p2 = 0;

	/**
	 * The stem of a lower-cased word.
	 *
	 * @param string $word A lower-cased word in UTF-8.
	 */
	public function stem( string $word ): string {
		$latin = mb_convert_encoding( $word, 'ISO-8859-1', 'UTF-8' );
		if ( mb_convert_encoding( $latin, 'UTF-8', 'ISO-8859-1' ) !== $word ) {
			return $word;
		}
		$this->w = $latin;
		$this->elisions();
		$this->prelude();
		$this->markRegions();
		$this->c = strlen( $this->w );
		$this->mainSuffixes();
		$this->unDouble();
		$this->unAccent();
		$this->postlude();
		return mb_convert_encoding( $this->w, 'UTF-8', 'ISO-8859-1' );
	}

	/**
	 * An elision at the start of the word (`l'`, `qu'`) is removed when something follows it.
	 */
	private function elisions(): void {
		$m = array();
		if ( 1 === preg_match( "/^(?:qu|[cdjlmnstz])'(?=.)/", $this->w, $m ) ) {
			$this->w = substr( $this->w, strlen( $m[0] ) );
		}
	}

	/**
	 * Mark u, i after a vowel and before a vowel as U, I; y next to a vowel as Y; u after q as U; ë and ï as
	 * He and Hi, so they count as consonants.
	 */
	private function prelude(): void {
		// Snowball's `repeat goto`: after a rewrite the scan resumes at the same position, not after it.
		$i = 0;
		$n = strlen( $this->w );
		while ( $i < $n ) {
			$ch   = $this->w[ $i ];
			$next = $this->w[ $i + 1 ] ?? '';
			$then = $this->w[ $i + 2 ] ?? '';
			if ( $this->vowel( $ch ) && 'u' === $next && $this->vowel( $then ) ) {
				$this->w[ $i + 1 ] = 'U';
			} elseif ( $this->vowel( $ch ) && 'i' === $next && $this->vowel( $then ) ) {
				$this->w[ $i + 1 ] = 'I';
			} elseif ( $this->vowel( $ch ) && 'y' === $next ) {
				$this->w[ $i + 1 ] = 'Y';
			} elseif ( self::E_DIAER === $ch ) {
				$this->w = substr_replace( $this->w, 'He', $i, 1 );
				$n       = strlen( $this->w );
			} elseif ( self::I_DIAER === $ch ) {
				$this->w = substr_replace( $this->w, 'Hi', $i, 1 );
				$n       = strlen( $this->w );
			} elseif ( 'y' === $ch && $this->vowel( $next ) ) {
				$this->w[ $i ] = 'Y';
			} elseif ( 'q' === $ch && 'u' === $next ) {
				$this->w[ $i + 1 ] = 'U';
			} else {
				++$i;
			}
		}
	}

	/**
	 * Whether a character is a vowel (the marked U, I, Y and H are not).
	 *
	 * @param string $ch One byte, or an empty string past the end.
	 */
	private function vowel( string $ch ): bool {
		return '' !== $ch && str_contains( self::VOWELS, $ch );
	}

	/**
	 * Find pV, p1 and p2.
	 */
	private function markRegions(): void {
		$n        = strlen( $this->w );
		$this->pV = $n;
		$this->p1 = $n;
		$this->p2 = $n;
		if ( $n >= 3 && $this->vowel( $this->w[0] ) && $this->vowel( $this->w[1] ) ) {
			$this->pV = 3;
		} elseif ( in_array( substr( $this->w, 0, 3 ), array( 'par', 'col', 'tap' ), true ) ) {
			$this->pV = 3;
		} elseif ( 'ni' === substr( $this->w, 0, 2 ) && $this->vowel( $this->w[2] ?? '' ) ) {
			$this->pV = 3;
		} else {
			for ( $i = 1; $i < $n; $i++ ) {
				if ( $this->vowel( $this->w[ $i ] ) ) {
					$this->pV = $i + 1;
					break;
				}
			}
		}
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
		while ( $i < $n && ! $this->vowel( $this->w[ $i ] ) ) {
			++$i;
		}
		while ( $i < $n && $this->vowel( $this->w[ $i ] ) ) {
			++$i;
		}
		++$i; // Past the non-vowel.
		return min( $i, $n );
	}

	/*
	 * Backward-mode primitives. Positions saved across a failing alternative are measured from the end, as
	 * Snowball's runtime does, so a deletion made before the failure leaves the cursor at the new end.
	 */

	/**
	 * The cursor as a distance from the end.
	 */
	private function save(): int {
		return strlen( $this->w ) - $this->c;
	}

	/**
	 * Restore a cursor saved with {@see save()}.
	 *
	 * @param int $m Distance from the end.
	 */
	private function restore( int $m ): void {
		$this->c = strlen( $this->w ) - $m;
	}

	/**
	 * Run a routine with the left limit at a mark (Snowball's `setlimit tomark pV for`), then put the limit back.
	 *
	 * @template T
	 * @param int           $limit   The left limit.
	 * @param callable(): T $routine The routine.
	 * @return T
	 */
	private function within( int $limit, callable $routine ): mixed {
		$previous = $this->lb;
		$this->lb = $limit;
		$result   = $routine();
		$this->lb = $previous;
		return $result;
	}

	/**
	 * The longest of the suffixes ending at the cursor and starting at or after the limit; the slice markers are
	 * set around it and the cursor moves before it. Null when none matches.
	 *
	 * @phpstan-impure
	 *
	 * @param string[] $suffixes Suffixes in ISO-8859-1.
	 */
	private function among( array $suffixes ): ?string {
		$best = null;
		foreach ( $suffixes as $suffix ) {
			$len = strlen( $suffix );
			if ( $this->c - $len >= $this->lb && substr_compare( $this->w, $suffix, $this->c - $len, $len ) === 0
				&& ( null === $best || $len > strlen( $best ) ) ) {
				$best = $suffix;
			}
		}
		if ( null === $best ) {
			return null;
		}
		$this->ket = $this->c;
		$this->bra = $this->c - strlen( $best );
		$this->c   = $this->bra;
		return $best;
	}

	/**
	 * Match a literal before the cursor, moving the cursor before it.
	 *
	 * @phpstan-impure
	 *
	 * @param string $s The literal.
	 */
	private function eq( string $s ): bool {
		$start = $this->c - strlen( $s );
		if ( $start < $this->lb || substr_compare( $this->w, $s, $start, strlen( $s ) ) !== 0 ) {
			return false;
		}
		$this->c = $start;
		return true;
	}

	/**
	 * The character before the cursor is in a set: move before it.
	 *
	 * @phpstan-impure
	 *
	 * @param string $set Characters.
	 */
	private function in( string $set ): bool {
		$ch = $this->before();
		if ( '' === $ch || ! str_contains( $set, $ch ) ) {
			return false;
		}
		--$this->c;
		return true;
	}

	/**
	 * The character before the cursor is not in a set: move before it.
	 *
	 * @phpstan-impure
	 *
	 * @param string $set Characters.
	 */
	private function out( string $set ): bool {
		$ch = $this->before();
		if ( '' === $ch || str_contains( $set, $ch ) ) {
			return false;
		}
		--$this->c;
		return true;
	}

	/**
	 * The byte before the cursor, or an empty string at the left limit.
	 */
	private function before(): string {
		return $this->c > $this->lb ? $this->w[ $this->c - 1 ] : '';
	}

	/**
	 * Replace the marked slice.
	 *
	 * @param string $s Replacement, possibly empty.
	 */
	private function slice( string $s ): void {
		$this->w = substr_replace( $this->w, $s, $this->bra, $this->ket - $this->bra );
	}

	/**
	 * Whether the cursor is in RV.
	 */
	private function rv(): bool {
		return $this->pV <= $this->c;
	}

	/**
	 * Whether the cursor is in R1.
	 */
	private function r1(): bool {
		return $this->p1 <= $this->c;
	}

	/**
	 * Whether the cursor is in R2.
	 */
	private function r2(): bool {
		return $this->p2 <= $this->c;
	}

	/**
	 * `do ( ( (standard or i_verb or verb) and try(Y→i or ç→c) ) or residual )`.
	 */
	private function mainSuffixes(): void {
		$m     = $this->save();
		$found = $this->standardSuffix();
		if ( ! $found ) {
			$this->restore( $m );
			$found = $this->iVerbSuffix();
		}
		if ( ! $found ) {
			$this->restore( $m );
			$found = $this->verbSuffix();
		}
		if ( $found ) {
			$this->restore( $m );
			$this->ket = $this->c;
			if ( $this->eq( 'Y' ) ) {
				$this->bra = $this->c;
				$this->slice( 'i' );
			} elseif ( $this->eq( self::C_CEDILLA ) ) {
				$this->bra = $this->c;
				$this->slice( 'c' );
			}
			return;
		}
		$this->restore( $m );
		$this->residualSuffix();
	}

	/**
	 * The standard (non-verb) suffixes.
	 *
	 * @return bool Whether the routine succeeded (a failing routine may still have changed the word: the
	 *              -ment group does, on purpose, so the verb suffixes get a turn).
	 */
	private function standardSuffix(): bool {
		$suffix = $this->among(
			array(
				'ance',
				'iqUe',
				'isme',
				'able',
				'iste',
				'eux',
				'ances',
				'iqUes',
				'ismes',
				'ables',
				'istes',
				'atrice',
				'ateur',
				'ation',
				'atrices',
				'ateurs',
				'ations',
				'logie',
				'logies',
				'usion',
				'ution',
				'usions',
				'utions',
				'ence',
				'ences',
				'ement',
				'ements',
				'it' . self::E_ACUTE,
				'it' . self::E_ACUTE . 's',
				'if',
				'ive',
				'ifs',
				'ives',
				'eaux',
				'aux',
				'oux',
				'euse',
				'euses',
				'issement',
				'issements',
				'amment',
				'emment',
				'ment',
				'ments',
			)
		);
		switch ( $suffix ) {
			case null:
				return false;
			case 'ance':
			case 'iqUe':
			case 'isme':
			case 'able':
			case 'iste':
			case 'eux':
			case 'ances':
			case 'iqUes':
			case 'ismes':
			case 'ables':
			case 'istes':
				return $this->deleteIf( $this->r2() );
			case 'atrice':
			case 'ateur':
			case 'ation':
			case 'atrices':
			case 'ateurs':
			case 'ations':
				if ( ! $this->r2() ) {
					return false;
				}
				$this->slice( '' );
				$this->ket = $this->c;
				if ( $this->eq( 'ic' ) ) {
					$this->bra = $this->c;
					$this->slice( $this->r2() ? '' : 'iqU' );
				}
				return true;
			case 'logie':
			case 'logies':
				return $this->replaceIf( $this->r2(), 'log' );
			case 'usion':
			case 'ution':
			case 'usions':
			case 'utions':
				return $this->replaceIf( $this->r2(), 'u' );
			case 'ence':
			case 'ences':
				return $this->replaceIf( $this->r2(), 'ent' );
			case 'ement':
			case 'ements':
				if ( ! $this->rv() ) {
					return false;
				}
				$this->slice( '' );
				$this->afterEment();
				return true;
			case 'if':
			case 'ive':
			case 'ifs':
			case 'ives':
				if ( ! $this->r2() ) {
					return false;
				}
				$this->slice( '' );
				$this->afterIf();
				return true;
			case 'eaux': // Unconditional, unlike -aux.
				return $this->replaceIf( true, 'eau' );
			case 'aux':
				return $this->replaceIf( $this->r1(), 'al' );
			case 'oux':
				return $this->replaceIf( $this->in( 'bhjlnp' ), 'ou' );
			case 'euse':
			case 'euses':
				return $this->deleteOrEux();
			case 'issement':
			case 'issements':
				return $this->deleteIf( $this->r1() && $this->out( self::VOWELS ) );
			case 'amment':
				if ( $this->rv() ) {
					$this->slice( 'ant' );
				}
				return false;
			case 'emment':
				if ( $this->rv() ) {
					$this->slice( 'ent' );
				}
				return false;
			case 'it' . self::E_ACUTE:
			case 'it' . self::E_ACUTE . 's':
				if ( ! $this->r2() ) {
					return false;
				}
				$this->slice( '' );
				$this->afterIte();
				return true;
			default: // ment, ments.
				if ( $this->in( self::VOWELS ) && $this->rv() ) {
					$this->slice( '' );
				}
				return false;
		}
	}

	/**
	 * Delete the slice when a condition holds.
	 *
	 * @param bool $condition The condition.
	 * @return bool The condition.
	 */
	private function deleteIf( bool $condition ): bool {
		return $this->replaceIf( $condition, '' );
	}

	/**
	 * Replace the slice when a condition holds.
	 *
	 * @param bool   $condition The condition.
	 * @param string $s         Replacement.
	 * @return bool The condition.
	 */
	private function replaceIf( bool $condition, string $s ): bool {
		if ( $condition ) {
			$this->slice( $s );
		}
		return $condition;
	}

	/**
	 * `(R2 delete) or (R1 <- 'eux')` for -euse(s), and for -eus after -ement.
	 */
	private function deleteOrEux(): bool {
		if ( $this->r2() ) {
			$this->slice( '' );
			return true;
		}
		return $this->replaceIf( $this->r1(), 'eux' );
	}

	/**
	 * After -ement is deleted: iv (R2, then at R2), eus, abl, iqU, ièr / Ièr.
	 */
	private function afterEment(): void {
		$suffix = $this->among( array( 'iv', 'eus', 'abl', 'iqU', "i\xE8r", "I\xE8r" ) );
		switch ( $suffix ) {
			case 'iv':
				if ( $this->r2() ) {
					$this->slice( '' );
					$this->ket = $this->c;
					if ( $this->eq( 'at' ) ) {
						$this->bra = $this->c;
						if ( $this->r2() ) {
							$this->slice( '' );
						}
					}
				}
				break;
			case 'eus':
				$this->deleteOrEux();
				break;
			case 'abl':
			case 'iqU':
				$this->deleteIf( $this->r2() );
				break;
			case "i\xE8r":
			case "I\xE8r":
				$this->replaceIf( $this->rv(), 'i' );
				break;
		}
	}

	/**
	 * After -ité(s) is deleted: abil, ic, iv.
	 */
	private function afterIte(): void {
		$suffix = $this->among( array( 'abil', 'ic', 'iv' ) );
		switch ( $suffix ) {
			case 'abil':
				$this->slice( $this->r2() ? '' : 'abl' );
				break;
			case 'ic':
				$this->slice( $this->r2() ? '' : 'iqU' );
				break;
			case 'iv':
				$this->deleteIf( $this->r2() );
				break;
		}
	}

	/**
	 * After -if / -ive is deleted: at (R2), then ic (R2 delete, else iqU).
	 */
	private function afterIf(): void {
		$this->ket = $this->c;
		if ( $this->eq( 'at' ) ) {
			$this->bra = $this->c;
			if ( $this->r2() ) {
				$this->slice( '' );
				$this->ket = $this->c;
				if ( $this->eq( 'ic' ) ) {
					$this->bra = $this->c;
					$this->slice( $this->r2() ? '' : 'iqU' );
				}
			}
		}
	}

	/**
	 * The -ir verb suffixes, within RV, after a non-vowel that is not H.
	 */
	private function iVerbSuffix(): bool {
		$ok = $this->within(
			$this->pV,
			fn (): bool => null !== $this->among( self::I_VERB_SUFFIXES ) && $this->out( self::VOWELS ) && 'H' !== $this->w[ $this->c ]
		);
		if ( $ok ) {
			$this->slice( '' );
		}
		return $ok;
	}

	/**
	 * The other verb suffixes: the suffix must lie within RV, the conditions run on the whole word.
	 */
	private function verbSuffix(): bool {
		$suffix = $this->within( $this->pV, fn (): ?string => $this->among( self::VERB_SUFFIXES ) );
		switch ( $suffix ) {
			case null:
				return false;
			case 'ions':
				return $this->deleteIf( $this->r2() );
			case 'ais':
			case 'aise':
			case 'aises':
				$exception = $this->eq( 'al' ) ? 1 === $this->c : ( $this->eq( 'auv' ) || $this->eq( self::E_ACUTE . 'pl' ) );
				return $this->deleteIf( ! $exception );
			case "\xE2mes":
			case "\xE2t":
			case "\xE2tes":
			case 'a':
			case 'ai':
			case 'aIent':
			case 'ait':
			case 'ant':
			case 'ante':
			case 'antes':
			case 'ants':
			case 'as':
			case 'asse':
			case 'assent':
			case 'asses':
			case 'assiez':
			case 'assions':
				// A preceding e in RV goes too.
				if ( $this->eq( 'e' ) && $this->rv() ) {
					$this->bra = $this->c;
				}
				break;
		}
		$this->slice( '' );
		return true;
	}

	/**
	 * When no suffix routine applied: a final s (unless after a i o u è s, or after Hi), then ion, ier, e in RV.
	 */
	private function residualSuffix(): void {
		$this->ket = $this->c;
		if ( $this->eq( 's' ) ) {
			$this->bra = $this->c;
			$m         = $this->save();
			$drop      = $this->eq( 'Hi' ) || $this->out( "aiou\xE8s" );
			$this->restore( $m );
			if ( $drop ) {
				$this->slice( '' );
			}
		}
		$this->within( $this->pV, fn (): ?string => $this->rvSuffix() );
	}

	/**
	 * The residual ion, ier, ière and e inside RV.
	 *
	 * @return string|null The suffix handled, or null when none ended the word.
	 */
	private function rvSuffix(): ?string {
		$suffix = $this->among( array( 'ion', 'ier', 'i' . self::E_GRAVE . 're', 'Ier', 'I' . self::E_GRAVE . 're', 'e' ) );
		switch ( $suffix ) {
			case 'ion':
				if ( $this->r2() && $this->in( 'st' ) ) {
					$this->slice( '' );
				}
				break;
			case 'e':
				$this->slice( '' );
				break;
			case null:
				break;
			default:
				$this->slice( 'i' );
		}
		return $suffix;
	}

	/**
	 * A final enn, onn, ett, ell or eill loses its last letter.
	 */
	private function unDouble(): void {
		$this->c = strlen( $this->w );
		if ( null !== $this->among( array( 'enn', 'onn', 'ett', 'ell', 'eill' ) ) ) {
			$this->w = substr( $this->w, 0, -1 );
		}
	}

	/**
	 * An é or è before one or more final non-vowels becomes e.
	 */
	private function unAccent(): void {
		$this->c = strlen( $this->w );
		$end     = $this->c;
		while ( $this->out( self::VOWELS ) ) {
			$this->ket = $this->c;
		}
		if ( $this->c < $end && ( $this->eq( self::E_ACUTE ) || $this->eq( self::E_GRAVE ) ) ) {
			$this->bra = $this->c;
			$this->slice( 'e' );
		}
	}

	/**
	 * Undo the markers: I U Y back to lower case, He and Hi back to ë and ï, a stray H removed.
	 */
	private function postlude(): void {
		$this->w = strtr(
			$this->w,
			array(
				'He' => self::E_DIAER,
				'Hi' => self::I_DIAER,
				'H'  => '',
				'I'  => 'i',
				'U'  => 'u',
				'Y'  => 'y',
			)
		);
	}
}
