<?php
/**
 * A test double of WP-CLI's static API: records what a command prints.
 *
 * @package CreativeBranding\Search\Tests
 */

// phpcs:ignoreFile -- a test double named after the class it stands in for.

namespace {
	if ( ! class_exists( 'WP_CLI' ) ) {
		class WP_CLI {
			/** @var string[] */
			public static array $lines = array();
			/** @var array<int, array<string, mixed>> */
			public static array $items = array();
			public static string $format = '';

			public static function add_command( string $name, $callable, array $args = array() ): bool {
				return true;
			}
			public static function log( string $m ): void {
				self::$lines[] = 'log: ' . $m;
			}
			public static function line( string $m = '' ): void {
				self::$lines[] = 'line: ' . $m;
			}
			public static function success( string $m ): void {
				self::$lines[] = 'success: ' . $m;
			}
			/**
			 * Real WP-CLI asks, and exits on anything but yes; `--yes` skips the question.
			 *
			 * @param string               $q     The question.
			 * @param array<string, mixed> $assoc Flags.
			 */
			public static function confirm( string $q, array $assoc = array() ): void {
				self::$lines[] = 'confirm: ' . $q;
				if ( empty( $assoc['yes'] ) ) {
					throw new \RuntimeException( 'not confirmed' );
				}
			}
			public static function warning( string $m ): void {
				self::$lines[] = 'warning: ' . $m;
			}
			public static function error( $m, $exit = true ): never {
				throw new \RuntimeException( (string) $m );
			}
		}
	}
}

namespace WP_CLI\Utils {
	if ( ! function_exists( 'WP_CLI\Utils\format_items' ) ) {
		function format_items( string $format, array $items, $fields ): void {
			\WP_CLI::$format = $format;
			\WP_CLI::$items  = $items;
		}
	}
}
