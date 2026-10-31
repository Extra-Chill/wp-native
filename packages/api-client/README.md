# wp-native-client

Universal WordPress client built on the [Abilities API](https://make.wordpress.org/core/). Discovery + execution. Works in React Native apps, Gutenberg blocks, and Node scripts — one client, three transports, one ability surface.

## Install

```bash
# React Native or Node
npm install wp-native-client

# Gutenberg blocks — also import the /wordpress subpath (see below)
npm install wp-native-client
```

No peer dependencies. The main entry point uses plain `fetch`. The `/wordpress` subpath entry wraps `@wordpress/api-fetch` for block contexts.

## Quick start

```ts
import { WPNativeClient, AuthFetchTransport } from 'wp-native-client';

const transport = new AuthFetchTransport({
  baseUrl: 'https://example.com/wp-json',
  getDeviceId: () => 'your-uuid-v4',
  loadTokens: async () => null,
  saveTokens: async () => {},
  clearTokens: async () => {},
  onAuthFailure: () => console.log('session expired'),
});

await transport.initialize();

const client = new WPNativeClient(transport);

// Discovery — fetches the site's ability catalog
await client.discover();

// Execution — call any ability by name
const me = await client.execute('wp-native/auth-me');
const posts = await client.execute('wp/post.list', { per_page: 20 });
```

### Site-scoped clients

An authenticated transport can approve alternate WordPress REST roots. Derived
clients share its token lifecycle and refresh lock while keeping separate
ability catalogs:

```ts
const transport = new AuthFetchTransport({
  baseUrl: 'https://example.com/wp-json',
  allowedBaseUrls: ['https://members.example.com/wp-json'],
  // storage and device callbacks...
});

const client = new WPNativeClient(transport);
const membersClient = client.derive('https://members.example.com/wp-json');
await membersClient.discover();
```

Alternate roots are denied unless listed exactly in `allowedBaseUrls`. URL
normalization permits equivalent trailing slashes but not credentials, query
strings, fragments, relative URLs, non-HTTP protocols, or redirects.

### Plain REST routes

Some routes are intentionally not abilities (editor wrappers, multipart
uploads). `client.request()` calls them through the client's own transport, so
bearer auth, token refresh, redirect protection, `derive()`d roots and
`ApiError` mapping all apply:

```ts
const editor = await client.request<{ content: string }>({
  path: 'extrachill/v1/community/topics/42/editor',
  query: { context: 'edit' },
});

await client.request({
  path: 'extrachill/v1/community/topics/42/editor',
  method: 'POST',
  body: { content: '<p>Updated</p>' },
});
```

Pass a `FormData` body for multipart uploads. It is sent untouched, and no JSON
`Content-Type` is set, so the runtime adds the multipart boundary:

```ts
const form = new FormData();
// React Native: form.append('file', { uri, name: 'photo.jpg', type: 'image/jpeg' } as any);
form.append('file', fileBlob, 'photo.jpg');
form.append('context', 'post');

const media = await client.request<{ id: number; url: string }>({
  path: 'extrachill/v1/media',
  method: 'POST',
  body: form,
});
```

`path` is relative to the client's REST root (a leading `/` is tolerated).
Absolute URLs, protocol-relative URLs, backslashes, fragments and `.`/`..`
segments (including percent-encoded ones) are rejected before any request is
sent, so credentials never leave the root. `method` defaults to `GET`. `query`
uses the same bracket encoding as ability input (`{ ids: [1, 2] }` →
`ids[0]=1&ids[1]=2`). Non-2xx responses throw `ApiError` with the WP_Error
`code`, `message` and HTTP `status`.

### Three transports

| Transport | Use when | Import |
|---|---|---|
| **`AuthFetchTransport`** | React Native apps, Node scripts — manages token lifecycle (load, save, refresh, clear) | `wp-native-client` |
| **`FetchTransport`** | Unauthenticated or externally-managed auth | `wp-native-client` |
| **`WpApiFetchTransport`** | Gutenberg blocks — wraps `@wordpress/api-fetch`, nonce handling by WP core | `wp-native-client/wordpress` |

```ts
// Gutenberg block example
import { WPNativeClient } from 'wp-native-client';
import { WpApiFetchTransport } from 'wp-native-client/wordpress';
import apiFetch from '@wordpress/api-fetch';

const client = new WPNativeClient(new WpApiFetchTransport(apiFetch));
await client.discover();
const posts = await client.execute('wp/post.list', { per_page: 10 });
```

## Public API summary

### Client

- **`WPNativeClient`** — the universal client. Wraps a transport, exposes `discover()`, `execute()`, `executeUnchecked()`, `request()`, `derive()`, `describe()`, `catalog`.
- **`WPNativeClientConfig`** — optional config (`validateAbilityNames`).
- **`WPNativeRequest`** — `{ path, method?, query?, body?, headers? }` argument for `request()`.

### Transports

- **`AuthFetchTransport`** — authenticated fetch with automatic token refresh and 401 retry.
- **`FetchTransport`** — plain fetch, no auth.
- **`WpApiFetchTransport`** — wraps `@wordpress/api-fetch` for block contexts. Separate entry point: `wp-native-client/wordpress`.
- **`ApiError`** — structured error with `code`, `message`, `status`.

### Abilities

- **`discoverAbilities(transport, filter?)`** — walk the Abilities API, return an `AbilityCatalog`.
- **`AbilityCatalog`** — in-memory lookup of ability descriptors by name.
- **`AbilityDescriptor`** — metadata for a single ability (name, category, input/output schemas).

### Types

- **`StoredTokens`** — `{ accessToken, refreshToken, accessExpiresAt }` (expiry is a Unix timestamp in seconds).
- **`Transport`**, **`TransportRequest`**, **`TransportResponse`** — transport interface contracts.
- **`AuthFetchTransportConfig`**, **`FetchTransportConfig`** — transport configuration shapes.

## Broader project

wp-native-client is the abilities client layer of [wp-native](https://github.com/Extra-Chill/wp-native) — an open-source framework for turning WordPress sites into real native apps. See the [main repo](https://github.com/Extra-Chill/wp-native) for the shell, the auth plugin, and the full documentation.

## License

MIT — [github.com/Extra-Chill/wp-native](https://github.com/Extra-Chill/wp-native)
