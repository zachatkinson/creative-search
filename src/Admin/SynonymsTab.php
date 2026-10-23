<?php
/**
 * The Synonyms tab.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Admin;

use CreativeBranding\Search\Index\Languages;
use CreativeBranding\Search\Repository\SynonymRepository;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Cast;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Synonyms per language (spec: Admin → Synonyms): the add or edit form, the list table, the test box that shows
 * what a word expands to, and the CSV import and export. A change applies at once; no reindex.
 */
final class SynonymsTab {

	public const BULK_NONCE = 'bulk-synonyms';

	/**
	 * Constructor.
	 *
	 * @param SynonymRepository $repository The table.
	 * @param SynonymService    $synonyms   Synonyms.
	 * @param Languages         $languages  The indexed languages.
	 */
	public function __construct( private SynonymRepository $repository, private SynonymService $synonyms, private Languages $languages ) {
	}

	/**
	 * Before the screen prints: the list table's bulk Delete (the table's form comes back to this screen).
	 */
	public function load(): void {
		$redirect = $this->bulkDelete();
		if ( null !== $redirect ) {
			wp_safe_redirect( $redirect );
			exit;
		}
	}

	/**
	 * The bulk Delete, when the request is one: checked, done, and where to go next.
	 */
	public function bulkDelete(): ?string {
		if ( 'delete' !== $this->table()->current_action() ) {
			return null;
		}
		Actions::guard( self::BULK_NONCE );
		$ids     = Settings::sanitizeIds( isset( $_REQUEST['ids'] ) && is_array( $_REQUEST['ids'] ) ? wp_unslash( $_REQUEST['ids'] ) : array() ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked in guard(); sanitizeIds() keeps positive integers only.
		$deleted = $this->synonyms->delete( $ids );
		/* translators: %s: number of synonyms */
		Notices::flash( 'success', sprintf( _n( '%s synonym deleted.', '%s synonyms deleted.', $deleted, 'creative-search' ), number_format_i18n( $deleted ) ) );
		return AdminPage::url( 'synonyms' );
	}

	/**
	 * The list table.
	 */
	public function table(): SynonymsTable {
		return new SynonymsTable( $this->repository, $this->languages->codes() );
	}

	/**
	 * Print the tab.
	 */
	public function render(): void {
		$codes   = $this->languages->codes();
		$editId  = isset( $_GET['edit'] ) && is_string( $_GET['edit'] ) ? Cast::int( sanitize_text_field( wp_unslash( $_GET['edit'] ) ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- opening the form changes nothing.
		$editing = $editId > 0 ? $this->repository->find( $editId ) : null;

		echo '<p>' . esc_html__( 'A synonym makes a search for one word also find another: “board” also finds “deck”. One way, a search for the word finds what it means; both ways, each finds the other. Searches use a change at once.', 'creative-search' ) . '</p>';
		echo '<p>' . esc_html__( 'Either side can be a few words: “ss” can mean “stainless steel”, and “skate shoes” can mean “sneakers”. A search holding those words side by side also finds what they mean, as a whole: “stainless steel”, not “steel” alone.', 'creative-search' ) . '</p>';

		echo '<h2 id="csearch-synonym-form">' . ( null === $editing ? esc_html__( 'Add a synonym', 'creative-search' ) : esc_html__( 'Edit the synonym', 'creative-search' ) ) . '</h2>';
		echo AdminPage::formStart( Actions::SYNONYM_SAVE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		if ( null !== $editing ) {
			echo '<input type="hidden" name="id" value="' . esc_attr( (string) $editId ) . '">';
		}
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="csearch-syn-word">' . esc_html__( 'Word', 'creative-search' ) . '</label></th><td><input type="text" id="csearch-syn-word" name="word" class="regular-text" maxlength="64" required value="' . esc_attr( Cast::str( $editing['word'] ?? '' ) ) . '" aria-describedby="csearch-syn-word-help"><p class="description" id="csearch-syn-word-help">' . esc_html__( 'What a shopper types.', 'creative-search' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="csearch-syn-means">' . esc_html__( 'Means', 'creative-search' ) . '</label></th><td><input type="text" id="csearch-syn-means" name="means" class="regular-text" maxlength="64" required value="' . esc_attr( Cast::str( $editing['means'] ?? '' ) ) . '"></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Direction', 'creative-search' ) . '</th><td><label for="csearch-syn-both"><input type="checkbox" id="csearch-syn-both" name="both_ways" value="1"' . checked( '1', Cast::str( $editing['both_ways'] ?? '1' ), false ) . '> ' . esc_html__( 'Both ways: a search for what it means also finds the word', 'creative-search' ) . '</label></td></tr>';
		$current = Cast::str( $editing['lang'] ?? ( $codes[0] ?? '' ) );
		if ( count( $codes ) > 1 ) {
			echo '<tr><th scope="row"><label for="csearch-syn-lang">' . esc_html__( 'Language', 'creative-search' ) . '</label></th><td><select id="csearch-syn-lang" name="lang">';
			foreach ( $codes as $code ) {
				echo '<option value="' . esc_attr( $code ) . '"' . selected( $code, $current, false ) . '>' . esc_html( $code ) . '</option>';
			}
			echo '</select></td></tr>';
		} else {
			echo '<input type="hidden" name="lang" value="' . esc_attr( $current ) . '">';
		}
		echo '</tbody></table>';
		submit_button( null === $editing ? __( 'Add synonym', 'creative-search' ) : __( 'Save synonym', 'creative-search' ), 'primary', 'submit', false );
		if ( null !== $editing ) {
			echo ' <a class="button" href="' . esc_url( AdminPage::url( 'synonyms' ) ) . '">' . esc_html__( 'Cancel', 'creative-search' ) . '</a>';
		}
		echo '</form>';

		echo '<h2>' . esc_html__( 'Synonyms', 'creative-search' ) . '</h2>';
		$table = $this->table();
		$table->prepare_items();
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( AdminPage::SLUG ) . '"><input type="hidden" name="tab" value="synonyms">';
		$table->search_box( __( 'Search synonyms', 'creative-search' ), 'csearch-synonyms' );
		$table->display();
		echo '</form>';

		$this->testBox( $codes );

		echo '<h2>' . esc_html__( 'Import and export', 'creative-search' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: the CSV's column names */
				__( 'A CSV with the columns %s; both_ways is yes or no. Rows already present are left alone; rows that are not synonyms are skipped and counted.', 'creative-search' ),
				implode( ', ', SynonymService::HEADER )
			)
		) . '</p>';
		echo AdminPage::formStart( Actions::SYNONYM_IMPORT, 'csearch-inline', true ) . '<label for="csearch-syn-csv">' . esc_html__( 'CSV file', 'creative-search' ) . '</label> <input type="file" id="csearch-syn-csv" name="csv" accept=".csv,text/csv" required> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		submit_button( __( 'Import', 'creative-search' ), 'secondary', 'submit', false, array( 'id' => 'csearch-syn-import' ) );
		echo '</form> ';
		echo AdminPage::formStart( Actions::SYNONYM_EXPORT, 'csearch-inline' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		submit_button( __( 'Export CSV', 'creative-search' ), 'secondary', 'submit', false, array( 'id' => 'csearch-syn-export' ) );
		echo '</form>';
	}

	/**
	 * What a word expands to.
	 *
	 * @param string[] $codes Language codes.
	 */
	private function testBox( array $codes ): void {
		$word = isset( $_GET['try'] ) && is_string( $_GET['try'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['try'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only test.
		$lang = isset( $_GET['try_lang'] ) && is_string( $_GET['try_lang'] ) ? sanitize_key( wp_unslash( $_GET['try_lang'] ) ) : ( $codes[0] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only test.
		$lang = in_array( $lang, $codes, true ) ? $lang : ( $codes[0] ?? '' );
		echo '<h2 id="csearch-try">' . esc_html__( 'Test a search', 'creative-search' ) . '</h2>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '#csearch-try" class="csearch-inline"><input type="hidden" name="page" value="' . esc_attr( AdminPage::SLUG ) . '"><input type="hidden" name="tab" value="synonyms">';
		echo '<label for="csearch-try-word">' . esc_html__( 'Words', 'creative-search' ) . '</label> <input type="search" id="csearch-try-word" name="try" value="' . esc_attr( $word ) . '"> ';
		if ( count( $codes ) > 1 ) {
			echo '<label for="csearch-try-lang">' . esc_html__( 'Language', 'creative-search' ) . '</label> <select id="csearch-try-lang" name="try_lang">';
			foreach ( $codes as $code ) {
				echo '<option value="' . esc_attr( $code ) . '"' . selected( $code, $lang, false ) . '>' . esc_html( $code ) . '</option>';
			}
			echo '</select> ';
		}
		submit_button( __( 'Test', 'creative-search' ), 'secondary', '', false, array( 'id' => 'csearch-try-submit' ) );
		echo '</form>';
		if ( '' === $word ) {
			return;
		}
		$expands = $this->synonyms->expand( $word, $lang );
		echo '<p class="csearch-try-answer" role="status">' . esc_html(
			array() === $expands
				/* translators: %s: a word */
				? sprintf( __( '“%s” has no synonyms.', 'creative-search' ), $word )
				/* translators: 1: a word, 2: the words it also searches for */
				: sprintf( __( 'A search for “%1$s” also finds: %2$s.', 'creative-search' ), $word, wp_sprintf_l( '%l', $expands ) )
		) . '</p>';
	}
}
