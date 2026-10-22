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
	 */
	public function __construct( public readonly string $term, public readonly string $kind, public readonly int $df = 0 ) {
	}

	/**
	 * The match weight.
	 */
	public function weight(): float {
		return self::WEIGHTS[ $this->kind ];
	}

	/**
	 * A copy with the document frequency filled in.
	 *
	 * @param int $df Document frequency.
	 */
	public function withDf( int $df ): self {
		return new self( $this->term, $this->kind, $df );
	}
}
