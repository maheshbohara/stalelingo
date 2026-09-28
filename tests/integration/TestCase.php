<?php
/**
 * Base class for integration tests.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Tests\Integration;

/**
 * Integration test base.
 */
abstract class TestCase extends \WP_UnitTestCase {

	/**
	 * Whether the test ran real DDL, which MySQL commits implicitly.
	 *
	 * @var bool
	 */
	private bool $ran_ddl = false;

	/**
	 * Lets DDL statements reach the real tables instead of the suite's temporary copies.
	 *
	 * DDL commits the open transaction, so state written by the test may survive the
	 * rollback. tear_down() rebuilds the schema and options once the rollback is done.
	 */
	protected function allow_real_ddl(): void {
		$this->ran_ddl = true;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	public function tear_down(): void {
		parent::tear_down();

		if ( $this->ran_ddl ) {
			$this->ran_ddl = false;
			remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
			remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
			delete_option( \TranslationDrift\Uninstaller::SETTINGS_OPTION );
			delete_option( \TranslationDrift\Database\Migrator::OPTION );
			wp_cache_flush();
			\TranslationDrift\Activator::activate();
		}
	}

	/**
	 * Whether a table exists in the test database.
	 */
	protected function table_exists( string $table ): bool {
		global $wpdb;

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}
