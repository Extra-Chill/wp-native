# wp-native

**Build decoupled applications on WordPress abilities.** `wp-native-client` is a universal TypeScript client for discovering and executing the [Abilities API](https://make.wordpress.org/core/) from Gutenberg blocks, browsers, React Native, and Node. `wp-native-shell` adds an Expo/React Native app shell, `wp-native-gutenberg` embeds GutenbergKit, and the `wp-native-auth` plugin provides generic token authentication for headless clients.

WordPress owns the behavior, schemas, data, and permissions. Each consumer owns its UI. The same ability can therefore power a Gutenberg block, a native screen, or another headless application without putting site-specific knowledge into this framework.

## Status

**Pre-1.0.** The current packages are `wp-native-client@0.0.2`, `wp-native-shell@0.1.0`, `wp-native-gutenberg@0.1.0`, and `wp-native-auth@0.2.0`. The client is used by multiple [Extra Chill community Gutenberg block frontends](https://github.com/Extra-Chill/extrachill-community). APIs may still change before 1.0.

## Quick install

```bash
# React Native app (the common case)
npm install wp-native-shell wp-native-client

# Add the GutenbergKit block editor to an Expo app
npm install wp-native-gutenberg

# Gutenberg blocks or Node scripts (no React Native)
npm install wp-native-client
```

**Peer dependencies** (for `wp-native-shell`):

- `react` >= 19
- `react-native` >= 0.80
- `expo-router` >= 6
- `react-native-gesture-handler` >= 2

The client requires the Abilities API available in WordPress 6.9+. Install **wp-native-auth** when a headless client needs its token lifecycle; same-origin Gutenberg consumers can instead use WordPress's existing cookie and nonce authentication.

## Hello world

`wp-native-shell` provides context providers and an auth gate. Your consumer app owns the navigation via expo-router's filesystem routing.

```tsx
// app/_layout.tsx
import { Slot } from 'expo-router';
import { WPNativeApp } from 'wp-native-shell';
import type { WPNativeConfig } from 'wp-native-shell';

const config: WPNativeConfig = {
  api: {
    baseUrl: 'https://example.com/wp-json',
    clientId: 'example-app',
  },
  tokenStorage: {
    // Plug in your RN storage adapter (expo-secure-store, MMKV, etc.)
    getItem: async (_key) => null,
    setItem: async (_key, _value) => {},
    removeItem: async (_key) => {},
  },
  navigation: {
    sections: [
      { id: 'feed', label: 'Feed', ability: 'wp/post.list' },
    ],
  },
};

export default function RootLayout() {
  return (
    <WPNativeApp config={config} loginScreen={LoginScreen}>
      <Slot />
    </WPNativeApp>
  );
}
```

`<WPNativeApp>` composes (outer to inner): `ThemeProvider` > `AuthProvider` > `NavigationConfigProvider` > `BrowserHandoffProvider` > `AuthGate` > `{children}`. The auth gate renders your `loginScreen` when logged out, a loading fallback during token initialization, and `children` (your expo-router `<Slot/>`) when authenticated.

The consumer mounts the drawer and section screens via expo-router's filesystem — see the [shell contract](https://github.com/Extra-Chill/wp-native/blob/main/packages/shell/SHELL.md) for the full pattern.

## Architecture

For a React Native consumer, the layers compose as follows:

```
┌─────────────────────────────────────────────────────────────┐
│              Consumer App (React Native + expo-router)      │
│                                                             │
│   <WPNativeApp config={...}>{<Slot/>}</WPNativeApp>         │
│   Consumer owns routes, drawer layout, screens              │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│                  wp-native-shell                            │
│   Context providers (auth, theme, navigation, handoff),     │
│   AuthGate, DrawerContent slot, generic ability-driven      │
│   screens (AbilityList, AbilityDetail, SectionScreen)       │
└─────┬───────────────────────────────────────┬───────────────┘
      │                                       │
      ▼                                       ▼
┌──────────────────┐               ┌──────────────────────────┐
│ wp-native-client │               │   wp-native-auth         │
│                  │ ◄── talks to ─►   (WordPress plugin)     │
│ Universal client │   WordPress   │                          │
│   • discover()   │               │   9 abilities:           │
│   • execute()    │               │   login, continue-login, │
│   • auth         │               │   register, refresh,     │
│                  │               │   logout, me, sessions,  │
│ Three transports │               │   revoke, browser-handoff│
│   • FetchTransport               │                          │
│   • AuthFetchTransport           │   Token lifecycle,       │
│   • WpApiFetchTransport          │   device sessions,       │
│     (Gutenberg)  │               │   refresh rotation       │
└──────────────────┘               └──────────────────────────┘
```

The client doesn't know what abilities exist on a given WordPress site — **it asks.** `client.discover()` fetches the site's ability catalog, including input/output schemas and behavioral annotations. `client.execute('ability-name', args)` invokes any ability by name. Site-specific names live in consumer configuration and code, never in `wp-native-client`.

The transport depends on the consumer:

- `WpApiFetchTransport` wraps `@wordpress/api-fetch` for Gutenberg and other same-origin WordPress interfaces.
- `AuthFetchTransport` manages access/refresh tokens for React Native and other headless clients.
- `FetchTransport` supports public abilities or externally managed authentication in browsers and Node.

## Project layout

```
wp-native/
├── packages/
│   ├── shell/          wp-native-shell — React Native app shell
│   ├── api-client/     wp-native-client — universal abilities client
│   ├── gutenberg/      wp-native-gutenberg — GutenbergKit Expo bridge
│   ├── meta/           wp-native — meta package (redirects to the real ones)
│   └── theme/          Design token primitives
├── plugins/
│   └── wp-native-auth/ WordPress plugin — token auth (9 abilities)
└── docs/               Roadmap + audit docs
```

## Documentation

The in-repo contract files are the deep-dive material for each topic:

| Document | What it covers |
|---|---|
| [SHELL.md](https://github.com/Extra-Chill/wp-native/blob/main/packages/shell/SHELL.md) | Full shell surface — auth, theme, navigation, app composition, browser handoff |
| [SCREENS.md](https://github.com/Extra-Chill/wp-native/blob/main/packages/shell/SCREENS.md) | Generic ability-driven screens — `AbilityList`, `AbilityDetail`, adapters |
| [EXPO-ROUTER-REBASE.md](https://github.com/Extra-Chill/wp-native/blob/main/packages/shell/EXPO-ROUTER-REBASE.md) | Architecture decision record for the expo-router migration |
| [SCHEMAS.md](https://github.com/Extra-Chill/wp-native/blob/main/plugins/wp-native-auth/SCHEMAS.md) | All 9 auth abilities — input/output schemas, error codes, extension hooks |
| [ROADMAP.md](https://github.com/Extra-Chill/wp-native/blob/main/docs/ROADMAP.md) | Current state, architectural principles, and next milestones |
| [EC-ABILITIES-AUDIT.md](https://github.com/Extra-Chill/wp-native/blob/main/docs/EC-ABILITIES-AUDIT.md) | Historical Extra Chill ability inventory that informed the initial migration |

## Compatibility

| Dependency | Minimum version |
|---|---|
| WordPress | 6.9+ (Abilities API) |
| React | >= 19 |
| React Native | >= 0.80 |
| expo-router | >= 6 |
| react-native-gesture-handler | >= 2 |

## License

MIT
