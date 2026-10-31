# wp-native-shell — expo-router rebase contract

> **Completed architecture decision.** This document preserves the migration plan from the original drawer-owned shell to the current consumer-owned expo-router model. `wp-native-shell@0.1.0` implements the resulting provider and slot architecture; checklist and version references below are historical execution notes.

This document was the authoritative implementation plan for migrating `wp-native-shell` from a self-mounted `@react-navigation/drawer` root to a guest of `expo-router`. The current public contract lives in [SHELL.md](SHELL.md); the rationale and slices below remain as architecture history for [Extra-Chill/wp-native#29](https://github.com/Extra-Chill/wp-native/issues/29).

## Why

`wp-native-shell@0.0.1` mounts `@react-navigation/drawer`'s `Drawer.Navigator` directly inside `<WPNativeApp/>`. That makes the shell the **root navigator**, incompatible with consumer apps using `expo-router` (the de-facto modern routing standard for React Native — already used by `extrachill-app`).

Discovered during M7.2.5 (mounting `<WPNativeApp/>` in `extrachill-app`): the existing `app/_layout.tsx` returns an `expo-router` `Stack` that auto-discovers routes from sibling files. Replacing it with `<WPNativeApp/>` would mean two navigators fighting for the nav root.

## The new model

```
Consumer's filesystem (extrachill-app)
─────────────────────────────────────────────────
app/
  _layout.tsx              ← <WPNativeApp config={...}><Slot/></WPNativeApp>
                              (Slot is from expo-router; renders matched route)

  login.tsx                ← consumer's own auth-gated screen
  onboarding.tsx           ← consumer's onboarding screen
  index.tsx                ← optional landing route

  (drawer)/
    _layout.tsx            ← <Drawer drawerContent={<DrawerContent/>}/>
                              (Drawer is from expo-router/drawer; DrawerContent
                               is from wp-native-shell)
    feed.tsx               ← <SectionScreen sectionId="feed"/>
    events.tsx             ← <SectionScreen sectionId="events"/>
    artists.tsx            ← <SectionScreen sectionId="artists"/>
    [id].tsx               ← <SectionDetailScreen/> for ability detail navigation
                              (consumer-controlled — they decide if they want
                               detail routes per section or shared)
```

`<WPNativeApp/>` provides **contexts only** — auth, theme, brand, navigation config, browser handoff. The consumer's expo-router file structure provides **navigation**.

## Top-level surface (post-rebase)

### `<WPNativeApp config={...}>{children}</WPNativeApp>`

The consumer wraps their root layout's children with this. Mounts the four context providers + an auth gate, then renders `children` (typically expo-router's `<Slot/>` or `<Stack/>`).

```tsx
// packages/shell/src/app/wp-native-app.tsx
export interface WPNativeAppProps {
  config: WPNativeConfig;
  /** Optional fallback rendered while initial auth state loads. */
  loading?: ReactNode;
  /** Component rendered when the user is logged out.
   *  Consumer-owned. Receives no props — uses useAuth() internally. */
  loginScreen?: ComponentType;
  /** The expo-router Slot, Stack, or whatever the consumer wants
   *  rendered when the user is authenticated. */
  children: ReactNode;
}

export function WPNativeApp({
  config, loading, loginScreen, children,
}: WPNativeAppProps): React.ReactElement;
```

Composition (outer to inner):

```
ThemeProvider
  BrandProvider
    AuthProvider
      NavigationConfigProvider
        BrowserHandoffProvider          ← NEW (replaces the inline hook wiring)
          AuthGate
            children
```

`AuthGate` decides between three states (unchanged from M5.4):
1. Loading — render `loading` prop or default loader
2. Logged out — render `loginScreen` prop or default placeholder
3. Logged in — render `children`

The **drawer is no longer mounted by `<WPNativeApp/>`.** That's the consumer's responsibility now.

### `<DrawerContent/>` — drop-in for expo-router's `drawerContent`

```tsx
// packages/shell/src/navigation/drawer-content.tsx
import type { DrawerContentComponentProps } from '@react-navigation/drawer';

export interface DrawerContentProps extends DrawerContentComponentProps {
  /** Content rendered above the section list in the drawer. */
  header?: React.ReactNode;
  /** Content rendered below the section list. */
  footer?: React.ReactNode;
}

export function DrawerContent(props: DrawerContentProps): React.ReactElement;
```

Consumer mounts inside `app/(drawer)/_layout.tsx`:

```tsx
import { Drawer } from 'expo-router/drawer';
import { DrawerContent } from 'wp-native-shell';
import { GestureHandlerRootView } from 'react-native-gesture-handler';

export default function DrawerLayout() {
  return (
    <GestureHandlerRootView style={{ flex: 1 }}>
      <Drawer
        drawerContent={(props) => (
          <DrawerContent {...props} header={<UserHeader/>} footer={<LogoutButton/>}/>
        )}
        screenOptions={{ headerShown: false }}
      />
    </GestureHandlerRootView>
  );
}
```

`<DrawerContent/>` reads from `useNavigationConfig()` and `useAuth()` — same as before. Only difference: it's not the navigator itself, just the content slot.

### `<SectionScreen sectionId="..."/>`

Consumer-mounted helper. Used inside route files to render a section's UI based on its config.

```tsx
// packages/shell/src/screens/section-screen.tsx
export interface SectionScreenProps {
  /** The id of the section in config.navigation.sections. */
  sectionId: string;
}

export function SectionScreen({ sectionId }: SectionScreenProps): React.ReactElement;
```

Behavior (same as M6.3, but reads section by id from context):

1. Look up the section by id in `useNavigationConfig().navigation.sections`.
2. If section has `screen`, render that.
3. If section has `ability` + `listAdapter`:
   - Render `<AbilityList ability={section.ability} adapter={section.listAdapter} detailAbility={section.detailAbility}/>`.
4. Else: render `<SectionPlaceholder label={section.label}/>`.

If the sectionId isn't in config, render an error state ("Section X not registered").

Consumer file:

```tsx
// app/(drawer)/feed.tsx
import { SectionScreen } from 'wp-native-shell';

export default function FeedRoute() {
  return <SectionScreen sectionId="feed"/>;
}
```

### `<AbilityList/>` and `<AbilityDetail/>` — expo-router-native

Both components rebase from `@react-navigation/native`'s `useNavigation()` to expo-router's `useRouter()`.

For `<AbilityList/>` with a configured `detailAbility`, item taps navigate to a detail route. **The detail route path is consumer-controlled.** The shell exposes a navigation helper via the adapter:

```tsx
export interface AbilityListAdapter<TItem> {
  // ... existing fields
  /**
   * Build the navigation path for a detail tap. Optional.
   * Default: pushes `./{id}` relative to the current route.
   *
   * Consumer can override to navigate to a custom path:
   *   detailHref: (item) => `/artists/${item.slug}`
   */
  detailHref?: (item: TItem) => string;
}
```

Internally `<AbilityList/>` uses `router.push(detailHref(item) ?? './' + adapter.itemId(item))`.

For `<AbilityDetail/>`, the params come from `useLocalSearchParams()` instead of a `route` prop. The component is unchanged in behavior — it's the routing wiring that changes.

Consumer detail file:

```tsx
// app/(drawer)/artists/[id].tsx
import { useLocalSearchParams } from 'expo-router';
import { SectionDetailScreen } from 'wp-native-shell';

export default function ArtistDetailRoute() {
  const { id } = useLocalSearchParams<{ id: string }>();
  return <SectionDetailScreen sectionId="artists" id={id}/>;
}
```

### `<SectionDetailScreen sectionId="..." id="..."/>` (NEW)

Twin to `SectionScreen` for the detail side. Looks up the section's `detailAbility` + `detailAdapter` from config and renders `<AbilityDetail/>`.

```tsx
export interface SectionDetailScreenProps {
  sectionId: string;
  id: string | number;
}

export function SectionDetailScreen({
  sectionId, id,
}: SectionDetailScreenProps): React.ReactElement;
```

If the section has no `detailAbility`/`detailAdapter` configured, render an error state.

## API removed

```
DrawerShell                  → REMOVED (consumer mounts <Drawer/> from
                                expo-router/drawer; uses <DrawerContent/>
                                slot for the items)
DrawerShellProps             → REMOVED
SectionStack                 → REMOVED (was an internal helper for
                                @react-navigation/native-stack — expo-router
                                handles list/detail through filesystem)
```

## Peer dependencies

```
Before                                After
─────────────────────────────────────────────────
react: >=19                           react: >=19
react-native: >=0.80                  react-native: >=0.80
@react-navigation/drawer: >=7         (transitive via expo-router)
@react-navigation/native: >=7         (transitive via expo-router)
@react-navigation/native-stack: >=7   (REMOVED — expo-router has its own Stack)
expo-router: >=6                      expo-router: >=6
wp-native-client: *                   wp-native-client: *
                                      react-native-gesture-handler: >=2  (NEW —
                                        required by expo-router/drawer)
```

## Files

```
packages/shell/src/
├── app/
│   ├── wp-native-app.tsx        REWRITTEN — providers only, no nav
│   ├── gate.tsx                 minor edits (unchanged behavior)
│   ├── brand.tsx                unchanged
│   ├── types.ts                 unchanged
│   └── index.ts                 unchanged exports
├── auth/                        unchanged
├── theme/                       unchanged
├── navigation/
│   ├── drawer.tsx               REMOVED — split into pieces below
│   ├── drawer-content.tsx       NEW — DrawerContent slot
│   ├── browser-handoff.tsx      NEW — BrowserHandoffProvider (extracted
│                                  from inline wiring)
│   ├── handoff.ts               minor edits to use new provider
│   ├── types.ts                 NavigationSection.detailHref added on
│                                  AbilityListAdapter (in screens module)
│   └── index.ts                 export DrawerContent, drop DrawerShell
├── screens/
│   ├── ability-list.tsx         REWRITTEN — uses expo-router useRouter
│   ├── ability-list-types.ts    AbilityListAdapter.detailHref? added
│   ├── ability-detail.tsx       REWRITTEN — uses useLocalSearchParams
│   ├── ability-detail-types.ts  unchanged
│   ├── section-screen.tsx       REWRITTEN — sectionId prop, lookup by id
│   ├── section-detail-screen.tsx NEW — twin of section-screen
│   └── index.ts                 export SectionDetailScreen
└── index.ts                     update top-level exports
```

## Slice plan (for the fleet)

Three independent slices. SHELL.md rewrite is orchestrator-only (this file).

### Slice B — navigation refactor

Owner: minion-1.

Files:
- DELETE `packages/shell/src/navigation/drawer.tsx`
- CREATE `packages/shell/src/navigation/drawer-content.tsx`
- CREATE `packages/shell/src/navigation/browser-handoff.tsx` (BrowserHandoffProvider — extract from current inline wiring inside the deleted DrawerShell)
- UPDATE `packages/shell/src/navigation/handoff.ts` — keep useBrowserHandoff hook, but it now reads from BrowserHandoffProvider context
- UPDATE `packages/shell/src/navigation/types.ts` — unchanged for now (NavigationSection adds nothing new; AbilityListAdapter changes are in screens module)
- UPDATE `packages/shell/src/navigation/index.ts` — export DrawerContent, BrowserHandoffProvider; drop DrawerShell, NavigationSection types stay

Acceptance:
- `npx tsc -b` exits 0 against this slice's branch
- DrawerContent reads useAuth() + useNavigationConfig() and renders one item per visible section
- DrawerContent's onItemPress calls `props.navigation.navigate(section.id)` (the prop is from expo-router's drawer, same shape as react-navigation/drawer)
- BrowserHandoffProvider context provides `WPNativeBrowserHandoffConfig` to descendants; useBrowserHandoff() consumes from this context

### Slice C — app refactor

Owner: minion-2.

Files:
- REWRITE `packages/shell/src/app/wp-native-app.tsx` — drop DrawerShell mount; add BrowserHandoffProvider mount; render `children` instead of fixed nav
- UPDATE `packages/shell/src/app/gate.tsx` — small edits (children prop instead of fixed nav children)
- UPDATE `packages/shell/src/app/types.ts` — add `children: ReactNode` to WPNativeAppProps
- UPDATE `packages/shell/src/app/index.ts` — exports stable

Acceptance:
- `npx tsc -b` exits 0 against this slice's branch
- `<WPNativeApp config>{children}</WPNativeApp>` renders children inside the provider stack when authenticated
- AuthGate behavior unchanged (loading / logged out / logged in / onboarding states)
- No `@react-navigation/drawer` imports anywhere in app/

### Slice D — screens refactor

Owner: minion-3.

Files:
- REWRITE `packages/shell/src/screens/section-screen.tsx` — takes `sectionId: string`, looks up section from `useNavigationConfig()`. Drops `<SectionStack/>` (consumer routes via filesystem now).
- CREATE `packages/shell/src/screens/section-detail-screen.tsx` — `<SectionDetailScreen sectionId id/>` for `/{id}.tsx`-style detail routes
- REWRITE `packages/shell/src/screens/ability-list.tsx` — uses `useRouter()` from expo-router instead of `useNavigation()`. Adds `detailHref` adapter field for custom paths.
- UPDATE `packages/shell/src/screens/ability-list-types.ts` — add `detailHref?: (item: TItem) => string`
- REWRITE `packages/shell/src/screens/ability-detail.tsx` — minor; the props stay (`ability`, `id`, `adapter`), but anything previously reading nav params is dropped
- UPDATE `packages/shell/src/screens/index.ts` — export SectionDetailScreen
- UPDATE `packages/shell/src/index.ts` — top-level barrel exports SectionDetailScreen

Acceptance:
- `npx tsc -b` exits 0 against this slice's branch
- `<SectionScreen sectionId="..."/>` renders correctly when section is registered
- `<SectionScreen sectionId="..."/>` renders an error state when section is missing
- `<SectionDetailScreen sectionId id/>` looks up `detailAbility`/`detailAdapter` from config; errors if missing
- `<AbilityList/>` calls `router.push(detailHref(item) ?? \`./\${itemId}\`)` on item tap
- `<AbilityDetail/>` reads its `id` and `ability` from props (passed by SectionDetailScreen)

## Slice ordering for merge

```
Slice B → Slice C → Slice D

  B is foundational (drops the DrawerShell that C currently imports).
  C uses B's new BrowserHandoffProvider.
  D uses C's BrowserHandoffProvider context (via useBrowserHandoff).

Each slice's PR should rebase on the prior one before merge to keep the
index.ts barrel coherent.
```

Each minion's PR may include local stub types or duplicated declarations to make standalone typecheck pass; orchestrator cleans those up during merge rebase.

## Slice E — release (orchestrator)

After all three slices merge:
- Bump `packages/shell/package.json` version `0.0.1` → `0.0.2`
- Update peer deps per the table above
- `npm publish --access public`

## What this DOESN'T do

- `wp-native-client` is unaffected — abilities API client doesn't depend on navigation
- `wp-native-auth` plugin is unaffected — server-side
- The 7 abilities on extrachill.com unchanged
- `extrachill-app` migration (M7.2.5+) blocked on this; resumes after `0.0.2` ships

## Definition of done

- [ ] All three slices merged in order
- [ ] `wp-native-shell@0.0.2` published to npm with new peer deps
- [ ] SHELL.md updated to reflect the new mount pattern (separate PR or part of slice C)
- [ ] [Extra-Chill/wp-native#29](https://github.com/Extra-Chill/wp-native/issues/29) closed
- [ ] [extrachill-app#33](https://github.com/Extra-Chill/extrachill-app/issues/33) (M7.2.5) unblocked

## Historical implementation rules

1. **TypeScript strict mode**. The repo's `tsconfig.base.json` enforces full strict — `exactOptionalPropertyTypes`, `noUncheckedIndexedAccess`, `useUnknownInCatchVariables`. Do not relax it.
2. **No `any`**. Use `unknown` + narrowing, or proper types. Catch variables typed `unknown`.
3. **Imports from `expo-router`** are the canonical nav source. Do not import from `@react-navigation/drawer`, `@react-navigation/native`, or `@react-navigation/native-stack` directly anywhere in shell source files. They become transitive deps via expo-router only.
4. **Theme integration**. All visual elements (loaders, errors, empty states, drawer items) use `useTheme()` for colors / typography / spacing. No hardcoded values.
5. **Verify**: `npx tsc -b` from repo root must exit 0 after your changes.
6. **`<@1493317298151489577>` mention** in your final PR comment.
