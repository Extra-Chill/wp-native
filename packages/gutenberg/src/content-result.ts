/**
 * Copyright (C) 2026 Chris Huber
 * SPDX-License-Identifier: MIT
 */

export type GutenbergContent = {
  title: string;
  content: string;
};

export function normalizeContentResult(value: unknown): GutenbergContent {
  if (!value || typeof value !== 'object') {
    throw new TypeError('The native editor result must be an object.');
  }

  const { title, content } = value as Record<string, unknown>;
  if (typeof title !== 'string' || typeof content !== 'string') {
    throw new TypeError(
      'The native editor result must contain string title and content values.',
    );
  }

  return { title, content };
}
