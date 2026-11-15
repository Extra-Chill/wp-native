<?php
/**
 * Expo push transport.
 *
 * The only file that knows the Expo Push API. Registers the `expo` provider
 * on `wp_native_auth_push_transports` and delivers through
 * https://exp.host/--/api/v2/push/send in batches of at most 100 messages.
 *
 * A ticket error of `DeviceNotRegistered` is reported as `invalid_token`,
 * which makes the generic push layer clear that device's token.
 *
 * @package WPNative\Auth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/** Expo Push API send endpoint. */
const WP_NATIVE_AUTH_EXPO_PUSH_ENDPOINT = 'https://exp.host/--/api/v2/push/send';

/** Maximum messages per Expo push request. */
const WP_NATIVE_AUTH_EXPO_PUSH_BATCH_SIZE = 100;

if ( ! defined( 'WP_NATIVE_AUTH_PUSH_DEFAULT_PROVIDER' ) ) {
	// Provider used when a registration omits `provider`.
	define( 'WP_NATIVE_AUTH_PUSH_DEFAULT_PROVIDER', 'expo' );
}

add_filter( 'wp_native_auth_push_transports', 'wp_native_auth_register_expo_push_transport' );

/**
 * Register the Expo transport.
 *
 * @param mixed $transports Provider id => transport.
 * @return array<string,mixed>
 */
function wp_native_auth_register_expo_push_transport( $transports ): array {
	$transports = is_array( $transports ) ? $transports : array();

	if ( ! isset( $transports['expo'] ) ) {
		$transports['expo'] = 'wp_native_auth_expo_push_transport';
	}

	return $transports;
}

/**
 * Build one Expo push message.
 *
 * Expo has no collapse-key field, so `collapse_id` travels in `data` for the
 * client to coalesce on.
 *
 * @param string              $token   Expo push token.
 * @param array<string,mixed> $message Normalized generic message.
 * @return array<string,mixed>
 */
function wp_native_auth_expo_build_message( string $token, array $message ): array {
	$data = isset( $message['data'] ) && is_array( $message['data'] ) ? $message['data'] : array();
	if ( ! empty( $message['collapse_id'] ) ) {
		$data['collapse_id'] = (string) $message['collapse_id'];
	}

	$expo = array(
		'to'    => $token,
		'title' => (string) $message['title'],
		'sound' => 'default',
		'data'  => (object) $data,
	);

	if ( isset( $message['body'] ) && '' !== $message['body'] ) {
		$expo['body'] = (string) $message['body'];
	}

	/**
	 * Filter a single Expo push message before it is sent.
	 *
	 * @param array<string,mixed> $expo    Expo message.
	 * @param array<string,mixed> $message Normalized generic message.
	 */
	return (array) apply_filters( 'wp_native_auth_expo_push_message', $expo, $message );
}

/**
 * Deliver messages through the Expo Push API.
 *
 * @param array<int,array{device_id:string, token:string}> $deliveries Targets.
 * @param array<string,mixed>                              $message    Normalized generic message.
 * @return array<int,array<string,string>> Receipts in delivery order.
 */
function wp_native_auth_expo_push_transport( array $deliveries, array $message ): array {
	$deliveries = array_values( $deliveries );
	$receipts   = array();

	$headers = array(
		'Accept'       => 'application/json',
		'Content-Type' => 'application/json',
	);

	/**
	 * Filter the optional Expo access token (enhanced push security).
	 *
	 * @param string $access_token Empty for none.
	 */
	$access_token = (string) apply_filters( 'wp_native_auth_expo_push_access_token', '' );
	if ( '' !== $access_token ) {
		$headers['Authorization'] = 'Bearer ' . $access_token;
	}

	foreach ( array_chunk( $deliveries, WP_NATIVE_AUTH_EXPO_PUSH_BATCH_SIZE ) as $chunk ) {
		$messages = array();
		foreach ( $chunk as $delivery ) {
			$messages[] = wp_native_auth_expo_build_message( (string) $delivery['token'], $message );
		}

		$response = wp_remote_post(
			WP_NATIVE_AUTH_EXPO_PUSH_ENDPOINT,
			array(
				'headers' => $headers,
				'body'    => (string) wp_json_encode( $messages ),
				'timeout' => 15,
			)
		);

		$tickets = null;
		$failure = '';

		if ( is_wp_error( $response ) ) {
			$failure = 'request_failed';
		} elseif ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$failure = 'http_' . (int) wp_remote_retrieve_response_code( $response );
		} else {
			$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( is_array( $decoded ) && isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
				$tickets = array_values( $decoded['data'] );
			} else {
				$failure = 'invalid_response';
			}
		}

		foreach ( array_keys( $chunk ) as $index ) {
			if ( null === $tickets ) {
				$receipts[] = array(
					'status' => 'error',
					'error'  => $failure,
				);
				continue;
			}

			$receipts[] = wp_native_auth_expo_ticket_to_receipt( isset( $tickets[ $index ] ) && is_array( $tickets[ $index ] ) ? $tickets[ $index ] : array() );
		}
	}

	return $receipts;
}

/**
 * Map an Expo push ticket to a generic receipt.
 *
 * @param array<string,mixed> $ticket Expo ticket.
 * @return array<string,string>
 */
function wp_native_auth_expo_ticket_to_receipt( array $ticket ): array {
	$status = isset( $ticket['status'] ) ? (string) $ticket['status'] : '';

	if ( 'ok' === $status ) {
		$receipt = array( 'status' => 'ok' );
		if ( ! empty( $ticket['id'] ) ) {
			$receipt['id'] = (string) $ticket['id'];
		}
		return $receipt;
	}

	$error = isset( $ticket['details']['error'] ) && is_string( $ticket['details']['error'] ) ? $ticket['details']['error'] : '';

	if ( 'DeviceNotRegistered' === $error ) {
		return array(
			'status' => 'invalid_token',
			'error'  => $error,
		);
	}

	return array(
		'status' => 'error',
		'error'  => '' !== $error ? $error : ( 'error' === $status ? 'provider_error' : 'missing_ticket' ),
	);
}
