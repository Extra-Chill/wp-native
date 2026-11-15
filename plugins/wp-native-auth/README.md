# wp-native-auth (WordPress plugin)

Generic token-based authentication for native and headless WordPress consumers, exposed through the WordPress Abilities API.

**Current version:** `0.2.0`. Requires WordPress 6.9+ and PHP 8.1+.

## Abilities

The plugin registers eleven abilities:

| Ability | Purpose |
|---|---|
| `wp-native/auth-login` | Authenticate credentials and create a device session |
| `wp-native/auth-continue-login` | Resume a policy challenge without resubmitting credentials |
| `wp-native/auth-refresh` | Atomically rotate a refresh token and issue a new access token |
| `wp-native/auth-logout` | Revoke the current device session |
| `wp-native/auth-me` | Return the authenticated user |
| `wp-native/auth-sessions` | List active device sessions |
| `wp-native/auth-revoke-session` | Revoke another device session |
| `wp-native/auth-browser-handoff` | Mint a one-time URL that establishes a browser session |
| `wp-native/auth-register` | Create an account and device session |
| `wp-native-auth/register-push-token` | Bind a push token to the calling bearer device session |
| `wp-native-auth/unregister-push-token` | Clear the calling device session's push token |

Clients discover and execute these through the standard Abilities REST surface:

```text
GET  /wp-json/wp-abilities/v1/abilities
POST /wp-json/wp-abilities/v1/abilities/wp-native/auth-login/run
```

Use [`wp-native-client`](../../packages/api-client/README.md) to select the correct HTTP method from each ability's annotations and manage authentication transport.

## OAuth 2.1 authorization server

For third-party clients, the plugin also ships a generic OAuth 2.1 authorization server (no vendor knowledge, no product branding):

```text
GET  /.well-known/oauth-protected-resource     RFC 9728 metadata
GET  /.well-known/oauth-authorization-server   RFC 8414 metadata
GET|POST /authorize                           consent (routes through normal WP login when logged out)
POST /token                                    authorization_code + refresh_token grants
POST /register                                 dynamic client registration (rate-limited)
POST /revoke                                   RFC 7009 revocation
```

- PKCE `S256` is mandatory; a missing `code_verifier` is rejected outright at the token endpoint.
- `resource` (RFC 8707) is carried through authorize + token and bound as the token audience.
- Authorization responses carry `iss` (RFC 9207).
- Clients resolve CIMD-first (`client_id` is an HTTPS URL hosting metadata), DCR fallback; only public clients (`token_endpoint_auth_method: "none"`) are issued.
- Redirect URIs match exactly, except port-insensitive matching for http loopback (`localhost` / `127.0.0.1` / `::1`).
- Consent ships as a minimal template replaced via the `wp_native_auth_oauth_consent_template` filter; branded UI belongs to hosts.

OAuth grants are **not** a second token store: each `(user, client)` pair maps onto a row of the existing per-device refresh-token table (stable derived device id), so refresh rotation, sliding expiry, reuse detection, and token-family revocation are the pre-existing lifecycle. The branded consent screen and Connected Apps management are host-layer concerns.

## Push

Push destinations live on the per-device session row (`push_token`, `push_provider`; schema v7) — no second device registry. Native apps register with `wp-native-auth/register-push-token` using their bearer session; logout, session revocation, password changes, refresh-family revocation, and expiry all leave no deliverable token.

Server code sends with:

```php
$receipt = wp_native_auth_send_push( $user_id, array(
	'title'       => 'Title',
	'body'        => 'Optional body',
	'data'        => array( 'route' => '/somewhere' ),
	'collapse_id' => 'optional-group',
) );
```

The call queues a background job (Action Scheduler when present, otherwise WP-Cron) and returns a per-device summary immediately. Providers plug in through the `wp_native_auth_push_transports` filter; an Expo Push API transport ships in `inc/push-transports/expo.php`. Observe with `wp_native_auth_push_token_registered`, `wp_native_auth_push_token_cleared`, and `wp_native_auth_push_sent`. See [`SCHEMAS.md`](SCHEMAS.md#push-118).

## Architecture

- Per-device refresh tokens stored in custom table
- Provider-agnostic policy challenges resume through short-lived, opaque, single-use continuations
- Refresh rotation is atomic: each refresh issues a new token and invalidates the prior one immediately
- Refresh-token replay revokes the complete token family
- Access TTL: 15 minutes
- Refresh TTL: 30 days (sliding — extended on every refresh)
- `device_id` is required (UUID v4) for all session-creating operations
- Password changes revoke every refresh session for the affected user
- Bearer tokens resolve the current WordPress user before protected abilities run

## Extension policy

The implementation contains no product-specific account policy. Consumer plugins can add membership checks, blocked-user policy, profile decoration, registration policy, and authentication challenges through the documented filters and challenge-policy registry.

See [`SCHEMAS.md`](SCHEMAS.md) for the authoritative input/output schemas, error codes, database shape, security behavior, and extension hooks.

## Verification

Run the dependency-free tests from this directory:

```bash
php tests/reuse-logic-standalone-smoke.php
```

The remaining tests extend `WP_UnitTestCase` and run through the managed Homeboy WordPress test harness in CI (`.github/workflows/wp-native-auth.yml`). The plugin ships no PHPUnit bootstrap of its own — the harness owns it.
