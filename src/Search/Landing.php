<?php
/**
 * The page a product search is sent to.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Search;

use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where a results-page query lands ({@see Destination}) and what that page lists: its address, its count, the values
 * of the query the shop filters by (D-101) that the page applies, and those it leaves out because nothing in them is
 * in stock there (D-154).
 */
final class Landing {

	/**
	 * Constructor.
	 *
	 * @param string                  $url       The page, with its filters and the signed query that sent the shopper.
	 * @param int                     $count     How many products it lists.
	 * @param bool                    $named     Whether the query named a value the shop filters by (applied or not).
	 * @param array<string, string[]> $filters   The values applied: attribute taxonomy to slugs.
	 * @param array<string, string[]> $unstocked The values named but left out, nothing in them being in stock there.
	 * @param WP_Term|null            $term      The category the page lists, or null.
	 * @param int[]|null              $ids       The products the other words found, or null when there were no others.
	 */
	public function __construct(
		public readonly string $url,
		public readonly int $count,
		public readonly bool $named = false,
		public readonly array $filters = array(),
		public readonly array $unstocked = array(),
		public readonly ?WP_Term $term = null,
		public readonly ?array $ids = null
	) {
	}

	/**
	 * Whether a value was left out because nothing in it is in stock (D-154): the search found nothing the shopper
	 * can buy in it, and is logged so.
	 */
	public function unstocked(): bool {
		return array() !== $this->unstocked;
	}

	/**
	 * The count the log keeps: none when the value asked for was left out.
	 */
	public function logged(): int {
		return $this->unstocked() ? 0 : $this->count;
	}
}
