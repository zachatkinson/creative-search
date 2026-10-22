<?php
/**
 * CSV in memory.
 *
 * @package CreativeBranding\Search
 */

declare( strict_types=1 );

namespace CreativeBranding\Search\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and reads CSV through `php://temp`, so no file is written (spec: Security). Cells that a spreadsheet
 * would run as a formula are prefixed with an apostrophe on the way out (CSV injection).
 */
final class Csv {

	/**
	 * Rows to CSV text.
	 *
	 * @param string[]                      $header Column names.
	 * @param array<int, array<int, mixed>> $rows   Rows, in header order.
	 */
	public static function build( array $header, array $rows ): string {
		$out = fopen( 'php://memory', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- memory, not a file.
		if ( false === $out ) {
			return '';
		}
		fputcsv( $out, $header, ',', '"', '' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv -- php://memory.
		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( array( self::class, 'cell' ), $row ), ',', '"', '' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv -- php://memory.
		}
		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- memory, not a file.
		return $csv;
	}

	/**
	 * Write one row to a stream, each cell made safe (the log export streams to the response).
	 *
	 * @param resource          $out The stream.
	 * @param array<int, mixed> $row The row.
	 * @param bool              $raw Whether the row is a header (no cell guard needed).
	 */
	public static function write( $out, array $row, bool $raw = false ): void {
		fputcsv( $out, $raw ? array_map( static fn( $v ): string => is_scalar( $v ) ? (string) $v : '', $row ) : array_map( array( self::class, 'cell' ), $row ), ',', '"', '' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv -- php://memory or php://output, never a file.
	}

	/**
	 * CSV text to rows keyed by the header's names; rows without the header's columns are skipped.
	 *
	 * @param string $csv The text.
	 * @return array<int, array<string, string>>
	 */
	public static function parse( string $csv ): array {
		$in = fopen( 'php://memory', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- memory, not a file.
		if ( false === $in ) {
			return array();
		}
		fwrite( $in, ltrim( $csv, "\xEF\xBB\xBF" ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite -- php://memory.
		rewind( $in );
		$header = fgetcsv( $in, null, ',', '"', '' );
		$rows   = array();
		if ( is_array( $header ) ) {
			$header = array_map( static fn( $h ): string => strtolower( trim( (string) $h ) ), $header );
			while ( false !== ( $line = fgetcsv( $in, null, ',', '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- the idiom for reading CSV.
				if ( count( $line ) !== count( $header ) ) {
					continue;
				}
				$rows[] = array_combine( $header, array_map( static fn( $v ): string => self::uncell( (string) $v ), $line ) );
			}
		}
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- memory, not a file.
		return $rows;
	}

	/**
	 * A cell safe to open in a spreadsheet.
	 *
	 * @param mixed $value The value.
	 */
	public static function cell( mixed $value ): string {
		$text = is_scalar( $value ) ? (string) $value : '';
		return '' !== $text && in_array( $text[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ? "'" . $text : $text;
	}

	/**
	 * A cell back from a spreadsheet: the apostrophe {@see Csv::cell()} added comes off.
	 *
	 * @param string $value The cell.
	 */
	public static function uncell( string $value ): string {
		return 1 === preg_match( "/^'[=+\\-@\\t\\r]/", $value ) ? substr( $value, 1 ) : $value;
	}
}
