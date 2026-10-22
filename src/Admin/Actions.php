<?php
/**
 * What the admin's forms do.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Admin;

use CreativeBranding\Search\Index\AnalyzerFactory;
use CreativeBranding\Search\Index\ContentReader;
use CreativeBranding\Search\Index\IndexQueue;
use CreativeBranding\Search\Index\Languages;
use CreativeBranding\Search\Index\Reindex;
use CreativeBranding\Search\Search\Cache;
use CreativeBranding\Search\Settings\Settings;
use CreativeBranding\Search\Support\Cast;
use CreativeBranding\Search\Support\Clock;
use CreativeBranding\Search\Support\Options;
use CreativeBranding\Search\Support\PluginException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every admin action, answered on `admin_post_{action}`: the capability and the nonce are checked first
 * ({@see Actions::guard()}, a 403 otherwise), the input is sanitised, the change made through a service, a notice
 * kept for the next screen and the browser sent back to the tab. Each handler returns where to go, so the
 * integration tests can call it without the redirect.
 */
final class Actions {

	public const SYNONYM_SAVE     = 'csearch_synonym_save';
	public const SYNONYM_DELETE   = 'csearch_synonym_delete';
	public const SYNONYM_IMPORT   = 'csearch_synonym_import';
	public const SYNONYM_EXPORT   = 'csearch_synonym_export';
	public const ANALYTICS_EXPORT = 'csearch_analytics_export';
	public const BOOSTS_SAVE      = 'csearch_boosts_save';
	public const TOOLS_SAVE       = 'csearch_tools_save';
	public const REINDEX          = 'csearch_reindex';
	public const REINDEX_CANCEL   = 'csearch_reindex_cancel';
	public const PRODUCT_REINDEX  = 'csearch_product_reindex';
	public const DISMISS          = 'csearch_dismiss_notice';

	public const IMPORT_MAX_BYTES = 1048576;

	/**
	 * A line added to the next notice (a fallback product that was not found).
	 *
	 * @var string
	 */
	private static string $warning = '';

	/**
	 * Settings the Tools tab saves: yes/no flags, then numbers and text.
	 */
	public const TOOLS_FLAGS  = array( 'index_long_description', 'index_tags', 'index_variation_skus', 'index_posts', 'index_pages', 'show_prices', 'show_stock', 'show_terms', 'label_visible', 'recent_searches', 'theme_form_takeover' );
	public const TOOLS_VALUES = array( 'retention_days', 'suggest_limit', 'suggest_terms', 'min_chars', 'placeholder', 'panel_position' );

	/**
	 * Settings that change what is indexed, so a rebuild is needed for products already in the index.
	 */
	public const INDEX_FLAGS = array( 'index_long_description', 'index_tags', 'index_variation_skus' );

	/**
	 * Constructor.
	 *
	 * @param SynonymService  $synonyms  Synonyms.
	 * @param Analytics       $analytics The log's numbers.
	 * @param Reindex         $reindex   The full rebuild.
	 * @param IndexQueue      $queue     One product's reindex.
	 * @param Languages       $languages The indexed languages.
	 * @param AnalyzerFactory $analyzers The analyzer, rebuilt when stop words change.
	 */
	public function __construct(
		private SynonymService $synonyms,
		private Analytics $analytics,
		private Reindex $reindex,
		private IndexQueue $queue,
		private Languages $languages,
		private AnalyzerFactory $analyzers
	) {
	}

	/**
	 * Action name to the method that answers it.
	 *
	 * @return array<string, string>
	 */
	public static function handlers(): array {
		return array(
			self::SYNONYM_SAVE     => 'saveSynonym',
			self::SYNONYM_DELETE   => 'deleteSynonyms',
			self::SYNONYM_IMPORT   => 'importSynonyms',
			self::SYNONYM_EXPORT   => 'exportSynonyms',
			self::ANALYTICS_EXPORT => 'exportLog',
			self::BOOSTS_SAVE      => 'saveBoosts',
			self::TOOLS_SAVE       => 'saveTools',
			self::REINDEX          => 'rebuild',
			self::REINDEX_CANCEL   => 'cancel',
			self::PRODUCT_REINDEX  => 'reindexProduct',
			self::DISMISS          => 'dismiss',
		);
	}

	/**
	 * Answer an action: check, run, redirect (or send the download).
	 *
	 * @param string $action The action.
	 */
	public function handle( string $action ): void {
		$method = self::handlers()[ $action ] ?? null;
		if ( null === $method ) {
			return;
		}
		self::guard( $action );
		$input = wp_unslash( $_POST + $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- checked in guard().
		$out   = $this->{$method}( is_array( $input ) ? $input : array() );
		if ( is_array( $out ) && isset( $out['write'] ) && is_callable( $out['write'] ) ) {
			self::download( Cast::str( $out['name'] ?? '' ), $out['write'] );
		}
		wp_safe_redirect( is_string( $out ) ? $out : AdminPage::url() );
		exit;
	}

	/**
	 * Refuse anyone without the capability or a valid nonce for the action.
	 *
	 * @param string $action The action, which names its nonce.
	 */
	public static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage the shop’s search.', 'creative-search' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * The URL of an action sent by a link (the product panel's Reindex now, a synonym's Delete), with its nonce.
	 *
	 * @param string               $action The action.
	 * @param array<string, mixed> $args   Its arguments.
	 */
	public static function link( string $action, array $args = array() ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => $action ) + $args, admin_url( 'admin-post.php' ) ), $action );
	}

	/**
	 * Add or change a synonym.
	 *
	 * @param array<string, mixed> $input The form.
	 */
	public function saveSynonym( array $input ): string {
		$id = Cast::int( $input['id'] ?? 0 );
		try {
			$synonym = $this->synonyms->make( Cast::str( $input['lang'] ?? '' ), Cast::str( $input['word'] ?? '' ), Cast::str( $input['means'] ?? '' ), ! empty( $input['both_ways'] ) );
		} catch ( PluginException $e ) {
			Notices::flash( 'error', $e->getMessage() );
			return AdminPage::url( 'synonyms', $id > 0 ? array( 'edit' => $id ) : array() );
		}
		if ( $id > 0 ) {
			$this->synonyms->update( $id, $synonym );
			/* translators: 1: a word, 2: what it means */
			Notices::flash( 'success', sprintf( __( 'Synonym saved: “%1$s” means “%2$s”. Searches use it now.', 'creative-search' ), $synonym->word, $synonym->means ) );
		} elseif ( 0 === $this->synonyms->add( $synonym, get_current_user_id() ) ) {
			/* translators: 1: a word, 2: what it means */
			Notices::flash( 'info', sprintf( __( '“%1$s” already means “%2$s”; nothing was added.', 'creative-search' ), $synonym->word, $synonym->means ) );
		} else {
			/* translators: 1: a word, 2: what it means */
			Notices::flash( 'success', sprintf( __( 'Synonym added: “%1$s” means “%2$s”. Searches use it now.', 'creative-search' ), $synonym->word, $synonym->means ) );
		}
		return AdminPage::url( 'synonyms' );
	}

	/**
	 * Delete synonyms: one from a row's link (`id`) or several from the bulk action (`ids`).
	 *
	 * @param array<string, mixed> $input The request.
	 */
	public function deleteSynonyms( array $input ): string {
		$ids     = Settings::sanitizeIds( array_merge( isset( $input['ids'] ) && is_array( $input['ids'] ) ? $input['ids'] : array(), array( $input['id'] ?? 0 ) ) );
		$deleted = $this->synonyms->delete( $ids );
		/* translators: %s: number of synonyms */
		Notices::flash( 'success', sprintf( _n( '%s synonym deleted.', '%s synonyms deleted.', $deleted, 'creative-search' ), number_format_i18n( $deleted ) ) );
		return AdminPage::url( 'synonyms' );
	}

	/**
	 * Import a CSV of synonyms.
	 *
	 * @param array<string, mixed> $input The form (the file is in `$_FILES['csv']`).
	 */
	public function importSynonyms( array $input ): string {
		unset( $input );
		$file = isset( $_FILES['csv'] ) && is_array( $_FILES['csv'] ) ? $_FILES['csv'] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked in guard(); the upload's fields are checked in uploaded().
		$csv  = self::uploaded( $file );
		if ( null === $csv ) {
			Notices::flash( 'error', __( 'Choose a CSV file of 1 MB or less to import.', 'creative-search' ) );
			return AdminPage::url( 'synonyms' );
		}
		$done = $this->synonyms->import( $csv, get_current_user_id() );
		Notices::flash(
			$done['added'] > 0 ? 'success' : 'warning',
			sprintf(
				/* translators: 1: synonyms added, 2: rows already present, 3: rows that were not synonyms */
				__( 'Import finished. Added: %1$s. Already there: %2$s. Skipped: %3$s.', 'creative-search' ),
				number_format_i18n( $done['added'] ),
				number_format_i18n( $done['existing'] ),
				number_format_i18n( $done['skipped'] )
			)
		);
		return AdminPage::url( 'synonyms' );
	}

	/**
	 * Every synonym as a CSV download.
	 *
	 * @param array<string, mixed> $input The request.
	 * @return array{name: string, write: callable(resource): void}
	 */
	public function exportSynonyms( array $input ): array {
		unset( $input );
		$csv = $this->synonyms->export();
		return array(
			'name'  => 'synonyms-' . gmdate( 'Y-m-d', Clock::timestamp() ) . '.csv',
			'write' => static function ( $out ) use ( $csv ): void {
				fwrite( $out, $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite -- php://output or php://memory, never a file.
			},
		);
	}

	/**
	 * The log of a period as a CSV download.
	 *
	 * @param array<string, mixed> $input The request.
	 * @return array{name: string, write: callable(resource): void}
	 */
	public function exportLog( array $input ): array {
		$days      = Analytics::period( $input['days'] ?? 30 );
		$analytics = $this->analytics;
		return array(
			'name'  => sprintf( 'search-log-%dd-', $days ) . gmdate( 'Y-m-d', Clock::timestamp() ) . '.csv',
			'write' => static function ( $out ) use ( $analytics, $days ): void {
				$analytics->exportTo( $out, $days );
			},
		);
	}

	/**
	 * Save the boost rules, the fallback products and the hidden-by-SKU setting; let excluded products back in.
	 *
	 * @param array<string, mixed> $input The form.
	 */
	public function saveBoosts( array $input ): string {
		$rules = array();
		foreach ( isset( $input['rules'] ) && is_array( $input['rules'] ) ? $input['rules'] : array() as $rule ) {
			if ( is_array( $rule ) && empty( $rule['remove'] ) ) {
				$rules[] = self::rule( Cast::str( $rule['ref'] ?? '' ), $rule['boost'] ?? 0 );
			}
		}
		$new = self::rule( Cast::str( $input['new_ref'] ?? '' ), $input['new_boost'] ?? 0 );
		if ( null !== $new ) {
			$rules   = array_values( array_filter( $rules, static fn( ?array $r ): bool => null !== $r && ( $r['taxonomy'] !== $new['taxonomy'] || $r['term_id'] !== $new['term_id'] ) ) );
			$rules[] = $new;
		}
		Settings::update(
			array(
				'boost_rules'       => array_values( array_filter( $rules ) ),
				'fallback_products' => self::fallback( $input ),
				'hidden_by_sku'     => Settings::yesNo( $input['hidden_by_sku'] ?? 'no' ),
				'help_page'         => $input['help_page'] ?? Settings::get( 'help_page' ),
				'help_text'         => $input['help_text'] ?? Settings::get( 'help_text' ),
				'guide_page'        => $input['guide_page'] ?? Settings::get( 'guide_page' ),
				'guide_text'        => $input['guide_text'] ?? Settings::get( 'guide_text' ),
			)
		);
		$included = 0;
		foreach ( Settings::sanitizeIds( isset( $input['include'] ) && is_array( $input['include'] ) ? $input['include'] : array() ) as $productId ) {
			$product = wc_get_product( $productId );
			if ( $product instanceof \WC_Product ) {
				$product->delete_meta_data( Options::META_EXCLUDE );
				$product->save();
				++$included;
			}
		}
		Cache::bump();
		$message = __( 'Boosts and exclusions saved. Searches use them now.', 'creative-search' );
		if ( $included > 0 ) {
			/* translators: %s: number of products */
			$message .= ' ' . sprintf( _n( '%s product is back in search once it is reindexed, in a moment.', '%s products are back in search once they are reindexed, in a moment.', $included, 'creative-search' ), number_format_i18n( $included ) );
		}
		if ( '' !== self::$warning ) {
			Notices::flash( 'warning', $message . ' ' . self::$warning );
			self::$warning = '';
			return AdminPage::url( 'boosts' );
		}
		Notices::flash( 'success', $message );
		return AdminPage::url( 'boosts' );
	}

	/**
	 * Save the Tools tab's settings and stop words.
	 *
	 * @param array<string, mixed> $input The form.
	 */
	public function saveTools( array $input ): string {
		$before  = Settings::all();
		$changes = array();
		foreach ( self::TOOLS_FLAGS as $flag ) {
			$changes[ $flag ] = Settings::yesNo( $input[ $flag ] ?? 'no' );
		}
		foreach ( self::TOOLS_VALUES as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$changes[ $key ] = $input[ $key ];
			}
		}
		$after = Settings::update( $changes );
		foreach ( $this->languages->codes() as $lang ) {
			if ( isset( $input['stopwords'] ) && is_array( $input['stopwords'] ) && array_key_exists( $lang, $input['stopwords'] ) ) {
				update_option( Options::STOPWORDS_PREFIX . $lang, self::words( Cast::str( $input['stopwords'][ $lang ] ) ), false );
			}
		}
		$this->analyzers->flush();
		Cache::bump();
		$rebuild = false;
		foreach ( self::INDEX_FLAGS as $flag ) {
			$rebuild = $rebuild || $before[ $flag ] !== $after[ $flag ];
		}
		foreach ( ContentReader::TYPES as $postType => [ , $flag ] ) {
			if ( $before[ $flag ] !== $after[ $flag ] ) {
				$this->queue->contentType( $postType ); // Indexed or removed in jobs; no rebuild needed (D-093).
			}
		}
		Notices::flash(
			'success',
			$rebuild
				? __( 'Settings saved. What is indexed changed: rebuild the index to apply it to every product.', 'creative-search' )
				: __( 'Settings saved.', 'creative-search' )
		);
		return AdminPage::url( 'tools' );
	}

	/**
	 * Queue a full rebuild.
	 *
	 * @param array<string, mixed> $input The form.
	 */
	public function rebuild( array $input ): string {
		unset( $input );
		Notices::flash(
			'success',
			$this->reindex->queue()
				? __( 'The index is being rebuilt. The shop keeps searching the current index until the new one is complete.', 'creative-search' )
				: __( 'A rebuild is already running or waiting to run.', 'creative-search' )
		);
		return AdminPage::url( 'tools' );
	}

	/**
	 * Stop the running rebuild.
	 *
	 * @param array<string, mixed> $input The form.
	 */
	public function cancel( array $input ): string {
		unset( $input );
		Notices::flash(
			'success',
			$this->reindex->cancel()
				? __( 'The rebuild is stopping. The shop keeps searching the current index.', 'creative-search' )
				: __( 'No rebuild was running.', 'creative-search' )
		);
		return AdminPage::url( 'tools' );
	}

	/**
	 * Queue one product's reindex (the product panel's link).
	 *
	 * @param array<string, mixed> $input The request.
	 */
	public function reindexProduct( array $input ): string {
		$productId = Cast::int( $input['product'] ?? 0 );
		$back      = get_edit_post_link( $productId, 'url' );
		if ( $productId <= 0 || ! wc_get_product( $productId ) instanceof \WC_Product ) {
			Notices::flash( 'error', __( 'That product does not exist.', 'creative-search' ) );
			return AdminPage::url( 'tools' );
		}
		$this->queue->product( $productId );
		$this->queue->flush();
		Notices::flash( 'success', __( 'The product is queued for the search index; it is searchable as it is now in a moment.', 'creative-search' ) );
		return is_string( $back ) && '' !== $back ? $back : AdminPage::url( 'tools' );
	}

	/**
	 * Dismiss a warning until its condition clears.
	 *
	 * @param array<string, mixed> $input The form.
	 */
	public function dismiss( array $input ): string {
		Notices::dismiss( sanitize_key( Cast::str( $input['notice'] ?? '' ) ) );
		$back = wp_get_referer();
		return is_string( $back ) ? $back : AdminPage::url();
	}

	/**
	 * The fallback products from the form: those kept, less those ticked for removal, plus those added by SKU
	 * or id (an unknown one is reported).
	 *
	 * @param array<string, mixed> $input The form.
	 * @return int[]
	 */
	private static function fallback( array $input ): array {
		$kept    = Settings::sanitizeIds( isset( $input['fallback_products'] ) && is_array( $input['fallback_products'] ) ? $input['fallback_products'] : array() );
		$removed = Settings::sanitizeIds( isset( $input['fallback_remove'] ) && is_array( $input['fallback_remove'] ) ? $input['fallback_remove'] : array() );
		$ids     = array_values( array_diff( $kept, $removed ) );
		$unknown = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( Cast::str( $input['fallback_add'] ?? '' ) ) ) ) ) as $ref ) {
			$id = wc_get_product_id_by_sku( $ref );
			if ( 0 === $id && ctype_digit( $ref ) && wc_get_product( (int) $ref ) instanceof \WC_Product ) {
				$id = (int) $ref;
			}
			if ( $id > 0 ) {
				$ids[] = $id;
			} else {
				$unknown[] = $ref;
			}
		}
		if ( array() !== $unknown ) {
			/* translators: %s: the SKUs or ids not found */
			self::$warning = sprintf( __( 'No product has the SKU or ID %s.', 'creative-search' ), wp_sprintf_l( '%l', $unknown ) );
		}
		return Settings::sanitizeIds( $ids );
	}

	/**
	 * A boost rule from the form: `product_cat:12` and a number.
	 *
	 * @param string $ref   Taxonomy and term id.
	 * @param mixed  $boost The number.
	 * @return array{taxonomy: string, term_id: int, boost: int}|null
	 */
	public static function rule( string $ref, mixed $boost ): ?array {
		$parts = explode( ':', $ref, 2 );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		$rules = Settings::sanitizeBoostRules(
			array(
				array(
					'taxonomy' => $parts[0],
					'term_id'  => $parts[1],
					'boost'    => Cast::int( $boost ),
				),
			)
		);
		return $rules[0] ?? null;
	}

	/**
	 * Stop words from a text area: one per line or comma-separated, lower-cased, unique.
	 *
	 * @param string $text The text.
	 * @return string[]
	 */
	public static function words( string $text ): array {
		$out = array();
		foreach ( Cast::strings( preg_split( '/[\s,]+/u', mb_strtolower( sanitize_textarea_field( $text ), 'UTF-8' ) ) ) as $word ) {
			if ( '' !== $word && mb_strlen( $word, 'UTF-8' ) <= 64 && ! in_array( $word, $out, true ) ) {
				$out[] = $word;
			}
		}
		return $out;
	}

	/**
	 * The text of an uploaded CSV, or null when there is none or it is too big.
	 *
	 * @param array<string, mixed> $file The `$_FILES` entry.
	 */
	public static function uploaded( array $file ): ?string {
		$path = Cast::str( $file['tmp_name'] ?? '' );
		if ( UPLOAD_ERR_OK !== Cast::int( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || '' === $path || ! is_uploaded_file( $path ) || Cast::int( $file['size'] ?? 0 ) > self::IMPORT_MAX_BYTES ) {
			return null;
		}
		$text = file_get_contents( $path ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- PHP's own upload, checked by is_uploaded_file().
		return is_string( $text ) ? $text : null;
	}

	/**
	 * Send a CSV download, streamed to the response, and stop.
	 *
	 * @param string                   $name  File name.
	 * @param callable(resource): void $write Writes the CSV to a stream; every cell is guarded in Csv::cell().
	 */
	private static function download( string $name, callable $write ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- the response, not a file.
		if ( false !== $out ) {
			$write( $out );
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the response, not a file.
		}
		exit;
	}
}
