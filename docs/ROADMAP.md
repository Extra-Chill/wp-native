# wp-native Roadmap

## Vision

Make the WordPress Abilities API a reusable application backend for decoupled interfaces.

`wp-native-client` gives Gutenberg blocks, browsers, React Native applications, and Node processes one generic discovery and execution client. `wp-native-shell` is an optional React Native/Expo layer for applications that need authentication, navigation, theming, browser handoff, and ability-driven screens.

WordPress owns behavior, schemas, data, and permissions. Consumers own presentation and supply site-specific ability names as configuration or call-site data.

## Principles

- **Abilities first.** Discover and execute the operations registered by the connected WordPress site.
- **One generic client.** Site-specific ability names and result types do not become framework wrappers or subclasses.
- **Multiple interfaces, one behavior surface.** A Gutenberg block and a mobile screen can invoke the same ability through environment-appropriate transports.
- **Consumer-owned UI.** The framework provides composition primitives and defaults without controlling product routes, branding, or workflows.
- **WordPress-native authorization.** Ability permission callbacks and the resolved WordPress user remain authoritative.
- **Strict TypeScript.** Unknown site-defined results are narrowed at consumer boundaries rather than weakened inside the client.

## Architecture

```text
Gutenberg / browser          React Native / Node
        |                            |
WpApiFetchTransport      AuthFetchTransport / FetchTransport
        |                            |
        +------ wp-native-client ----+
                    |
          WordPress Abilities REST API
                    |
       Core, plugin, and theme abilities
```

`wp-native-client` discovers ability descriptors, validates names when a catalog is loaded, selects execution methods from annotations, and invokes the standard `/wp-abilities/v1/abilities/{name}/run` endpoint. It does not contain Extra Chill or other site-specific knowledge.

## Current State

| Surface | Version | State |
|---|---:|---|
| `wp-native-client` | `0.2.0` | Discovery, execution, catalog validation, site-scoped `derive()` clients, plain REST `request()`, and fetch/auth/Gutenberg transports implemented |
| `wp-native-shell` | `0.2.0` | Expo-router provider stack, auth gate, navigation slots, browser handoff, theming, and generic list/detail screens implemented |
| `wp-native-auth` | `0.2.0` | Nine auth abilities, bearer resolution, device sessions, atomic refresh rotation, replay detection, browser handoff, registration, and policy continuations implemented |

Known consumers:

- [Extra Chill community](https://github.com/Extra-Chill/extrachill/tree/main/plugins/extrachill-community) uses `wp-native-client` and `WpApiFetchTransport` in Gutenberg block frontends.
- [`Extra-Chill/extrachill-app`](https://github.com/Extra-Chill/extrachill-app) mounts `wp-native-shell` across its production routes and uses `wp-native-client` for every screen. App Store submission and real-device verification are still pending.

The project remains pre-1.0. Package surfaces may change as the mobile integration is completed and additional consumers exercise the generic boundary.

## Next Milestones

### 1. Complete Mobile Dogfooding

- Verify login, refresh, logout, browser handoff, navigation, and ability-driven screens on real iOS and Android devices.
- Record end-to-end evidence for token persistence, expiry, replay handling, and app restart behavior.

### 2. Expand Shared Client Adoption

- Migrate suitable Extra Chill web consumers from endpoint-specific clients to `wp-native-client` as their server operations become abilities.
- Keep multipart uploads and other unsupported wire formats on explicit REST routes, called through `client.request()`, until the Abilities API supports them.
- Retire `@extrachill/api-client` only after its remaining consumers and contracts have been migrated and verified.

### 3. Improve Schema Tooling

- Generate optional TypeScript types from discovered ability input/output schemas.
- Add drift checking so generated contracts fail CI when the server schema changes.
- Preserve the dynamic `execute<TResult, TInput>()` path for consumers that do not want generated code.

### 4. Harden Framework Contracts

- Add high-signal integration coverage for real Abilities REST discovery and execution.
- Verify compatibility across supported WordPress, React Native, and Expo versions.
- Stabilize public APIs based on production consumers before a 1.0 release.

## Non-goals

- WebView wrappers
- A no-code application builder or hosted SaaS
- Rendering `wp-admin` inside native applications
- Per-site API client subclasses in the framework
- Product-specific ability names, schemas, routes, or policy in generic packages
- Replacing WordPress's data, permission, or ability execution layers

## Historical Context

The original M1-M8 plan established the monorepo, universal client, auth plugin, shell, and generic screens. [`EC-ABILITIES-AUDIT.md`](EC-ABILITIES-AUDIT.md) preserves the May 2, 2026 Extra Chill inventory that informed that work. It is a point-in-time migration artifact, not a current source of truth.
