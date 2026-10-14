<?php
/**
 * Plugin Name:       wp-native Auth
 * Plugin URI:        https://github.com/chubes4/wp-native
 * Description:       Token-based authentication for WordPress, built for native app and third-party OAuth 2.1 clients. Provides login, refresh, logout, and session abilities via the WP 6.9+ Abilities API, plus a generic OAuth 2.1 authorization server (PKCE S256, DCR/CIMD, discovery, revocation).
 * Version:           0.4.3
 * Author:            Chris Huber
 * Author URI:        https://chubes.net
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Text Domain:       wp-native-auth
 *
 * @package WPNativeAuth
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Plugin constants.
 *
 * Schema-bearing constants (TTLs, rate limits) MUST match SCHEMAS.md exactly.
 * The wp-native-client side relies on these values being stable.
 */
define( 'WP_NATIVE_AUTH_PLUGIN_FILE', __FILE__ );
define( 'WP_NATIVE_AUTH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_NATIVE_AUTH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_NATIVE_AUTH_VERSION', '0.4.3' );

// Access token lifetime: 15 minutes.
define( 'WP_NATIVE_AUTH_ACCESS_TOKEN_TTL', 15 * MINUTE_IN_SECONDS );

// Refresh token lifetime: 30 days, sliding (extended on each refresh).
define( 'WP_NATIVE_AUTH_REFRESH_TOKEN_TTL', 30 * DAY_IN_SECONDS );

// Per-device refresh rate limit (seconds between successful refreshes).
define( 'WP_NATIVE_AUTH_REFRESH_RATE_LIMIT_SECONDS', 5 );

// Pending authentication challenge lifetime and verification-attempt ceiling.
define( 'WP_NATIVE_AUTH_CONTINUATION_TTL', 5 * MINUTE_IN_SECONDS );
define( 'WP_NATIVE_AUTH_CONTINUATION_MAX_ATTEMPTS', 5 );
define( 'WP_NATIVE_AUTH_CONTINUATION_CLEANUP_HOOK', 'wp_native_auth_cleanup_continuations' );

// DB layer (refresh tokens table installer).
require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/db.php';

// Token primitives (hashing, access-token generation/validation, helpers).
require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/tokens.php';

// Opaque, single-use authentication challenge continuations.
require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/continuations.php';

// External-service token signing (HMAC-SHA256 signed tokens for delegating
// scoped access to external services that share an HMAC secret).
require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/external-tokens.php';

// Token service (login, refresh, revoke, sessions, user payload).
require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/service.php';

// Password changes end every refresh chain for the affected user.
add_action( 'wp_set_password', 'wp_native_auth_revoke_refresh_sessions_on_password_change', 10, 2 );

// Bearer token request filter (resolves Authorization header → current user).
require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/bearer-auth.php';

// Generic OAuth 2.1 authorization server (discovery, DCR/CIMD, authorize,
// token, revoke — built on the refresh-token lifecycle above).
require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/oauth.php';

// Browser handoff token primitives (mint + validate).
require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/handoff-tokens.php';

// Browser handoff receiver (init-hooked handler for ?wp-native-handoff=<token>).
require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/handoff-receiver.php';

// Ability registrations (the public surface for wp-native-client).
require_once WP_NATIVE_AUTH_PLUGIN_DIR . 'inc/abilities.php';

/**
 * Activation: install the network-wide refresh tokens table.
 */
register_activation_hook( __FILE__, 'wp_native_auth_activate' );

/** Install schema and schedule bounded continuation cleanup. */
function wp_native_auth_activate(): void {
	wp_native_auth_install_refresh_tokens_table();
	wp_native_auth_schedule_continuation_cleanup();
}

/** Remove the continuation cleanup schedule on deactivation. */
function wp_native_auth_deactivate(): void {
	wp_clear_scheduled_hook( WP_NATIVE_AUTH_CONTINUATION_CLEANUP_HOOK );
}

register_deactivation_hook( __FILE__, 'wp_native_auth_deactivate' );

/**
 * Lazy schema upgrade: pick up additive column migrations on existing
 * installs without requiring a plugin reactivation. Backward-compatible —
 * never logs active users out.
 */
add_action( 'init', 'wp_native_auth_ensure_schema', 1 );
add_action( 'init', 'wp_native_auth_schedule_continuation_cleanup', 2 );
add_action( WP_NATIVE_AUTH_CONTINUATION_CLEANUP_HOOK, 'wp_native_auth_cleanup_continuations' );
add_action( 'deleted_user', 'wp_native_auth_delete_user_continuations' );
