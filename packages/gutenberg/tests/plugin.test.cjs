/**
 * Copyright (C) 2026 wp-native contributors
 * SPDX-License-Identifier: MIT
 */

const assert = require('node:assert/strict');
const test = require('node:test');
const plugin = require('../plugin/index.cjs');

test('configures the supported native build requirements', () => {
  assert.deepEqual(plugin.buildProperties, {
    ios: {
      deploymentTarget: '17.0',
    },
    android: {
      extraMavenRepos: ['https://a8c-libs.s3.amazonaws.com/android'],
    },
  });
});

test('registers Expo mods for both native platforms', () => {
  const config = plugin({ name: 'Fixture', slug: 'fixture' });

  assert.ok(config.mods.ios.podfileProperties);
  assert.ok(config.mods.ios.xcodeproj);
  assert.ok(config.mods.android.gradleProperties);
  assert.ok(config.mods.android.settingsGradle);
});
