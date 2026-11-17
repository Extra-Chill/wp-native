<?php
/**
 * Opaque authentication challenge continuation storage and consumption.
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

$GLOBALS['wp_native_auth_challenge_policies'] = array();

/**
 * Register one targeted authentication challenge policy.
 *
 * @param string   $policy_id         Stable generic identifier.
 * @param callable $challenge_callback Returns null, WP_Error, or a public challenge array.
 * @param callable $verify_callback    Returns true to complete login, a public
 *                                    challenge array to re-challenge without
 *                                    consuming the continuation (e.g. a code
 *                                    was re-sent), WP_Error to reject with a
 *                                    policy error, or false to reject.
 * @return bool True when registered; false for invalid or duplicate IDs.
 */
function wp_native_auth_register_challenge_policy( string $policy_id, callable $challenge_callback, callable $verify_callback ): bool {
	if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]{0,190}$/', $policy_id ) || isset( $GLOBALS['wp_native_auth_challenge_policies'][ $policy_id ] ) ) {
		return false;
	}

	$GLOBALS['wp_native_auth_challenge_policies'][ $policy_id ] = array(
		'challenge' => $challenge_callback,
		'verify'    => $verify_callback,
	);

	return true;
}

/** Unregister a challenge policy, primarily for request-scoped integrations. */
function wp_native_auth_unregister_challenge_policy( string $policy_id ): void {
	unset( $GLOBALS['wp_native_auth_challenge_policies'][ $policy_id ] );
}

/**
 * Load registered policies once per request and return them in registration order.
 *
 * @return array<string,array{challenge:callable,verify:callable}>
 */
function wp_native_auth_get_challenge_policies(): array {
	static $loaded = false;

	if ( ! $loaded ) {
		$loaded = true;
		do_action( 'wp_native_auth_register_challenge_policies' );
	}

	return $GLOBALS['wp_native_auth_challenge_policies'];
}

/**
 * Run the registered challenge policies for an already authenticated user.
 *
 * The password login runs this loop itself. Integrations that verify the
 * primary credential some other way (an identity provider token, a magic
 * link) call it before minting a session, so no primary authenticator can
 * skip a policy that would challenge the same account.
 *
 * Policies run in registration order and the first public challenge wins.
 * The pending login, including `$login_options`, is bound to an opaque
 * continuation redeemed through wp_native_auth_continue_login(), which
 * completes the login with wp_native_auth_complete_login().
 *
 * @param WP_User             $user          User whose primary credential was already verified.
 * @param string              $device_id     Device UUID the session will belong to.
 * @param array<string,mixed> $login_options Options for wp_native_auth_complete_login(): device_name, remember, set_cookie.
 *                                           Extra keys are stored with the pending login and ignored by the core flow.
 * @param array<string,mixed> $context       Login context passed to policies and to the continuation phase. Keys
 *                                           device_id, client_id, blog_id, reason, and phase default when absent.
 * @return array<string,mixed>|WP_Error|null Challenge response, WP_Error from a policy or storage, or null when no
 *                                           policy challenged and the caller may complete the login.
 */
function wp_native_auth_challenge_login( WP_User $user, string $device_id, array $login_options, array $context = array() ) {
	if ( ! wp_native_auth_is_uuid_v4( $device_id ) ) {
		return new WP_Error( 'invalid_device_id', __( 'device_id must be a UUID v4.', 'wp-native-auth' ), array( 'status' => 400 ) );
	}

	$login_context = array_merge(
		array(
			'device_id' => $device_id,
			'client_id' => wp_native_auth_get_client_id(),
			'blog_id'   => get_current_blog_id(),
			'reason'    => 'login',
			'phase'     => 'primary',
		),
		$context
	);

	$pending_request = array(
		'pending_request_id' => wp_generate_uuid4(),
		'user_id'            => (int) $user->ID,
		'blog_id'            => get_current_blog_id(),
		'device_id'          => $device_id,
		'client_id'          => wp_native_auth_get_client_id(),
		'login_context'      => $login_context,
		'login_options'      => $login_options,
	);

	$challenge_context                       = $login_context;
	$challenge_context['pending_request_id'] = $pending_request['pending_request_id'];

	foreach ( wp_native_auth_get_challenge_policies() as $policy_id => $policy ) {
		$challenge = call_user_func( $policy['challenge'], $user, $challenge_context );
		if ( is_wp_error( $challenge ) ) {
			return $challenge;
		}

		if ( null === $challenge ) {
			continue;
		}

		if ( 'array' !== gettype( $challenge ) || empty( $challenge['type'] ) || ! is_string( $challenge['type'] ) ) {
			return new WP_Error( 'invalid_challenge_contract', __( 'Authentication policy returned an invalid challenge.', 'wp-native-auth' ), array( 'status' => 500 ) );
		}

		$pending_request['policy_id'] = $policy_id;

		$continuation = wp_native_auth_create_continuation( $pending_request );
		if ( is_wp_error( $continuation ) ) {
			return $continuation;
		}

		return array(
			'challenge_required'      => true,
			'challenge_policy'        => $policy_id,
			'challenge'               => $challenge,
			'continuation_token'      => $continuation['token'],
			'continuation_expires_at' => gmdate( 'c', $continuation['expires_at'] ),
		);
	}

	return null;
}

/** Read the generic client identifier supplied by wp-native transports. */
function wp_native_auth_get_client_id(): string {
	$value = isset( $_SERVER['HTTP_WP_NATIVE_CLIENT'] ) ? wp_unslash( (string) $_SERVER['HTTP_WP_NATIVE_CLIENT'] ) : '';

	return substr( sanitize_text_field( $value ), 0, 191 );
}

/**
 * Create an HMAC for a pending request snapshot.
 *
 * @param array<string,mixed> $request Pending login request.
 */
function wp_native_auth_hash_pending_request( array $request ): string {
	$encoded = wp_json_encode( $request );

	return hash_hmac( 'sha256', is_string( $encoded ) ? $encoded : '', wp_salt( 'auth' ) );
}

/** Build a stable, non-reversible attempt identity for one account policy. */
function wp_native_auth_continuation_rate_limit_id( int $blog_id, int $user_id, string $policy_id ): string {
	return hash_hmac( 'sha256', $blog_id . '|' . $user_id . '|' . $policy_id, wp_salt( 'auth' ) );
}

/**
 * Persist or reissue the one pending login for an account policy.
 *
 * Reissuance rotates the bearer and request snapshot but preserves attempts,
 * creation time, and expiry. Password resubmission therefore cannot reset the
 * guess budget or extend its window.
 *
 * @param array<string,mixed> $request Pending login request.
 * @return array{token:string,expires_at:int,rate_limit_id:string}|WP_Error
 */
function wp_native_auth_create_continuation( array $request ) {
	global $wpdb;

	wp_native_auth_ensure_schema();

	$table         = wp_native_auth_continuations_table_name();
	$token         = wp_native_auth_generate_opaque_token();
	$token_hash    = hash( 'sha256', $token );
	$now           = time();
	$now_mysql     = wp_native_auth_mysql_gmt( $now );
	$rate_limit_id = wp_native_auth_continuation_rate_limit_id( (int) $request['blog_id'], (int) $request['user_id'], (string) $request['policy_id'] );
	$encoded       = wp_json_encode( $request );

	if ( ! is_string( $encoded ) ) {
		return new WP_Error( 'continuation_storage_failed', __( 'The pending login could not be stored.', 'wp-native-auth' ), array( 'status' => 500 ) );
	}

	$existing = $wpdb->get_row(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
			"SELECT id, attempts, claim_token, expires_at FROM {$table} WHERE rate_limit_hash = %s LIMIT 1",
			$rate_limit_id
		),
		ARRAY_A
	);

	if ( is_array( $existing ) && (string) $existing['expires_at'] < $now_mysql ) {
		$wpdb->delete( $table, array( 'id' => (int) $existing['id'] ), array( '%d' ) );
		$existing = null;
	}

	if ( is_array( $existing ) ) {
		if ( ! empty( $existing['claim_token'] ) ) {
			return new WP_Error( 'continuation_in_progress', __( 'Authentication completion is already in progress.', 'wp-native-auth' ), array( 'status' => 409 ) );
		}

		if ( (int) $existing['attempts'] >= WP_NATIVE_AUTH_CONTINUATION_MAX_ATTEMPTS ) {
			return new WP_Error( 'continuation_rate_limited', __( 'Too many authentication challenge attempts.', 'wp-native-auth' ), array( 'status' => 429 ) );
		}

		$updated = $wpdb->update(
			$table,
			array(
				'token_hash'   => $token_hash,
				'device_id'    => (string) $request['device_id'],
				'client_id'    => (string) $request['client_id'],
				'request_hash' => wp_native_auth_hash_pending_request( $request ),
				'request_data' => $encoded,
			),
			array( 'id' => (int) $existing['id'] ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'continuation_storage_failed', __( 'The pending login could not be stored.', 'wp-native-auth' ), array( 'status' => 500 ) );
		}

		return array(
			'token'         => $token,
			'expires_at'    => (int) strtotime( (string) $existing['expires_at'] . ' UTC' ),
			'rate_limit_id' => $rate_limit_id,
		);
	}

	$expires_at = $now + WP_NATIVE_AUTH_CONTINUATION_TTL;
	$inserted   = $wpdb->insert(
		$table,
		array(
			'token_hash'      => $token_hash,
			'user_id'         => (int) $request['user_id'],
			'blog_id'         => (int) $request['blog_id'],
			'device_id'       => (string) $request['device_id'],
			'client_id'       => (string) $request['client_id'],
			'policy_id'       => (string) $request['policy_id'],
			'rate_limit_hash' => $rate_limit_id,
			'request_hash'    => wp_native_auth_hash_pending_request( $request ),
			'request_data'    => $encoded,
			'attempts'        => 0,
			'claim_token'     => null,
			'claimed_at'      => null,
			'created_at'      => $now_mysql,
			'expires_at'      => wp_native_auth_mysql_gmt( $expires_at ),
		),
		array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
	);

	if ( false === $inserted ) {
		return new WP_Error( 'continuation_storage_failed', __( 'The pending login could not be stored.', 'wp-native-auth' ), array( 'status' => 500 ) );
	}

	return array(
		'token'         => $token,
		'expires_at'    => $expires_at,
		'rate_limit_id' => $rate_limit_id,
	);
}

/**
 * Verify and atomically claim a pending authentication challenge.
 *
 * @param string              $token              Opaque continuation bearer.
 * @param string              $device_id          Original device UUID.
 * @param array<string,mixed> $challenge_response Policy-specific public response fields.
 * @return array<string,mixed>|WP_Error Token pair + user on success.
 */
function wp_native_auth_continue_login( string $token, string $device_id, array $challenge_response ) {
	global $wpdb;

	wp_native_auth_ensure_schema();

	if ( '' === $token || strlen( $token ) > 512 ) {
		return new WP_Error( 'invalid_continuation', __( 'The authentication continuation is invalid.', 'wp-native-auth' ), array( 'status' => 400 ) );
	}

	if ( ! wp_native_auth_is_uuid_v4( $device_id ) ) {
		return new WP_Error( 'invalid_device_id', __( 'device_id must be a UUID v4.', 'wp-native-auth' ), array( 'status' => 400 ) );
	}

	$table       = wp_native_auth_continuations_table_name();
	$token_hash  = hash( 'sha256', $token );
	$client_id   = wp_native_auth_get_client_id();
	$blog_id     = get_current_blog_id();
	$now         = wp_native_auth_mysql_gmt( time() );
	$claim_token = hash( 'sha256', wp_native_auth_generate_opaque_token() );

	// Blog, client, device, expiry, claim state, and attempt budget are checked
	// atomically before any policy verifier can run.
	$updated = $wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
			"UPDATE {$table} SET attempts = attempts + 1, claim_token = %s, claimed_at = %s WHERE token_hash = %s AND blog_id = %d AND device_id = %s AND client_id = %s AND expires_at >= %s AND claim_token IS NULL AND attempts < %d",
			$claim_token,
			$now,
			$token_hash,
			$blog_id,
			$device_id,
			$client_id,
			$now,
			WP_NATIVE_AUTH_CONTINUATION_MAX_ATTEMPTS
		)
	);

	if ( 1 !== $updated ) {
		return wp_native_auth_continuation_error( $token_hash, $blog_id, $now );
	}

	$row = $wpdb->get_row(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
			"SELECT * FROM {$table} WHERE token_hash = %s AND blog_id = %d AND claim_token = %s LIMIT 1",
			$token_hash,
			$blog_id,
			$claim_token
		),
		ARRAY_A
	);

	$request       = is_array( $row ) ? json_decode( (string) $row['request_data'], true ) : null;
	$required_keys = array( 'pending_request_id', 'user_id', 'blog_id', 'device_id', 'client_id', 'policy_id', 'login_context', 'login_options' );
	if ( ! is_array( $row ) || ! is_array( $request ) || array_diff( $required_keys, array_keys( $request ) ) || ! hash_equals( (string) $row['request_hash'], wp_native_auth_hash_pending_request( $request ) ) ) {
		return new WP_Error( 'invalid_continuation', __( 'The authentication continuation is invalid.', 'wp-native-auth' ), array( 'status' => 401 ) );
	}

	$user = get_user_by( 'id', (int) $request['user_id'] );
	if (
		! ( $user instanceof WP_User )
		|| (int) $row['user_id'] !== (int) $user->ID
		|| (int) $request['blog_id'] !== $blog_id
		|| (string) $request['device_id'] !== $device_id
		|| (string) $request['client_id'] !== $client_id
		|| (string) $request['policy_id'] !== (string) $row['policy_id']
	) {
		return new WP_Error( 'invalid_continuation', __( 'The authentication continuation is invalid.', 'wp-native-auth' ), array( 'status' => 401 ) );
	}

	$policies  = wp_native_auth_get_challenge_policies();
	$policy_id = (string) $row['policy_id'];
	if ( ! isset( $policies[ $policy_id ] ) ) {
		wp_native_auth_release_continuation_claim( (int) $row['id'], $claim_token );
		return new WP_Error( 'challenge_policy_unavailable', __( 'The authentication policy is unavailable.', 'wp-native-auth' ), array( 'status' => 503 ) );
	}

	$context = array_merge(
		(array) $request['login_context'],
		array(
			'phase'              => 'continuation',
			'pending_request_id' => (string) $request['pending_request_id'],
			'policy_id'          => $policy_id,
			'rate_limit_id'      => (string) $row['rate_limit_hash'],
			'attempt'            => (int) $row['attempts'],
		)
	);

	$verified = call_user_func( $policies[ $policy_id ]['verify'], $user, $challenge_response, $context );
	if ( is_wp_error( $verified ) ) {
		wp_native_auth_release_continuation_claim( (int) $row['id'], $claim_token );
		return $verified;
	}

	// A re-challenge (for example a re-sent code) keeps the pending login
	// alive: the claim is released and the same continuation is returned with
	// the policy's fresh public descriptor. The attempt already counted, so a
	// re-challenge loop still exhausts the shared attempt budget.
	if ( is_array( $verified ) ) {
		wp_native_auth_release_continuation_claim( (int) $row['id'], $claim_token );
		return wp_native_auth_build_rechallenge_response( $policy_id, $verified, $token, $row );
	}

	if ( true !== $verified ) {
		wp_native_auth_release_continuation_claim( (int) $row['id'], $claim_token );
		return new WP_Error( 'challenge_rejected', __( 'The authentication challenge was not completed.', 'wp-native-auth' ), array( 'status' => 401 ) );
	}

	/**
	 * Revalidate policy-sensitive account state without rerunning pre-login side effects.
	 *
	 * @param null|WP_Error $blocked Null to continue or WP_Error to block.
	 * @param WP_User       $user    Bound user.
	 * @param array         $context Original login context plus continuation phase fields.
	 */
	$blocked = apply_filters( 'wp_native_auth_revalidate_login', null, $user, $context );
	if ( is_wp_error( $blocked ) ) {
		wp_native_auth_release_continuation_claim( (int) $row['id'], $claim_token );
		return $blocked;
	}

	$consumed_claim = hash( 'sha256', wp_native_auth_generate_opaque_token() );
	$consumed       = $wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
			"UPDATE {$table} SET claim_token = %s WHERE id = %d AND token_hash = %s AND blog_id = %d AND expires_at >= %s AND claim_token = %s",
			$consumed_claim,
			(int) $row['id'],
			$token_hash,
			$blog_id,
			wp_native_auth_mysql_gmt( time() ),
			$claim_token
		)
	);

	if ( 1 !== $consumed ) {
		wp_native_auth_release_continuation_claim( (int) $row['id'], $claim_token );
		return wp_native_auth_continuation_error( $token_hash, $blog_id, wp_native_auth_mysql_gmt( time() ) );
	}

	$login_options                     = (array) $request['login_options'];
	$login_options['challenge_policy'] = $policy_id;

	$result = wp_native_auth_complete_login( $user, $device_id, $login_options );
	if ( is_wp_error( $result ) ) {
		wp_native_auth_release_continuation_claim( (int) $row['id'], $consumed_claim );
		return $result;
	}

	// A failed delete leaves a permanently claimed row, which is non-replayable;
	// the already-persisted usable token pair is still returned to the client.
	$wpdb->delete(
		$table,
		array(
			'id'          => (int) $row['id'],
			'claim_token' => $consumed_claim,
		),
		array( '%d', '%s' )
	);

	return $result;
}

/**
 * Build the response for a verifier that re-issued its challenge.
 *
 * @param string              $policy_id Issuing policy.
 * @param array<mixed>        $challenge Public challenge descriptor from the verifier.
 * @param string              $token     Presented continuation bearer (still valid).
 * @param array<string,mixed> $row       Claimed continuation row.
 * @return array<string,mixed>|WP_Error
 */
function wp_native_auth_build_rechallenge_response( string $policy_id, array $challenge, string $token, array $row ) {
	if ( empty( $challenge['type'] ) || ! is_string( $challenge['type'] ) ) {
		return new WP_Error( 'invalid_challenge_contract', __( 'Authentication policy returned an invalid challenge.', 'wp-native-auth' ), array( 'status' => 500 ) );
	}

	// The attempt that produced this re-challenge may have been the last one;
	// handing back a continuation that can never be redeemed would only stall
	// the client, so report the exhausted budget instead.
	if ( (int) $row['attempts'] >= WP_NATIVE_AUTH_CONTINUATION_MAX_ATTEMPTS ) {
		return new WP_Error( 'continuation_rate_limited', __( 'Too many authentication challenge attempts.', 'wp-native-auth' ), array( 'status' => 429 ) );
	}

	return array(
		'challenge_required'      => true,
		'challenge_policy'        => $policy_id,
		'challenge'               => $challenge,
		'continuation_token'      => $token,
		'continuation_expires_at' => gmdate( 'c', (int) strtotime( (string) $row['expires_at'] . ' UTC' ) ),
	);
}

/** Return a non-oracular continuation error after an atomic match fails. */
function wp_native_auth_continuation_error( string $token_hash, int $blog_id, string $now ): WP_Error {
	global $wpdb;

	$table = wp_native_auth_continuations_table_name();
	$row   = $wpdb->get_row(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
			"SELECT blog_id, expires_at, attempts, claim_token FROM {$table} WHERE token_hash = %s LIMIT 1",
			$token_hash
		),
		ARRAY_A
	);

	if ( is_array( $row ) && (int) $row['blog_id'] === $blog_id ) {
		if ( (string) $row['expires_at'] < $now ) {
			return new WP_Error( 'continuation_expired', __( 'The authentication continuation has expired.', 'wp-native-auth' ), array( 'status' => 401 ) );
		}
		if ( (int) $row['attempts'] >= WP_NATIVE_AUTH_CONTINUATION_MAX_ATTEMPTS ) {
			return new WP_Error( 'continuation_rate_limited', __( 'Too many authentication challenge attempts.', 'wp-native-auth' ), array( 'status' => 429 ) );
		}
		if ( ! empty( $row['claim_token'] ) ) {
			return new WP_Error( 'continuation_in_progress', __( 'Authentication completion is already in progress.', 'wp-native-auth' ), array( 'status' => 409 ) );
		}
	}

	return new WP_Error( 'invalid_continuation', __( 'The authentication continuation is invalid.', 'wp-native-auth' ), array( 'status' => 401 ) );
}

/** Release a verifier/issuance claim after a recoverable failure. */
function wp_native_auth_release_continuation_claim( int $id, string $claim_token ): void {
	global $wpdb;

	$wpdb->update(
		wp_native_auth_continuations_table_name(),
		array(
			'claim_token' => null,
			'claimed_at'  => null,
		),
		array(
			'id'          => $id,
			'claim_token' => $claim_token,
		),
		array( '%s', '%s' ),
		array( '%d', '%s' )
	);
}

/** Delete expired continuation state in bounded batches. */
function wp_native_auth_cleanup_continuations( int $limit = 500 ): int {
	global $wpdb;

	$table = wp_native_auth_continuations_table_name();
	$limit = max( 1, min( 5000, $limit ) );

	return (int) $wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal table name.
			"DELETE FROM {$table} WHERE expires_at < %s ORDER BY id ASC LIMIT %d",
			wp_native_auth_mysql_gmt( time() ),
			$limit
		)
	);
}

/** Ensure hourly cleanup is scheduled. */
function wp_native_auth_schedule_continuation_cleanup(): void {
	if ( ! wp_next_scheduled( WP_NATIVE_AUTH_CONTINUATION_CLEANUP_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', WP_NATIVE_AUTH_CONTINUATION_CLEANUP_HOOK );
	}
}

/** Delete ephemeral pending authentication state when a user is deleted. */
function wp_native_auth_delete_user_continuations( int $user_id ): void {
	global $wpdb;

	$wpdb->delete( wp_native_auth_continuations_table_name(), array( 'user_id' => $user_id ), array( '%d' ) );
}
