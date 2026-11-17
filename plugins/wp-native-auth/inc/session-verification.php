<?php
/**
 * Per-session authentication challenge verification state.
 *
 * A device session (one refresh-token row) records which challenge policy
 * approved its login and when the session last passed that policy. Policies
 * that later re-verify the user (step-up) stamp the same fields, so
 * integrations can answer "did this bearer session pass the challenge, and
 * how recently?" without relying on cookie session metadata.
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Return the device session behind the current bearer-authenticated request.
 *
 * Only bearer access tokens identify a device session. Cookie-authenticated
 * requests, unknown tokens, and revoked or expired sessions return null.
 *
 * @return array{user_id:int, device_id:string, device_name:?string, oauth_client_id:?string, created_at:int, challenge_policy:?string, challenge_verified_at:?int}|null
 */
function wp_native_auth_get_current_session(): ?array {
	$bearer = wp_native_auth_get_current_bearer_session();
	if ( null === $bearer ) {
		return null;
	}

	return wp_native_auth_get_session( get_current_user_id(), $bearer['device_id'] );
}

/**
 * Return one active device session for a user.
 *
 * @param int    $user_id   Session owner.
 * @param string $device_id Device UUID.
 * @return array{user_id:int, device_id:string, device_name:?string, oauth_client_id:?string, created_at:int, challenge_policy:?string, challenge_verified_at:?int}|null
 */
function wp_native_auth_get_session( int $user_id, string $device_id ): ?array {
	global $wpdb;

	if ( $user_id <= 0 || ! wp_native_auth_is_uuid_v4( $device_id ) ) {
		return null;
	}

	wp_native_auth_ensure_schema();

	$row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT user_id, device_id, device_name, oauth_client_id, created_at, challenge_policy, challenge_verified_at
			 FROM %i
			 WHERE user_id = %d AND device_id = %s AND revoked_at IS NULL AND expires_at > %s
			 LIMIT 1',
			wp_native_auth_refresh_tokens_table_name(),
			$user_id,
			$device_id,
			wp_native_auth_mysql_gmt( time() )
		),
		ARRAY_A
	);

	if ( ! is_array( $row ) ) {
		return null;
	}

	$verified_ts = ! empty( $row['challenge_verified_at'] ) ? strtotime( (string) $row['challenge_verified_at'] . ' UTC' ) : false;
	$created_ts  = strtotime( (string) $row['created_at'] . ' UTC' );

	return array(
		'user_id'               => (int) $row['user_id'],
		'device_id'             => (string) $row['device_id'],
		'device_name'           => ! empty( $row['device_name'] ) ? (string) $row['device_name'] : null,
		'oauth_client_id'       => ! empty( $row['oauth_client_id'] ) ? (string) $row['oauth_client_id'] : null,
		'created_at'            => $created_ts ? (int) $created_ts : 0,
		'challenge_policy'      => ! empty( $row['challenge_policy'] ) ? (string) $row['challenge_policy'] : null,
		'challenge_verified_at' => $verified_ts ? (int) $verified_ts : null,
	);
}

/**
 * Record that the current bearer session just (re-)passed a challenge policy.
 *
 * @param string $policy_id Registered-style policy identifier.
 * @return bool True when the current session row was stamped.
 */
function wp_native_auth_mark_current_session_verified( string $policy_id ): bool {
	$bearer = wp_native_auth_get_current_bearer_session();
	if ( null === $bearer ) {
		return false;
	}

	return wp_native_auth_mark_session_verified( get_current_user_id(), $bearer['device_id'], $policy_id );
}

/**
 * Record that a device session just (re-)passed a challenge policy.
 *
 * @param int    $user_id   Session owner.
 * @param string $device_id Device UUID.
 * @param string $policy_id Policy identifier (same format as registration).
 * @return bool True when an active session row was stamped.
 */
function wp_native_auth_mark_session_verified( int $user_id, string $device_id, string $policy_id ): bool {
	global $wpdb;

	if ( $user_id <= 0 || ! wp_native_auth_is_uuid_v4( $device_id ) || ! preg_match( '/^[a-z0-9][a-z0-9._-]{0,190}$/', $policy_id ) ) {
		return false;
	}

	wp_native_auth_ensure_schema();

	$now     = time();
	$updated = $wpdb->query(
		$wpdb->prepare(
			'UPDATE %i SET challenge_policy = %s, challenge_verified_at = %s
			 WHERE user_id = %d AND device_id = %s AND revoked_at IS NULL AND expires_at > %s',
			wp_native_auth_refresh_tokens_table_name(),
			$policy_id,
			wp_native_auth_mysql_gmt( $now ),
			$user_id,
			$device_id,
			wp_native_auth_mysql_gmt( $now )
		)
	);

	if ( ! is_numeric( $updated ) ) {
		return false;
	}

	// MySQL reports zero affected rows when a same-second re-stamp leaves the
	// row unchanged, so confirm against the stored state instead of failing.
	if ( (int) $updated < 1 ) {
		$session = wp_native_auth_get_session( $user_id, $device_id );
		if ( null === $session || $policy_id !== $session['challenge_policy'] || (int) $session['challenge_verified_at'] < $now ) {
			return false;
		}
	}

	/**
	 * Fires after a device session records a challenge verification.
	 *
	 * @param int    $user_id   Session owner.
	 * @param string $device_id Device UUID.
	 * @param string $policy_id Policy that verified the session.
	 */
	do_action( 'wp_native_auth_session_verified', $user_id, $device_id, $policy_id );

	return true;
}
