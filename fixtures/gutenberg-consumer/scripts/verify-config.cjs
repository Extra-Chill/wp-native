const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const projectRoot = path.resolve(__dirname, '..');
// The tarball under test is the workspace package, so expect its own version
// (release-owned) instead of a pinned literal that drifts on every release.
const gutenbergVersion = require('../../../packages/gutenberg/package.json').version;
const expoCli = require.resolve('expo/bin/cli');
const output = execFileSync(
  process.execPath,
  [expoCli, 'config', '--type', 'prebuild', '--json'],
  { cwd: projectRoot, encoding: 'utf8' },
);
const config = JSON.parse(output);

assert.equal(config.sdkVersion, '54.0.0');
assert.deepEqual(config.plugins, ['wp-native-gutenberg/plugin']);
assert.ok(config._internal.autolinkedModules.includes('wp-native-gutenberg'));
assert.ok(config.mods.ios);
assert.ok(config.mods.android);

function resolveModules(platform) {
  const autolinkingCli = require.resolve('expo-modules-autolinking/bin/expo-modules-autolinking');
  const result = execFileSync(
    process.execPath,
    [autolinkingCli, 'resolve', '--platform', platform, '--project-root', '.', '--json'],
    { cwd: projectRoot, encoding: 'utf8' },
  );
  return JSON.parse(result).modules;
}

for (const platform of ['apple', 'android']) {
  const modules = resolveModules(platform);
  const versions = Object.fromEntries(
    modules.map(({ packageName, packageVersion }) => [packageName, packageVersion]),
  );

  assert.equal(versions.expo, '54.0.36');
  assert.equal(versions['expo-modules-core'], '3.0.30');
  assert.equal(versions['wp-native-gutenberg'], gutenbergVersion);

  for (const module of modules) {
    const nativeProjects = [...(module.pods ?? []), ...(module.projects ?? [])];
    for (const nativeProject of nativeProjects) {
      const sourcePath = nativeProject.podspecDir ?? nativeProject.sourceDir;
      assert.ok(
        sourcePath.startsWith(path.join(projectRoot, 'node_modules')),
        `${module.packageName} resolved outside the isolated fixture: ${sourcePath}`,
      );
    }
  }
}

console.log('Expo 54 config loaded the plugin and isolated native modules.');
