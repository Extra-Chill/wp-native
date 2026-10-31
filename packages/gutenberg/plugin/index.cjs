/**
 * Copyright (C) 2026 wp-native contributors
 * SPDX-License-Identifier: MIT
 */

const { withBuildProperties } = require('expo-build-properties');

const AUTOMATTIC_MAVEN_REPOSITORY =
  'https://a8c-libs.s3.amazonaws.com/android';

const buildProperties = Object.freeze({
  ios: Object.freeze({
    deploymentTarget: '17.0',
  }),
  android: Object.freeze({
    extraMavenRepos: Object.freeze([AUTOMATTIC_MAVEN_REPOSITORY]),
  }),
});

function withWPNativeGutenberg(config) {
  return withBuildProperties(config, buildProperties);
}

module.exports = withWPNativeGutenberg;
module.exports.buildProperties = buildProperties;
