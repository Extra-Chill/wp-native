/**
 * Copyright (C) 2026 Chris Huber
 * SPDX-License-Identifier: MIT
 */

import assert from 'node:assert/strict';
import test from 'node:test';
import { normalizeContentResult } from '../dist/content-result.js';

test('preserves serialized Gutenberg content across the native boundary', () => {
  const content =
    '<!-- wp:paragraph --><p>Hello from GutenbergKit.</p><!-- /wp:paragraph -->';

  assert.deepEqual(normalizeContentResult({ title: 'Draft', content }), {
    title: 'Draft',
    content,
  });
});

test('rejects non-object native results', () => {
  assert.throws(() => normalizeContentResult(null), {
    name: 'TypeError',
    message: 'The native editor result must be an object.',
  });
});

test('rejects incomplete native results', () => {
  assert.throws(() => normalizeContentResult({ title: 'Draft' }), {
    name: 'TypeError',
    message:
      'The native editor result must contain string title and content values.',
  });
});
