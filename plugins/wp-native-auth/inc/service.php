<?php
/**
 * Generic token lifecycle service for wp-native-auth.
 *
 * Issue, refresh-rotate, revoke, and validate refresh tokens — plus the
 * login and registration flows that wire those primitives together with
 * WordPress's native authentication.
 *
 * Site authentication and account policy are exposed through the extension
 * hooks documented in SCHEMAS.md so this generic layer does not know about
 * integration implementations.
 *
 * @package WPNative\Auth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/tokens.php';

/**
 * Issue (or update on conflict) a refresh token for a user/device pair.
 *
 * The `(user_id, device_id)` pair is unique in the schema. Re-issuing
 * for the same pair updates the existing row in place rather than
 * inserting a duplicate, which keeps the device's session monotonic.
 *
 * The refresh TTL is filterable via `wp_native_auth_refresh_token_ttl`.
 *
 * Optional OAuth bindings (`oauth_client_id`, `resource`) associate the
 * refresh session with a generic OAuth 2.1 client grant. NULL/absent
 * means a native app session. These bindings are preserved on rotation
 * (see wp_native_auth_refresh_tokens()).
 *
 * Optional challenge verification (`challenge_policy`) records that this
 * session was minted after an authentication challenge policy approved the
 * login; `challenge_verified_at` is stamped with the issue time. A fresh
 * issue without it clears any verification left by a superseded session on
 * the same device, so verification never outlives the login that earned it.
 *
 * @param int                $user_id    User ID.
 * @param string             $device_id  Device ID (UUID v4).
 * @param string             $device_name Optional human-readable device name.
 * @param array<string,mixed> $oauth_meta Optional. { @type string $oauth_client_id @type string $resource }
 * @param array<string,mixed> $session_meta Optional. { @type string $challenge_policy Policy that verified this login. }
 * @return array{token:string, expires_at:int}|WP_Error Plaintext token and Unix expiry.
 */
function wp_native_auth_issue_refresh_token( int $user_id, string $device_id, string $device_name = '', array $oauth_meta = array(), array $session_meta = array() ) {
	global $wpdb;

	$table_name = wp_native_auth_refresh_tokens_table_name();

	$ttl = (int) apply_filters(
		'wp_native_auth_refresh_token_ttl',
		WP_NATIVE_AUTH_REFRESH_TOKEN_TTL,
		$user_id
	);
	if ( $ttl <= 0 ) {
		$ttl = WP_NATIVE_AUTH_REFRESH_TOKEN_TTL;
	}

	$now_ts     = time();
	$now        = wp_native_auth_mysql_gmt( $now_ts );
	$expires_ts = $now_ts + $ttl;
	$expires_at = wp_native_auth_mysql_gmt( $expires_ts );

	$refresh_token = wp_native_auth_generate_opaque_token();
	$token_hash    = wp_native_auth_hash_refresh_token( $refresh_token );

	// A login/register issue starts a fresh token family for this device
	// session. The family id is preserved across subsequent rotations (see
	// wp_native_auth_refresh_tokens()) so a reuse anywhere in the lineage
	// can revoke the whole family. A brand-new login also clears any
	// prev_token_hash from a superseded session — there is no "previous"
	// token to replay against a freshly issued one.
	$token_family = wp_generate_uuid4();

	$existing_id = $wpdb->get_var(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is a trusted internal constant.
			"SELECT id FROM {$table_name} WHERE user_id = %d AND device_id = %s LIMIT 1",
			$user_id,
			$device_id
		)
	);

	$data = array(
		'user_id'            => $user_id,
		'device_id'          => $device_id,
		'device_name'        => '' !== $device_name ? $device_name : null,
		'refresh_token_hash' => $token_hash,
		'token_family'       => $token_family,
		'prev_token_hash'    => null,
		'oauth_client_id'    => isset( $oauth_meta['oauth_client_id'] ) && '' !== (string) $oauth_meta['oauth_client_id']
			? (string) $oauth_meta['oauth_client_id']
			: null,
		'resource'           => isset( $oauth_meta['resource'] ) && '' !== (string) $oauth_meta['resource']
			? (string) $oauth_meta['resource']
			: null,
		'last_used_at'       => $now,
		'expires_at'         => $expires_at,
		'revoked_at'         => null,
	);

	$challenge_policy = isset( $session_meta['challenge_policy'] ) ? (string) $session_meta['challenge_policy'] : '';

	$data['challenge_policy']      = '' !== $challenge_policy ? $challenge_policy : null;
	$data['challenge_verified_at'] = '' !== $challenge_policy ? $now : null;

	if ( $existing_id ) {
		$persisted = $wpdb->update(
			$table_name,
			$data,
			array( 'id' => (int) $existing_id ),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	} else {
		$data['created_at'] = $now;
		$persisted          = $wpdb->insert(
			$table_name,
			$data,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	if ( false === $persisted ) {
		return new WP_Error( 'refresh_token_storage_failed', __( 'The refresh token could not be stored.', 'wp-native-auth' ), array( 'status' => 500 ) );
	}

	return array(
		'token'      => $refresh_token,
		'expires_at' => $expires_ts,
	);
}

/**
 * Build the public User payload for login/refresh/me responses.
 *
 * The shape is fixed by SCHEMAS.md (id, username, display_name, email,
 * avatar_url, roles, registered_at). Consumers can decorate the array via
 * the `wp_native_auth_user_payload` filter.
 *
 * @param WP_User             $user    WordPress user.
 * @param array<string,mixed> $context Optional. Additional context (e.g. device_id, reason).
 * @return array<string,mixed> User payload.
 */
function wp_native_auth_build_user_payload( WP_User $user, array $context = array() ): array {
	$registered_ts = strtotime( (string) $user->user_registered . ' UTC' );
	$registered_at = $registered_ts ? gmdate( 'c', (int) $registered_ts ) : '';

	$roles = array_values( array_map( 'strval', $user->roles ) );

	$payload = array(
		'id'            => (int) $user->ID,
		'username'      => (string) $user->user_login,
		'display_name'  => (string) $user->display_name,
		'email'         => (string) $user->user_email,
		'avatar_url'    => (string) get_avatar_url( $user->ID, array( 'size' => 96 ) ),
		'roles'         => $roles,
		'registered_at' => $registered_at,
	);

	/**
	 * Filter the User payload returned in auth responses.
	 *
	 * @param array   $payload User array (id, username, display_name, email, avatar_url, roles, registered_at).
	 * @param WP_User $user    Underlying WP_User object.
	 * @param array   $context Additional context (device_id, reason, etc.).
	 */
	/** @var mixed $filtered */
	$filtered = apply_filters( 'wp_native_auth_user_payload', $payload, $user, $context );

	return is_array( $filtered ) ? $filtered : $payload;
}

/**
 * Authenticate a user by credentials and issue a fresh token pair.
 *
 * Flow:
 *   1. Validate `device_id` is a UUID v4.
 *   2. Apply `wp_native_auth_pre_authenticate` filter — short-circuits
 *      before WP touches the password (request policy, IP block, etc.).
 *   3. Call `wp_authenticate()` for username/email + password check.
 *   4. Apply `wp_native_auth_pre_login` filter on the resolved user —
 *      lets extensions block specific accounts.
 *   5. Let authentication policy require a resumable challenge.
 *   6. Optionally set the WP browsing cookie for same-origin web clients.
 *   7. Issue an access token + refresh token bound to the device.
 *   8. Fire `wp_native_auth_after_login`.
 *
 * @param string               $identifier Username or email.
 * @param string               $password   Plaintext password.
 * @param string               $device_id  Device ID (UUID v4).
 * @param array<string,mixed>  $options    Optional. {
 *     @type string $device_name Human-readable device name.
 *     @type bool   $remember    Extend cookie session length when set_cookie is true.
 *     @type bool   $set_cookie  Also set a WordPress browsing cookie.
 * }
 * @return array<string,mixed>|WP_Error Token pair + User on success, WP_Error on failure.
 */
function wp_native_auth_login_with_tokens( string $identifier, string $password, string $device_id, array $options = array() ) {
	$device_name = isset( $options['device_name'] ) ? (string) $options['device_name'] : '';
	$remember    = ! empty( $options['remember'] );
	$set_cookie  = ! empty( $options['set_cookie'] );

	if ( '' === $device_id || ! wp_native_auth_is_uuid_v4( $device_id ) ) {
		return new WP_Error(
			'invalid_device_id',
			__( 'device_id must be a UUID v4.', 'wp-native-auth' ),
			array( 'status' => 400 )
		);
	}

	$context = array(
		'identifier' => $identifier,
		'device_id'  => $device_id,
		'client_id'  => wp_native_auth_get_client_id(),
		'blog_id'    => get_current_blog_id(),
		'reason'     => 'login',
		'phase'      => 'primary',
	);

	/**
	 * Filter to short-circuit authentication before password check.
	 *
	 * Return a WP_Error to block (e.g. request policy failure, IP block).
	 *
	 * @param null|WP_Error $blocked    Null to continue, WP_Error to block.
	 * @param string        $identifier Username or email being authenticated.
	 * @param array         $context    Login context.
	 */
	$pre_auth = apply_filters( 'wp_native_auth_pre_authenticate', null, $identifier, $context );
	if ( is_wp_error( $pre_auth ) ) {
		return $pre_auth;
	}

	$user = wp_authenticate( $identifier, $password );
	if ( is_wp_error( $user ) ) {
		return new WP_Error(
			'invalid_credentials',
			__( 'Invalid username or password.', 'wp-native-auth' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Filter to block a resolved user post-authentication.
	 *
	 * Return a WP_Error to block (e.g. user is suspended, not a member of
	 * a required blog). Null/non-WP_Error continues the flow.
	 *
	 * @param null|WP_Error $blocked Null to continue, WP_Error to block.
	 * @param WP_User       $user    Authenticated user.
	 * @param array         $context Login context.
	 */
	$pre_login = apply_filters( 'wp_native_auth_pre_login', null, $user, $context );
	if ( is_wp_error( $pre_login ) ) {
		return $pre_login;
	}

	$login_options = array(
		'device_name' => $device_name,
		'remember'    => $remember,
		'set_cookie'  => $set_cookie,
	);

	$challenge = wp_native_auth_challenge_login( $user, $device_id, $login_options, $context );
	if ( null !== $challenge ) {
		return $challenge;
	}

	return wp_native_auth_complete_login( $user, $device_id, $login_options );
}

/**
 * Mint the session for an already authenticated and policy-approved user.
 *
 * When `$options['challenge_policy']` is set (the continuation path passes the
 * policy whose verifier approved the login), the device session records that
 * policy and the verification time. See wp_native_auth_get_current_session().
 *
 * @param WP_User             $user      Authenticated user.
 * @param string              $device_id Device UUID.
 * @param array<string,mixed> $options   Bound pending login options.
 * @return array<string,mixed>|WP_Error
 */
function wp_native_auth_complete_login( WP_User $user, string $device_id, array $options ) {
	global $wpdb;

	$device_name = isset( $options['device_name'] ) ? (string) $options['device_name'] : '';
	$remember    = ! empty( $options['remember'] );
	$set_cookie  = ! empty( $options['set_cookie'] );

	if ( $set_cookie && headers_sent() ) {
		return new WP_Error( 'cookie_issuance_failed', __( 'The browsing session could not be established.', 'wp-native-auth' ), array( 'status' => 500 ) );
	}

	// Keep refresh persistence rollback-capable until access-token storage has
	// also succeeded. Cookie side effects happen only after both token stores.
	if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
		return new WP_Error( 'session_storage_failed', __( 'The session could not be stored.', 'wp-native-auth' ), array( 'status' => 500 ) );
	}
	$session_meta = array();
	if ( ! empty( $options['challenge_policy'] ) ) {
		$session_meta['challenge_policy'] = (string) $options['challenge_policy'];
	}

	$refresh = wp_native_auth_issue_refresh_token( (int) $user->ID, $device_id, $device_name, array(), $session_meta );
	if ( is_wp_error( $refresh ) ) {
		$wpdb->query( 'ROLLBACK' );
		return $refresh;
	}

	$access = wp_native_auth_generate_access_token( (int) $user->ID, $device_id );
	if ( is_wp_error( $access ) ) {
		$wpdb->query( 'ROLLBACK' );
		return $access;
	}

	if ( false === $wpdb->query( 'COMMIT' ) ) {
		$wpdb->query( 'ROLLBACK' );
		wp_native_auth_revoke_access_token( $access['token'] );
		return new WP_Error( 'session_storage_failed', __( 'The session could not be stored.', 'wp-native-auth' ), array( 'status' => 500 ) );
	}

	if ( $set_cookie ) {
		wp_set_current_user( $user->ID, $user->user_login );
		wp_set_auth_cookie( $user->ID, $remember );
		do_action( 'wp_login', $user->user_login, $user );
	}

	$token_pair = array(
		'access_token'       => $access['token'],
		'access_expires_at'  => gmdate( 'c', (int) $access['expires_at'] ),
		'refresh_token'      => $refresh['token'],
		'refresh_expires_at' => gmdate( 'c', (int) $refresh['expires_at'] ),
	);

	$payload_context = array(
		'device_id' => $device_id,
		'reason'    => 'login',
	);

	$response = array_merge(
		$token_pair,
		array( 'user' => wp_native_auth_build_user_payload( $user, $payload_context ) )
	);

	/**
	 * Fires after a successful token-based login.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $device_id  Device ID.
	 * @param array  $token_pair Token pair (access + refresh + ISO expiries).
	 */
	do_action( 'wp_native_auth_after_login', (int) $user->ID, $device_id, $token_pair );

	return $response;
}

/**
 * Revoke an entire token family (all rows sharing a `token_family`).
 *
 * Used on reuse detection: when a stolen/replayed refresh token is
 * presented, the whole device-session lineage is burned so the attacker
 * AND the legitimate client are forced to re-authenticate (RFC 9700
 * §4.14.2). Given the `(user_id, device_id)` UNIQUE KEY there is normally
 * exactly one row per family, but revoking by family is correct regardless
 * of how many rows share it.
 *
 * @param string $token_family Family UUID. Empty string is a no-op.
 * @return int Rows revoked.
 */
function wp_native_auth_revoke_token_family( string $token_family ): int {
	global $wpdb;

	if ( '' === $token_family ) {
		return 0;
	}

	$table_name = wp_native_auth_refresh_tokens_table_name();
	$now        = wp_native_auth_mysql_gmt( time() );

	$revoked = $wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is a trusted internal constant.
			"UPDATE {$table_name} SET revoked_at = %s WHERE token_family = %s AND revoked_at IS NULL",
			$now,
			$token_family
		)
	);

	// A revoked family must not keep a deliverable push destination.
	wp_native_auth_clear_token_family_push_tokens( $token_family, 'family_revoked' );

	return is_numeric( $revoked ) ? (int) $revoked : 0;
}

/**
 * Rotate a refresh token: validate the supplied token, invalidate it, and
 * issue a fresh access + refresh pair.
 *
 * Security (RFC 9700 §4.14.2):
 *   - **Atomic rotation** — the hash swap is a single conditional UPDATE
 *     matching the OLD hash, so two concurrent refreshes of the same token
 *     cannot both mint a pair (InnoDB makes the single-statement update
 *     atomic). The loser sees 0 affected rows and is treated as reuse.
 *   - **Reuse detection** — the immediately-superseded hash is retained in
 *     `prev_token_hash`. A replayed just-rotated token matches that column
 *     and triggers FULL FAMILY REVOCATION plus a distinct
 *     `refresh_token_reused` error, instead of silently succeeding or
 *     returning a generic invalid-token error.
 *
 * Refresh rotation also enforces a 5-second per-device rate limit to
 * prevent runaway client retry loops from generating churn.
 *
 * @param string $refresh_token Plaintext refresh token.
 * @param string $device_id     Device ID (UUID v4).
 * @return array<string,mixed>|WP_Error Token pair + User on success.
 */
function wp_native_auth_refresh_tokens( string $refresh_token, string $device_id ) {
	global $wpdb;

	if ( '' === $refresh_token ) {
		return new WP_Error(
			'invalid_refresh_token',
			__( 'Refresh token is required.', 'wp-native-auth' ),
			array( 'status' => 401 )
		);
	}

	if ( '' === $device_id || ! wp_native_auth_is_uuid_v4( $device_id ) ) {
		return new WP_Error(
			'invalid_device_id',
			__( 'device_id must be a UUID v4.', 'wp-native-auth' ),
			array( 'status' => 400 )
		);
	}

	// Per-device rate limit. Prevents a buggy client retry loop from
	// rotating tokens dozens of times per second.
	$rate_key     = 'wp_native_auth_refresh_' . md5( $device_id );
	$last_refresh = get_transient( $rate_key );
	if ( $last_refresh && ( time() - (int) $last_refresh ) < WP_NATIVE_AUTH_REFRESH_RATE_LIMIT_SECONDS ) {
		return new WP_Error(
			'rate_limited',
			__( 'Please wait before refreshing tokens.', 'wp-native-auth' ),
			array( 'status' => 429 )
		);
	}
	set_transient( $rate_key, time(), MINUTE_IN_SECONDS );

	$table_name = wp_native_auth_refresh_tokens_table_name();
	$token_hash = wp_native_auth_hash_refresh_token( $refresh_token );

	// Look up the device's session row. The (user_id, device_id) UNIQUE KEY
	// means there is at most one row per device, so we fetch by device_id
	// and compare hashes in PHP — this lets us distinguish a replay of the
	// just-rotated token (prev_token_hash) from a wholly invalid token.
	$session = $wpdb->get_row(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is a trusted internal constant.
			"SELECT * FROM {$table_name} WHERE device_id = %s LIMIT 1",
			$device_id
		),
		ARRAY_A
	);

	if ( empty( $session ) ) {
		return new WP_Error(
			'invalid_refresh_token',
			__( 'Invalid refresh token.', 'wp-native-auth' ),
			array( 'status' => 401 )
		);
	}

	$current_hash = (string) $session['refresh_token_hash'];
	$prev_hash    = isset( $session['prev_token_hash'] ) ? (string) $session['prev_token_hash'] : '';
	$token_family = isset( $session['token_family'] ) ? (string) $session['token_family'] : '';

	// Constant-time hash comparison. The presented token must match either
	// the current hash (→ rotate) or the previous hash (→ reuse). Anything
	// else is a plain invalid token.
	$matches_current = hash_equals( $current_hash, $token_hash );
	$matches_prev    = ( '' !== $prev_hash && hash_equals( $prev_hash, $token_hash ) );

	// REUSE DETECTION: a replay of the immediately-superseded token. Burn
	// the entire family and surface a distinct error so consumers can alarm.
	if ( ! $matches_current && $matches_prev ) {
		wp_native_auth_revoke_token_family( $token_family );

		/**
		 * Fires when a rotated (superseded) refresh token is replayed.
		 *
		 * Signals a likely stolen-token replay (RFC 9700 §4.14.2). The
		 * entire token family has already been revoked when this fires.
		 * Consumers (e.g. a security-logging plugin) can alarm, email the
		 * user, or rate-limit the source. This plugin stays generic and
		 * knows nothing about who consumes the signal.
		 *
		 * @param int    $user_id      User whose token family was revoked.
		 * @param string $device_id    Device ID the replay targeted.
		 * @param string $token_family Revoked family UUID.
		 */
		do_action( 'wp_native_auth_refresh_token_reuse_detected', (int) $session['user_id'], $device_id, $token_family );

		return new WP_Error(
			'refresh_token_reused',
			__( 'Refresh token reuse detected. All sessions for this device have been revoked.', 'wp-native-auth' ),
			array( 'status' => 401 )
		);
	}

	// Neither current nor previous hash — a wholly invalid token.
	if ( ! $matches_current ) {
		return new WP_Error(
			'invalid_refresh_token',
			__( 'Invalid refresh token.', 'wp-native-auth' ),
			array( 'status' => 401 )
		);
	}

	if ( ! empty( $session['revoked_at'] ) ) {
		return new WP_Error(
			'invalid_refresh_token',
			__( 'Refresh token has been revoked.', 'wp-native-auth' ),
			array( 'status' => 401 )
		);
	}

	$now_ts        = time();
	$now           = wp_native_auth_mysql_gmt( $now_ts );
	$expires_at_ts = strtotime( (string) $session['expires_at'] . ' UTC' );

	if ( $expires_at_ts && $expires_at_ts < $now_ts ) {
		return new WP_Error(
			'refresh_token_expired',
			__( 'Refresh token has expired.', 'wp-native-auth' ),
			array( 'status' => 401 )
		);
	}

	$user_id = (int) $session['user_id'];
	$user    = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		return new WP_Error(
			'invalid_user',
			__( 'User not found.', 'wp-native-auth' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Run the same pre_login policy filter on refresh that login uses.
	 *
	 * Lets extensions revoke active sessions when a user is suspended /
	 * removed from a required blog mid-session.
	 */
	$context = array(
		'device_id' => $device_id,
		'reason'    => 'refresh',
	);
	$blocked = apply_filters( 'wp_native_auth_pre_login', null, $user, $context );
	if ( is_wp_error( $blocked ) ) {
		return $blocked;
	}

	$ttl = (int) apply_filters(
		'wp_native_auth_refresh_token_ttl',
		WP_NATIVE_AUTH_REFRESH_TOKEN_TTL,
		$user_id
	);
	if ( $ttl <= 0 ) {
		$ttl = WP_NATIVE_AUTH_REFRESH_TOKEN_TTL;
	}

	$new_refresh_token = wp_native_auth_generate_opaque_token();
	$new_token_hash    = wp_native_auth_hash_refresh_token( $new_refresh_token );
	$new_expires_ts    = $now_ts + $ttl;
	$new_expires_at    = wp_native_auth_mysql_gmt( $new_expires_ts );

	// Carry OAuth bindings (client association + RFC 8707 resource
	// audience) from the refresh session onto the new access token so the
	// binding survives every rotation. Native sessions carry no bindings.
	$access_meta = array();
	if ( ! empty( $session['oauth_client_id'] ) ) {
		$access_meta['client_id'] = (string) $session['oauth_client_id'];
	}
	if ( ! empty( $session['resource'] ) ) {
		$access_meta['resource'] = (string) $session['resource'];
	}

	$access = wp_native_auth_generate_access_token( $user_id, $device_id, $access_meta );
	if ( is_wp_error( $access ) ) {
		return $access;
	}

	// ATOMIC ROTATION: a single conditional UPDATE that only succeeds if the
	// row STILL holds the old hash. InnoDB makes this single statement
	// atomic, so of two concurrent refreshes of the same token exactly one
	// can win — the other affects 0 rows. The family is preserved across
	// the rotation; the just-superseded hash is parked in prev_token_hash
	// so a replay of it is detectable on the next call.
	$updated = $wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is a trusted internal constant.
			"UPDATE {$table_name}
			 SET refresh_token_hash = %s, prev_token_hash = %s, last_used_at = %s, expires_at = %s, revoked_at = NULL
			 WHERE id = %d AND refresh_token_hash = %s AND revoked_at IS NULL",
			$new_token_hash,
			$current_hash,
			$now,
			$new_expires_at,
			(int) $session['id'],
			$current_hash
		)
	);

	if ( false === $updated ) {
		wp_native_auth_revoke_access_token( $access['token'] );
		return new WP_Error(
			'refresh_update_failed',
			__( 'Failed to rotate refresh token.', 'wp-native-auth' ),
			array( 'status' => 500 )
		);
	}

	// Zero rows affected means the row no longer held the old hash (a
	// concurrent or replayed request already rotated it). That IS reuse of
	// this token — burn the family and surface the reuse error rather than
	// double-minting a second valid pair from the same old token.
	if ( 0 === (int) $updated ) {
		wp_native_auth_revoke_access_token( $access['token'] );
		wp_native_auth_revoke_token_family( $token_family );

		/** This action is documented above (reuse-detection branch). */
		do_action( 'wp_native_auth_refresh_token_reuse_detected', $user_id, $device_id, $token_family );

		return new WP_Error(
			'refresh_token_reused',
			__( 'Refresh token reuse detected. All sessions for this device have been revoked.', 'wp-native-auth' ),
			array( 'status' => 401 )
		);
	}

	$token_pair = array(
		'access_token'       => $access['token'],
		'access_expires_at'  => gmdate( 'c', (int) $access['expires_at'] ),
		'refresh_token'      => $new_refresh_token,
		'refresh_expires_at' => gmdate( 'c', (int) $new_expires_ts ),
	);

	$payload_context = array(
		'device_id' => $device_id,
		'reason'    => 'refresh',
	);

	$response = array_merge(
		$token_pair,
		array( 'user' => wp_native_auth_build_user_payload( $user, $payload_context ) )
	);

	/**
	 * Fires after a successful refresh-token rotation.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $device_id  Device ID.
	 * @param array  $token_pair Token pair.
	 */
	do_action( 'wp_native_auth_after_refresh', $user_id, $device_id, $token_pair );

	return $response;
}

/**
 * Look up a refresh-token session row by the presented token's hash.
 *
 * The v5 schema indexes `refresh_token_hash`, so this is an index seek.
 * Matches both the CURRENT hash and the immediately-superseded
 * `prev_token_hash` — a replayed just-rotated token must resolve to the
 * same session so downstream reuse detection can classify it. Used by
 * consumers that cannot know the device_id up front (the generic OAuth
 * endpoints identify sessions by token + client binding) and MUST be
 * followed by a row-ownership check before the row is acted on.
 *
 * @param string $refresh_token Plaintext refresh token.
 * @return array<string,mixed>|null Session row (ARRAY_A) or null when unknown.
 */
function wp_native_auth_find_refresh_session_by_token( string $refresh_token ): ?array {
	global $wpdb;

	if ( '' === $refresh_token ) {
		return null;
	}

	$table_name = wp_native_auth_refresh_tokens_table_name();
	$token_hash = wp_native_auth_hash_refresh_token( $refresh_token );

	$row = $wpdb->get_row(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is a trusted internal constant.
			"SELECT * FROM {$table_name} WHERE refresh_token_hash = %s OR prev_token_hash = %s LIMIT 1",
			$token_hash,
			$token_hash
		),
		ARRAY_A
	);

	return is_array( $row ) ? $row : null;
}

/**
 * Revoke the refresh token for a user/device pair.
 *
 * Used by `auth.logout` (current device) and `auth.revoke-session`
 * (remote sign-out of another device). Idempotent — revoking an
 * already-revoked or non-existent session returns false but does not error.
 *
 * @param int    $user_id   User ID.
 * @param string $device_id Device ID (UUID v4).
 * @return bool True if a row was revoked, false otherwise.
 */
function wp_native_auth_revoke_refresh_token( int $user_id, string $device_id ): bool {
	global $wpdb;

	$table_name = wp_native_auth_refresh_tokens_table_name();
	$now        = wp_native_auth_mysql_gmt( time() );

	$updated = $wpdb->update(
		$table_name,
		array( 'revoked_at' => $now ),
		array(
			'user_id'   => $user_id,
			'device_id' => $device_id,
		),
		array( '%s' ),
		array( '%d', '%s' )
	);

	$revoked = ( false !== $updated && $updated > 0 );

	// A signed-out device must not keep a deliverable push destination.
	// Cleared even when the row was already revoked, so no stale token stays.
	wp_native_auth_clear_push_token( $user_id, $device_id, 'logout' );

	if ( $revoked ) {
		/**
		 * Fires after a refresh token is revoked (logout / remote sign-out).
		 *
		 * @param int    $user_id   User ID.
		 * @param string $device_id Device ID whose session was revoked.
		 */
		do_action( 'wp_native_auth_after_logout', $user_id, $device_id );
	}

	return $revoked;
}

/**
 * Revoke every active refresh session for a user.
 *
 * This is the user-wide counterpart to per-device revocation. The single
 * conditional UPDATE is atomic across all devices and token families for the
 * user, and repeated calls are safe: an already-fully-revoked user returns 0.
 * Existing access tokens are not affected and remain valid until their short
 * site-transient TTL expires; revoked refresh tokens cannot mint replacements.
 *
 * @param int $user_id User ID.
 * @return int|WP_Error Number of rows revoked, or an error on storage failure.
 */
function wp_native_auth_revoke_user_refresh_tokens( int $user_id ) {
	global $wpdb;

	if ( $user_id <= 0 ) {
		return new WP_Error(
			'invalid_user_id',
			__( 'A valid user ID is required to revoke refresh sessions.', 'wp-native-auth' )
		);
	}

	$table_name = wp_native_auth_refresh_tokens_table_name();
	$now        = wp_native_auth_mysql_gmt( time() );
	$updated    = $wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is a trusted internal constant.
			"UPDATE {$table_name} SET revoked_at = %s WHERE user_id = %d AND revoked_at IS NULL",
			$now,
			$user_id
		)
	);

	if ( false === $updated ) {
		$error = new WP_Error(
			'refresh_session_revocation_failed',
			__( 'Failed to revoke refresh sessions.', 'wp-native-auth' ),
			array( 'user_id' => $user_id )
		);

		/**
		 * Fires when user-wide refresh-session revocation fails.
		 *
		 * @param int      $user_id User whose sessions could not be revoked.
		 * @param WP_Error $error   Storage failure.
		 */
		do_action( 'wp_native_auth_user_refresh_session_revocation_failed', $user_id, $error );

		return $error;
	}

	$revoked = (int) $updated;

	// No revoked session keeps a deliverable push destination.
	wp_native_auth_clear_user_push_tokens( $user_id, 'sessions_revoked' );

	/**
	 * Fires after user-wide refresh-session revocation succeeds.
	 *
	 * Fires with zero rows for an idempotent no-op, allowing consumers to
	 * distinguish successful completion from a storage failure.
	 *
	 * @param int $user_id User whose sessions were revoked.
	 * @param int $revoked Number of rows revoked.
	 */
	do_action( 'wp_native_auth_user_refresh_sessions_revoked', $user_id, $revoked );

	return $revoked;
}

/**
 * Revoke native refresh sessions after WordPress sets a password.
 *
 * The canonical wp_set_password lifecycle covers direct password writes,
 * authenticated wp_update_user() changes, and reset_password().
 *
 * @param string $_password Plaintext password just set. Unused.
 * @param int    $user_id   User whose password changed.
 */
function wp_native_auth_revoke_refresh_sessions_on_password_change( string $_password, int $user_id ): void {
	wp_native_auth_revoke_user_refresh_tokens( $user_id );
}

/**
 * List active device sessions for a user.
 *
 * Returns one row per device that has a non-revoked, non-expired refresh
 * token. The shape matches the `auth.sessions` output schema in
 * SCHEMAS.md exactly.
 *
 * OAuth-bound sessions surface the client binding stored on the row:
 * `oauth_client_id` is the client the grant was issued to and
 * `oauth_client_name` is the human-readable label recorded at grant
 * creation (the client name, or the client id when unnamed). Both are
 * null for native app sessions.
 *
 * @param int    $user_id           User ID.
 * @param string $current_device_id Optional. The device making the request,
 *                                  so the matching row gets `current = true`.
 * @return array<int,array<string,mixed>> List of session rows.
 */
function wp_native_auth_list_user_sessions( int $user_id, string $current_device_id = '' ): array {
	global $wpdb;

	$table_name = wp_native_auth_refresh_tokens_table_name();
	$now        = wp_native_auth_mysql_gmt( time() );

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT device_id, device_name, oauth_client_id, created_at, last_used_at, expires_at
			 FROM %i
			 WHERE user_id = %d
			   AND revoked_at IS NULL
			   AND expires_at > %s
			 ORDER BY last_used_at DESC, created_at DESC',
			$table_name,
			$user_id,
			$now
		),
		ARRAY_A
	);

	if ( ! is_array( $rows ) ) {
		return array();
	}

	$sessions = array();
	foreach ( $rows as $row ) {
		$device_id       = (string) $row['device_id'];
		$created_ts      = strtotime( (string) $row['created_at'] . ' UTC' );
		$expires_ts      = strtotime( (string) $row['expires_at'] . ' UTC' );
		$last_used_ts    = ! empty( $row['last_used_at'] )
			? strtotime( (string) $row['last_used_at'] . ' UTC' )
			: false;
		$oauth_client_id = ! empty( $row['oauth_client_id'] )
			? (string) $row['oauth_client_id']
			: null;

		$sessions[] = array(
			'device_id'         => $device_id,
			'device_name'       => null !== $row['device_name'] && '' !== $row['device_name']
				? (string) $row['device_name']
				: null,
			'created_at'        => $created_ts ? gmdate( 'c', (int) $created_ts ) : '',
			'last_used_at'      => $last_used_ts ? gmdate( 'c', (int) $last_used_ts ) : null,
			'expires_at'        => $expires_ts ? gmdate( 'c', (int) $expires_ts ) : '',
			'current'           => ( '' !== $current_device_id && $device_id === $current_device_id ),
			// The grant path labels OAuth sessions with the client name
			// (or client id fallback) in the device_name column, so that
			// label IS the client name for OAuth-bound rows. Native rows
			// carry a user-facing device name instead and surface null.
			'oauth_client_id'   => $oauth_client_id,
			'oauth_client_name' => null !== $oauth_client_id && null !== $row['device_name'] && '' !== $row['device_name']
				? (string) $row['device_name']
				: null,
		);
	}

	return $sessions;
}

/**
 * Register a new user by email + password and issue a fresh token pair.
 *
 * Flow:
 *   1. Validate email via `is_email()`.
 *   2. Validate password length >= 8.
 *   3. Validate password === password_confirm.
 *   4. Validate `device_id` is a UUID v4.
 *   5. Apply `wp_native_auth_pre_authenticate` filter — short-circuits
 *      before any user creation (CAPTCHA, IP block, etc.).
 *   6. Check `email_exists()` → return generic registration_failed
 *      (prevents email enumeration).
 *   7. Generate a deterministic username via `wp_hash()`.
 *   8. Apply `wp_native_auth_pre_register` filter — lets consumers
 *      adjust the username, set initial meta, or abort.
 *   9. Call `wp_create_user()` for the actual account creation.
 *  10. Apply `wp_native_auth_pre_login` filter — same post-user policy
 *      as login (user blocking, membership checks, etc.).
 *  11. Issue access + refresh tokens bound to the device.
 *  12. Fire `wp_native_auth_after_register`.
 *
 * @param string               $email            Email address.
 * @param string               $password         Plaintext password.
 * @param string               $password_confirm Must match $password.
 * @param string               $device_id        Device ID (UUID v4).
 * @param array<string,mixed>  $options          Optional. {
 *     @type string $device_name Human-readable device name.
 * }
 * @return array<string,mixed>|WP_Error Token pair + User on success, WP_Error on failure.
 */
function wp_native_auth_register_with_tokens( string $email, string $password, string $password_confirm, string $device_id, array $options = array() ) {
	$device_name = isset( $options['device_name'] ) ? (string) $options['device_name'] : '';

	// 1. Validate email format.
	if ( '' === $email || ! is_email( $email ) ) {
		return new WP_Error(
			'invalid_email',
			__( 'Please provide a valid email address.', 'wp-native-auth' ),
			array( 'status' => 400 )
		);
	}

	// 2. Validate password length.
	if ( strlen( $password ) < 8 ) {
		return new WP_Error(
			'password_too_short',
			__( 'Password must be at least 8 characters.', 'wp-native-auth' ),
			array( 'status' => 400 )
		);
	}

	// 3. Validate password confirmation.
	if ( $password !== $password_confirm ) {
		return new WP_Error(
			'password_mismatch',
			__( 'Passwords do not match.', 'wp-native-auth' ),
			array( 'status' => 400 )
		);
	}

	// 4. Validate device_id.
	if ( '' === $device_id || ! wp_native_auth_is_uuid_v4( $device_id ) ) {
		return new WP_Error(
			'invalid_device_id',
			__( 'device_id must be a UUID v4.', 'wp-native-auth' ),
			array( 'status' => 400 )
		);
	}

	// 5. Pre-authenticate filter (CAPTCHA, IP block, etc.).
	$context = array(
		'email'     => $email,
		'device_id' => $device_id,
	);

	/** This filter is documented in wp_native_auth_login_with_tokens(). */
	$pre_auth = apply_filters( 'wp_native_auth_pre_authenticate', null, $email, $context );
	if ( is_wp_error( $pre_auth ) ) {
		return $pre_auth;
	}

	// 6. Check if email already exists — use a generic error to prevent enumeration.
	if ( email_exists( $email ) ) {
		return new WP_Error(
			'registration_failed',
			__( 'Registration could not be completed. Please try again.', 'wp-native-auth' ),
			array( 'status' => 400 )
		);
	}

	// 7. Generate a deterministic default username.
	$username = 'user' . substr( wp_hash( $email ), 0, 8 );

	// 8. Pre-register filter — consumers can adjust username, set initial meta, or abort.
	$registration_data = array(
		'email'    => $email,
		'password' => $password,
		'username' => $username,
	);

	/**
	 * Filter to modify registration data or abort registration.
	 *
	 * Return a WP_Error to abort. Return an array with modified
	 * registration data (e.g. different username) to continue.
	 * Null passes through unchanged.
	 *
	 * @param null|array|WP_Error $result            Null to continue, WP_Error to abort, array to override.
	 * @param array               $registration_data Registration data (email, password, username).
	 * @param array               $context           Registration context (email, device_id).
	 */
	$pre_register = apply_filters( 'wp_native_auth_pre_register', null, $registration_data, $context );

	if ( is_wp_error( $pre_register ) ) {
		return $pre_register;
	}

	// Allow consumers to override the username via the filter.
	if ( is_array( $pre_register ) && ! empty( $pre_register['username'] ) ) {
		$username = (string) $pre_register['username'];
	}

	// 9. Create the user.
	$user_id = wp_create_user( $username, $password, $email );
	if ( is_wp_error( $user_id ) ) {
		return new WP_Error(
			'registration_failed',
			__( 'Registration could not be completed. Please try again.', 'wp-native-auth' ),
			array( 'status' => 500 )
		);
	}

	$user = get_user_by( 'id', $user_id );
	if ( ! $user instanceof WP_User ) {
		return new WP_Error(
			'registration_failed',
			__( 'Registration could not be completed. Please try again.', 'wp-native-auth' ),
			array( 'status' => 500 )
		);
	}

	// 10. Pre-login filter — same post-user policy as login.
	$login_context = array(
		'device_id' => $device_id,
		'reason'    => 'register',
	);

	/** This filter is documented in wp_native_auth_login_with_tokens(). */
	$pre_login = apply_filters( 'wp_native_auth_pre_login', null, $user, $login_context );
	if ( is_wp_error( $pre_login ) ) {
		return $pre_login;
	}

	// 11. Issue tokens.
	$access  = wp_native_auth_generate_access_token( $user_id, $device_id );
	$refresh = wp_native_auth_issue_refresh_token( $user_id, $device_id, $device_name );
	if ( is_wp_error( $access ) ) {
		return $access;
	}
	if ( is_wp_error( $refresh ) ) {
		wp_native_auth_revoke_access_token( $access['token'] );
		return $refresh;
	}

	$token_pair = array(
		'access_token'       => $access['token'],
		'access_expires_at'  => gmdate( 'c', (int) $access['expires_at'] ),
		'refresh_token'      => $refresh['token'],
		'refresh_expires_at' => gmdate( 'c', (int) $refresh['expires_at'] ),
	);

	$payload_context = array(
		'device_id' => $device_id,
		'reason'    => 'register',
	);

	$response = array_merge(
		$token_pair,
		array( 'user' => wp_native_auth_build_user_payload( $user, $payload_context ) )
	);

	// 12. Fire after-register action.
	/**
	 * Fires after a successful user registration.
	 *
	 * @param int    $user_id    Newly created user ID.
	 * @param string $device_id  Device ID.
	 * @param array  $token_pair Token pair (access + refresh + ISO expiries).
	 */
	do_action( 'wp_native_auth_after_register', $user_id, $device_id, $token_pair );

	return $response;
}
