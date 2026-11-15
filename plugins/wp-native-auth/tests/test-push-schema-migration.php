<?php
/**
 * Tests for the schema v7 push-column migration (#118).
 *
 * @package WPNativeAuth\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/**
 * Schema v6 -> v7 migration (#118).
 *
 * ALTER TABLE implicitly commits the test transaction, so this class creates
 * no factory fixtures and removes its one committed session row explicitly.
 *
 * @group auth
 * @group push
 */
class Test_WP_Native_Auth_Push_Schema_Migration extends WP_UnitTestCase {

	private const LEGACY_USER   = 987654;
	private const LEGACY_DEVICE = '55555555-5555-4555-8555-555555555555';

	public function tear_down(): void {
		global $wpdb;

		$wpdb->delete( wp_native_auth_refresh_tokens_table_name(), array( 'device_id' => self::LEGACY_DEVICE ) );
		wp_native_auth_install_refresh_tokens_table();
		$wpdb->query( 'COMMIT' );

		parent::tear_down();
	}

	public function test_lazy_migration_adds_push_columns_to_a_v6_table_without_losing_sessions(): void {
		global $wpdb;

		$table = wp_native_auth_refresh_tokens_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted test table name.
		$wpdb->query( "ALTER TABLE {$table} DROP INDEX push_token, DROP COLUMN push_token, DROP COLUMN push_provider" );
		update_site_option( WP_NATIVE_AUTH_SCHEMA_VERSION_OPTION, 6 );
		$this->assertArrayNotHasKey( 'push_token', $this->columns() );

		$legacy = wp_native_auth_issue_refresh_token( self::LEGACY_USER, self::LEGACY_DEVICE );
		$this->assertIsArray( $legacy, 'A v6 table still issues sessions.' );

		wp_native_auth_maybe_upgrade_schema();

		$columns = $this->columns();
		$this->assertSame( 7, (int) get_site_option( WP_NATIVE_AUTH_SCHEMA_VERSION_OPTION ) );
		$this->assertArrayHasKey( 'push_token', $columns );
		$this->assertArrayHasKey( 'push_provider', $columns );
		$this->assertSame( 'YES', $columns['push_token']->Null );

		$sessions = wp_native_auth_list_user_sessions( self::LEGACY_USER );
		$this->assertCount( 1, $sessions, 'The migration must keep existing sessions.' );
		$this->assertSame( self::LEGACY_DEVICE, $sessions[0]['device_id'] );
	}

	/** @return array<string,object> Column descriptions keyed by name. */
	private function columns(): array {
		global $wpdb;

		$columns = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', wp_native_auth_refresh_tokens_table_name() ) ) as $column ) {
			$columns[ $column->Field ] = $column; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL column name.
		}

		return $columns;
	}
}
