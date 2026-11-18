<?php
/**
 * The Analytics tab.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Admin;

use CreativeBranding\Search\Support\Cast;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The last 7, 30 or 90 days (spec: Admin → Analytics): searches a day as a chart with the same numbers in a
 * table, the totals, suggestions against results-page use, the top 50 queries with results and click-through,
 * the top 50 that found nothing, the most clicked suggestions, an Explain link on each query and the CSV export.
 */
final class AnalyticsTab {

	/**
	 * Constructor.
	 *
	 * @param Analytics $analytics The log's numbers.
	 */
	public function __construct( private Analytics $analytics ) {
	}

	/**
	 * Print the tab.
	 */
	public function render(): void {
		$days   = Analytics::period( isset( $_GET['days'] ) && is_string( $_GET['days'] ) ? sanitize_text_field( wp_unslash( $_GET['days'] ) ) : 30 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing a period changes nothing.
		$source = Analytics::source( isset( $_GET['source'] ) && is_string( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing a source changes nothing.
		$totals = $this->analytics->totals( $days, $source );
		$daily  = $this->analytics->daily( $days, $source );

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="csearch-period">';
		echo '<input type="hidden" name="page" value="' . esc_attr( AdminPage::SLUG ) . '"><input type="hidden" name="tab" value="analytics">';
		echo '<label for="csearch-days">' . esc_html__( 'Period', 'creative-search' ) . '</label> <select id="csearch-days" name="days">';
		foreach ( Analytics::PERIODS as $period ) {
			/* translators: %d: number of days */
			echo '<option value="' . esc_attr( (string) $period ) . '"' . selected( $period, $days, false ) . '>' . esc_html( sprintf( _n( 'Last %d day', 'Last %d days', $period, 'creative-search' ), $period ) ) . '</option>';
		}
		echo '</select> <label for="csearch-source">' . esc_html__( 'Searches from', 'creative-search' ) . '</label> <select id="csearch-source" name="source">';
		echo '<option value=""' . selected( '', $source, false ) . '>' . esc_html__( 'The shop: suggestions and results page', 'creative-search' ) . '</option>';
		foreach ( Analytics::sources() as $choice ) {
			echo '<option value="' . esc_attr( $choice ) . '"' . selected( $choice, $source, false ) . '>' . esc_html( self::source( $choice ) ) . '</option>';
		}
		echo '</select> ' . get_submit_button( __( 'Show', 'creative-search' ), 'secondary', 'submit', false, array( 'id' => 'csearch-show-period' ) ) . '</form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress builds the button escaped.

		echo '<h2>' . esc_html__( 'Summary', 'creative-search' ) . '</h2><ul class="csearch-totals">';
		$ctr  = $totals['searches'] > 0 ? $totals['clicks'] / $totals['searches'] : 0.0;
		$rows = array(
			array( __( 'Searches', 'creative-search' ), number_format_i18n( $totals['searches'] ) ),
			array( __( 'Searches with no results', 'creative-search' ), number_format_i18n( $totals['zero'] ) ),
			array( __( 'Suggestions clicked', 'creative-search' ), number_format_i18n( $totals['clicks'] ) ),
			array( __( 'Click-through', 'creative-search' ), self::percent( $ctr ) ),
		);
		// Every source's searches, the shop's two always (D-163).
		foreach ( $totals['sources'] as $from => $count ) {
			$rows[] = array( self::source( $from ), number_format_i18n( $count ) );
		}
		foreach ( $rows as $total ) {
			echo '<li><span class="csearch-total-value">' . esc_html( $total[1] ) . '</span> <span class="csearch-total-label">' . esc_html( $total[0] ) . '</span></li>';
		}
		echo '</ul>';

		echo '<h2 id="csearch-daily-title">' . esc_html__( 'Searches a day', 'creative-search' ) . '</h2>';
		echo self::chart( $daily ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from numbers and escaped labels.
		echo '<details class="csearch-daily-table"><summary>' . esc_html__( 'The numbers by day', 'creative-search' ) . '</summary><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Day', 'creative-search' ) . '</th><th scope="col">' . esc_html__( 'Searches', 'creative-search' ) . '</th><th scope="col">' . esc_html__( 'No results', 'creative-search' ) . '</th><th scope="col">' . esc_html__( 'Clicks', 'creative-search' ) . '</th></tr></thead><tbody>';
		foreach ( array_reverse( $daily, true ) as $day => $counts ) {
			echo '<tr><th scope="row">' . esc_html( $day ) . '</th><td>' . esc_html( number_format_i18n( $counts['searches'] ) ) . '</td><td>' . esc_html( number_format_i18n( $counts['zero'] ) ) . '</td><td>' . esc_html( number_format_i18n( $counts['clicks'] ) ) . '</td></tr>';
		}
		echo '</tbody></table></details>';

		echo '<h2>' . esc_html__( 'Top queries', 'creative-search' ) . '</h2>';
		$this->queries( $this->analytics->topQueries( $days, false, Analytics::TOP, 0, $source ), __( 'No searches in this period.', 'creative-search' ) );
		echo '<h2>' . esc_html__( 'Top queries with no results', 'creative-search' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'What shoppers wanted and could not find: a buying list, or a synonym to add.', 'creative-search' ) . '</p>';
		$this->queries( $this->analytics->topQueries( $days, true, Analytics::TOP, 0, $source ), __( 'Every search in this period found something.', 'creative-search' ) );

		echo '<h2>' . esc_html__( 'Most clicked suggestions', 'creative-search' ) . '</h2>';
		$clicked = $this->analytics->topClicked( $days );
		if ( array() === $clicked ) {
			echo '<p>' . esc_html__( 'No suggestion was clicked in this period.', 'creative-search' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Suggestion', 'creative-search' ) . '</th><th scope="col">' . esc_html__( 'Type', 'creative-search' ) . '</th><th scope="col">' . esc_html__( 'Clicks', 'creative-search' ) . '</th></tr></thead><tbody>';
			foreach ( $clicked as $row ) {
				$name = '' !== $row['url'] ? '<a href="' . esc_url( $row['url'] ) . '">' . esc_html( $row['name'] ) . '</a>' : esc_html( $row['name'] );
				echo '<tr><td>' . $name . '</td><td>' . esc_html( self::type( $row['type'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['clicks'] ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $name is escaped above.
			}
			echo '</tbody></table>';
		}

		echo '<h2>' . esc_html__( 'Export', 'creative-search' ) . '</h2>';
		echo AdminPage::formStart( Actions::ANALYTICS_EXPORT ) . '<input type="hidden" name="days" value="' . esc_attr( (string) $days ) . '"><p class="description">' . esc_html__( 'Every search of the period, one row each, the same columns as the log. Nothing in it identifies a shopper.', 'creative-search' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		submit_button( __( 'Export CSV', 'creative-search' ), 'secondary', 'submit', false );
		echo '</form>';

		$this->clear();
	}

	/**
	 * Clear analytics (D-139): a link to a confirmation that says what goes, then a button that deletes it. No
	 * script is needed, and nothing is deleted by one click.
	 */
	private function clear(): void {
		echo '<h2 id="csearch-clear">' . esc_html__( 'Clear analytics', 'creative-search' ) . '</h2>';
		$asked = isset( $_GET['clear'] ) && '1' === $_GET['clear']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- showing the question changes nothing; the deletion checks its nonce.
		if ( ! $asked ) {
			echo '<p class="description">' . esc_html__( 'Start the numbers again from nothing, such as on launch day, after testing.', 'creative-search' ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( AdminPage::url( 'analytics', array( 'clear' => '1' ) ) . '#csearch-clear' ) . '">' . esc_html__( 'Clear analytics…', 'creative-search' ) . '</a></p>';
			return;
		}
		$held = $this->analytics->held();
		echo '<div class="notice notice-warning inline csearch-confirm" role="group" aria-labelledby="csearch-clear"><p><strong>' . esc_html__( 'This deletes, for good:', 'creative-search' ) . '</strong></p><ul class="ul-disc">';
		/* translators: %s: number of searches */
		echo '<li>' . esc_html( sprintf( _n( '%s logged search, with what was clicked after it', 'all %s logged searches, with what was clicked after each', $held['searches'], 'creative-search' ), number_format_i18n( $held['searches'] ) ) ) . '</li>';
		/* translators: %s: number of days */
		echo '<li>' . esc_html( sprintf( _n( '%s day of daily counts behind the charts', '%s days of daily counts behind the charts', $held['days'], 'creative-search' ), number_format_i18n( $held['days'] ) ) ) . '</li>';
		echo '<li>' . esc_html__( 'the suggested searches, which are built from the log, until shoppers search again', 'creative-search' ) . '</li></ul>';
		echo '<p>' . esc_html__( 'Synonyms, redirects, pins and the index are not touched. Export a CSV first to keep a copy.', 'creative-search' ) . '</p>';
		echo AdminPage::formStart( Actions::ANALYTICS_CLEAR ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo '<p>';
		submit_button( __( 'Clear analytics', 'creative-search' ), 'delete', 'submit', false, array( 'id' => 'csearch-clear-confirm' ) );
		echo ' <a class="button button-secondary" href="' . esc_url( AdminPage::url( 'analytics' ) ) . '">' . esc_html__( 'Cancel', 'creative-search' ) . '</a></p></form></div>';
	}

	/**
	 * A table of queries.
	 *
	 * @param array<int, array{query: string, lang: string, searches: int, results: int, clicks: int, ctr: float}> $rows  The queries.
	 * @param string                                                                                               $none  What to say when there are none.
	 */
	private function queries( array $rows, string $none ): void {
		if ( array() === $rows ) {
			echo '<p>' . esc_html( $none ) . '</p>';
			return;
		}
		echo '<table class="widefat striped csearch-queries"><thead><tr><th scope="col">' . esc_html__( 'Query', 'creative-search' ) . '</th><th scope="col">' . esc_html__( 'Language', 'creative-search' ) . '</th><th scope="col">' . esc_html__( 'Searches', 'creative-search' ) . '</th><th scope="col">' . esc_html__( 'Results', 'creative-search' ) . '</th><th scope="col">' . esc_html__( 'Click-through', 'creative-search' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'creative-search' ) . '</span></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$explain = AdminPage::url(
				'tools',
				array(
					'test' => rawurlencode( $row['query'] ),
					'lang' => $row['lang'],
				)
			) . '#csearch-test';
			echo '<tr><td>' . esc_html( $row['query'] ) . '</td><td>' . esc_html( $row['lang'] ) . '</td><td>' . esc_html( number_format_i18n( $row['searches'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['results'] ) ) . '</td><td>' . esc_html( self::percent( $row['ctr'] ) ) . '</td>';
			/* translators: %s: a search query */
			echo '<td><a href="' . esc_url( $explain ) . '" aria-label="' . esc_attr( sprintf( __( 'Explain “%s”', 'creative-search' ), $row['query'] ) ) . '">' . esc_html__( 'Explain', 'creative-search' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Searches a day as an SVG bar chart; the same numbers are in the table below it.
	 *
	 * @param array<string, array{searches: int, zero: int, clicks: int}> $daily By day.
	 */
	public static function chart( array $daily ): string {
		$max    = max( 1, ...array_map( static fn( array $c ): int => $c['searches'], array_merge( array( array( 'searches' => 0 ) ), array_values( $daily ) ) ) );
		$count  = max( 1, count( $daily ) );
		$width  = 720;
		$height = 160;
		$bar    = $width / $count;
		$total  = array_sum( array_map( static fn( array $c ): int => $c['searches'], $daily ) );
		$label  = sprintf(
			/* translators: 1: number of searches, 2: number of days, 3: the busiest day's searches */
			_n( '%1$s search over %2$s days; the busiest day had %3$s.', '%1$s searches over %2$s days; the busiest day had %3$s.', $total, 'creative-search' ),
			number_format_i18n( $total ),
			number_format_i18n( $count ),
			number_format_i18n( 1 === $max && 0 === $total ? 0 : $max )
		);
		$svg = '<svg class="csearch-chart" viewBox="0 0 ' . $width . ' ' . ( $height + 2 ) . '" preserveAspectRatio="none" role="img" aria-labelledby="csearch-daily-title csearch-chart-desc"><desc id="csearch-chart-desc">' . esc_html( $label ) . '</desc>';
		$i   = 0;
		foreach ( $daily as $day => $counts ) {
			$h    = (int) round( $counts['searches'] / $max * $height );
			$zh   = (int) round( $counts['zero'] / $max * $height );
			$x    = round( $i * $bar + $bar * 0.1, 2 );
			$w    = round( $bar * 0.8, 2 );
			$svg .= '<g><title>' . esc_html(
				sprintf(
					/* translators: 1: a day, 2: searches, 3: searches with no results */
					_n( '%1$s: %2$s search, %3$s with no results', '%1$s: %2$s searches, %3$s with no results', $counts['searches'], 'creative-search' ),
					(string) $day,
					number_format_i18n( $counts['searches'] ),
					number_format_i18n( $counts['zero'] )
				)
			) . '</title>';
			$svg .= '<rect class="csearch-bar" x="' . $x . '" y="' . ( $height - $h ) . '" width="' . $w . '" height="' . $h . '"></rect>';
			$svg .= '<rect class="csearch-bar-zero" x="' . $x . '" y="' . ( $height - $zh ) . '" width="' . $w . '" height="' . $zh . '"></rect></g>';
			++$i;
		}
		$svg .= '<line class="csearch-axis" x1="0" y1="' . ( $height + 1 ) . '" x2="' . $width . '" y2="' . ( $height + 1 ) . '"></line></svg>';
		$svg .= '<p class="csearch-chart-key"><span class="csearch-key csearch-key-all"></span> ' . esc_html__( 'Searches', 'creative-search' ) . ' <span class="csearch-key csearch-key-zero"></span> ' . esc_html__( 'With no results', 'creative-search' ) . '</p>';
		return $svg;
	}

	/**
	 * Where searches came from, as the screen names it (D-163).
	 *
	 * @param string $source A log source.
	 */
	public static function source( string $source ): string {
		$names = array(
			'suggest' => __( 'From the suggestions', 'creative-search' ),
			'page'    => __( 'From the results page', 'creative-search' ),
			'builder' => __( 'From a product builder', 'creative-search' ),
			'other'   => __( 'From other plugins', 'creative-search' ),
		);
		/* translators: %s: the short name another plugin logs its searches under, such as kiosk */
		return $names[ $source ] ?? sprintf( __( 'From “%s”', 'creative-search' ), $source );
	}

	/**
	 * A share as a percentage.
	 *
	 * @param float $share 0 to 1.
	 */
	private static function percent( float $share ): string {
		/* translators: %s: a percentage number */
		return sprintf( __( '%s%%', 'creative-search' ), number_format_i18n( $share * 100, 1 ) );
	}

	/**
	 * A document type's name.
	 *
	 * @param string $type product, category, tag or brand.
	 */
	public static function type( string $type ): string {
		$names = array(
			'product'  => __( 'Product', 'creative-search' ),
			'category' => __( 'Category', 'creative-search' ),
			'tag'      => __( 'Tag', 'creative-search' ),
			'brand'    => __( 'Brand', 'creative-search' ),
		);
		return $names[ $type ] ?? Cast::str( $type );
	}
}
