/**
 * Copyright (C) 2026 Chris Huber
 * SPDX-License-Identifier: MIT
 */

import { forwardRef, useImperativeHandle, useRef } from 'react';
import { requireNativeViewManager } from 'expo-modules-core';
import type { StyleProp, ViewStyle } from 'react-native';
import type { GutenbergContent } from './content-result.js';
import {
  requestEditorContent,
  type NativeGutenbergEditorRef,
} from './imperative.js';

type NativeEvent<T> = { nativeEvent: T };

export type GutenbergEditorRef = {
  requestContent(): Promise<GutenbergContent>;
};

export type GutenbergEditorProps = {
  initialTitle: string;
  initialContent: string;
  onReady?: (event: NativeEvent<Record<string, never>>) => void;
  onError?: (event: NativeEvent<{ message: string }>) => void;
  style?: StyleProp<ViewStyle>;
};

const NativeGutenbergEditor = requireNativeViewManager<GutenbergEditorProps>(
  'WPNativeGutenberg',
) as React.ComponentType<
  GutenbergEditorProps & React.RefAttributes<NativeGutenbergEditorRef>
>;

export const GutenbergEditor = forwardRef<
  GutenbergEditorRef,
  GutenbergEditorProps
>(function GutenbergEditor(props, forwardedRef) {
  const nativeRef = useRef<NativeGutenbergEditorRef>(null);

  useImperativeHandle(forwardedRef, () => ({
    async requestContent() {
      return requestEditorContent(nativeRef.current);
    },
  }));

  return <NativeGutenbergEditor ref={nativeRef} {...props} />;
});

export { normalizeContentResult } from './content-result.js';
export type { GutenbergContent } from './content-result.js';
