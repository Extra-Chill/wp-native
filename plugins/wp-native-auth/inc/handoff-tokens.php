<?php
/**
 * Browser handoff token service.
 *
 * Generates and validates one-time tokens used to bootstrap a WordPress
 * cookie session in a real browser after app authentication.
 *
 * Token store: transient keyed by sha256(token), 60-second TTL, single-use.
 *
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Handoff token TTL in seconds.
 */
if ( ! defined( 'WP_NATIVE_AUTH_HANDOFF_TOKEN_TTL' ) ) {
	define( 'WP_NATIVE_AUTH_HANDOFF_TOKEN_TTL', 60 );
}

/**
 * Create a single-use browser handoff token for a user.
 *
 * Generates a random token, stores its SHA-256 hash as a transient key
 * with a 60-second TTL, and returns the plaintext token (returned to
 * the client exactly once).
 *
 * The token records the device session that minted it, so the receiver can
 * check that session's challenge state before it sets a cookie. When no
 * device is passed, the current bearer session is used if it belongs to the
 * user; a handoff minted any other way records no session.
 *
 * @param int         $user_id   User ID to bind the handoff to.
 * @param string|null $device_id Device UUID of the minting session, or null for the current bearer session.
 * @return string Plaintext handoff token.
 */
function wp_native_auth_create_handoff_token( int $user_id, ?string $device_id = null ): string {
	if ( null === $device_id ) {
		$device_id = wp_native_auth_current_handoff_device_id( $user_id );
	}

	$token      = wp_native_auth_generate_opaque_token();
	$token_hash = hash( 'sha256', $token );

	set_transient(
		'wp_native_auth_handoff_' . $token_hash,
		array(
			'user_id'    => $user_id,
			'device_id'  => null !== $device_id && wp_native_auth_is_uuid_v4( $device_id ) ? $device_id : null,
			'created_at' => time(),
		),
		WP_NATIVE_AUTH_HANDOFF_TOKEN_TTL
	);

	return $token;
}

/**
 * Device ID of the current bearer session when it belongs to a user.
 *
 * @param int $user_id User the session must belong to.
 * @return string|null
 */
function wp_native_auth_current_handoff_device_id( int $user_id ): ?string {
	if ( $user_id <= 0 || get_current_user_id() !== $user_id || ! function_exists( 'wp_native_auth_get_current_bearer_session' ) ) {
		return null;
	}

	$bearer = wp_native_auth_get_current_bearer_session();

	return null !== $bearer ? $bearer['device_id'] : null;
}

/**
 * Validate and consume a browser handoff token (single-use).
 *
 * Computes sha256 of the incoming token, looks up the transient, deletes
 * it immediately (single-use semantics), then checks the created_at
 * timestamp against the TTL.
 *
 * @param string $token Plaintext handoff token from the query param.
 * @return array{user_id:int, device_id:?string, created_at:int}|null Handoff on success, null on failure.
 */
function wp_native_auth_consume_handoff_token( string $token ): ?array {
	$token = trim( $token );
	if ( '' === $token ) {
		return null;
	}

	$token_hash    = hash( 'sha256', $token );
	$transient_key = 'wp_native_auth_handoff_' . $token_hash;
	$payload       = get_transient( $transient_key );

	// Delete immediately — single-use regardless of validity.
	delete_transient( $transient_key );

	if ( ! is_array( $payload ) || empty( $payload['user_id'] ) ) {
		return null;
	}

	// Double-check TTL (transient expiry is the primary guard, but belt-and-suspenders).
	if ( ! empty( $payload['created_at'] ) && ( time() - (int) $payload['created_at'] ) > WP_NATIVE_AUTH_HANDOFF_TOKEN_TTL ) {
		return null;
	}

	$device_id = isset( $payload['device_id'] ) && is_string( $payload['device_id'] ) && wp_native_auth_is_uuid_v4( $payload['device_id'] )
		? $payload['device_id']
		: null;

	return array(
		'user_id'    => (int) $payload['user_id'],
		'device_id'  => $device_id,
		'created_at' => isset( $payload['created_at'] ) ? (int) $payload['created_at'] : 0,
	);
}

/**
 * Validate and consume a browser handoff token (single-use).
 *
 * @param string $token Plaintext handoff token from the query param.
 * @return int|null User ID on success, null on failure.
 */
function wp_native_auth_validate_handoff_token( string $token ): ?int {
	$handoff = wp_native_auth_consume_handoff_token( $token );

	return null !== $handoff ? $handoff['user_id'] : null;
}

/**
 * Decide whether a consumed handoff may set a cookie for its user.
 *
 * Runs the registered challenge policies the way a login would: the first
 * policy that would challenge the user decides. When one does, the session
 * that minted the handoff must still be active and must have passed that
 * same policy (`challenge_verified_at`). A handoff never upgrades a session
 * that skipped a challenge into a cookie session.
 *
 * Challenge callbacks run with `reason` and `phase` set to `browser_handoff`.
 * This is a probe: a policy should report whether it would challenge, and
 * not deliver anything (such as a code) to the user.
 *
 * @param WP_User                                                 $user    User the handoff is for.
 * @param array{user_id:int, device_id:?string, created_at?:int} $handoff Consumed handoff.
 * @return true|WP_Error True when the cookie may be set.
 */
function wp_native_auth_check_browser_handoff( WP_User $user, array $handoff ) {
	$device_id = $handoff['device_id'] ?? null;
	$session   = null !== $device_id ? wp_native_auth_get_session( (int) $user->ID, $device_id ) : null;

	if ( null !== $device_id && null === $session ) {
		return new WP_Error(
			'handoff_session_inactive',
			__( 'The session that started this sign-in has ended.', 'wp-native-auth' ),
			array( 'status' => 403 )
		);
	}

	$challenge_policy = null;
	$context          = array(
		'device_id' => $device_id,
		'client_id' => null !== $session ? (string) $session['oauth_client_id'] : '',
		'blog_id'   => get_current_blog_id(),
		'reason'    => 'browser_handoff',
		'phase'     => 'browser_handoff',
	);

	foreach ( wp_native_auth_get_challenge_policies() as $policy_id => $policy ) {
		$challenge = call_user_func( $policy['challenge'], $user, $context );
		if ( is_wp_error( $challenge ) ) {
			return $challenge;
		}

		if ( null === $challenge ) {
			continue;
		}

		if ( 'array' !== gettype( $challenge ) || empty( $challenge['type'] ) || ! is_string( $challenge['type'] ) ) {
			return new WP_Error( 'invalid_challenge_contract', __( 'Authentication policy returned an invalid challenge.', 'wp-native-auth' ), array( 'status' => 500 ) );
		}

		$challenge_policy = (string) $policy_id;
		break;
	}

	if ( null !== $challenge_policy
		&& ( null === $session || $challenge_policy !== $session['challenge_policy'] || null === $session['challenge_verified_at'] ) ) {
		return new WP_Error(
			'handoff_challenge_required',
			__( 'Sign in to continue.', 'wp-native-auth' ),
			array(
				'status'           => 403,
				'challenge_policy' => $challenge_policy,
			)
		);
	}

	/**
	 * Filters whether a browser handoff may set a cookie for its user.
	 *
	 * Runs only after the challenge policies allowed the handoff, so it can
	 * refuse a handoff but never allow one a policy refused.
	 *
	 * @param null|WP_Error $blocked Null to allow, WP_Error to refuse.
	 * @param WP_User       $user    User the handoff is for.
	 * @param array         $context {
	 *     @type string|null $device_id        Device UUID of the minting session, if any.
	 *     @type array|null  $session          Active minting session (see wp_native_auth_get_session()).
	 *     @type string|null $challenge_policy Policy that would challenge this user, or null.
	 * }
	 */
	$blocked = apply_filters(
		'wp_native_auth_pre_browser_handoff',
		null,
		$user,
		array(
			'device_id'        => $device_id,
			'session'          => $session,
			'challenge_policy' => $challenge_policy,
		)
	);

	if ( is_wp_error( $blocked ) ) {
		return $blocked;
	}

	return true;
}
