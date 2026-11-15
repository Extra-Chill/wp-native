<?php
/**
 * Generic push-token registration and push sending.
 *
 * A push destination is bound to the existing per-device session row in the
 * refresh tokens table (`push_token` + `push_provider`, schema v7). There is
 * no second device registry: a device can receive push exactly while its
 * session row is active (not revoked, not expired), and every lifecycle path
 * that ends a session also clears the push destination.
 *
 * Delivery is provider-agnostic. Providers are resolved through the
 * `wp_native_auth_push_transports` filter, which maps a provider id to a
 * transport (a callable, a class name, or an object exposing `send()`).
 * This file knows nothing about any specific push vendor.
 *
 * Sending never blocks the caller: wp_native_auth_send_push() snapshots the
 * target devices, queues a background job (Action Scheduler when available,
 * otherwise a single WP-Cron event), and returns immediately. The job
 * re-reads the session rows at delivery time so a session revoked between
 * queueing and delivery receives nothing.
 *
 * @package WPNative\Auth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/** Background hook that delivers a queued push job. */
const WP_NATIVE_AUTH_PUSH_DELIVERY_HOOK = 'wp_native_auth_deliver_push';

/** Action Scheduler group for queued push jobs. */
const WP_NATIVE_AUTH_PUSH_QUEUE_GROUP = 'wp-native-auth';

/** Maximum stored push token length (matches the `push_token` column). */
const WP_NATIVE_AUTH_PUSH_TOKEN_MAX_LENGTH = 255;

/** Provider ids: lowercase slug, at most 32 chars (matches `push_provider`). */
const WP_NATIVE_AUTH_PUSH_PROVIDER_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,31}$/';

add_action( WP_NATIVE_AUTH_PUSH_DELIVERY_HOOK, 'wp_native_auth_deliver_push' );

/**
 * Return the registered push transports keyed by provider id.
 *
 * @return array<string,mixed> Provider id => transport (callable|class-string|object).
 */
function wp_native_auth_get_push_transports(): array {
	/**
	 * Filter the push transports keyed by provider id.
	 *
	 * Each value is either a callable, a class name whose instances expose a
	 * public `send()` method, or an object exposing `send()`. The transport
	 * receives `( array $deliveries, array $message )` where `$deliveries`
	 * is a list of `array{ device_id: string, token: string }` and must
	 * return a list of receipts in the same order, each
	 * `array{ status: 'ok'|'error'|'invalid_token', error?: string, id?: string }`.
	 * Returning `invalid_token` clears that device's push token.
	 *
	 * @param array<mixed> $transports Provider id => transport.
	 */
	$transports = apply_filters( 'wp_native_auth_push_transports', array() );

	$valid = array();
	foreach ( (array) $transports as $provider => $transport ) {
		if ( is_string( $provider ) && preg_match( WP_NATIVE_AUTH_PUSH_PROVIDER_PATTERN, $provider ) ) {
			$valid[ $provider ] = $transport;
		}
	}

	return $valid;
}

/**
 * Resolve a provider id to an invokable transport.
 *
 * @param string $provider Provider id.
 * @return callable|null Callable accepting ( array $deliveries, array $message ), or null.
 */
function wp_native_auth_resolve_push_transport( string $provider ): ?callable {
	$transports = wp_native_auth_get_push_transports();

	if ( ! array_key_exists( $provider, $transports ) ) {
		return null;
	}

	$transport = $transports[ $provider ];

	if ( is_string( $transport ) && class_exists( $transport ) && method_exists( $transport, 'send' ) ) {
		$transport = array( new $transport(), 'send' );
	} elseif ( is_object( $transport ) && ! ( $transport instanceof Closure ) && method_exists( $transport, 'send' ) ) {
		$transport = array( $transport, 'send' );
	}

	return is_callable( $transport ) ? $transport : null;
}

/**
 * Default provider id used when a registration omits `provider`.
 */
function wp_native_auth_push_default_provider(): string {
	return defined( 'WP_NATIVE_AUTH_PUSH_DEFAULT_PROVIDER' ) ? (string) WP_NATIVE_AUTH_PUSH_DEFAULT_PROVIDER : '';
}

/**
 * Resolve the device id of the bearer session making the current request.
 *
 * Only a bearer access token identifies a device; cookie-authenticated
 * requests have no device session and return null. The token's user must be
 * the current user, so a cookie session cannot borrow a stray bearer header.
 *
 * @return array{device_id:string, oauth_client_id:string}|null
 */
function wp_native_auth_get_current_bearer_session(): ?array {
	if ( ! function_exists( 'wp_native_auth_get_bearer_token' ) ) {
		return null;
	}

	$token = wp_native_auth_get_bearer_token();
	if ( null === $token ) {
		return null;
	}

	$payload = wp_native_auth_get_access_token_payload( $token );
	if ( null === $payload ) {
		return null;
	}

	$current_user_id = get_current_user_id();
	if ( $current_user_id <= 0 || (int) $payload['user_id'] !== $current_user_id ) {
		return null;
	}

	$device_id = isset( $payload['device_id'] ) ? (string) $payload['device_id'] : '';
	if ( ! wp_native_auth_is_uuid_v4( $device_id ) ) {
		return null;
	}

	$meta = isset( $payload['meta'] ) && is_array( $payload['meta'] ) ? $payload['meta'] : array();

	return array(
		'device_id'       => $device_id,
		'oauth_client_id' => isset( $meta['client_id'] ) ? (string) $meta['client_id'] : '',
	);
}

/**
 * Validate a push token string.
 *
 * Tokens are opaque to this layer: any printable, whitespace-free ASCII
 * string up to the column width is accepted. Providers judge validity at
 * delivery time and report `invalid_token`.
 *
 * @param string $token Candidate token.
 */
function wp_native_auth_is_valid_push_token( string $token ): bool {
	return strlen( $token ) <= WP_NATIVE_AUTH_PUSH_TOKEN_MAX_LENGTH
		&& (bool) preg_match( '/^[\x21-\x7E]+$/', $token );
}

/**
 * Bind a push token to an active native device session.
 *
 * Re-registering on the same session replaces the previous token in place.
 * If the same token is currently bound to any other session row (another
 * device, or another user on a shared device), it is cleared there first so
 * one physical destination never receives another account's push.
 *
 * @param int    $user_id   Session owner.
 * @param string $device_id Session device id (UUID v4).
 * @param string $token     Provider push token.
 * @param string $provider  Provider id; must be a registered transport.
 * @return array{registered:bool, device_id:string, provider:string}|WP_Error
 */
function wp_native_auth_register_push_token( int $user_id, string $device_id, string $token, string $provider = '' ) {
	global $wpdb;

	$token    = trim( $token );
	$provider = '' === $provider ? wp_native_auth_push_default_provider() : strtolower( trim( $provider ) );

	if ( '' === $token || ! wp_native_auth_is_valid_push_token( $token ) ) {
		return new WP_Error( 'invalid_push_token', __( 'The push token is invalid.', 'wp-native-auth' ), array( 'status' => 400 ) );
	}

	if ( ! preg_match( WP_NATIVE_AUTH_PUSH_PROVIDER_PATTERN, $provider ) || null === wp_native_auth_resolve_push_transport( $provider ) ) {
		return new WP_Error( 'unsupported_push_provider', __( 'The push provider is not supported.', 'wp-native-auth' ), array( 'status' => 400 ) );
	}

	$session = wp_native_auth_get_active_session_row( $user_id, $device_id );
	if ( null === $session ) {
		return new WP_Error( 'session_not_found', __( 'No active session exists for this device.', 'wp-native-auth' ), array( 'status' => 404 ) );
	}

	if ( ! empty( $session['oauth_client_id'] ) ) {
		return new WP_Error( 'push_requires_native_session', __( 'Push tokens can only be registered by native device sessions.', 'wp-native-auth' ), array( 'status' => 403 ) );
	}

	$table_name = wp_native_auth_refresh_tokens_table_name();

	// The same destination bound elsewhere is cleared there first.
	$elsewhere = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT id, user_id, device_id, push_token FROM %i WHERE push_token = %s AND id <> %d',
			$table_name,
			$token,
			(int) $session['id']
		),
		ARRAY_A
	);
	wp_native_auth_clear_push_token_rows( is_array( $elsewhere ) ? $elsewhere : array(), 'reassigned' );

	$updated = $wpdb->update(
		$table_name,
		array(
			'push_token'    => $token,
			'push_provider' => $provider,
		),
		array( 'id' => (int) $session['id'] ),
		array( '%s', '%s' ),
		array( '%d' )
	);

	if ( false === $updated ) {
		return new WP_Error( 'push_token_storage_failed', __( 'The push token could not be stored.', 'wp-native-auth' ), array( 'status' => 500 ) );
	}

	/**
	 * Fires after a push token is bound to a device session.
	 *
	 * @param int    $user_id   Session owner.
	 * @param string $device_id Device id.
	 * @param string $provider  Provider id.
	 */
	do_action( 'wp_native_auth_push_token_registered', $user_id, $device_id, $provider );

	return array(
		'registered' => true,
		'device_id'  => $device_id,
		'provider'   => $provider,
	);
}

/**
 * Fetch the active (unrevoked, unexpired) session row for a user/device.
 *
 * @param int    $user_id   User id.
 * @param string $device_id Device id.
 * @return array<string,mixed>|null
 */
function wp_native_auth_get_active_session_row( int $user_id, string $device_id ): ?array {
	global $wpdb;

	if ( $user_id <= 0 || ! wp_native_auth_is_uuid_v4( $device_id ) ) {
		return null;
	}

	$row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT id, user_id, device_id, oauth_client_id, push_token, push_provider FROM %i
			 WHERE user_id = %d AND device_id = %s AND revoked_at IS NULL AND expires_at > %s
			 LIMIT 1',
			wp_native_auth_refresh_tokens_table_name(),
			$user_id,
			$device_id,
			wp_native_auth_mysql_gmt( time() )
		),
		ARRAY_A
	);

	return is_array( $row ) ? $row : null;
}

/**
 * Clear the push token on specific session rows.
 *
 * Each row is cleared only while it still holds the token that was read, so
 * a concurrent re-registration is never wiped. Fires
 * `wp_native_auth_push_token_cleared` once per cleared row.
 *
 * @param array<int,array<string,mixed>> $rows   Rows with id, user_id, device_id, push_token.
 * @param string                         $reason Why the token was cleared.
 * @return int Rows cleared.
 */
function wp_native_auth_clear_push_token_rows( array $rows, string $reason ): int {
	global $wpdb;

	$table_name = wp_native_auth_refresh_tokens_table_name();
	$cleared    = 0;

	foreach ( $rows as $row ) {
		if ( empty( $row['push_token'] ) ) {
			continue;
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET push_token = NULL, push_provider = NULL WHERE id = %d AND push_token = %s',
				$table_name,
				(int) $row['id'],
				(string) $row['push_token']
			)
		);

		if ( ! is_numeric( $updated ) || (int) $updated < 1 ) {
			continue;
		}

		++$cleared;

		/**
		 * Fires after a push token is removed from a device session.
		 *
		 * Reasons: `unregistered`, `reassigned` (the token moved to another
		 * session), `logout` (device session revoked), `sessions_revoked`
		 * (user-wide revocation), `family_revoked` (refresh-token reuse),
		 * `expired`, and `invalid_token` (the provider rejected it).
		 *
		 * @param int    $user_id   Session owner.
		 * @param string $device_id Device id.
		 * @param string $reason    Why the token was cleared.
		 */
		do_action( 'wp_native_auth_push_token_cleared', (int) $row['user_id'], (string) $row['device_id'], $reason );
	}

	return $cleared;
}

/**
 * Select session rows holding a push token that match a WHERE fragment.
 *
 * @param string            $where Prepared-placeholder WHERE fragment (no leading AND).
 * @param array<int,mixed>  $args  Placeholder values.
 * @return array<int,array<string,mixed>>
 */
function wp_native_auth_select_push_token_rows( string $where, array $args ): array {
	global $wpdb;

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is an internal placeholder fragment; its values are prepared.
			"SELECT id, user_id, device_id, push_token, push_provider FROM %i WHERE push_token IS NOT NULL AND {$where}",
			array_merge( array( wp_native_auth_refresh_tokens_table_name() ), $args )
		),
		ARRAY_A
	);

	return is_array( $rows ) ? $rows : array();
}

/**
 * Clear the push token for one user/device session.
 *
 * @param int    $user_id   User id.
 * @param string $device_id Device id.
 * @param string $reason    Why the token was cleared.
 * @return bool True when a token was cleared.
 */
function wp_native_auth_clear_push_token( int $user_id, string $device_id, string $reason = 'unregistered' ): bool {
	$rows = wp_native_auth_select_push_token_rows( 'user_id = %d AND device_id = %s', array( $user_id, $device_id ) );

	return wp_native_auth_clear_push_token_rows( $rows, $reason ) > 0;
}

/**
 * Clear every push token owned by a user.
 *
 * @param int    $user_id User id.
 * @param string $reason  Why the tokens were cleared.
 * @return int Tokens cleared.
 */
function wp_native_auth_clear_user_push_tokens( int $user_id, string $reason ): int {
	return wp_native_auth_clear_push_token_rows(
		wp_native_auth_select_push_token_rows( 'user_id = %d', array( $user_id ) ),
		$reason
	);
}

/**
 * Clear every push token in a refresh-token family.
 *
 * @param string $token_family Family UUID.
 * @param string $reason       Why the tokens were cleared.
 * @return int Tokens cleared.
 */
function wp_native_auth_clear_token_family_push_tokens( string $token_family, string $reason ): int {
	if ( '' === $token_family ) {
		return 0;
	}

	return wp_native_auth_clear_push_token_rows(
		wp_native_auth_select_push_token_rows( 'token_family = %s', array( $token_family ) ),
		$reason
	);
}

/**
 * Clear push tokens left on a user's expired or revoked session rows.
 *
 * Inactive rows are already excluded from delivery; this keeps no stale
 * destination at rest once a send touches the user.
 *
 * @param int $user_id User id.
 * @return int Tokens cleared.
 */
function wp_native_auth_clear_inactive_push_tokens( int $user_id ): int {
	return wp_native_auth_clear_push_token_rows(
		wp_native_auth_select_push_token_rows(
			'user_id = %d AND ( revoked_at IS NOT NULL OR expires_at <= %s )',
			array( $user_id, wp_native_auth_mysql_gmt( time() ) )
		),
		'expired'
	);
}

/**
 * Active native sessions of a user that hold a push token.
 *
 * @param int $user_id User id.
 * @return array<int,array<string,mixed>> Rows with id, user_id, device_id, push_token, push_provider.
 */
function wp_native_auth_get_push_targets( int $user_id ): array {
	return wp_native_auth_select_push_token_rows(
		"user_id = %d AND push_token <> '' AND revoked_at IS NULL AND expires_at > %s AND oauth_client_id IS NULL ORDER BY id ASC",
		array( $user_id, wp_native_auth_mysql_gmt( time() ) )
	);
}

/**
 * Normalize a push message to the documented shape.
 *
 * @param array<string,mixed> $message { title: string, body?: string, data?: array, collapse_id?: string }.
 * @return array{title:string, body:string, data:array<string,mixed>, collapse_id:string}|WP_Error
 */
function wp_native_auth_normalize_push_message( array $message ) {
	$title = isset( $message['title'] ) && is_string( $message['title'] ) ? trim( $message['title'] ) : '';
	if ( '' === $title ) {
		return new WP_Error( 'invalid_push_message', __( 'A push message requires a title.', 'wp-native-auth' ) );
	}

	if ( isset( $message['body'] ) && ! is_string( $message['body'] ) ) {
		return new WP_Error( 'invalid_push_message', __( 'A push message body must be a string.', 'wp-native-auth' ) );
	}

	if ( isset( $message['data'] ) && ! is_array( $message['data'] ) ) {
		return new WP_Error( 'invalid_push_message', __( 'Push message data must be an array.', 'wp-native-auth' ) );
	}

	if ( isset( $message['collapse_id'] ) && ! is_string( $message['collapse_id'] ) ) {
		return new WP_Error( 'invalid_push_message', __( 'A push message collapse_id must be a string.', 'wp-native-auth' ) );
	}

	return array(
		'title'       => $title,
		'body'        => isset( $message['body'] ) ? (string) $message['body'] : '',
		'data'        => isset( $message['data'] ) ? $message['data'] : array(),
		'collapse_id' => isset( $message['collapse_id'] ) ? (string) $message['collapse_id'] : '',
	);
}

/**
 * Queue a push message for every active session of a user that has a token.
 *
 * Non-blocking: targets are snapshotted, a background job is queued, and the
 * per-device summary is returned immediately. Delivery happens in
 * wp_native_auth_deliver_push(), which re-checks every session.
 *
 * Return shape:
 *   - `user_id` (int)
 *   - `queued`  (bool) true when a delivery job was queued
 *   - `job_id`  (string|null) opaque job id
 *   - `devices` (list) `{ device_id, provider, status }`, status `queued` or
 *     `unsupported_provider`
 *   - `error`   (string, only when not queued) `invalid_message`,
 *     `no_devices`, or `queue_failed`
 *
 * @param int                 $user_id Recipient user id.
 * @param array<string,mixed> $message { title: string, body?: string, data?: array, collapse_id?: string }.
 * @return array<string,mixed> Per-device receipt summary.
 */
function wp_native_auth_send_push( int $user_id, array $message ): array {
	$summary = array(
		'user_id' => $user_id,
		'queued'  => false,
		'job_id'  => null,
		'devices' => array(),
	);

	$normalized = wp_native_auth_normalize_push_message( $message );
	if ( is_wp_error( $normalized ) ) {
		$summary['error'] = 'invalid_message';
		return $summary;
	}

	if ( $user_id <= 0 ) {
		$summary['error'] = 'no_devices';
		return $summary;
	}

	wp_native_auth_clear_inactive_push_tokens( $user_id );

	$device_ids = array();
	foreach ( wp_native_auth_get_push_targets( $user_id ) as $row ) {
		$provider  = (string) $row['push_provider'];
		$supported = null !== wp_native_auth_resolve_push_transport( $provider );

		$summary['devices'][] = array(
			'device_id' => (string) $row['device_id'],
			'provider'  => $provider,
			'status'    => $supported ? 'queued' : 'unsupported_provider',
		);

		if ( $supported ) {
			$device_ids[] = (string) $row['device_id'];
		}
	}

	if ( array() === $device_ids ) {
		$summary['error'] = 'no_devices';
		return $summary;
	}

	$job = array(
		'job_id'     => wp_generate_uuid4(),
		'user_id'    => $user_id,
		'device_ids' => $device_ids,
		'message'    => $normalized,
	);

	if ( ! wp_native_auth_queue_push_job( $job ) ) {
		foreach ( $summary['devices'] as $index => $device ) {
			if ( 'queued' === $device['status'] ) {
				$summary['devices'][ $index ]['status'] = 'queue_failed';
			}
		}
		$summary['error'] = 'queue_failed';
		return $summary;
	}

	$summary['queued'] = true;
	$summary['job_id'] = $job['job_id'];

	return $summary;
}

/**
 * Queue a delivery job in the background.
 *
 * Uses Action Scheduler when it is loaded and initialized, otherwise a
 * single WP-Cron event. Each job carries a unique id, so identical messages
 * are never collapsed by WP-Cron's duplicate-event guard.
 *
 * @param array<string,mixed> $job Delivery job.
 * @return bool True when queued.
 */
function wp_native_auth_queue_push_job( array $job ): bool {
	$use_action_scheduler = function_exists( 'as_enqueue_async_action' )
		&& ( ! class_exists( 'ActionScheduler' ) || ActionScheduler::is_initialized() );

	/**
	 * Filter whether push jobs are queued through Action Scheduler.
	 *
	 * @param bool                $use_action_scheduler Default: true when Action Scheduler is available.
	 * @param array<string,mixed> $job                  Delivery job.
	 */
	$use_action_scheduler = (bool) apply_filters( 'wp_native_auth_push_use_action_scheduler', $use_action_scheduler, $job );

	if ( $use_action_scheduler && function_exists( 'as_enqueue_async_action' ) ) {
		return (int) as_enqueue_async_action( WP_NATIVE_AUTH_PUSH_DELIVERY_HOOK, array( $job ), WP_NATIVE_AUTH_PUSH_QUEUE_GROUP ) > 0;
	}

	return true === wp_schedule_single_event( time(), WP_NATIVE_AUTH_PUSH_DELIVERY_HOOK, array( $job ), true );
}

/**
 * Deliver a queued push job. Runs in the background.
 *
 * Re-reads the user's active sessions so a device revoked or unregistered
 * after queueing is skipped. Groups devices by provider, invokes each
 * transport once, clears tokens the provider reports as invalid, and fires
 * `wp_native_auth_push_sent` with the delivery summary.
 *
 * @param mixed $job Delivery job queued by wp_native_auth_send_push().
 * @return array<string,mixed> Delivery summary: user_id, job_id, devices
 *                             (`{ device_id, provider, status, error?, id? }`
 *                             with status `ok`, `error`, `invalid_token`, or
 *                             `unsupported_provider`), sent, failed, invalidated.
 */
function wp_native_auth_deliver_push( $job ): array {
	$job     = is_array( $job ) ? $job : array();
	$user_id = isset( $job['user_id'] ) ? (int) $job['user_id'] : 0;
	$summary = array(
		'user_id'     => $user_id,
		'job_id'      => isset( $job['job_id'] ) ? (string) $job['job_id'] : '',
		'devices'     => array(),
		'sent'        => 0,
		'failed'      => 0,
		'invalidated' => 0,
	);

	$message = wp_native_auth_normalize_push_message( isset( $job['message'] ) && is_array( $job['message'] ) ? $job['message'] : array() );
	if ( $user_id <= 0 || is_wp_error( $message ) ) {
		return $summary;
	}

	$wanted = isset( $job['device_ids'] ) && is_array( $job['device_ids'] ) ? array_map( 'strval', $job['device_ids'] ) : array();

	$by_provider = array();
	foreach ( wp_native_auth_get_push_targets( $user_id ) as $row ) {
		if ( in_array( (string) $row['device_id'], $wanted, true ) ) {
			$by_provider[ (string) $row['push_provider'] ][] = $row;
		}
	}

	foreach ( $by_provider as $provider => $rows ) {
		$provider  = (string) $provider;
		$transport = wp_native_auth_resolve_push_transport( $provider );

		if ( null === $transport ) {
			foreach ( $rows as $row ) {
				$summary['devices'][] = array(
					'device_id' => (string) $row['device_id'],
					'provider'  => $provider,
					'status'    => 'unsupported_provider',
				);
				++$summary['failed'];
			}
			continue;
		}

		$deliveries = array();
		foreach ( $rows as $row ) {
			$deliveries[] = array(
				'device_id' => (string) $row['device_id'],
				'token'     => (string) $row['push_token'],
			);
		}

		try {
			$receipts = call_user_func( $transport, $deliveries, $message );
		} catch ( Throwable $e ) {
			$receipts = array_fill(
				0,
				count( $deliveries ),
				array(
					'status' => 'error',
					'error'  => 'transport_exception',
				)
			);
		}

		$receipts = is_array( $receipts ) ? array_values( $receipts ) : array();

		foreach ( $rows as $index => $row ) {
			$receipt = isset( $receipts[ $index ] ) && is_array( $receipts[ $index ] ) ? $receipts[ $index ] : array(
				'status' => 'error',
				'error'  => 'missing_receipt',
			);
			$status  = isset( $receipt['status'] ) && in_array( $receipt['status'], array( 'ok', 'error', 'invalid_token' ), true )
				? (string) $receipt['status']
				: 'error';

			$device = array(
				'device_id' => (string) $row['device_id'],
				'provider'  => $provider,
				'status'    => $status,
			);
			if ( ! empty( $receipt['error'] ) ) {
				$device['error'] = (string) $receipt['error'];
			}
			if ( ! empty( $receipt['id'] ) ) {
				$device['id'] = (string) $receipt['id'];
			}

			if ( 'ok' === $status ) {
				++$summary['sent'];
			} else {
				++$summary['failed'];
			}

			if ( 'invalid_token' === $status ) {
				$summary['invalidated'] += wp_native_auth_clear_push_token_rows( array( $row ), 'invalid_token' );
			}

			$summary['devices'][] = $device;
		}
	}

	/**
	 * Fires after a queued push job has been delivered to its transports.
	 *
	 * @param int                 $user_id Recipient user id.
	 * @param array<string,mixed> $summary Delivery summary (see wp_native_auth_deliver_push()).
	 * @param array<string,mixed> $message Normalized message.
	 */
	do_action( 'wp_native_auth_push_sent', $user_id, $summary, $message );

	return $summary;
}
