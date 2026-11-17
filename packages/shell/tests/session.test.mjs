import assert from 'node:assert/strict';
import test from 'node:test';
import { isApiError } from 'wp-native-client';
import { buildAuthStack } from '../src/auth/transport.ts';
import {
  installSession,
  isChallengeRequirement,
  parseExpiresAt,
  requestContinueLogin,
} from '../src/auth/session.ts';

const ROOT = 'https://site.test/wp-json';
const DEVICE = '0f8fad5b-d9cb-469f-a165-70867728950e';
const CONTINUE = `${ROOT}/wp-abilities/v1/abilities/wp-native/auth-continue-login`;
const EXPIRES = '2099-01-01T00:00:00Z';
const USER = { id: 7, username: 'member', display_name: 'Member', email: 'm@x.test', avatar_url: '', roles: [], registered_at: '' };
const SUCCESS = {
  access_token: 'access-1',
  access_expires_at: EXPIRES,
  refresh_token: 'refresh-1',
  refresh_expires_at: EXPIRES,
  user: USER,
};

function memoryStorage(initial = {}) {
  const items = new Map(Object.entries(initial));
  return {
    items,
    getItem: async (key) => items.get(key) ?? null,
    setItem: async (key, value) => { items.set(key, value); },
    removeItem: async (key) => { items.delete(key); },
  };
}

function jsonResponse(data, status = 200) {
  return new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });
}

async function withFetch(handler, fn) {
  const original = globalThis.fetch;
  const calls = [];
  globalThis.fetch = async (url, init) => {
    calls.push({ url: String(url), init });
    return handler(String(url), init);
  };
  try {
    await fn(calls);
  } finally {
    globalThis.fetch = original;
  }
}

const emptyCatalog = () => jsonResponse([], 200);
const continueDescriptor = () => jsonResponse({
  name: 'wp-native/auth-continue-login',
  label: 'Continue login',
  description: '',
  category: 'wp-native-auth',
  input_schema: { type: 'object' },
  meta: { annotations: { readonly: false } },
});

function stackWith(storage) {
  return buildAuthStack({ baseUrl: ROOT, clientId: 'test-app' }, storage);
}

test('installSession stores tokens where the shell transport reads them, then discovers', async () => {
  const storage = memoryStorage();
  const stack = stackWith(storage);
  await withFetch(
    (url) => (url.startsWith(`${ROOT}/wp-abilities/v1/`) ? emptyCatalog() : jsonResponse({}, 404)),
    async (calls) => {
      const user = await installSession(stack, SUCCESS);
      assert.deepEqual(user, USER);
      assert.equal(stack.transport.hasTokens(), true);
      assert.equal(storage.items.get('wp_native_access_token'), 'access-1');
      assert.equal(storage.items.get('wp_native_refresh_token'), 'refresh-1');
      assert.equal(storage.items.get('wp_native_access_expiry'), String(parseExpiresAt(EXPIRES)));
      assert.equal(stack.client.hasCatalog(), true);
      // Discovery ran with the new bearer token.
      assert.ok(calls.length > 0);
      assert.ok(calls.every((call) => call.init.headers.Authorization === 'Bearer access-1'));
    },
  );

  // A fresh stack (app restart) loads the same tokens.
  const restarted = stackWith(storage);
  await restarted.transport.initialize();
  assert.equal(restarted.transport.hasTokens(), true);
});

test('installSession rejects challenges and malformed results without storing tokens', async () => {
  const storage = memoryStorage();
  const stack = stackWith(storage);
  const challenge = {
    challenge_required: true,
    challenge_policy: 'demo',
    challenge: { type: 'code' },
    continuation_token: 'cont',
    continuation_expires_at: EXPIRES,
  };
  assert.equal(isChallengeRequirement(challenge), true);
  await assert.rejects(installSession(stack, challenge), /continueLogin/);
  await assert.rejects(installSession(stack, { ...SUCCESS, access_token: '' }), /missing/);
  await assert.rejects(installSession(stack, { ...SUCCESS, access_expires_at: 'nope' }), /missing/);
  await assert.rejects(installSession(stack, { ...SUCCESS, user: undefined }), /missing/);
  assert.equal(stack.transport.hasTokens(), false);
  assert.equal(storage.items.size, 0);
});

test('requestContinueLogin sends the shell device id and surfaces WP_Error data', async () => {
  const stack = stackWith(memoryStorage({ wp_native_device_id: DEVICE }));
  await withFetch(
    (url) => {
      if (url === CONTINUE) return continueDescriptor();
      return jsonResponse({
        code: 'two_factor_too_fast',
        message: 'Please wait before trying again.',
        data: { status: 429, retry_after: 12, continuation_token: 'cont-2' },
      }, 429);
    },
    async (calls) => {
      await assert.rejects(
        requestContinueLogin(stack, 'cont-1', { provider: 'totp', code: '123456' }),
        (error) => {
          // isApiError, not instanceof: the CJS-loaded shell source and this
          // ESM test can see two module instances of wp-native-client.
          assert.ok(isApiError(error));
          assert.equal(error.name, 'ApiError');
          assert.equal(error.status, 429);
          assert.equal(error.code, 'two_factor_too_fast');
          assert.equal(error.data.retry_after, 12);
          assert.equal(error.data.continuation_token, 'cont-2');
          return true;
        },
      );
      const run = calls.find((call) => call.url === `${CONTINUE}/run`);
      assert.equal(run.init.method, 'POST');
      assert.equal(run.init.headers['WP-Native-Client'], 'test-app');
      assert.equal(run.init.headers.Authorization, undefined);
      assert.deepEqual(JSON.parse(run.init.body), {
        input: {
          continuation_token: 'cont-1',
          device_id: DEVICE,
          challenge_response: { provider: 'totp', code: '123456' },
        },
      });
    },
  );
});

test('requestContinueLogin returns a re-issued challenge untouched', async () => {
  const stack = stackWith(memoryStorage({ wp_native_device_id: DEVICE }));
  const challenge = {
    challenge_required: true,
    challenge_policy: 'demo',
    challenge: { type: 'code' },
    continuation_token: 'cont-2',
    continuation_expires_at: EXPIRES,
  };
  await withFetch(
    (url) => (url === CONTINUE ? continueDescriptor() : jsonResponse(challenge)),
    async () => {
      assert.deepEqual(await requestContinueLogin(stack, 'cont-1', { resend: true }), challenge);
      assert.equal(stack.transport.hasTokens(), false);
    },
  );
});
