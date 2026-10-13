<?php
/**
 * Generic OAuth 2.1 authorization server.
 *
 * Adds a spec-complete authorization-code flow with mandatory PKCE S256,
 * dynamic client registration (RFC 7591), client-id metadata documents,
 * RFC 8707 resource audience binding, RFC 9207 issuer responses, and
 * RFC 7009 revocation — on top of the refresh-token lifecycle this plugin
 * already owns. No second token store: OAuth grants are rows in the
 * existing refresh-token table with their client binding stored alongside.
 *
 * This layer is host-agnostic. It contains no vendor knowledge and no
 * product-specific branding; hosts customize the consent screen via the
 * `wp_native_auth_oauth_consent_template` filter.
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Authorization-code lifetime in seconds. Codes are single-use and never
 * re-issuable; a short TTL bounds the replay window.
 */
if ( ! defined( 'WP_NATIVE_AUTH_OAUTH_CODE_TTL' ) ) {
	define( 'WP_NATIVE_AUTH_OAUTH_CODE_TTL', 120 );
}

/**
 * Maximum age of a signed authorization request awaiting user consent.
 */
if ( ! defined( 'WP_NATIVE_AUTH_OAUTH_REQUEST_TTL' ) ) {
	define( 'WP_NATIVE_AUTH_OAUTH_REQUEST_TTL', 15 * MINUTE_IN_SECONDS );
}

/**
 * Dynamic client registrations allowed per IP per rate-limit window.
 */
if ( ! defined( 'WP_NATIVE_AUTH_OAUTH_REGISTRATION_RATE_LIMIT' ) ) {
	define( 'WP_NATIVE_AUTH_OAUTH_REGISTRATION_RATE_LIMIT', 10 );
}

/**
 * Rate-limit window for dynamic client registration, in seconds.
 */
if ( ! defined( 'WP_NATIVE_AUTH_OAUTH_REGISTRATION_RATE_WINDOW' ) ) {
	define( 'WP_NATIVE_AUTH_OAUTH_REGISTRATION_RATE_WINDOW', HOUR_IN_SECONDS );
}

/**
 * Client-id metadata document cache lifetime in seconds.
 */
if ( ! defined( 'WP_NATIVE_AUTH_OAUTH_CIMD_CACHE_TTL' ) ) {
	define( 'WP_NATIVE_AUTH_OAUTH_CIMD_CACHE_TTL', 5 * MINUTE_IN_SECONDS );
}

/**
 * Device-code lifetime in seconds (RFC 8628 §3.2 `expires_in`).
 *
 * Longer than an authorization code because a human has to read a code
 * off one screen and type it into another, possibly after walking to a
 * different room. Short enough that a leaked user code is worthless
 * within minutes.
 */
if ( ! defined( 'WP_NATIVE_AUTH_OAUTH_DEVICE_CODE_TTL' ) ) {
	define( 'WP_NATIVE_AUTH_OAUTH_DEVICE_CODE_TTL', 10 * MINUTE_IN_SECONDS );
}

/**
 * Minimum seconds a client must wait between device-code polls
 * (RFC 8628 §3.2 `interval`).
 */
if ( ! defined( 'WP_NATIVE_AUTH_OAUTH_DEVICE_POLL_INTERVAL' ) ) {
	define( 'WP_NATIVE_AUTH_OAUTH_DEVICE_POLL_INTERVAL', 5 );
}

/**
 * User-code verification attempts allowed per IP per window.
 *
 * RFC 8628 §5.2: the user code is short enough to be guessable, so the
 * verification endpoint needs a brute-force bound. This is the bound.
 */
if ( ! defined( 'WP_NATIVE_AUTH_OAUTH_DEVICE_VERIFY_RATE_LIMIT' ) ) {
	define( 'WP_NATIVE_AUTH_OAUTH_DEVICE_VERIFY_RATE_LIMIT', 20 );
}

/**
 * Rate-limit window for user-code verification attempts, in seconds.
 */
if ( ! defined( 'WP_NATIVE_AUTH_OAUTH_DEVICE_VERIFY_RATE_WINDOW' ) ) {
	define( 'WP_NATIVE_AUTH_OAUTH_DEVICE_VERIFY_RATE_WINDOW', 15 * MINUTE_IN_SECONDS );
}

/**
 * Device authorization requests allowed per IP per window.
 *
 * Issuance is unauthenticated by design — a device client has no secret to
 * present before a user has approved anything — and every request inserts a
 * row. Expiry plus cleanup bound the table over time, but cleanup runs in
 * capped batches, so a caller sustaining more issuance than a batch can
 * reclaim still grows it. This is the bound on that.
 */
if ( ! defined( 'WP_NATIVE_AUTH_OAUTH_DEVICE_AUTHORIZATION_RATE_LIMIT' ) ) {
	define( 'WP_NATIVE_AUTH_OAUTH_DEVICE_AUTHORIZATION_RATE_LIMIT', 30 );
}

/**
 * Rate-limit window for device authorization requests, in seconds.
 */
if ( ! defined( 'WP_NATIVE_AUTH_OAUTH_DEVICE_AUTHORIZATION_RATE_WINDOW' ) ) {
	define( 'WP_NATIVE_AUTH_OAUTH_DEVICE_AUTHORIZATION_RATE_WINDOW', HOUR_IN_SECONDS );
}

/**
 * The single OAuth scope this server grants. Declared for metadata
 * purposes only — consent is allow/deny and issued tokens inherit the
 * user's capability set.
 */
if ( ! defined( 'WP_NATIVE_AUTH_OAUTH_SCOPE' ) ) {
	define( 'WP_NATIVE_AUTH_OAUTH_SCOPE', 'account' );
}

/**
 * Every grant type this server supports.
 *
 * Discovery and dynamic client registration must agree on this list. They
 * previously hardcoded it separately, and adding the device grant to
 * discovery alone meant the server advertised a grant that registration
 * then rejected — a client could see it and could not register to use it.
 * One source of truth so that cannot diverge again.
 *
 * Read at call time rather than as a constant because the device grant
 * identifier is declared in inc/oauth-device.php, which loads later.
 *
 * @return list<string> Supported grant type identifiers.
 */
function wp_native_auth_oauth_supported_grant_types(): array {
	return array(
		'authorization_code',
		'refresh_token',
		WP_NATIVE_AUTH_OAUTH_DEVICE_GRANT_TYPE,
	);
}

require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/oauth-http.php';
require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/oauth-metadata.php';
require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/oauth-clients.php';
require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/oauth-codes.php';
require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/oauth-device-codes.php';
require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/oauth-authorize.php';
require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/oauth-device.php';
require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/oauth-token.php';
require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/oauth-revoke.php';

/**
 * Returns the default endpoint paths this server answers on.
 *
 * Paths are relative to the site root and filterable so a host can move
 * them (e.g. when a page already occupies a default path). Values are
 * matched after stripping the leading slash and any trailing slash.
 *
 * @return array<string,string> endpoint => path.
 */
function wp_native_auth_oauth_routes(): array {
	/**
	 * Filter the endpoint path map.
	 *
	 * @param array<string,string> $routes endpoint => site-root-relative path.
	 */
	return (array) apply_filters(
		'wp_native_auth_oauth_routes',
		array(
			'protected_resource'   => '.well-known/oauth-protected-resource',
			'server_metadata'      => '.well-known/oauth-authorization-server',
			'authorize'            => 'authorize',
			'token'                => 'token',
			'register'             => 'register',
			'revoke'               => 'revoke',
			'device_authorization' => 'device_authorization',
			'device_verification'  => 'device',
		)
	);
}

/**
 * Answer OAuth endpoint requests.
 *
 * Hooked on template_redirect (priority 1, ahead of redirect_canonical at
 * 10). The two RFC 8615 `.well-known` metadata documents are answered by
 * path whatever the main query resolved to: those paths are reserved for
 * this purpose, and a foreign rewrite rule left behind by another plugin
 * would otherwise turn them into a front-page query that
 * redirect_canonical sends to a trailing-slash HTML page (#99).
 *
 * Every other endpoint is answered only when WordPress resolved nothing
 * else, so real content (a page at /register, for example) keeps being
 * served by WordPress; hosts can move any path via
 * wp_native_auth_oauth_routes().
 *
 * @return void
 */
function wp_native_auth_oauth_maybe_handle_request(): void {
	$path = wp_native_auth_oauth_current_path();
	if ( '' === $path ) {
		return;
	}

	$routes = wp_native_auth_oauth_routes();
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

	if ( isset( $routes['protected_resource'] ) && wp_native_auth_oauth_path_matches( $path, (string) $routes['protected_resource'], true ) ) {
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			wp_native_auth_oauth_send_method_not_allowed( 'GET, HEAD' );
		}
		wp_native_auth_oauth_send_protected_resource_metadata();
	}

	if ( isset( $routes['server_metadata'] ) && wp_native_auth_oauth_path_matches( $path, (string) $routes['server_metadata'], true ) ) {
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			wp_native_auth_oauth_send_method_not_allowed( 'GET, HEAD' );
		}
		wp_native_auth_oauth_send_server_metadata();
	}

	if ( ! is_404() ) {
		return;
	}

	if ( isset( $routes['authorize'] ) && wp_native_auth_oauth_path_matches( $path, (string) $routes['authorize'] ) ) {
		if ( 'GET' === $method || 'HEAD' === $method ) {
			wp_native_auth_oauth_handle_authorize();
		}
		if ( 'POST' === $method ) {
			wp_native_auth_oauth_handle_authorize_decision();
		}
		wp_native_auth_oauth_send_method_not_allowed( 'GET, POST' );
	}

	if ( isset( $routes['token'] ) && wp_native_auth_oauth_path_matches( $path, (string) $routes['token'] ) ) {
		if ( 'POST' !== $method ) {
			wp_native_auth_oauth_send_method_not_allowed( 'POST' );
		}
		wp_native_auth_oauth_handle_token();
	}

	if ( isset( $routes['register'] ) && wp_native_auth_oauth_path_matches( $path, (string) $routes['register'] ) ) {
		if ( 'POST' !== $method ) {
			wp_native_auth_oauth_send_method_not_allowed( 'POST' );
		}
		wp_native_auth_oauth_handle_register();
	}

	if ( isset( $routes['revoke'] ) && wp_native_auth_oauth_path_matches( $path, (string) $routes['revoke'] ) ) {
		if ( 'POST' !== $method ) {
			wp_native_auth_oauth_send_method_not_allowed( 'POST' );
		}
		wp_native_auth_oauth_handle_revoke();
	}

	if ( isset( $routes['device_authorization'] ) && wp_native_auth_oauth_path_matches( $path, (string) $routes['device_authorization'] ) ) {
		if ( 'POST' !== $method ) {
			wp_native_auth_oauth_send_method_not_allowed( 'POST' );
		}
		wp_native_auth_oauth_handle_device_authorization();
	}

	if ( isset( $routes['device_verification'] ) && wp_native_auth_oauth_path_matches( $path, (string) $routes['device_verification'] ) ) {
		if ( 'GET' === $method || 'HEAD' === $method ) {
			wp_native_auth_oauth_handle_device_verification();
		}
		if ( 'POST' === $method ) {
			wp_native_auth_oauth_handle_device_verification_decision();
		}
		wp_native_auth_oauth_send_method_not_allowed( 'GET, POST' );
	}
}
add_action( 'template_redirect', 'wp_native_auth_oauth_maybe_handle_request', 1 );

/**
 * Delete expired authorization and device codes on the existing hourly
 * cleanup hook.
 *
 * @return void
 */
function wp_native_auth_oauth_schedule_cleanup(): void {
	add_action( WP_NATIVE_AUTH_CONTINUATION_CLEANUP_HOOK, 'wp_native_auth_cleanup_oauth_codes' );
	add_action( WP_NATIVE_AUTH_CONTINUATION_CLEANUP_HOOK, 'wp_native_auth_cleanup_oauth_device_codes' );
	add_action( 'deleted_user', 'wp_native_auth_oauth_delete_user_codes' );
	add_action( 'deleted_user', 'wp_native_auth_oauth_delete_user_device_codes' );
}
add_action( 'init', 'wp_native_auth_oauth_schedule_cleanup', 2 );
