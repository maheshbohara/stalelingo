<?php
/**
 * A stand-in for WP-CLI, so the command class can run inside the WordPress test suite.
 *
 * Records messages and formatted items; WP_CLI::error() throws CliError like the real
 * one halts. Loaded only when WP-CLI itself isn't.
 *
 * @package Stalelingo
 */

// phpcs:ignoreFile -- A test double of WP-CLI's own API: its names, signatures and file layout mirror WP-CLI, not this plugin's standards.

namespace Stalelingo\Tests\Integration\Support {

	/**
	 * Thrown by the stubbed WP_CLI::error().
	 */
	final class CliError extends \RuntimeException {
	}
}

namespace {

	if ( ! class_exists( 'WP_CLI' ) ) {
		/**
		 * WP-CLI stand-in.
		 */
		class WP_CLI {

			/**
			 * Messages: [ type, text ].
			 *
			 * @var list<array{string, string}>
			 */
			public static array $messages = array();

			/**
			 * Last call to format_items(): [ format, items, fields ].
			 *
			 * @var array{0: string, 1: list<array<string, mixed>>, 2: list<string>}|null
			 */
			public static ?array $formatted = null;

			public static function reset(): void {
				self::$messages  = array();
				self::$formatted = null;
			}

			public static function success( string $message ): void {
				self::$messages[] = array( 'success', $message );
			}

			public static function log( string $message ): void {
				self::$messages[] = array( 'log', $message );
			}

			public static function line( string $message = '' ): void {
				self::$messages[] = array( 'line', $message );
			}

			public static function warning( string $message ): void {
				self::$messages[] = array( 'warning', $message );
			}

			public static function error( string $message ): void {
				self::$messages[] = array( 'error', $message );
				throw new \Stalelingo\Tests\Integration\Support\CliError( $message );
			}

			public static function add_command( string $name, mixed $callable ): bool {
				return true;
			}
		}
	}
}

namespace WP_CLI\Utils {

	if ( ! function_exists( __NAMESPACE__ . '\\format_items' ) ) {
		function format_items( string $format, array $items, array $fields ): void {
			\WP_CLI::$formatted = array( $format, array_values( $items ), $fields );
		}

		function make_progress_bar( string $message, int $count ): object {
			return new class() {
				public function tick( int $increment = 1 ): void {
				}

				public function finish(): void {
				}
			};
		}
	}
}
