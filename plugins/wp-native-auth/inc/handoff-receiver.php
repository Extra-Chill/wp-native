<?php
/**
 * Browser handoff receiver.
 *
 * Hooks into `init` to detect the `?wp-native-handoff=<token>` query
 * parameter, consumes the handoff token, and, when the challenge policies and
 * the minting session allow it, establishes a WordPress browsing session.
 * It then redirects to the clean URL (without the handoff param), or to the
 * login URL when no session was established.
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wp_native_auth_handle_browser_handoff' ) ) {
	/**
	 * Handle an incoming browser handoff request.
	 *
	 * Checks for the `wp-native-handoff` query parameter, consumes the token
	 * (single-use), and redirects to the original URL sans handoff param,
	 * signed in when wp_native_auth_process_browser_handoff() allows it.
	 *
	 * @return void
	 */
	function wp_native_auth_handle_browser_handoff(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- handoff token is the auth mechanism.
		if ( empty( $_GET['wp-native-handoff'] ) ) {
			return;
		}

		if ( ! function_exists( 'wp_native_auth_consume_handoff_token' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$token = sanitize_text_field( wp_unslash( $_GET['wp-native-handoff'] ) );

		wp_safe_redirect( wp_native_auth_process_browser_handoff( $token, wp_native_auth_browser_handoff_destination() ) );
		exit;
	}
}

if ( ! function_exists( 'wp_native_auth_browser_handoff_destination' ) ) {
	/**
	 * The current request URL without the handoff param, on this site's origin.
	 *
	 * @return string
	 */
	function wp_native_auth_browser_handoff_destination(): string {
		$path   = remove_query_arg( 'wp-native-handoff' );
		$home   = wp_parse_url( home_url() );
		$origin = ( $home['scheme'] ?? ( is_ssl() ? 'https' : 'http' ) ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );

		return $origin . '/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'wp_native_auth_process_browser_handoff' ) ) {
	/**
	 * Consume a handoff token and, when allowed, start a cookie session.
	 *
	 * The cookie is set only when wp_native_auth_check_browser_handoff()
	 * allows it, and then `wp_login` fires like any other login. A refused or
	 * invalid handoff sets no cookie and sends the user to the site's login
	 * URL with the destination preserved, unless this browser is already
	 * signed in as the handoff's user.
	 *
	 * @param string $token       Plaintext handoff token.
	 * @param string $destination Where to land after the handoff.
	 * @return string Redirect location.
	 */
	function wp_native_auth_process_browser_handoff( string $token, string $destination ): string {
		$handoff = wp_native_auth_consume_handoff_token( $token );
		$user    = null !== $handoff ? get_userdata( $handoff['user_id'] ) : false;

		if ( null === $handoff || ! $user instanceof WP_User ) {
			return wp_native_auth_refused_browser_handoff_location( $destination, 0, new WP_Error( 'invalid_handoff', __( 'Invalid or expired handoff token.', 'wp-native-auth' ) ) );
		}

		$allowed = wp_native_auth_check_browser_handoff( $user, $handoff );
		if ( is_wp_error( $allowed ) ) {
			return wp_native_auth_refused_browser_handoff_location( $destination, (int) $user->ID, $allowed );
		}

		// Establish the WordPress session.
		wp_set_current_user( (int) $user->ID, $user->user_login );
		wp_set_auth_cookie( (int) $user->ID, false );

		/** This action is documented in wp-includes/user.php */
		do_action( 'wp_login', $user->user_login, $user );

		/**
		 * Fires after a successful browser handoff session is established.
		 *
		 * @param int $user_id The user ID that was authenticated.
		 */
		do_action( 'wp_native_auth_after_browser_handoff', (int) $user->ID );

		return $destination;
	}
}

if ( ! function_exists( 'wp_native_auth_refused_browser_handoff_location' ) ) {
	/**
	 * Where a handoff that sets no cookie sends the browser.
	 *
	 * @param string   $destination Handoff destination.
	 * @param int      $user_id     Handoff user, or 0 when the token was invalid.
	 * @param WP_Error $error       Why no cookie was set.
	 * @return string
	 */
	function wp_native_auth_refused_browser_handoff_location( string $destination, int $user_id, WP_Error $error ): string {
		/**
		 * Fires when a browser handoff sets no cookie.
		 *
		 * @param int      $user_id Handoff user, or 0 when the token was invalid.
		 * @param WP_Error $error   Why no cookie was set.
		 */
		do_action( 'wp_native_auth_browser_handoff_refused', $user_id, $error );

		// Already signed in as this user here: nothing to hand off.
		if ( $user_id > 0 && get_current_user_id() === $user_id ) {
			return $destination;
		}

		/**
		 * Filters where a browser handoff that sets no cookie redirects.
		 *
		 * @param string   $location    Login URL with the destination preserved.
		 * @param string   $destination Handoff destination.
		 * @param int      $user_id     Handoff user, or 0 when the token was invalid.
		 * @param WP_Error $error       Why no cookie was set.
		 */
		return (string) apply_filters( 'wp_native_auth_browser_handoff_refused_redirect', wp_login_url( $destination ), $destination, $user_id, $error );
	}
}

add_action( 'init', 'wp_native_auth_handle_browser_handoff', 1 );
