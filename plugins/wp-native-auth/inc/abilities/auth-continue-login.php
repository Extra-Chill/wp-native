<?php
/**
 * Ability: wp-native/auth-continue-login
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/** Register the authentication challenge continuation ability. */
function wp_native_auth_register_continue_login_ability(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'wp-native/auth-continue-login',
		array(
			'label'               => __( 'Continue a pending authentication challenge', 'wp-native-auth' ),
			'description'         => __( 'Verify an authentication policy challenge and complete the bound pending login.', 'wp-native-auth' ),
			'category'            => WP_NATIVE_AUTH_ABILITY_CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'continuation_token', 'device_id', 'challenge_response' ),
				'additionalProperties' => false,
				'properties'           => array(
					'continuation_token' => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 512,
					),
					'device_id'          => array(
						'type'    => 'string',
						'pattern' => '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$',
					),
					'challenge_response' => array( 'type' => 'object' ),
				),
			),
			'output_schema'       => array(
				'oneOf' => array(
					array(
						'type'                 => 'object',
						'required'             => array( 'access_token', 'access_expires_at', 'refresh_token', 'refresh_expires_at', 'user' ),
						'additionalProperties' => false,
						'properties'           => array(
							'access_token'       => array( 'type' => 'string' ),
							'access_expires_at'  => array(
								'type'   => 'string',
								'format' => 'date-time',
							),
							'refresh_token'      => array( 'type' => 'string' ),
							'refresh_expires_at' => array(
								'type'   => 'string',
								'format' => 'date-time',
							),
							'user'               => array( 'type' => 'object' ),
						),
					),
					// The issuing policy re-challenged (e.g. re-sent a code);
					// the same continuation stays redeemable.
					array(
						'type'                 => 'object',
						'required'             => array( 'challenge_required', 'challenge_policy', 'challenge', 'continuation_token', 'continuation_expires_at' ),
						'additionalProperties' => false,
						'properties'           => array(
							'challenge_required'      => array(
								'type' => 'boolean',
								'enum' => array( true ),
							),
							'challenge_policy'        => array( 'type' => 'string' ),
							'challenge'               => array( 'type' => 'object' ),
							'continuation_token'      => array( 'type' => 'string' ),
							'continuation_expires_at' => array(
								'type'   => 'string',
								'format' => 'date-time',
							),
						),
					),
				),
			),
			'permission_callback' => '__return_true',
			'execute_callback'    => 'wp_native_auth_execute_continue_login_ability',
		)
	);
}

/** Execute the authentication challenge continuation ability. */
function wp_native_auth_execute_continue_login_ability( array $input ) {
	wp_native_auth_ensure_schema();

	return wp_native_auth_continue_login(
		isset( $input['continuation_token'] ) ? (string) $input['continuation_token'] : '',
		isset( $input['device_id'] ) ? (string) $input['device_id'] : '',
		isset( $input['challenge_response'] ) && is_array( $input['challenge_response'] ) ? $input['challenge_response'] : array()
	);
}
