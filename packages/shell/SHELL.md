# wp-native-shell Public Contract

`wp-native-shell` is an optional React Native/Expo composition layer around `wp-native-client`. It owns generic authentication, theme, navigation configuration, browser handoff, and ability-driven screen primitives. The consumer owns the expo-router route tree and all product-specific UI.

## Application Composition

`<WPNativeApp>` is a provider stack, not a navigator:

```text
ThemeProvider
  AuthProvider
    NavigationConfigProvider
      BrowserHandoffProvider
        AuthGate
          consumer children
```

The consumer normally passes an expo-router `<Slot/>`, `<Stack/>`, or `<Drawer/>` as `children`.

```tsx
import { Slot } from 'expo-router';
import { WPNativeApp, type WPNativeConfig } from 'wp-native-shell';

const config: WPNativeConfig = {
  api: {
    baseUrl: 'https://example.com/wp-json',
    clientId: 'example-app',
  },
  tokenStorage: {
    getItem: async (_key) => null,
    setItem: async (_key, _value) => {},
    removeItem: async (_key) => {},
  },
  navigation: {
    sections: [{ id: 'feed', label: 'Feed', ability: 'wp/post.list' }],
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

## Configuration

```ts
interface WPNativeConfig {
  api: {
    baseUrl: string;
    clientId: string;
  };
  tokenStorage: TokenStorageAdapter;
  navigation: WPNativeNavigationConfig;
  browserHandoff?: WPNativeBrowserHandoffConfig;
  theme?: Partial<ThemeTokens>;
}

interface TokenStorageAdapter {
  getItem(key: string): Promise<string | null>;
  setItem(key: string, value: string): Promise<void>;
  removeItem(key: string): Promise<void>;
}
```

The shell creates and persists its device UUID through this storage adapter. It also stores access and refresh tokens there. Consumers should use secure platform storage for production credentials.

`WPNativeAppProps` accepts:

- `config`: required `WPNativeConfig`.
- `children`: required consumer navigation tree.
- `loginScreen`: optional component rendered while logged out.
- `loading`: optional node rendered during initial token/session loading.

Brand strings, onboarding policy, product routes, and product-specific screens belong to the consumer and are not part of `WPNativeConfig`.

## Authentication

`AuthProvider` builds an `AuthFetchTransport` and `WPNativeClient`. On mount it loads stored tokens, discovers abilities, and invokes `wp-native/auth-me`. `useAuth()` exposes state and actions:

```ts
interface AuthState {
  user: AuthMeUser | null;
  isLoading: boolean;
  isAuthenticated: boolean;
  sessionExpired: boolean;
}

interface AuthActions {
  login(identifier: string, password: string): Promise<AuthChallengeRequirement | void>;
  continueLogin(token: string, response: Record<string, unknown>): Promise<void>;
  register(email: string, password: string, confirmation: string): Promise<void>;
  logout(): Promise<void>;
  refreshSession(): Promise<void>;
  clearSessionExpired(): void;
  client: WPNativeClient;
}
```

The shell invokes the generic `wp-native/auth-*` abilities. Site-specific account policy is applied by WordPress extensions, not hardcoded in the shell.

## Navigation

The shell does not mount a root navigator. Consumers configure sections and render them from their own expo-router files.

```ts
interface NavigationSection {
  id: string;
  label: string;
  ability?: string;
  screen?: ComponentType;
  visibleWhen?: (auth: AuthState) => boolean;
  listAdapter?: AbilityListAdapter<unknown>;
  detailAbility?: string;
  detailAdapter?: AbilityDetailAdapter<unknown>;
}

interface WPNativeNavigationConfig {
  sections: ReadonlyArray<NavigationSection>;
}
```

- `<DrawerContent>` renders visible section links as an expo-router drawer-content slot.
- `<SectionScreen sectionId="...">` resolves a configured section. A custom `screen` wins; `ability` plus `listAdapter` renders `<AbilityList>`; otherwise it renders a placeholder.
- `<SectionDetailScreen sectionId="..." id="...">` resolves the configured detail ability and adapter.
- List-to-detail routing is owned by consumer filesystem routes, not an internal stack navigator.

See [SCREENS.md](SCREENS.md) for adapter contracts.

## Browser Handoff

```ts
interface WPNativeBrowserHandoffConfig {
  handoffHosts: ReadonlyArray<string>;
  excludeHosts?: ReadonlyArray<string>;
  handoffAbility?: string; // defaults to wp-native/auth-browser-handoff
}
```

`useBrowserHandoff().handle(url)` mints a one-time authenticated URL for allowed hosts. It opens matching URLs directly when the user is logged out or ability execution fails, returns `false` for unmatched hosts, and never throws.

Exact hosts and leading wildcard subdomains such as `*.example.com` are supported. Wildcards do not match the apex host.

## Theme

`ThemeProvider` deep-merges a partial `ThemeTokens` override onto neutral defaults. `useTheme()` returns the fully resolved colors, typography, spacing, and radii. The shell does not depend on consumer design-token packages.

Public exports include:

- `ThemeProvider`, `useTheme`, `defaultThemeTokens`, `deepMergeTokens`
- `ThemeTokens`, `ThemeProviderProps`

## Verification

From the repository root:

```bash
npm run typecheck
npm run test:transport
```

The source exports in `packages/shell/src/index.ts` are authoritative when this document and implementation differ.
