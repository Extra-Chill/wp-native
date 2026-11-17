<?php
/**
 * Authentication tables — installer + table-name helpers.
 *
 * The table is network-wide ({$wpdb->base_prefix}), shared across every blog
 * in a multisite install. Schema is the contract defined in SCHEMAS.md.
 *
 * This storage layer is generic and runs on vanilla WordPress.
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Schema version for the authentication tables.
 *
 * Bump this whenever the table shape changes so the lazy migration in
 * wp_native_auth_maybe_upgrade_schema() re-runs dbDelta on existing
 * installs without requiring a plugin reactivation.
 *
 * History:
 *   1 — initial shape (id, user_id, device_id, device_name,
 *       refresh_token_hash, created_at, last_used_at, expires_at,
 *       revoked_at).
 *   2 — refresh-token reuse detection (#55): adds token_family and
 *       prev_token_hash columns.
 *   3 — authentication challenge continuations (#63): adds the opaque
 *       login continuations table.
 *   4 — binds continuations to blog and policy, adds stable rate-limit
 *       identity and atomic issuance claims.
 *   5 — generic OAuth 2.1 authorization server (#80): adds oauth_client_id
 *       and resource columns plus a refresh_token_hash index to the refresh
 *       tokens table, and adds the OAuth clients and authorization codes
 *       tables.
 *   6 — device authorization grant (#91): adds the OAuth device codes
 *       table. Purely additive — a new table, no change to any existing
 *       one, so installs that never use the device grant are unaffected.
 *   7 — push token registration (#118): adds nullable push_token and
 *       push_provider columns (plus a push_token index) to the refresh
 *       tokens table so a push destination is bound to the existing
 *       per-device session row. Purely additive — no second registry.
 *   8 — per-session challenge verification (#120): adds nullable
 *       challenge_policy and challenge_verified_at columns to the refresh
 *       tokens table so a device session records which authentication
 *       challenge policy it passed and when it last (re-)verified it.
 *       Purely additive — existing sessions read as never verified.
 */
const WP_NATIVE_AUTH_SCHEMA_VERSION = 8;

/**
 * Network option key storing the installed schema version.
 *
 * Uses a *site* option (get_site_option) so it tracks the network-wide
 * table, mirroring base_prefix table placement on multisite.
 */
const WP_NATIVE_AUTH_SCHEMA_VERSION_OPTION = 'wp_native_auth_schema_version';

/**
 * Returns the fully-qualified refresh tokens table name.
 *
 * Uses base_prefix so the table lives at the network level on multisite,
 * not per-blog. SCHEMAS.md mandates this.
 */
function wp_native_auth_refresh_tokens_table_name(): string {
	global $wpdb;

	return $wpdb->base_prefix . 'wp_native_auth_refresh_tokens';
}

/**
 * Returns the network-wide login continuations table name.
 */
function wp_native_auth_continuations_table_name(): string {
	global $wpdb;

	return $wpdb->base_prefix . 'wp_native_auth_login_continuations';
}

/**
 * Returns the network-wide OAuth client registrations table name.
 */
function wp_native_auth_oauth_clients_table_name(): string {
	global $wpdb;

	return $wpdb->base_prefix . 'wp_native_auth_oauth_clients';
}

/**
 * Returns the network-wide OAuth authorization codes table name.
 */
function wp_native_auth_oauth_codes_table_name(): string {
	global $wpdb;

	return $wpdb->base_prefix . 'wp_native_auth_oauth_authorization_codes';
}

/**
 * Returns the network-wide OAuth device codes table name.
 */
function wp_native_auth_oauth_device_codes_table_name(): string {
	global $wpdb;

	return $wpdb->base_prefix . 'wp_native_auth_oauth_device_codes';
}

/**
 * Install (or upgrade) the refresh tokens table via dbDelta().
 *
 * Called on plugin activation. Idempotent — dbDelta diffs the existing
 * schema and only applies necessary changes.
 *
 * Schema MUST match SCHEMAS.md exactly. Do not change column order, types,
 * or index names without updating the contract.
 *
 * The migration is purely additive and backward-compatible:
 *   - `token_family` and `prev_token_hash` are added NULL-able, so existing
 *     rows survive untouched and their current refresh tokens keep working.
 *   - `oauth_client_id` and `resource` are added NULL-able; NULL means the
 *     row is a native app session, not an OAuth grant.
 *   - `push_token` and `push_provider` are added NULL-able; NULL means the
 *     device session has no registered push destination.
 *   - `challenge_policy` and `challenge_verified_at` are added NULL-able;
 *     NULL means the session never passed an authentication challenge.
 *   - dbDelta() only emits ALTER TABLE ADD COLUMN for the missing columns;
 *     it never drops or rewrites existing data.
 * After dbDelta runs, legacy rows missing a token_family are backfilled
 * with a fresh UUID each (see wp_native_auth_backfill_token_family()).
 */
function wp_native_auth_install_refresh_tokens_table(): void {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table_name      = wp_native_auth_refresh_tokens_table_name();
	$continuations   = wp_native_auth_continuations_table_name();
	$oauth_clients   = wp_native_auth_oauth_clients_table_name();
	$oauth_codes     = wp_native_auth_oauth_codes_table_name();
	$device_codes    = wp_native_auth_oauth_device_codes_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table_name} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL,
		device_id char(36) NOT NULL,
		device_name varchar(191) NULL,
		refresh_token_hash char(64) NOT NULL,
		token_family char(36) NULL,
		prev_token_hash char(64) NULL,
		oauth_client_id varchar(255) NULL,
		resource varchar(255) NULL,
		push_token varchar(255) NULL,
		push_provider varchar(32) NULL,
		challenge_policy varchar(191) NULL,
		challenge_verified_at datetime NULL,
		created_at datetime NOT NULL,
		last_used_at datetime NULL,
		expires_at datetime NOT NULL,
		revoked_at datetime NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY user_device (user_id, device_id),
		KEY user_id (user_id),
		KEY token_family (token_family),
		KEY refresh_token_hash (refresh_token_hash),
		KEY prev_token_hash (prev_token_hash),
		KEY push_token (push_token(191)),
		KEY expires_at (expires_at)
	) {$charset_collate};";

	dbDelta( $sql );

	$continuations_sql = "CREATE TABLE {$continuations} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		token_hash char(64) NOT NULL,
		user_id bigint(20) unsigned NOT NULL,
		blog_id bigint(20) unsigned NOT NULL,
		device_id char(36) NOT NULL,
		client_id varchar(191) NOT NULL DEFAULT '',
		policy_id varchar(191) NOT NULL,
		rate_limit_hash char(64) NOT NULL,
		request_hash char(64) NOT NULL,
		request_data longtext NOT NULL,
		attempts smallint(5) unsigned NOT NULL DEFAULT 0,
		claim_token char(64) NULL,
		claimed_at datetime NULL,
		created_at datetime NOT NULL,
		expires_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY token_hash (token_hash),
		UNIQUE KEY rate_limit_hash (rate_limit_hash),
		KEY user_id (user_id),
		KEY blog_id (blog_id),
		KEY expires_at (expires_at)
	) {$charset_collate};";

	dbDelta( $continuations_sql );

	$oauth_clients_sql = "CREATE TABLE {$oauth_clients} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		client_id varchar(255) NOT NULL,
		client_name varchar(191) NULL,
		client_uri varchar(255) NULL,
		redirect_uris text NOT NULL,
		token_endpoint_auth_method varchar(32) NOT NULL DEFAULT 'none',
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY client_id (client_id),
		KEY created_at (created_at)
	) {$charset_collate};";

	dbDelta( $oauth_clients_sql );

	$oauth_codes_sql = "CREATE TABLE {$oauth_codes} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		code_hash char(64) NOT NULL,
		user_id bigint(20) unsigned NOT NULL,
		client_id varchar(255) NOT NULL,
		redirect_uri text NOT NULL,
		code_challenge varchar(128) NOT NULL,
		code_challenge_method varchar(16) NOT NULL DEFAULT 'S256',
		resource varchar(255) NULL,
		scope varchar(64) NULL,
		claim_token char(64) NULL,
		claimed_at datetime NULL,
		created_at datetime NOT NULL,
		expires_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY code_hash (code_hash),
		KEY user_id (user_id),
		KEY client_id (client_id),
		KEY expires_at (expires_at)
	) {$charset_collate};";

	dbDelta( $oauth_codes_sql );

	/*
	 * Device authorization grant (RFC 8628).
	 *
	 * Column naming note: the RFC calls these fields `interval` and the row
	 * state is conceptually `status`, but `INTERVAL` is a reserved word in
	 * MySQL. Both are prefixed (`poll_interval`, `grant_status`) so the
	 * schema needs no backtick-quoting anywhere it is referenced.
	 *
	 * `user_id` is NULL until someone approves: a pending request belongs to
	 * no user yet, which is exactly what distinguishes it from a code grant.
	 */
	$device_codes_sql = "CREATE TABLE {$device_codes} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		device_code_hash char(64) NOT NULL,
		user_code_hash char(64) NOT NULL,
		client_id varchar(255) NOT NULL,
		user_id bigint(20) unsigned NULL,
		grant_status varchar(16) NOT NULL DEFAULT 'pending',
		resource varchar(255) NULL,
		scope varchar(64) NULL,
		poll_interval smallint(5) unsigned NOT NULL DEFAULT 5,
		last_polled_at datetime NULL,
		claim_token char(64) NULL,
		claimed_at datetime NULL,
		created_at datetime NOT NULL,
		expires_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY device_code_hash (device_code_hash),
		UNIQUE KEY user_code_hash (user_code_hash),
		KEY user_id (user_id),
		KEY client_id (client_id),
		KEY expires_at (expires_at)
	) {$charset_collate};";

	dbDelta( $device_codes_sql );

	wp_native_auth_backfill_token_family();

	update_site_option( WP_NATIVE_AUTH_SCHEMA_VERSION_OPTION, WP_NATIVE_AUTH_SCHEMA_VERSION );
}

/**
 * Backfill `token_family` for any legacy rows that predate the column.
 *
 * Each legacy row gets its own fresh UUID v4 family id. A pre-migration
 * row has no `prev_token_hash`, so reuse of its pre-migration token cannot
 * be detected until it rotates once post-migration — acceptable, and it
 * never logs the user out. Idempotent: only touches rows where
 * `token_family IS NULL`.
 */
function wp_native_auth_backfill_token_family(): void {
	global $wpdb;

	$table_name = wp_native_auth_refresh_tokens_table_name();

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $table_name is a trusted internal constant; one-time migration read.
	$ids = $wpdb->get_col( "SELECT id FROM {$table_name} WHERE token_family IS NULL" );

	if ( empty( $ids ) ) {
		return;
	}

	foreach ( $ids as $id ) {
		$wpdb->update(
			$table_name,
			array( 'token_family' => wp_generate_uuid4() ),
			array( 'id' => (int) $id ),
			array( '%s' ),
			array( '%d' )
		);
	}
}

/**
 * Lazily run the schema migration when the installed version is behind.
 *
 * Called during early init and directly by public auth execution paths so
 * headless installs pick up new columns without a plugin reactivation.
 * Cheap no-op once the stored version matches the current schema constant.
 */
function wp_native_auth_maybe_upgrade_schema(): void {
	$installed = (int) get_site_option( WP_NATIVE_AUTH_SCHEMA_VERSION_OPTION, 0 );

	if ( $installed >= WP_NATIVE_AUTH_SCHEMA_VERSION ) {
		return;
	}

	wp_native_auth_install_refresh_tokens_table();
}

/**
 * Ensure public/headless requests can use the current schema before execution.
 */
function wp_native_auth_ensure_schema(): void {
	wp_native_auth_maybe_upgrade_schema();
}
