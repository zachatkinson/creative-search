<?php
/**
 * The synonyms list table.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Admin;

use CreativeBranding\Search\Repository\SynonymRepository;
use CreativeBranding\Search\Support\Cast;
use WP_List_Table;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( WP_List_Table::class ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Synonyms in WordPress's list table (spec: Admin → Synonyms): word, means, one way or both, language, who added
 * it and when; a search box, a language filter, 20 a page, Edit and Delete on each row and a bulk Delete.
 */
final class SynonymsTable extends WP_List_Table {

	public const PER_PAGE = 20;

	/**
	 * Constructor.
	 *
	 * @param SynonymRepository $repository The table.
	 * @param string[]          $languages  The indexed language codes.
	 */
	public function __construct( private SynonymRepository $repository, private array $languages ) {
		parent::__construct(
			array(
				'singular' => 'synonym',
				'plural'   => 'synonyms',
				'ajax'     => false,
				'screen'   => 'woocommerce_page_' . AdminPage::SLUG,
			)
		);
	}

	/**
	 * The language filter of the request.
	 */
	public static function langFilter(): string {
		return isset( $_REQUEST['lang'] ) && is_string( $_REQUEST['lang'] ) ? sanitize_key( wp_unslash( $_REQUEST['lang'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a filter changes nothing.
	}

	/**
	 * The search of the request.
	 */
	public static function searchFilter(): string {
		return isset( $_REQUEST['s'] ) && is_string( $_REQUEST['s'] ) ? trim( sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a filter changes nothing.
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox">',
			'word'       => __( 'Word', 'creative-search' ),
			'means'      => __( 'Means', 'creative-search' ),
			'both_ways'  => __( 'Direction', 'creative-search' ),
			'lang'       => __( 'Language', 'creative-search' ),
			'created_by' => __( 'Added by', 'creative-search' ),
			'created_at' => __( 'Added', 'creative-search' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function prepare_items() {
		$lang   = self::langFilter();
		$search = self::searchFilter();
		$total  = $this->repository->count( $lang, $search );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);
		$page        = max( 1, $this->get_pagenum() );
		$this->items = $this->repository->page( $lang, $search, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );
		cache_users( array_filter( array_map( static fn( array $row ): int => Cast::int( $row['created_by'] ?? 0 ), $this->items ) ) );
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'word' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions() {
		return array( 'delete' => __( 'Delete', 'creative-search' ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No synonyms yet. Add one above, or import a CSV.', 'creative-search' );
	}

	/**
	 * The language filter.
	 *
	 * @param string $which top or bottom.
	 *
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which || count( $this->languages ) < 2 ) {
			return;
		}
		$current = self::langFilter();
		echo '<div class="alignleft actions"><label for="csearch-filter-lang" class="screen-reader-text">' . esc_html__( 'Filter by language', 'creative-search' ) . '</label><select id="csearch-filter-lang" name="lang"><option value="">' . esc_html__( 'All languages', 'creative-search' ) . '</option>';
		foreach ( $this->languages as $code ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( $code, $current, false ) . '>' . esc_html( $code ) . '</option>';
		}
		echo '</select>';
		submit_button( __( 'Filter', 'creative-search' ), '', 'filter_action', false, array( 'id' => 'csearch-filter-submit' ) );
		echo '</div>';
	}

	/**
	 * The checkbox column.
	 *
	 * @param array<string, string|null>|object $item The row.
	 *
	 * @return string
	 */
	protected function column_cb( $item ) {
		$row = (array) $item;
		/* translators: %s: a word */
		return '<label class="screen-reader-text" for="csearch-syn-' . esc_attr( Cast::str( $row['id'] ) ) . '">' . esc_html( sprintf( __( 'Select %s', 'creative-search' ), Cast::str( $row['word'] ) ) ) . '</label><input type="checkbox" id="csearch-syn-' . esc_attr( Cast::str( $row['id'] ) ) . '" name="ids[]" value="' . esc_attr( Cast::str( $row['id'] ) ) . '">';
	}

	/**
	 * The word, with the row actions.
	 *
	 * @param array<string, string|null> $item The row.
	 */
	protected function column_word( array $item ): string {
		$id      = Cast::int( $item['id'] );
		$word    = Cast::str( $item['word'] );
		$actions = array(
			/* translators: %s: a word */
			'edit'   => '<a href="' . esc_url( AdminPage::url( 'synonyms', array( 'edit' => $id ) ) . '#csearch-synonym-form' ) . '" aria-label="' . esc_attr( sprintf( __( 'Edit “%s”', 'creative-search' ), $word ) ) . '">' . esc_html__( 'Edit', 'creative-search' ) . '</a>',
			/* translators: %s: a word */
			'delete' => '<a href="' . esc_url( Actions::link( Actions::SYNONYM_DELETE, array( 'id' => $id ) ) ) . '" class="submitdelete" aria-label="' . esc_attr( sprintf( __( 'Delete “%s”', 'creative-search' ), $word ) ) . '">' . esc_html__( 'Delete', 'creative-search' ) . '</a>',
		);
		return '<strong>' . esc_html( $word ) . '</strong>' . $this->row_actions( $actions );
	}

	/**
	 * The other columns.
	 *
	 * @param array<string, string|null>|object $item        The row.
	 * @param string                            $column_name The column.
	 *
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		$row = (array) $item;
		switch ( $column_name ) {
			case 'means':
			case 'lang':
				return esc_html( Cast::str( $row[ $column_name ] ?? '' ) );
			case 'both_ways':
				return '1' === Cast::str( $row['both_ways'] ?? '' ) ? esc_html__( 'Both ways', 'creative-search' ) : esc_html__( 'One way', 'creative-search' );
			case 'created_by':
				$user = get_userdata( Cast::int( $row['created_by'] ?? 0 ) );
				return $user instanceof \WP_User ? esc_html( $user->display_name ) : esc_html__( 'Import or WP-CLI', 'creative-search' );
			case 'created_at':
				$at = strtotime( Cast::str( $row['created_at'] ?? '' ) . ' UTC' );
				return false === $at ? '' : esc_html( Cast::str( wp_date( Cast::str( get_option( 'date_format' ) ) . ' ' . Cast::str( get_option( 'time_format' ) ), $at ) ) );
		}
		return '';
	}
}
