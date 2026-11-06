# wp-native-auth — Ability Schemas

This document is the **authoritative contract** for every ability the `wp-native-auth` WordPress plugin registers. Implementations MUST match these schemas exactly. The wp-native-client side relies on them.

The contract is generic. Site policy and product-specific behavior belong in extension hooks.

## Constants

```php
WP_NATIVE_AUTH_ACCESS_TOKEN_TTL   = 15 * MINUTE_IN_SECONDS  // 900 seconds
WP_NATIVE_AUTH_REFRESH_TOKEN_TTL  = 30 * DAY_IN_SECONDS     // sliding, extended on refresh
WP_NATIVE_AUTH_REFRESH_RATE_LIMIT_SECONDS = 5
WP_NATIVE_AUTH_CONTINUATION_TTL = 5 * MINUTE_IN_SECONDS
WP_NATIVE_AUTH_CONTINUATION_MAX_ATTEMPTS = 5
WP_NATIVE_AUTH_OAUTH_CODE_TTL = 120                       // authorization-code lifetime
WP_NATIVE_AUTH_OAUTH_REQUEST_TTL = 15 * MINUTE_IN_SECONDS // signed consent-request lifetime
WP_NATIVE_AUTH_OAUTH_REGISTRATION_RATE_LIMIT = 10         // DCR registrations per IP
WP_NATIVE_AUTH_OAUTH_REGISTRATION_RATE_WINDOW = HOUR_IN_SECONDS
WP_NATIVE_AUTH_OAUTH_CIMD_CACHE_TTL = 5 * MINUTE_IN_SECONDS
WP_NATIVE_AUTH_OAUTH_SCOPE = 'account'                    // single, metadata-only scope
```

All timestamps in responses are ISO-8601 strings (e.g. `2026-05-02T19:30:00+00:00`) generated via `gmdate( 'c', $unix_ts )`. Inputs that need expiry semantics use Unix seconds where present.

## Database table

Table name: `{$wpdb->base_prefix}wp_native_auth_refresh_tokens` (network-wide via `base_prefix`, **not** per-blog `prefix`).

```sql
CREATE TABLE {$table_name} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  device_id char(36) NOT NULL,
  device_name varchar(191) NULL,
  refresh_token_hash char(64) NOT NULL,
  token_family char(36) NULL,
  prev_token_hash char(64) NULL,
  oauth_client_id varchar(255) NULL,
  resource varchar(255) NULL,
  created_at datetime NOT NULL,
  last_used_at datetime NULL,
  expires_at datetime NOT NULL,
  revoked_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_device (user_id, device_id),
  KEY user_id (user_id),
  KEY token_family (token_family),
  KEY refresh_token_hash (refresh_token_hash),
  KEY prev_token_hash (prev_token_hash),
  KEY expires_at (expires_at)
) {$charset_collate};
```

Hash algorithm: `hash( 'sha256', $token, false )` — 64 hex chars. Plaintext refresh tokens are returned to the client exactly once (issue + rotate) and never persisted.

### OAuth grant columns (v5)

- **`oauth_client_id`** (`varchar(255)` NULL) — the generic OAuth 2.1 client identifier an OAuth grant was issued to. NULL for native app sessions. Written at grant issuance and preserved across every rotation. Sized for CIMD client identifiers, which are full HTTPS URLs.
- **`resource`** (`varchar(255)` NULL) — the RFC 8707 resource audience the grant was consented to. NULL when the client sent no `resource`. Preserved across rotation and copied into each access token's stored payload (`meta.resource`).

The v5 migration also adds the `refresh_token_hash` and `prev_token_hash` indexes so the OAuth token/revoke endpoints can locate a session by presented token hash (current OR superseded) instead of device id.

### Reuse-detection columns (v2)

- **`token_family`** (`char(36)` NULL) — a UUID v4 set at login/register issue and **preserved across every rotation** of that device session. Reuse detection revokes the whole family. NULL only for legacy rows pending backfill.
- **`prev_token_hash`** (`char(64)` NULL) — SHA-256 hex of the immediately-superseded token. On refresh, a presented token matching this column (and not the current hash) is a **replay → reuse detection**. Reset to NULL on a fresh login/register issue.

### Schema version &amp; migration

The installed table version lives in the network option
`wp_native_auth_schema_version` (`WP_NATIVE_AUTH_SCHEMA_VERSION_OPTION`),
compared against the `WP_NATIVE_AUTH_SCHEMA_VERSION` constant in `inc/db.php`:

- **v1** — original shape (no `token_family` / `prev_token_hash`).
- **v2** — refresh-token reuse detection (#55): adds `token_family` + `prev_token_hash`.
- **v3** — authentication challenge continuations (#63): adds the network-wide `wp_native_auth_login_continuations` table.
- **v4** — binds continuation state to blog and issuing policy, adds one stable account-policy rate-limit identity, and adds atomic claim fields.
- **v5** — generic OAuth 2.1 authorization server (#80): adds `oauth_client_id` + `resource` columns and `refresh_token_hash` / `prev_token_hash` indexes to the refresh tokens table, and adds the `wp_native_auth_oauth_clients` and `wp_native_auth_oauth_authorization_codes` tables.
- **v6** — device authorization grant (#91): adds the `wp_native_auth_oauth_device_codes` table. Purely additive — one new table, no change to any existing one, so installs that never use the device grant are unaffected.

```sql
CREATE TABLE {$wpdb->base_prefix}wp_native_auth_login_continuations (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token_hash char(64) NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  blog_id bigint(20) unsigned NOT NULL,
  device_id char(36) NOT NULL,
  client_id varchar(191) NOT NULL DEFAULT '',
  policy_id varchar(191) NOT NULL,
  rate_limit_hash char(64) NOT NULL,
  request_hash char(64) NOT NULL,
  request_data longtext NOT NULL,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  claim_token char(64) NULL,
  claimed_at datetime NULL,
  created_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY token_hash (token_hash),
  UNIQUE KEY rate_limit_hash (rate_limit_hash),
  KEY user_id (user_id),
  KEY blog_id (blog_id),
  KEY expires_at (expires_at)
);
```

`wp_native_auth_maybe_upgrade_schema()` runs on `admin_init`; when the stored
version is behind it re-runs `dbDelta()` (purely additive — the new columns are
NULL-able, no existing data is rewritten) and backfills a fresh `token_family`
UUID per legacy row via `wp_native_auth_backfill_token_family()`. **Active
refresh tokens keep working through the migration — no user is logged out.** A
legacy row has no `prev_token_hash`, so reuse of its *pre-migration* token
cannot be detected until it rotates once post-migration; that is the only gap
and it is acceptable.

### Lifecycle

1. **Login/Register** — `wp_native_auth_issue_refresh_token()` writes a fresh hash, a **fresh `token_family`**, and `prev_token_hash = NULL`.
2. **Refresh** — `wp_native_auth_refresh_tokens()` rotates via an **atomic conditional UPDATE** that matches the old hash, **preserves `token_family`**, parks the old hash in `prev_token_hash`, and extends `expires_at`. 0 rows affected (a concurrent/replayed rotation already won) is treated as reuse.
3. **Reuse detected** — a token matching `prev_token_hash` (or the 0-rows-affected race) revokes the **entire `token_family`** (`wp_native_auth_revoke_token_family()`), fires `wp_native_auth_refresh_token_reuse_detected`, and returns `refresh_token_reused` (401).
4. **Logout/Revoke** — `wp_native_auth_revoke_refresh_token()` sets `revoked_at` for one device. `wp_native_auth_revoke_user_refresh_tokens()` atomically sets it for every active device and token family owned by one user.
5. **Password changes** — the canonical WordPress `wp_set_password` lifecycle invokes user-wide revocation, covering direct password writes, authenticated `wp_update_user()` changes, and `reset_password()`. Existing access tokens are site transients and remain valid only until their normal 15-minute expiry; no revoked refresh token can mint a replacement. User-wide revocation returns the affected-row count (including `0` for an idempotent no-op) or `WP_Error` on storage failure, and emits distinct success/failure actions.

## Common types referenced below

### `User` object

Returned in login/refresh/me responses.

```json
{
  "id": 42,
  "username": "chubes",
  "display_name": "Chris Huber",
  "email": "chubes@example.com",
  "avatar_url": "https://gravatar.com/...",
  "roles": ["administrator"],
  "registered_at": "2024-01-15T12:00:00+00:00"
}
```

The `roles` array is **the WordPress user's roles on the current blog**. Apps that need network-wide role info should use additional abilities (out of M4 scope).

### `TokenPair` object

Returned by every ability that creates or rotates a session.

```json
{
  "access_token": "eyJhbG...",
  "access_expires_at": "2026-05-02T19:45:00+00:00",
  "refresh_token": "x7Q2pK...",
  "refresh_expires_at": "2026-06-01T19:30:00+00:00"
}
```

### Error envelope

All abilities return `WP_Error` on failure, which the Abilities API translates to a standard error response:

```json
{
  "code": "invalid_credentials",
  "message": "Invalid username or password.",
  "data": { "status": 401 }
}
```

Error codes use snake_case. HTTP status codes match the semantic intent (401 for auth failures, 400 for validation, 429 for rate limits, 500 for server errors).

---

## Abilities

### `wp-native/auth-login`

**Category:** `wp-native-auth`
**Label:** Log in with username or email + password
**Description:** Authenticate a user by credentials and issue an access token + refresh token bound to a device.

#### Input schema

```json
{
  "type": "object",
  "required": ["identifier", "password", "device_id"],
  "additionalProperties": false,
  "properties": {
    "identifier": {
      "type": "string",
      "minLength": 1,
      "description": "Username or email address."
    },
    "password": {
      "type": "string",
      "minLength": 1,
      "description": "Plaintext password. Sent over HTTPS only."
    },
    "device_id": {
      "type": "string",
      "pattern": "^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$",
      "description": "UUID v4 generated client-side and persisted per device."
    },
    "device_name": {
      "type": "string",
      "maxLength": 191,
      "description": "Optional human-readable device name (e.g. 'Chris's iPhone')."
    },
    "remember": {
      "type": "boolean",
      "default": false,
      "description": "Whether to extend cookie session length when set_cookie is also true."
    },
    "set_cookie": {
      "type": "boolean",
      "default": false,
      "description": "Whether to also set a WordPress browsing cookie. Mobile apps pass false; web clients calling same-origin pass true."
    }
  }
}
```

#### Output schema

Successful password-only logins retain the existing response unchanged.

```json
{
  "type": "object",
  "required": ["access_token", "access_expires_at", "refresh_token", "refresh_expires_at", "user"],
  "additionalProperties": false,
  "properties": {
    "access_token":       { "type": "string" },
    "access_expires_at":  { "type": "string", "format": "date-time" },
    "refresh_token":      { "type": "string" },
    "refresh_expires_at": { "type": "string", "format": "date-time" },
    "user":               { "$ref": "#/definitions/User" }
  }
}
```

#### Error codes

| Code | HTTP | Meaning |
|---|---|---|
| `invalid_credentials` | 401 | Bad identifier/password combination |
| `invalid_device_id` | 400 | `device_id` is not a valid UUID v4 |
| `user_blocked` | 403 | User account is suspended (extension hook — see "Extension points" below) |

---

### `wp-native/auth-continue-login`

An authentication policy can pause `wp-native/auth-login` before cookies or
tokens are created by registering through
`wp_native_auth_register_challenge_policy()`. Policies run in registration
order; the first public challenge returned becomes the sole issuer:

```json
{
  "challenge_required": true,
  "challenge_policy": "stable-policy-id",
  "challenge": { "type": "policy-defined", "prompt": "Public instructions" },
  "continuation_token": "opaque-single-use-bearer",
  "continuation_expires_at": "2026-05-02T19:35:00+00:00"
}
```

The policy ID must match `^[a-z0-9][a-z0-9._-]{0,190}$` and the descriptor must
contain a non-empty string `type`. Integrations must not put secrets in this
public object. To resume, clients call
`wp-native/auth-continue-login` with `continuation_token`, the original
`device_id`, and an arbitrary object `challenge_response`. The continuation
dispatches directly to the verifier registered for the stored,
HMAC-bound `challenge_policy`; unrelated callbacks cannot observe or override
the result. The verifier receives the bound user, untrusted response, and
pending request context. It must return boolean `true` to complete login,
`WP_Error` to reject with a policy-specific error, or null/false to return
`challenge_rejected`.

Continuations use 256-bit opaque bearers. Only SHA-256 hashes are persisted.
Server-side state binds the blog, user, issuing policy, device,
`WP-Native-Client`, pending request, original login context, cookie options, and
device name under an HMAC. No password or challenge response is stored. One row
per blog/account/policy preserves its five-attempt budget and original expiry
across password resubmission and bearer reissuance. A request atomically claims
the row before its targeted verifier runs. Expiry is atomically rechecked after
verification and before transactional token persistence. Successful state is
then deleted; recoverable persistence failure releases the claim for retry.

| Code | HTTP | Meaning |
|---|---|---|
| `invalid_continuation` | 401 | Unknown, replayed, substituted, or binding-mismatched continuation |
| `continuation_expired` | 401 | Continuation lifetime elapsed |
| `challenge_rejected` | 401 | Policy did not explicitly approve the response |
| `continuation_rate_limited` | 429 | Five verification attempts were consumed |
| `continuation_in_progress` | 409 | Another request already claimed this continuation |
| `challenge_policy_unavailable` | 503 | The issuing policy is not registered on the redemption request |

### Continuation table and lifecycle

`{$wpdb->base_prefix}wp_native_auth_login_continuations` stores hashed opaque
bearers, blog/user/policy bindings, an HMAC-protected pending request, stable
rate-limit hash, attempt count, claim state, and timestamps. It never stores a
password or challenge response. The schema is created lazily before public
ability execution as well as on activation/init, so headless traffic does not
depend on admin requests. Hourly cleanup removes expired rows in batches of at
most 500; `deleted_user` removes that user's rows; deactivation clears the cron
schedule. This ephemeral security state is therefore not a durable privacy
export record.

---

## OAuth 2.1 authorization server (#80)

The plugin ships a generic OAuth 2.1 authorization server for third-party
clients. It is host-agnostic: no vendor knowledge, no product branding, and no
MCP-specific behavior. The branded consent screen is a host concern, replaced
via the `wp_native_auth_oauth_consent_template` filter.

### Endpoints

Default paths (each filterable through the `wp_native_auth_oauth_routes` path
map). Requests only take over when WordPress resolved nothing else for the
path — a real page at a default path keeps being served, and hosts can move
any endpoint via the filter.

| Endpoint | Method | Purpose |
|---|---|---|
| `/.well-known/oauth-protected-resource` | GET | RFC 9728 protected-resource metadata (path-insertion suffixes also served) |
| `/.well-known/oauth-authorization-server` | GET | RFC 8414 authorization-server metadata |
| `/authorize` | GET / POST | Consent screen + decision (routes unauthenticated users through normal WordPress login) |
| `/token` | POST | `authorization_code` + `refresh_token` + `device_code` grants |
| `/register` | POST | Dynamic client registration (RFC 7591), rate-limited per IP |
| `/revoke` | POST | RFC 7009 revocation, ownership-checked |
| `/device_authorization` | POST | RFC 8628 device authorization request |
| `/device` | GET / POST | User-code entry + consent (routes unauthenticated users through normal WordPress login) |

Metadata advertises at minimum:

```json
{
  "code_challenge_methods_supported": ["S256"],
  "token_endpoint_auth_methods_supported": ["none"],
  "client_id_metadata_document_supported": true,
  "authorization_response_iss_parameter_supported": true,
  "grant_types_supported": [
    "authorization_code",
    "refresh_token",
    "urn:ietf:params:oauth:grant-type:device_code"
  ],
  "response_types_supported": ["code"],
  "scopes_supported": ["account"],
  "device_authorization_endpoint": "https://example.com/device_authorization"
}
```

### Client model

- **CIMD first.** A `client_id` that is itself an HTTPS URL is resolved by
  fetching the metadata document hosted there. The document must self-identify
  with the same URL, its `redirect_uris` must pass the same validation as DCR,
  and validated documents are cached briefly (filterable; failures are
  negative-cached for the same window). IP-literal hosts in private/reserved
  ranges are refused.
- **DCR fallback.** `POST /register` issues opaque public client ids
  (`token_endpoint_auth_method: "none"`, no secret — confidential clients are
  rejected, not silently rewritten). Rate-limited per IP (default 10/hour,
  filterable).

### Redirect URI validation

Registered URIs must be HTTPS, except `http://localhost`, `http://127.0.0.1`,
and `http://::1` (RFC 8252 §7.3). Fragments, credentials, and non-HTTP(S)
schemes are rejected. At authorize/token time the presented URI must match a
registered URI exactly — with one exception: for http loopback URIs the PORT
is ignored. Hosts are never interchangeable (`localhost` ≠ `127.0.0.1`), and
path + query must match exactly.

### Authorization codes

Stored in `{$wpdb->base_prefix}wp_native_auth_oauth_authorization_codes`
(network-wide):

```sql
CREATE TABLE {$wpdb->base_prefix}wp_native_auth_oauth_authorization_codes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code_hash char(64) NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  client_id varchar(255) NOT NULL,
  redirect_uri text NOT NULL,
  code_challenge varchar(128) NOT NULL,
  code_challenge_method varchar(16) NOT NULL DEFAULT 'S256',
  resource varchar(255) NULL,
  scope varchar(64) NULL,
  claim_token char(64) NULL,
  claimed_at datetime NULL,
  created_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY code_hash (code_hash),
  KEY user_id (user_id),
  KEY client_id (client_id),
  KEY expires_at (expires_at)
);
```

- 256-bit opaque codes, persisted as SHA-256 hashes; TTL 120 seconds
  (`wp_native_auth_oauth_code_ttl` filter).
- **Single use by atomic claim** (the continuation-claim pattern): one
  conditional UPDATE sets `claim_token`; concurrent losers see 0 rows and are
  treated as replay. Client and redirect bindings are checked BEFORE the
  claim, so wrong-client probing of a live code does not consume it.
- **PKCE S256 is mandatory and the verifier is required**: an absent or
  malformed `code_verifier` is `invalid_request` before the code is claimed;
  a wrong verifier fails PKCE after the claim, so a code allows exactly one
  verification attempt ever.
- **Replay revokes.** A second redemption of a claimed code revokes the
  refresh session the code minted and fires
  `wp_native_auth_oauth_code_replay_detected`.

### OAuth client registrations

Stored in `{$wpdb->base_prefix}wp_native_auth_oauth_clients`:

```sql
CREATE TABLE {$wpdb->base_prefix}wp_native_auth_oauth_clients (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_id varchar(255) NOT NULL,
  client_name varchar(191) NULL,
  client_uri varchar(255) NULL,
  redirect_uris text NOT NULL,
  token_endpoint_auth_method varchar(32) NOT NULL DEFAULT 'none',
  created_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY client_id (client_id),
  KEY created_at (created_at)
);
```

### Device authorization grant (#91)

For clients that cannot receive a redirect at all — a CLI on a VPS, a
container, a CI runner, a TV app, an agent sandbox. The browser and the
client are on different machines, so no redirect (not even loopback) can
carry the authorization response back.

Stored in `{$wpdb->base_prefix}wp_native_auth_oauth_device_codes`
(network-wide):

```sql
CREATE TABLE {$wpdb->base_prefix}wp_native_auth_oauth_device_codes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  device_code_hash char(64) NOT NULL,
  user_code_hash char(64) NOT NULL,
  client_id varchar(255) NOT NULL,
  user_id bigint(20) unsigned NULL,
  grant_status varchar(16) NOT NULL DEFAULT 'pending',
  resource varchar(255) NULL,
  scope varchar(64) NULL,
  poll_interval smallint(5) unsigned NOT NULL DEFAULT 5,
  last_polled_at datetime NULL,
  claim_token char(64) NULL,
  claimed_at datetime NULL,
  created_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY device_code_hash (device_code_hash),
  UNIQUE KEY user_code_hash (user_code_hash),
  KEY user_id (user_id),
  KEY client_id (client_id),
  KEY expires_at (expires_at)
);
```

`INTERVAL` is a reserved word in MySQL, so the RFC's `interval` field is
stored as `poll_interval`; `status` is stored as `grant_status` for
symmetry. `user_id` is NULL until someone approves — a pending request
belongs to no user yet.

- **Two secrets, two jobs.** `device_code` is a 256-bit opaque bearer held
  by the client that never transits the browser. `user_code` is short and
  human-transcribable (8 characters over a 22-character alphabet with no
  vowels and no ambiguous glyphs, displayed `XXXX-XXXX`). Both are stored
  as SHA-256 hashes, the user code under a distinct domain prefix.
- **No PKCE, deliberately.** This grant has no authorization response and
  no redirect leg, so there is no code-interception attack for PKCE to
  bind against; RFC 8628 defines no `code_challenge` and clients send
  none. Requiring one would reject every spec-compliant device client.
  Single-use atomic claim, client binding, and a 10-minute TTL carry the
  security here.
- **Input is forgiving** (RFC 8628 §6.1): case is folded and every
  character outside the alphabet is discarded, so `wdjb-mjht`,
  `WDJB MJHT`, and `WDJBMJHT` are the same code. Wrong-length input is
  rejected outright, never padded or truncated.
- **Brute force is bounded** (RFC 8628 §5.2): ~35.7 bits of user-code
  entropy is only safe alongside the short TTL and a per-IP attempt limit
  on the verification screen (default 20 per 15 minutes, filterable).
- **Issuance is bounded too.** `POST /device_authorization` is
  unauthenticated by design — a device client has no secret to present
  before anything is approved — and every request inserts a row, which
  capped cleanup batches may not reclaim fast enough. Per-IP limit,
  default 30 per hour, filterable, checked after client authentication
  and before any insert, returning `slow_down` with 429. Keyed on IP
  rather than `client_id` because dynamic registration is open, so a
  per-client bound is evaded by registering another client.
- **Polling follows RFC 8628 §3.5** in this order: unknown code →
  `invalid_grant`; wrong client → `invalid_grant` *before any state
  change*, so one client cannot consume or throttle another's request;
  expired → `expired_token`; too fast → `slow_down`, and the stored
  interval grows by 5 seconds; denied → `access_denied`; pending →
  `authorization_pending`; approved → atomic claim, exactly once.
- **Decisions are conditional UPDATEs** on the row still being `pending`
  and unexpired, so a double submission or a second browser tab cannot
  flip an already-decided request.
- Token issuance is the shared `wp_native_auth_oauth_build_grant()` path,
  so a device grant lands on the same refresh-session row — with the same
  rotation and reuse detection — as a code grant for the same
  `(user, client)` pair.

### Token issuance and rotation (reuse of the existing session layer)

An OAuth grant is **not** a second token store. Each `(user, client)` pair
maps onto one row of the existing refresh-token table via a stable,
UUID-v4-shaped device id derived from a keyed hash
(`wp_native_auth_oauth_device_id()`). Grant issuance calls the existing
`wp_native_auth_issue_refresh_token()` with the OAuth bindings; refresh
grants resolve the presented token by hash (current OR superseded), verify
the `oauth_client_id` ownership binding, and then delegate to the existing
`wp_native_auth_refresh_tokens()` — so **rotation, sliding expiry, per-device
rate limiting, atomic swap, `prev_token_hash` reuse detection, and token-
family revocation are exactly the pre-existing lifecycle, unchanged.**

Access tokens are the existing opaque site-transient bearers, with the RFC
8707 resource audience and client binding stored in the token payload
(`meta.resource`, `meta.client_id`) and carried forward on every rotation.
Every 401 from the token/revoke endpoints carries
`WWW-Authenticate: Bearer resource_metadata="<RFC 9728 URL>"`.

### Consent flow

`GET /authorize` validates client + redirect URI first (failures are shown to
the user directly, never redirected — RFC 6749 §4.1.2.1), then validates
response type, PKCE challenge, scope, and resource, redirecting
specification errors back to the client with `error`, `error_description`,
`state`, and the RFC 9207 `iss` parameter. Unauthenticated users are routed
through normal WordPress login (preserving the full request URL) so existing
site authentication policies — including two-factor plugins — keep working
untouched.

The consent form submits an HMAC-signed, age-limited bundle of the validated
parameters (bound to the authorizing user via `wp_salt('auth')`), plus a
nonce. The decision handler verifies nonce, signature, age, and current-user
identity, re-validates the client, and mints the code. Consent is allow/deny;
the single scope (`account`) exists for metadata purposes only and issued
tokens inherit the user's capability set.

### OAuth error envelope

The OAuth endpoints return RFC 6749 §5.2 error JSON (`error` +
`error_description`) with specification status codes — distinct from the
ability error envelope below:

```json
{
  "error": "invalid_grant",
  "error_description": "The authorization code has already been used."
}
```

### OAuth filters and actions

```php
// Endpoint path map (see table above).
apply_filters( 'wp_native_auth_oauth_routes', array<string,string> $routes );

// Issuer identifier (defaults to the site URL without trailing slash).
apply_filters( 'wp_native_auth_oauth_issuer', string $issuer );

// Metadata documents.
apply_filters( 'wp_native_auth_oauth_server_metadata', array $metadata );
apply_filters( 'wp_native_auth_oauth_protected_resource_metadata', array $metadata );

// Consent template path (hosts supply branded product UI here). The
// device grant renders this same screen with its own nonce action and an
// `is_device_flow` marker, so branding it brands both flows.
apply_filters( 'wp_native_auth_oauth_consent_template', string $path, array $args );

// Device-flow template paths.
apply_filters( 'wp_native_auth_oauth_device_form_template', string $path, array $args );
apply_filters( 'wp_native_auth_oauth_device_result_template', string $path, array $args );

// Lifetimes and limits.
apply_filters( 'wp_native_auth_oauth_code_ttl', int $ttl );
apply_filters( 'wp_native_auth_oauth_request_ttl', int $ttl );
apply_filters( 'wp_native_auth_oauth_registration_rate_limit', int $limit );
apply_filters( 'wp_native_auth_oauth_cimd_cache_ttl', int $ttl );
apply_filters( 'wp_native_auth_oauth_device_code_ttl', int $ttl );
apply_filters( 'wp_native_auth_oauth_device_poll_interval', int $seconds );
apply_filters( 'wp_native_auth_oauth_device_verify_rate_limit', int $limit );
apply_filters( 'wp_native_auth_oauth_device_authorization_rate_limit', int $limit );

// Observability.
do_action( 'wp_native_auth_oauth_grant_issued', int $user_id, string $client_id, string $resource );
do_action( 'wp_native_auth_oauth_code_replay_detected', int $user_id, string $client_id );
do_action( 'wp_native_auth_oauth_device_authorization_issued', string $client_id, string $resource );
do_action( 'wp_native_auth_oauth_device_approved', int $user_id, string $client_id );

// Fired immediately before an endpoint sends its JSON response and
// terminates the request.
do_action( 'wp_native_auth_oauth_before_response', array $data, int $status, array $headers );

// Fired immediately before the browser is redirected to an
// authorization response / login and the request terminates.
do_action( 'wp_native_auth_oauth_before_redirect', string $url );
```

Hourly cleanup of expired authorization codes and device codes (`expires_at`
older than one day, batches of at most 500) shares the existing
continuation-cleanup schedule; `deleted_user` removes that user's codes and
device codes.

---

### `wp-native/auth-refresh`

**Category:** `wp-native-auth`
**Label:** Refresh access token using a refresh token
**Description:** Validate the supplied refresh token, rotate it (issue a new one, invalidate the prior), and return a fresh access token. Sliding 30-day expiry.

#### Input schema

```json
{
  "type": "object",
  "required": ["refresh_token", "device_id"],
  "additionalProperties": false,
  "properties": {
    "refresh_token": { "type": "string", "minLength": 1 },
    "device_id":     { "type": "string", "pattern": "^[0-9a-fA-F]{8}-..." }
  }
}
```

#### Output schema

Same as `wp-native/auth-login` output (TokenPair + User).

#### Error codes

| Code | HTTP | Meaning |
|---|---|---|
| `invalid_refresh_token` | 401 | Token not found, hash mismatch, or revoked |
| `refresh_token_reused` | 401 | A previously-rotated (superseded) token was replayed. The **entire token family is revoked** — the client must re-authenticate. Distinct from `invalid_refresh_token`: treat as a hard logout, not a retry. |
| `refresh_token_expired` | 401 | Token expired (past 30 days without use) |
| `invalid_device_id` | 400 | `device_id` not a valid UUID v4 |
| `rate_limited` | 429 | More than one refresh per 5 seconds for the same device |
| `invalid_user` | 500 | Underlying user record missing |

---

### `wp-native/auth-logout`

**Category:** `wp-native-auth`
**Label:** Revoke the refresh token for a device
**Description:** Mark the device's refresh token as revoked. Access tokens cannot be invalidated server-side (they're short-lived), but the refresh chain ends here.

#### Input schema

```json
{
  "type": "object",
  "required": ["device_id"],
  "additionalProperties": false,
  "properties": {
    "device_id": { "type": "string", "pattern": "^[0-9a-fA-F]{8}-..." }
  }
}
```

Authenticated via Bearer token. The `user_id` is derived from the bearer token, not the input.

#### Output schema

```json
{
  "type": "object",
  "required": ["revoked"],
  "additionalProperties": false,
  "properties": {
    "revoked": { "type": "boolean", "description": "True if a session was revoked, false if no matching device session existed." }
  }
}
```

#### Error codes

| Code | HTTP | Meaning |
|---|---|---|
| `not_authenticated` | 401 | No valid bearer token |
| `invalid_device_id` | 400 | `device_id` not a valid UUID v4 |

---

### `wp-native/auth-me`

**Category:** `wp-native-auth`
**Label:** Get the currently authenticated user
**Description:** Returns the user payload for whoever the bearer token authenticates.

#### Input schema

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {}
}
```

(No input. Authenticated via Bearer token only.)

#### Output schema

```json
{
  "type": "object",
  "required": ["user"],
  "additionalProperties": false,
  "properties": {
    "user": { "$ref": "#/definitions/User" }
  }
}
```

#### Error codes

| Code | HTTP | Meaning |
|---|---|---|
| `not_authenticated` | 401 | No valid bearer token |

---

### `wp-native/auth-sessions`

**Category:** `wp-native-auth`
**Label:** List active device sessions for the current user
**Description:** Returns one row per device that has a non-revoked, non-expired refresh token. Useful for "manage your devices" UI.

#### Input schema

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {}
}
```

(Authenticated via Bearer token.)

#### Output schema

```json
{
  "type": "object",
  "required": ["sessions"],
  "additionalProperties": false,
  "properties": {
    "sessions": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["device_id", "device_name", "created_at", "last_used_at", "expires_at", "current", "oauth_client_id", "oauth_client_name"],
        "properties": {
          "device_id":         { "type": "string" },
          "device_name":       { "type": ["string", "null"] },
          "created_at":        { "type": "string", "format": "date-time" },
          "last_used_at":      { "type": ["string", "null"], "format": "date-time" },
          "expires_at":        { "type": "string", "format": "date-time" },
          "current":           { "type": "boolean", "description": "True if this is the device that made the request." },
          "oauth_client_id":   { "type": ["string", "null"], "description": "OAuth client identifier when the session is an OAuth grant; null for native app sessions." },
          "oauth_client_name": { "type": ["string", "null"], "description": "Human-readable client name recorded when the OAuth grant was created; null for native app sessions." }
        }
      }
    }
  }
}
```

The OAuth binding fields are additive (issue #85). `oauth_client_id` mirrors
the `oauth_client_id` column on the refresh-token row. `oauth_client_name`
comes from the label the grant path writes onto the session row at creation
(`inc/oauth-token.php`): the registered client name, or the raw client id
when the client registered no name. Both are null for native app sessions —
a null `oauth_client_id` continues to mean "not an OAuth grant".

---

### `wp-native/auth-revoke-session`

**Category:** `wp-native-auth`
**Label:** Revoke a specific device session
**Description:** Like `auth.logout`, but takes a target `device_id` rather than the bearer token's own device. Used to remotely sign out other devices.

#### Input schema

```json
{
  "type": "object",
  "required": ["device_id"],
  "additionalProperties": false,
  "properties": {
    "device_id": { "type": "string", "pattern": "^[0-9a-fA-F]{8}-..." }
  }
}
```

Same output and error shape as `auth.logout`.

---

### `wp-native/auth-browser-handoff`

**Category:** `wp-native-auth`
**Label:** Generate a browser handoff URL
**Description:** Mint a one-time signed URL that establishes a WordPress browsing session when opened in the system browser. Used by wp-native-shell's `useBrowserHandoff()` hook to bridge from the app to web flows that aren't yet ability-callable (checkout, password reset, etc.).

#### Input schema

```json
{
  "type": "object",
  "required": ["url"],
  "additionalProperties": false,
  "properties": {
    "url": {
      "type": "string",
      "format": "uri",
      "description": "Destination URL to open in the browser. Must be a valid http(s) URL."
    }
  }
}
```

Authenticated via Bearer token. The `user_id` is derived from the bearer token, not the input.

#### Output schema

```json
{
  "type": "object",
  "required": ["handoff_url", "expires_at"],
  "additionalProperties": false,
  "properties": {
    "handoff_url": {
      "type": "string",
      "format": "uri",
      "description": "The original URL with a ?wp-native-handoff=<token> query appended."
    },
    "expires_at": {
      "type": "string",
      "format": "date-time",
      "description": "ISO 8601 timestamp when the handoff token expires (~60 seconds from issuance)."
    }
  }
}
```

#### Token semantics

- Token: 43-character base64url-encoded random string via `wp_native_auth_generate_opaque_token()` (256 random bits, RFC 7235 b64token alphabet)
- Storage: transient keyed `wp_native_auth_handoff_<sha256(token)>` → `{ user_id: int, created_at: int }`
- TTL: 60 seconds (constant `WP_NATIVE_AUTH_HANDOFF_TOKEN_TTL`)
- Single-use: transient is deleted on validation regardless of outcome

#### Receiver side

The plugin hooks `init` (priority 1) to check for `?wp-native-handoff=<token>`. On valid token:

1. Token is consumed (deleted from transient store)
2. `wp_set_current_user()` + `wp_set_auth_cookie()` establish a WP session
3. `wp_safe_redirect()` to the original URL minus the handoff query param

On invalid/expired token, the handler calls `wp_die()` with a 403 response.

#### Error codes

| Code | HTTP | Meaning |
|---|---|---|
| `invalid_url` | 400 | `url` is not a valid http(s) URL (fails `wp_http_validate_url`) |
| `not_authenticated` | 401 | No valid bearer token |

---

### `wp-native/auth-register`

**Category:** `wp-native-auth`
**Label:** Register a new user account
**Description:** Create a new WordPress user by email + password and issue an access token + refresh token bound to a device. Platform-specific registration concerns (CAPTCHA, membership provisioning, etc.) are layered by consumer plugins via the `wp_native_auth_pre_authenticate`, `wp_native_auth_pre_register`, and `wp_native_auth_pre_login` filters.

#### Input schema

```json
{
  "type": "object",
  "required": ["email", "password", "password_confirm", "device_id"],
  "additionalProperties": false,
  "properties": {
    "email": {
      "type": "string",
      "format": "email",
      "description": "Email address for the new account."
    },
    "password": {
      "type": "string",
      "minLength": 8,
      "description": "Plaintext password. Sent over HTTPS only. Minimum 8 characters."
    },
    "password_confirm": {
      "type": "string",
      "minLength": 8,
      "description": "Must match password exactly."
    },
    "device_id": {
      "type": "string",
      "pattern": "^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$",
      "description": "UUID v4 generated client-side and persisted per device."
    },
    "device_name": {
      "type": "string",
      "maxLength": 191,
      "description": "Optional human-readable device name (e.g. 'Chris's iPhone')."
    }
  }
}
```

#### Output schema

Same as `wp-native/auth-login` output (TokenPair + User).

#### Error codes

| Code | HTTP | Meaning |
|---|---|---|
| `invalid_email` | 400 | Email is empty or fails `is_email()` validation |
| `password_too_short` | 400 | Password is shorter than 8 characters |
| `password_mismatch` | 400 | `password` does not match `password_confirm` |
| `invalid_device_id` | 400 | `device_id` is not a valid UUID v4 |
| `registration_failed` | 400 | Email already registered (generic message to prevent enumeration) |
| `registration_failed` | 500 | `wp_create_user()` returned a `WP_Error` |

#### Extension hooks used

1. `wp_native_auth_pre_authenticate` — fires before any user creation. Consumer can block (request policy, IP rate-limit).
2. `wp_native_auth_pre_register` — fires after validation, before `wp_create_user()`. Consumer can modify `username` or abort.
3. `wp_native_auth_pre_login` — fires after user creation, before token issuance. Same post-user policy as login.
4. `wp_native_auth_after_register` — fires after successful registration and token issuance.

---

## Current non-goals

These concerns are not provided by the current plugin:

- **OAuth abilities** (`wp-native/auth-oauth-google`, etc.)
- **Password reset** — use WordPress's existing browser flow
- Authentication policy integrations are supplied through extension hooks.

## Extension points (filters and actions)

The plugin MUST register these so consumers can layer policy:

### Filters

```php
// Block specific users from logging in (return WP_Error to block).
apply_filters( 'wp_native_auth_pre_login', null, $user, $context );

// Decorate the User object returned in responses.
apply_filters( 'wp_native_auth_user_payload', $user_array, $wp_user, $context );

// Override access token TTL per user (e.g. shorter for admins).
apply_filters( 'wp_native_auth_access_token_ttl', WP_NATIVE_AUTH_ACCESS_TOKEN_TTL, $user_id );

// Override refresh token TTL per user.
apply_filters( 'wp_native_auth_refresh_token_ttl', WP_NATIVE_AUTH_REFRESH_TOKEN_TTL, $user_id );

// Pre-validate registration / login (request policy, IP block, etc.).
apply_filters( 'wp_native_auth_pre_authenticate', null, $identifier, $context );

// Modify registration data or abort before wp_create_user().
// Return null to continue, WP_Error to abort, or an array with
// modified registration data (e.g. override username).
apply_filters( 'wp_native_auth_pre_register', null, $registration_data, $context );

// Recheck account state after a challenge without rerunning pre-login effects.
apply_filters( 'wp_native_auth_revalidate_login', null, $user, $context );

// Observe/override the boolean result of access-token cache persistence.
apply_filters( 'wp_native_auth_access_token_storage_result', $stored, $token_hash, $user_id, $device_id );
```

### Actions

```php
// Integrations register stable challenge and verifier callback pairs here.
do_action( 'wp_native_auth_register_challenge_policies' );

// Fired after a successful login.
do_action( 'wp_native_auth_after_login', $user_id, $device_id, $token_pair );

// Fired after a successful registration.
do_action( 'wp_native_auth_after_register', $user_id, $device_id, $token_pair );

// Fired after a successful refresh.
do_action( 'wp_native_auth_after_refresh', $user_id, $device_id, $token_pair );

// Fired after a logout / revoke.
do_action( 'wp_native_auth_after_logout', $user_id, $device_id );

// Fired when a rotated (superseded) refresh token is replayed — likely a
// stolen-token replay (RFC 9700 §4.14.2). The entire token family has
// already been revoked when this fires. Generic seam for consumers to
// alarm/log; this plugin knows nothing about who consumes it.
do_action( 'wp_native_auth_refresh_token_reuse_detected', $user_id, $device_id, $token_family );
```

Registration callbacks call:

```php
wp_native_auth_register_challenge_policy(
  'stable-policy-id',
  $challenge_callback, // fn( WP_User $user, array $context ): null|array|WP_Error
  $verify_callback     // fn( WP_User $user, array $response, array $context ): bool|WP_Error
);
```

Duplicate or malformed IDs are rejected. The registration map is immutable by
ID for the request; continuation verification invokes the stored issuer's
callback directly rather than passing a mutable result through other policies.

Integration plugins consume these hooks to enforce site authentication and account policy without wp-native-auth knowing implementation details.

## Implementation notes

1. **All abilities live in PHP files under `plugins/wp-native-auth/inc/abilities/`** — one file per ability.
2. **Ability registration uses `wp_register_ability()`** (the WP 6.9+ Abilities API). Each registration includes the schemas above, the execute callback, and the permission callback.
3. **Permission callbacks**: `auth.login` and `auth.refresh` are public; everything else requires a valid bearer token (delegate to a shared helper).
4. **Token generation**: access tokens are opaque random strings minted via `wp_native_auth_generate_opaque_token()` (256-bit random, base64url-encoded, 43 chars from `[A-Za-z0-9_-]`) for v0.1 — JWT comes later. Refresh tokens and handoff tokens use the same helper. The alphabet is the RFC 7235 b64token form: HTTP-header-safe, URL-safe, shell-safe, no characters that `sanitize_text_field()` mangles.
5. **Bearer auth**: M4 must include a small request-time hook that reads `Authorization: Bearer <token>` and resolves it to a `WP_User` via `wp_set_current_user()`. This is the gateway for `is_user_logged_in()` to work in permission callbacks.
6. **Network-wide tables**: the refresh tokens table uses `$wpdb->base_prefix` so it's shared across all blogs in a multisite install.
7. **No site dependencies**: this plugin must run cleanly on a vanilla WP install. Anything site-specific is delegated to the filters above.
