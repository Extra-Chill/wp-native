# wp-native-gutenberg

Expo and React Native integration for [GutenbergKit](https://github.com/wordpress-mobile/GutenbergKit). The package embeds GutenbergKit's native iOS and Android editor views and leaves data ownership with the consuming app.

This initial package is intentionally offline-only. It accepts initial title and serialized block content, reports readiness and errors, and returns the current title and serialized content on request. It does not provide authentication, REST persistence, media handling, theme styles, plugins, or autosaves.

## Requirements

- Expo SDK 54, React 19.1, React Native 0.81, and Expo Modules Core 3. Newer native stacks are not supported until verified.
- A custom development or production build. Expo Go cannot load this native module.
- iOS 17 or newer.
- Xcode 26 with Swift tools 6.2, required by GutenbergKit v0.19.0.
- Android API 24 or newer.
- The package config plugin enabled in the Expo app configuration.

Add the plugin to `app.json`; it uses Expo SDK 54's `expo-build-properties` integration to set the iOS deployment target and configure the Automattic Maven repository:

```json
{
  "expo": {
    "plugins": ["wp-native-gutenberg/plugin"]
  }
}
```

The plugin sets the iOS deployment target to `17.0` and adds `https://a8c-libs.s3.amazonaws.com/android` to Android's Maven repositories. Run `npx expo prebuild --clean` after adding or updating the plugin. A custom native build is required afterward.

The native dependency is pinned to GutenbergKit `0.19.0` through an exact Swift Package Manager requirement on iOS and `org.wordpress.gutenbergkit:android:v0.19.0` on Android.

## Usage

```tsx
import { useRef, useState } from 'react';
import { Text } from 'react-native';
import {
  GutenbergEditor,
  type GutenbergEditorRef,
} from 'wp-native-gutenberg';

export function Editor() {
  const editorRef = useRef<GutenbergEditorRef>(null);
  const [error, setError] = useState('');

  async function readContent() {
    const { title, content } = await editorRef.current!.requestContent();
    // Persist title and serialized Gutenberg block content in the host app.
  }

  return (
    <>
      {error ? <Text>{error}</Text> : null}
      <GutenbergEditor
        ref={editorRef}
        initialTitle="Draft"
        initialContent="<!-- wp:paragraph --><p>Hello.</p><!-- /wp:paragraph -->"
        onReady={() => undefined}
        onError={({ nativeEvent }) => setError(nativeEvent.message)}
        style={{ flex: 1 }}
      />
    </>
  );
}
```

`initialTitle` and `initialContent` are read when the native editor is created. Use `requestContent()` after `onReady` to retrieve the latest title and canonical serialized block content.

The package coalesces content-change notifications into at most one serialization request per 250 milliseconds. These best-effort in-memory snapshots can help GutenbergKit recover recently observed content after a WebView process reload, and Android seeds a recreated editor from the latest completed snapshot after detach and reattach. Serialization is asynchronous, so an edit immediately before process death or detach may not be present. The host app must call `requestContent()` and persist/autosave its result for durable recovery; the package does not provide persistence or claim lossless recovery.

## Current Native Compile Gates

GutenbergKit v0.19.0 raises the native toolchain floor beyond many current Expo applications. Before release, consumers must verify a clean iOS build with Xcode 26 and CocoaPods' Swift Package Manager dependency support, and a clean Android build with the Automattic Maven repository configured. The pod compiles in Swift 6.0 language mode while GutenbergKit's package manifest requires Swift tools 6.2. On Android, `onError` only reports synchronous `GutenbergView` construction failures. GutenbergKit does not expose later asynchronous dependency-load failures to this wrapper and renders those failures in its own native error view. iOS forwards load and critical editor errors.

Native compilation and device behavior must be verified on macOS/Xcode and in an Android toolchain; TypeScript and autolinking checks alone do not validate Swift or Kotlin compatibility.

## License

The wrapper code is licensed under MIT. GutenbergKit currently has no root license file and GitHub reports no detected license. Publication and distribution of this package remain blocked until GutenbergKit's maintainers explicitly clarify the license in [wordpress-mobile/GutenbergKit#585](https://github.com/wordpress-mobile/GutenbergKit/issues/585). Do not publish this package or distribute applications containing GutenbergKit based on the wrapper's MIT license; GutenbergKit and all transitive native dependencies require their own redistribution rights.
