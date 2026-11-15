<?php
/**
 * Abilities: wp-native-auth/register-push-token, wp-native-auth/unregister-push-token
 *
 * Bind or clear a push destination on the calling bearer session's device
 * row. The device is always taken from the bearer access token, never from
 * input, so a client can only manage its own session's push token.
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_native_auth_register_push_token_abilities' ) ) {
	/**
	 * Register the push-token abilities.
	 *
	 * @return void
	 */
	function wp_native_auth_register_push_token_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'wp-native-auth/register-push-token',
			array(
				'label'               => __( 'Register a push token for this device session', 'wp-native-auth' ),
				'description'         => __( 'Bind a push token to the calling bearer session\'s device. Re-registering replaces the previous token; the same token bound to another session is cleared there first.', 'wp-native-auth' ),
				'category'            => WP_NATIVE_AUTH_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'token' ),
					'additionalProperties' => false,
					'properties'           => array(
						'token'    => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => WP_NATIVE_AUTH_PUSH_TOKEN_MAX_LENGTH,
						),
						'provider' => array(
							'type'    => 'string',
							'pattern' => '^[a-z0-9][a-z0-9_-]{0,31}$',
							'default' => wp_native_auth_push_default_provider(),
						),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'registered', 'device_id', 'provider' ),
					'additionalProperties' => false,
					'properties'           => array(
						'registered' => array( 'type' => 'boolean' ),
						'device_id'  => array( 'type' => 'string' ),
						'provider'   => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => 'wp_native_auth_require_authenticated',
				'execute_callback'    => 'wp_native_auth_execute_register_push_token_ability',
				'meta'                => array(
					'show_in_rest' => true,
				),
			)
		);

		wp_register_ability(
			'wp-native-auth/unregister-push-token',
			array(
				'label'               => __( 'Unregister the push token for this device session', 'wp-native-auth' ),
				'description'         => __( 'Clear the push token bound to the calling bearer session\'s device.', 'wp-native-auth' ),
				'category'            => WP_NATIVE_AUTH_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'additionalProperties' => false,
					'properties'           => array(),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'cleared' ),
					'additionalProperties' => false,
					'properties'           => array(
						'cleared' => array(
							'type'        => 'boolean',
							'description' => 'True if a token was cleared, false if the session had none.',
						),
					),
				),
				'permission_callback' => 'wp_native_auth_require_authenticated',
				'execute_callback'    => 'wp_native_auth_execute_unregister_push_token_ability',
				'meta'                => array(
					'show_in_rest' => true,
				),
			)
		);
	}
}

if ( ! function_exists( 'wp_native_auth_require_bearer_session' ) ) {
	/**
	 * Resolve the calling bearer session or explain why it is unavailable.
	 *
	 * @return array{device_id:string, oauth_client_id:string}|WP_Error
	 */
	function wp_native_auth_require_bearer_session() {
		$session = wp_native_auth_get_current_bearer_session();

		if ( null === $session ) {
			return new WP_Error(
				'bearer_session_required',
				__( 'A bearer device session is required.', 'wp-native-auth' ),
				array( 'status' => 401 )
			);
		}

		if ( '' !== $session['oauth_client_id'] ) {
			return new WP_Error(
				'push_requires_native_session',
				__( 'Push tokens can only be managed by native device sessions.', 'wp-native-auth' ),
				array( 'status' => 403 )
			);
		}

		return $session;
	}
}

if ( ! function_exists( 'wp_native_auth_execute_register_push_token_ability' ) ) {
	/**
	 * Execute callback for wp-native-auth/register-push-token.
	 *
	 * @param array<string, mixed> $input Validated ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	function wp_native_auth_execute_register_push_token_ability( array $input ) {
		$session = wp_native_auth_require_bearer_session();
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$token    = isset( $input['token'] ) ? (string) $input['token'] : '';
		$provider = isset( $input['provider'] ) ? (string) $input['provider'] : '';

		return wp_native_auth_register_push_token( get_current_user_id(), $session['device_id'], $token, $provider );
	}
}

if ( ! function_exists( 'wp_native_auth_execute_unregister_push_token_ability' ) ) {
	/**
	 * Execute callback for wp-native-auth/unregister-push-token.
	 *
	 * @param array<string, mixed>|null $_input Validated ability input (empty for this ability).
	 * @return array<string, bool>|WP_Error
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required ability callback signature.
	function wp_native_auth_execute_unregister_push_token_ability( $_input = null ) {
		$session = wp_native_auth_require_bearer_session();
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		return array(
			'cleared' => wp_native_auth_clear_push_token( get_current_user_id(), $session['device_id'], 'unregistered' ),
		);
	}
}
