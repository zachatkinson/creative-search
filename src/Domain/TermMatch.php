<?php
/**
 * One matched term.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A term a query token was widened to, with how it was reached and the match weight that earns
 * (spec: Matching → Match weights).
 */
final class TermMatch {

	public const EXACT   = 'exact';
	public const STEM    = 'stem';
	public const SYNONYM = 'synonym';
	public const PREFIX  = 'prefix';
	public const TYPO1   = 'typo1';
	public const TYPO2   = 'typo2';

	/**
	 * Match weights m by kind.
	 *
	 * @var array<string, float>
	 */
	public const WEIGHTS = array(
		self::EXACT   => 1.0,
		self::STEM    => 0.9,
		self::SYNONYM => 0.8,
		self::PREFIX  => 0.7,
		self::TYPO1   => 0.5,
		self::TYPO2   => 0.3,
	);

	/**
	 * Constructor.
	 *
	 * @param string $term The term.
	 * @param string $kind How it was reached: one of the constants.
	 * @param int    $df   Its document frequency.
	 * @param bool   $loose        A typo candidate other than the word the query is read as (D-146): found only in a
	 *                             description, it ranks after every product found elsewhere.
	 * @param bool   $descriptions Whether its description postings count: not for a loose term of a word of four
	 *                             letters or fewer (D-146).
	 */
	public function __construct( public readonly string $term, public readonly string $kind, public readonly int $df = 0, public readonly bool $loose = false, public readonly bool $descriptions = true ) {
	}

	/**
	 * The match weight.
	 */
	public function weight(): float {
		return self::WEIGHTS[ $this->kind ];
	}

	/**
	 * Whether a posting of this term in a field reaches the product firmly: anywhere but a description, or any term
	 * but a loose one (D-146).
	 *
	 * @param int $field The posting's field.
	 */
	public function firmIn( int $field ): bool {
		return ! $this->loose || ! in_array( $field, Fields::DESCRIPTIONS, true );
	}

	/**
	 * A copy with the document frequency filled in.
	 *
	 * @param int $df Document frequency.
	 */
	public function withDf( int $df ): self {
		return new self( $this->term, $this->kind, $df, $this->loose, $this->descriptions );
	}
}
