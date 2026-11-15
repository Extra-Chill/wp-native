<?php
/**
 * wp-native-auth — Abilities API bootstrap.
 *
 * Loads each ability registration file and hooks them into the
 * WordPress Abilities API initialization action.
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_NATIVE_AUTH_ABILITY_CATEGORY' ) ) {
	define( 'WP_NATIVE_AUTH_ABILITY_CATEGORY', 'wp-native-auth' );
}

require_once __DIR__ . '/abilities/auth-login.php';
require_once __DIR__ . '/abilities/auth-continue-login.php';
require_once __DIR__ . '/abilities/auth-refresh.php';
require_once __DIR__ . '/abilities/auth-logout.php';
require_once __DIR__ . '/abilities/auth-me.php';
require_once __DIR__ . '/abilities/auth-sessions.php';
require_once __DIR__ . '/abilities/auth-revoke-session.php';
require_once __DIR__ . '/abilities/auth-browser-handoff.php';
require_once __DIR__ . '/abilities/auth-register.php';
require_once __DIR__ . '/abilities/push-tokens.php';

if ( ! function_exists( 'wp_native_auth_register_ability_category' ) ) {
	/**
	 * Register the wp-native-auth ability category.
	 *
	 * @return void
	 */
	function wp_native_auth_register_ability_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		// The wp_abilities_api_categories_init action can fire more than once per
		// request (notably on multisite), so guard against re-registering the
		// category and tripping core's "already registered" _doing_it_wrong notice.
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( WP_NATIVE_AUTH_ABILITY_CATEGORY ) ) {
			return;
		}

		wp_register_ability_category(
			WP_NATIVE_AUTH_ABILITY_CATEGORY,
			array(
				'label'       => __( 'WP Native Auth', 'wp-native-auth' ),
				'description' => __( 'Token-based authentication abilities for native app and headless clients.', 'wp-native-auth' ),
			)
		);
	}
}

if ( ! function_exists( 'wp_native_auth_register_abilities' ) ) {
	/**
	 * Register every wp-native-auth ability with the Abilities API.
	 *
	 * @return void
	 */
	function wp_native_auth_register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_native_auth_register_login_ability();
		wp_native_auth_register_continue_login_ability();
		wp_native_auth_register_refresh_ability();
		wp_native_auth_register_logout_ability();
		wp_native_auth_register_me_ability();
		wp_native_auth_register_sessions_ability();
		wp_native_auth_register_revoke_session_ability();
		wp_native_auth_register_browser_handoff_ability();
		wp_native_auth_register_register_ability();
		wp_native_auth_register_push_token_abilities();
	}
}

if ( ! function_exists( 'wp_native_auth_require_authenticated' ) ) {
	/**
	 * Shared permission callback for protected abilities.
	 *
	 * Returns true when a user is logged in (via the bearer-token resolver
	 * shipped in M4.1+M4.2). Otherwise returns a 401 WP_Error.
	 *
	 * @return true|WP_Error
	 */
	function wp_native_auth_require_authenticated() {
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			return true;
		}

		return new WP_Error(
			'not_authenticated',
			__( 'Authentication required.', 'wp-native-auth' ),
			array( 'status' => 401 )
		);
	}
}

if ( doing_action( 'wp_abilities_api_categories_init' ) ) {
	wp_native_auth_register_ability_category();
} elseif ( ! did_action( 'wp_abilities_api_categories_init' ) ) {
	add_action( 'wp_abilities_api_categories_init', 'wp_native_auth_register_ability_category' );
}

if ( doing_action( 'wp_abilities_api_init' ) ) {
	wp_native_auth_register_abilities();
} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
	add_action( 'wp_abilities_api_init', 'wp_native_auth_register_abilities' );
}
