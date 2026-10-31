/**
 * Copyright (C) 2026 Chris Huber
 * SPDX-License-Identifier: MIT
 */

import assert from 'node:assert/strict';
import test from 'node:test';
import { requestEditorContent } from '../dist/imperative.js';

test('the imperative bridge validates and returns native content', async () => {
  const result = await requestEditorContent({
    async requestContent() {
      return { title: 'Draft', content: '<!-- wp:paragraph /-->' };
    },
  });

  assert.deepEqual(result, {
    title: 'Draft',
    content: '<!-- wp:paragraph /-->',
  });
});

test('the imperative bridge rejects malformed native content', async () => {
  await assert.rejects(
    requestEditorContent({
      async requestContent() {
        return { title: 'Draft' };
      },
    }),
    TypeError,
  );
});

test('the imperative bridge rejects an unmounted native view', async () => {
  await assert.rejects(requestEditorContent(null), {
    message: 'The Gutenberg editor view is not mounted.',
  });
});
