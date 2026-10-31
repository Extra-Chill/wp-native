/**
 * Copyright (C) 2026 Chris Huber
 * SPDX-License-Identifier: MIT
 */

import {
  normalizeContentResult,
  type GutenbergContent,
} from './content-result.js';

export type NativeGutenbergEditorRef = {
  requestContent(): Promise<unknown>;
};

export async function requestEditorContent(
  nativeRef: NativeGutenbergEditorRef | null,
): Promise<GutenbergContent> {
  if (!nativeRef) {
    throw new Error('The Gutenberg editor view is not mounted.');
  }

  return normalizeContentResult(await nativeRef.requestContent());
}
