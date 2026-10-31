<?php
/**
 * Discovery metadata documents: RFC 8414 authorization-server metadata
 * and RFC 9728 protected-resource metadata.
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * The issuer identifier for this site.
 *
 * Per RFC 8414 §2 the issuer is a URL with no query or fragment; each
 * site in a multisite network is its own issuer.
 *
 * @return string
 */
function wp_native_auth_oauth_issuer(): string {
	$issuer = untrailingslashit( home_url() );

	/**
	 * Filter the OAuth issuer identifier.
	 *
	 * @param string $issuer Issuer URL.
	 */
	return (string) apply_filters( 'wp_native_auth_oauth_issuer', $issuer );
}

/**
 * The protected-resource metadata document URL (RFC 9728 §3.1).
 *
 * @return string
 */
function wp_native_auth_oauth_protected_resource_url(): string {
	return untrailingslashit( home_url() ) . '/.well-known/oauth-protected-resource';
}

/**
 * The URL of this server's endpoints, derived from the issuer.
 *
 * @param string $endpoint Endpoint slug (authorize, token, register, revoke).
 * @return string
 */
function wp_native_auth_oauth_endpoint_url( string $endpoint ): string {
	$routes = wp_native_auth_oauth_routes();
	$path   = isset( $routes[ $endpoint ] ) ? (string) $routes[ $endpoint ] : $endpoint;

	return untrailingslashit( home_url() ) . '/' . $path;
}

/**
 * Build the RFC 8414 authorization-server metadata document.
 *
 * The single declared scope is metadata-only: consent is allow/deny and
 * issued tokens inherit the user's capability set. `none` client
 * authentication (public clients) is the only supported method; CIMD is
 * advertised so clients that carry their own metadata use it before
 * falling back to dynamic registration.
 *
 * The RFC 8628 device grant is advertised alongside the code grant so a
 * client on a host with no browser can discover it rather than assuming
 * a loopback redirect is the only way in.
 *
 * @return array<string,mixed>
 */
function wp_native_auth_oauth_server_metadata(): array {
	$metadata = array(
		'issuer'                                         => wp_native_auth_oauth_issuer(),
		'authorization_endpoint'                         => wp_native_auth_oauth_endpoint_url( 'authorize' ),
		'token_endpoint'                                 => wp_native_auth_oauth_endpoint_url( 'token' ),
		'registration_endpoint'                          => wp_native_auth_oauth_endpoint_url( 'register' ),
		'revocation_endpoint'                            => wp_native_auth_oauth_endpoint_url( 'revoke' ),
		'device_authorization_endpoint'                  => wp_native_auth_oauth_endpoint_url( 'device_authorization' ),
		'scopes_supported'                               => array( WP_NATIVE_AUTH_OAUTH_SCOPE ),
		'response_types_supported'                       => array( 'code' ),
		'response_modes_supported'                       => array( 'query' ),
		'grant_types_supported'                          => wp_native_auth_oauth_supported_grant_types(),
		'token_endpoint_auth_methods_supported'          => array( 'none' ),
		'revocation_endpoint_auth_methods_supported'     => array( 'none' ),
		'code_challenge_methods_supported'               => array( 'S256' ),
		'authorization_response_iss_parameter_supported' => true,
		'client_id_metadata_document_supported'          => true,
		'protected_resource_metadata'                    => wp_native_auth_oauth_protected_resource_url(),
	);

	/**
	 * Filter the authorization-server metadata document.
	 *
	 * @param array<string,mixed> $metadata RFC 8414 metadata.
	 */
	return (array) apply_filters( 'wp_native_auth_oauth_server_metadata', $metadata );
}

/**
 * Build the RFC 9728 protected-resource metadata document.
 *
 * @return array<string,mixed>
 */
function wp_native_auth_oauth_protected_resource_metadata(): array {
	$metadata = array(
		'resource'                 => wp_native_auth_oauth_issuer(),
		'authorization_servers'    => array( wp_native_auth_oauth_issuer() ),
		'scopes_supported'         => array( WP_NATIVE_AUTH_OAUTH_SCOPE ),
		'bearer_methods_supported' => array( 'header' ),
		'resource_documentation'   => 'https://github.com/Extra-Chill/wp-native/tree/main/plugins/wp-native-auth',
	);

	/**
	 * Filter the protected-resource metadata document.
	 *
	 * @param array<string,mixed> $metadata RFC 9728 metadata.
	 */
	return (array) apply_filters( 'wp_native_auth_oauth_protected_resource_metadata', $metadata );
}

/**
 * Send the authorization-server metadata document.
 *
 * @return never
 */
function wp_native_auth_oauth_send_server_metadata(): void {
	wp_native_auth_oauth_send_json( wp_native_auth_oauth_server_metadata() );
}

/**
 * Send the protected-resource metadata document.
 *
 * @return never
 */
function wp_native_auth_oauth_send_protected_resource_metadata(): void {
	wp_native_auth_oauth_send_json( wp_native_auth_oauth_protected_resource_metadata() );
}
