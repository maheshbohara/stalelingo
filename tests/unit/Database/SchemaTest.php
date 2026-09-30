<?php
/**
 * Tests for Schema.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Tests\Unit\Database;

use Stalelingo\Database\Schema;
use Stalelingo\Tests\Unit\TestCase;

/**
 * @covers \Stalelingo\Database\Schema
 */
final class SchemaTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		$GLOBALS['wpdb'] = (object) array( 'prefix' => 'wp_7_' );
	}

	protected function tear_down(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tear_down();
	}

	public function test_table_names_use_the_site_prefix(): void {
		$this->assertSame(
			array( 'wp_7_stalelingo_sync', 'wp_7_stalelingo_snapshots', 'wp_7_stalelingo_events' ),
			Schema::tables()
		);
	}

	public function test_statements_are_dbdelta_compatible(): void {
		$statements = Schema::statements( 'DEFAULT CHARSET=utf8mb4' );

		$this->assertCount( 3, $statements );
		foreach ( $statements as $sql ) {
			$this->assertMatchesRegularExpression( '/^CREATE TABLE wp_7_stalelingo_\w+ \(\n/', $sql );
			// dbDelta() requires exactly two spaces between PRIMARY KEY and the column list.
			$this->assertStringContainsString( 'PRIMARY KEY  (id)', $sql );
			$this->assertStringEndsWith( ') DEFAULT CHARSET=utf8mb4;', $sql );
			$this->assertDoesNotMatchRegularExpression( '/\bIF NOT EXISTS\b/', $sql );
		}
	}

	public function test_sync_table_has_one_row_per_source_and_language(): void {
		$sync = Schema::statements( '' )[0];

		$this->assertStringContainsString( 'UNIQUE KEY source_lang (source_id,lang)', $sync );
		foreach ( array( 'translation_id', 'source_rev_id', 'field_hashes', 'synced_at', 'synced_by', 'status' ) as $column ) {
			$this->assertMatchesRegularExpression( "/^  {$column} /m", $sync );
		}
	}
}
